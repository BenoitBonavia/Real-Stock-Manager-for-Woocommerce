<?php
/**
 * Affectation du stock physique aux commandes.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Distribue le stock physique disponible sur les commandes en attente, et le
 * reprend lors d'un retrait.
 *
 * L'équité repose sur deux invariants : une commande se sert dès qu'elle entre
 * dans le périmètre, et une réception se distribue de la plus ancienne à la plus
 * récente. Du stock libre signifie donc que personne n'attend cette référence.
 */
final class Allocator {

	/**
	 * Attribution automatique temporairement neutralisée.
	 *
	 * @var bool
	 */
	private static $suppressed = false;

	/**
	 * Commandes en cours de traitement, garde de réentrance.
	 *
	 * @var array<int, bool>
	 */
	private static $in_progress = array();

	/**
	 * Accroche l'attribution automatique.
	 */
	public static function register(): void {
		// Priorité 20 : après les notifications de WooCommerce, pour que le mail
		// « commande en cours » parte normalement avant la bascule.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_auto_allocate' ), 20, 4 );

		// Filet pour les commandes créées directement dans un statut suivi :
		// aucune transition n'est enregistrée dans ce cas.
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ), 20, 2 );

		/*
		 * Sortie du périmètre : symétrique de maybe_auto_allocate() ci-dessus.
		 * L'entrée dans le périmètre est automatisée depuis toujours ; sans ces
		 * accroches, la sortie (annulation, remboursement, suppression...)
		 * n'a jamais restitué le stock détenu, qui reste gelé indéfiniment.
		 */
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'release_if_out_of_scope' ), 20, 4 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flag_completed_without_prep' ), 20, 4 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'release_on_trash' ) );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'release_on_delete' ), 10, 2 );
		add_action( 'woocommerce_before_delete_order_item', array( __CLASS__, 'release_on_delete_item' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_order_refunded' ), 10, 2 );
		add_action( 'woocommerce_saved_order_items', array( __CLASS__, 'normalize_saved_items' ), 10, 2 );
	}

	/**
	 * Sert une commande avec le stock libre disponible.
	 *
	 * Portée volontairement limitée à cette commande : l'opération tourne pendant
	 * la requête du client au moment du paiement, un parcours de toutes les
	 * commandes actives y serait déplacé.
	 *
	 * Idempotent : relancée, elle ne prélève rien de plus.
	 *
	 * @param \WC_Order $order Commande.
	 *
	 * @return int Nombre d'articles pointés.
	 */
	public static function allocate_order( $order ): int {
		if ( ! $order instanceof \WC_Order ) {
			return 0;
		}

		$taken = 0;

		foreach ( $order->get_items() as $item ) {

			$free = Stock::get( Items::key( $item ) );

			if ( $free <= 0 ) {
				continue;
			}

			$prepared = Items::prepared( $item );
			$needed   = (int) $item->get_quantity() - $prepared;

			if ( $needed <= 0 ) {
				continue;
			}

			$take = min( $needed, $free );

			Items::set_quantity( $item, $prepared + $take );

			$taken += $take;
		}

		if ( $taken > 0 ) {
			$order->add_order_note(
				sprintf(
					/* translators: %d: nombre d'articles pointés. */
					__( 'Stock disponible : %d article(s) pointé(s) automatiquement.', 'real-stock-manager-for-woocommerce' ),
					$taken
				)
			);

			Demand::flush();
			Log::info( sprintf( 'Commande %d servie automatiquement : %d article(s).', $order->get_id(), $taken ) );
		}

		return $taken;
	}

	/**
	 * Couvre les lignes encore à découvert avec du commandé fournisseur libre.
	 *
	 * Pendant symétrique de allocate_order() pour le second compteur : une
	 * commande qui arrive alors que la marchandise est déjà en route doit
	 * l'afficher, plutôt que de se présenter comme manquante.
	 *
	 * Ne touche jamais au statut : la marchandise n'est pas là.
	 *
	 * @param \WC_Order $order Commande.
	 *
	 * @return int Nombre d'articles réservés.
	 */
	public static function allocate_ordered_to_order( $order ): int {
		if ( ! $order instanceof \WC_Order ) {
			return 0;
		}

		$taken = 0;

		foreach ( $order->get_items() as $item ) {

			$product_id = Items::key( $item );
			$available  = Supply::get( $product_id );

			if ( $available <= 0 ) {
				continue;
			}

			$ordered = Items::ordered( $item );
			$needed  = (int) $item->get_quantity() - Items::prepared( $item ) - $ordered;

			if ( $needed <= 0 ) {
				continue;
			}

			$take = min( $needed, $available );

			$written = Items::set_ordered( $item, $ordered + $take );
			$applied = $written - $ordered;

			if ( $applied !== $take ) {
				Log::error( sprintf( 'Référence #%d : bornage sur set_ordered(), %d appliqué au lieu de %d.', $product_id, $applied, $take ) );
			}

			Supply::adjust( $product_id, -$applied );

			$taken += $applied;
		}

		if ( $taken > 0 ) {
			$order->add_order_note(
				sprintf(
					/* translators: %d: nombre d'articles réservés. */
					__( 'Commande fournisseur en cours : %d article(s) réservé(s) pour cette commande.', 'real-stock-manager-for-woocommerce' ),
					$taken
				)
			);

			Demand::flush();
			Log::info( sprintf( 'Commande %d couverte par une commande fournisseur : %d article(s).', $order->get_id(), $taken ) );
		}

		return $taken;
	}

	/**
	 * Déclenche l'attribution quand une commande entre dans le périmètre.
	 *
	 * @param int       $order_id Identifiant de commande.
	 * @param string    $from     Statut précédent.
	 * @param string    $to       Statut courant.
	 * @param \WC_Order $order    Commande, si fournie par le hook.
	 */
	public static function maybe_auto_allocate( $order_id, $from = '', $to = '', $order = null ): void {
		if ( ! Config::auto_allocate() || self::$suppressed ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Sur woocommerce_new_order, $to n'est pas fourni : on lit le statut réel.
		$status = '' !== (string) $to ? (string) $to : $order->get_status();

		if ( ! in_array( $status, Config::statuses(), true ) ) {
			return;
		}

		// Garde de réentrance : la synchronisation de statut déclenche un nouveau
		// changement de statut, qui repasserait par ce même hook.
		$key = (int) $order->get_id();

		if ( isset( self::$in_progress[ $key ] ) ) {
			return;
		}

		self::$in_progress[ $key ] = true;

		try {
			self::allocate_order( $order );

			/*
			 * Passe fournisseur : réserver de la marchandise en route ne rend pas
			 * une commande prête à empaqueter, mais cela ne change rien à l'ordre —
			 * order_is_ready(), lu par sync() ci-dessous, ne regarde que le stock
			 * physique pointé, jamais la réserve fournisseur.
			 */
			self::allocate_ordered_to_order( $order );

			/*
			 * Synchronisation inconditionnelle : sync() est idempotente et sort
			 * sans écrire si rien ne change (StatusSync::sync). La conditionner à
			 * « quelque chose a été pris cette passe » ratait les commandes déjà
			 * complètes qui (re)entrent dans le périmètre sans qu'aucune allocation
			 * n'ait eu lieu cette fois-ci — par exemple après un aller-retour manuel
			 * de statut.
			 */
			$fresh = wc_get_order( $key );

			if ( $fresh instanceof \WC_Order ) {
				StatusSync::sync( $fresh );
			}
		} finally {
			unset( self::$in_progress[ $key ] );
		}
	}

	/**
	 * Adaptateur pour `woocommerce_new_order`, qui ne transmet pas de statut.
	 *
	 * @param int       $order_id Identifiant de commande.
	 * @param \WC_Order $order    Commande, si fournie par le hook.
	 */
	public static function on_new_order( $order_id, $order = null ): void {
		self::maybe_auto_allocate( $order_id, '', '', $order );
	}

	/**
	 * Restitue au libre ce qu'une commande détient dès qu'elle quitte le
	 * périmètre suivi (annulation, remboursement total, échec, statut retiré
	 * du réglage...).
	 *
	 * Symétrique de maybe_auto_allocate() : celle-ci sert une commande qui
	 * entre dans le périmètre, celle-ci libère une commande qui en sort. Ne
	 * teste jamais $from : si la commande était déjà hors périmètre, il n'y a
	 * simplement rien à restituer (no-op silencieux via release_item()).
	 *
	 * @param int       $order_id Identifiant de commande.
	 * @param string    $from     Statut précédent, non utilisé.
	 * @param string    $to       Statut courant.
	 * @param \WC_Order $order    Commande, si fournie par le hook.
	 */
	public static function release_if_out_of_scope( $order_id, $from = '', $to = '', $order = null ): void {
		unset( $from );

		$tracked = array_merge( Config::statuses(), array( Legacy::STATUS_SLUG ) );

		if ( in_array( (string) $to, $tracked, true ) ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		self::without_auto_allocation(
			static function () use ( $order ) {
				self::release_order( $order );
			}
		);
	}

	/**
	 * Signale, sans rien écrire sur le stock, qu'une commande passe
	 * « Terminée » alors que des lignes n'ont jamais été pointées.
	 *
	 * Le plugin ne suppose jamais qu'une commande a été expédiée en dehors de
	 * son propre système de pointage : décrémenter automatiquement le stock
	 * ici léserait les marchands qui ne pointent pas systématiquement
	 * (attribution automatique désactivée, flux hors métabox).
	 *
	 * @param int       $order_id Identifiant de commande.
	 * @param string    $from     Statut précédent, non utilisé.
	 * @param string    $to       Statut courant.
	 * @param \WC_Order $order    Commande, si fournie par le hook.
	 */
	public static function flag_completed_without_prep( $order_id, $from = '', $to = '', $order = null ): void {
		unset( $from );

		if ( 'completed' !== (string) $to ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$missing = 0;

		foreach ( $order->get_items() as $item ) {
			$missing += max( 0, (int) $item->get_quantity() - Items::prepared( $item ) );
		}

		if ( $missing <= 0 ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %d: nombre d'articles jamais pointés. */
				__( 'Commande terminée avec %d article(s) jamais pointé(s) : le stock réel du plugin n’a pas été décrémenté pour cette part.', 'real-stock-manager-for-woocommerce' ),
				$missing
			)
		);

		Log::error( sprintf( 'Commande %d terminée avec %d article(s) non pointé(s).', $order->get_id(), $missing ) );
	}

	/**
	 * Restitue au libre ce qu'une commande détient au moment où elle est mise
	 * à la corbeille.
	 *
	 * Accroche indispensable, pas une redondance défensive : la mise à la
	 * corbeille ne déclenche PAS woocommerce_order_status_changed (vérifié
	 * dans le cœur WooCommerce — la persistance du statut « trash » contourne
	 * $order->save(), seule méthode qui émet ce hook), donc
	 * release_if_out_of_scope() ne peut pas la voir passer.
	 *
	 * @param int $order_id Identifiant de commande.
	 */
	public static function release_on_trash( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		self::without_auto_allocation(
			static function () use ( $order ) {
				self::release_order( $order );
			}
		);
	}

	/**
	 * Restitue au libre ce qu'une commande détient juste avant sa suppression
	 * définitive.
	 *
	 * Distinct de woocommerce_delete_order (déjà câblé sur Demand::flush dans
	 * OrderPreparation.php) : ce dernier est émis APRÈS destruction des
	 * order_itemmeta, trop tard pour lire from_stock/ordered. Couvre la
	 * suppression directe, sans passage par la corbeille — le cas
	 * « corbeille puis suppression » est déjà réglé par release_on_trash(),
	 * cet appel y est alors un no-op.
	 *
	 * @param int       $order_id Identifiant de commande.
	 * @param \WC_Order $order    Commande, fournie par le hook.
	 */
	public static function release_on_delete( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		self::without_auto_allocation(
			static function () use ( $order ) {
				self::release_order( $order );
			}
		);
	}

	/**
	 * Restitue au libre ce qu'une ligne détenait, juste avant sa suppression
	 * (l'admin retire une ligne sans supprimer toute la commande).
	 *
	 * Le hook ne transmet que l'identifiant : wc_delete_order_item() n'appelle
	 * la suppression effective qu'après avoir émis ce hook, la ligne est donc
	 * encore lisible en base. La résolution globale via WC_Order_Factory est
	 * ici sans risque : l'appelant est WooCommerce lui-même, dont les points
	 * d'entrée admin valident déjà nonce/capability/appartenance en amont.
	 *
	 * @param int $item_id Identifiant de la ligne.
	 */
	public static function release_on_delete_item( $item_id ): void {
		$item = \WC_Order_Factory::get_order_item( absint( $item_id ) );

		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		self::without_auto_allocation(
			static function () use ( $item ) {
				$released = self::release_item( $item );

				if ( $released <= 0 ) {
					return;
				}

				Demand::flush();
				Log::info( sprintf( 'Ligne %d supprimée : %d article(s) restitué(s) au stock libre.', $item->get_id(), $released ) );

				$order = wc_get_order( $item->get_order_id() );

				if ( $order instanceof \WC_Order ) {
					$order->add_order_note(
						sprintf(
							/* translators: %d: nombre d'articles restitués. */
							__( 'Ligne supprimée : %d article(s) restitué(s) au stock libre.', 'real-stock-manager-for-woocommerce' ),
							$released
						)
					);
				}
			}
		);
	}

	/**
	 * Réduit le pointage d'une ligne à hauteur d'un remboursement partiel.
	 *
	 * Seul cas où la commande RESTE dans le périmètre : elle continue
	 * d'afficher 100 % préparé tant que rien ne corrige _mh_prep_qty, sans
	 * qu'aucun mécanisme de sortie ne puisse la rattraper autrement. La
	 * quantité de la ligne d'origine (get_quantity()) n'est jamais modifiée
	 * par WooCommerce lors d'un remboursement, quel qu'il soit — c'est
	 * pourquoi order_is_ready() reste bloqué sans ce correctif.
	 *
	 * Traité comme une mise au rebut, pas une restitution garantie : ce hook
	 * ne dit pas si l'article a été physiquement repris (case « Restocker »
	 * de l'écran de remboursement). Par prudence, l'écart est déduit du libre
	 * plutôt que d'y rester ; au marchand de le recréditer explicitement via
	 * Mouvement à l'unité s'il a bien repris l'article en main.
	 *
	 * @param int $order_id  Identifiant de commande.
	 * @param int $refund_id Identifiant du remboursement.
	 */
	public static function on_order_refunded( $order_id, $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );

		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		self::without_auto_allocation(
			static function () use ( $order, $refund, $order_id, $refund_id ) {
				$changed = false;

				foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
					$original_id  = absint( $refund_item->get_meta( '_refunded_item_id' ) );
					$refunded_qty = abs( (int) $refund_item->get_quantity() );

					if ( $original_id <= 0 || $refunded_qty <= 0 ) {
						continue;
					}

					$original_item = $order->get_item( $original_id, false );

					if ( ! $original_item instanceof \WC_Order_Item_Product ) {
						continue;
					}

					$prepared = Items::prepared( $original_item );
					$target   = max( 0, $prepared - $refunded_qty );

					if ( $target === $prepared ) {
						continue;
					}

					$product_id = Items::key( $original_item );
					$before     = Stock::get( $product_id );

					Items::set_quantity( $original_item, $target );

					$returned = Stock::get( $product_id ) - $before;

					if ( $returned > 0 ) {
						Stock::adjust( $product_id, -$returned );
					}

					$changed = true;
				}

				if ( $changed ) {
					Demand::flush();
					Log::info( sprintf( 'Commande %d : remboursement %d, pointage réduit en conséquence.', $order_id, $refund_id ) );
				}
			}
		);

		$fresh = wc_get_order( $order_id );

		if ( $fresh instanceof \WC_Order ) {
			StatusSync::sync( $fresh );
		}
	}

	/**
	 * Reborne le pointage d'une commande sur ses quantités réelles après
	 * édition de ses lignes (quantité réduite, ligne ajoutée ou supprimée).
	 *
	 * Deux effets : une ligne dont la quantité a baissé après pointage ne
	 * laisse plus _mh_prep_qty désynchronisé de get_quantity() ; l'appel
	 * systématique à StatusSync::sync() redescend une commande « À
	 * empaqueter » qui vient de recevoir une ligne neuve incomplète —
	 * aujourd'hui elle reste affichée comme prête à tort.
	 *
	 * @param int   $order_id Identifiant de commande.
	 * @param array $items    Lignes soumises, non utilisé : les valeurs à
	 *                        jour sont relues depuis la commande.
	 */
	public static function normalize_saved_items( $order_id, $items = array() ): void {
		unset( $items );

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		self::without_auto_allocation(
			static function () use ( $order ) {
				$changed = false;

				foreach ( $order->get_items() as $item ) {
					$quantity = (int) $item->get_quantity();
					$prepared = Items::prepared( $item );

					if ( $prepared > $quantity ) {
						Items::set_quantity( $item, $quantity );
						$changed = true;
					}

					$room    = max( 0, $quantity - min( $quantity, Items::prepared( $item ) ) );
					$ordered = Items::ordered( $item );

					if ( $ordered > $room ) {
						$excess = $ordered - $room;

						Items::set_ordered( $item, $room );
						Supply::adjust( Items::key( $item ), $excess );
						$changed = true;
					}
				}

				if ( $changed ) {
					Demand::flush();
				}
			}
		);

		$fresh = wc_get_order( $order_id );

		if ( $fresh instanceof \WC_Order ) {
			StatusSync::sync( $fresh );
		}
	}

	/**
	 * Restitue au libre le stock détenu par les commandes des statuts qui
	 * viennent d'être retirés du réglage « Statuts à préparer ».
	 *
	 * L'admin vient de redéfinir explicitement le périmètre suivi : sans
	 * cette restitution, le stock détenu par le parc de commandes concerné
	 * reste gelé sans la moindre transition. Bornée à 2000 commandes par
	 * sécurité — un réglage rare et délibéré, pas un chemin chaud.
	 *
	 * @param mixed $old_value Statuts avant l'enregistrement.
	 * @param mixed $new_value Statuts après l'enregistrement.
	 */
	public static function release_removed_statuses( $old_value, $new_value ): void {
		$before  = Config::normalize_statuses( $old_value );
		$after   = Config::normalize_statuses( $new_value );
		$removed = array_diff( $before, $after );

		Demand::flush();

		if ( empty( $removed ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'status' => array_values( $removed ),
				'type'   => 'shop_order',
				'limit'  => 2000,
				'return' => 'ids',
			)
		);

		$orders    = is_array( $orders ) ? $orders : array();
		$processed = 0;

		self::without_auto_allocation(
			static function () use ( $orders, &$processed ) {
				foreach ( $orders as $order_id ) {
					$order = wc_get_order( $order_id );

					if ( $order instanceof \WC_Order ) {
						self::release_order( $order );
						++$processed;
					}
				}
			}
		);

		if ( count( $orders ) >= 2000 ) {
			Log::error(
				sprintf(
					'Retrait de statut(s) « %s » du périmètre de préparation : plafond de 2000 commandes atteint, libération possiblement incomplète.',
					implode( ', ', $removed )
				)
			);
		}

		Log::info( sprintf( 'Retrait de statut(s) « %s » : %d commande(s) traitée(s).', implode( ', ', $removed ), $processed ) );
	}

	/**
	 * Crédite le stock libre puis affecte aux commandes les plus anciennes.
	 *
	 * @param int $product_id Produit ou variation.
	 * @param int $qty        Quantité reçue.
	 *
	 * @return array Compte rendu.
	 */
	public static function receive( $product_id, $qty ): array {
		$product_id = (int) $product_id;
		$qty        = (int) $qty;

		$report = array(
			'produit'   => $product_id,
			'recu'      => $qty,
			'affecte'   => 0,
			'libre'     => 0,
			'converti'  => 0,
			'commande'  => 0,
			'lignes'    => array(),
			'basculees' => array(),
		);

		if ( $product_id <= 0 || $qty <= 0 ) {
			return $report;
		}

		// Le stock entre d'abord en entier, l'affectation le consomme ensuite.
		Stock::adjust( $product_id, $qty );

		$remaining = $qty;
		$converted = 0;

		foreach ( Demand::active_order_ids() as $order_id ) {

			if ( $remaining <= 0 ) {
				break;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$status_before = $order->get_status();
			$allocated     = 0;

			foreach ( $order->get_items() as $item ) {

				if ( $remaining <= 0 ) {
					break;
				}

				if ( Items::key( $item ) !== $product_id ) {
					continue;
				}

				$needed = (int) $item->get_quantity() - Items::prepared( $item );

				if ( $needed <= 0 ) {
					continue;
				}

				$take = min( $needed, $remaining );

				$result = Items::set_quantity( $item, Items::prepared( $item ) + $take, true );

				// Part de la ligne qui était en commande fournisseur et vient
				// d'arriver : elle a déjà été retirée du décompte de la ligne.
				$converted += (int) $result['converted'];

				$remaining         -= $take;
				$allocated         += $take;
				$report['affecte'] += $take;
			}

			if ( $allocated <= 0 ) {
				/*
				 * Rien pris cette passe : rattraper malgré tout une commande déjà
				 * complète qui n'a jamais été synchronisée (aller-retour de statut,
				 * timeout d'une réaffectation précédente). sync() est idempotente.
				 */
				if ( Items::order_is_ready( $order ) ) {
					$fresh = wc_get_order( $order_id );

					if ( $fresh instanceof \WC_Order && StatusSync::sync( $fresh ) !== $status_before ) {
						$report['basculees'][] = $fresh->get_order_number();
					}
				}

				continue;
			}

			$order = wc_get_order( $order_id );

			$report['lignes'][] = self::order_summary( $order, $allocated );

			$order->add_order_note(
				sprintf(
					/* translators: 1: quantité affectée, 2: nom de la référence. */
					__( 'Réception fournisseur : %1$d × %2$s affecté(s) à cette commande.', 'real-stock-manager-for-woocommerce' ),
					$allocated,
					wp_strip_all_tags( Labels::get( $product_id )['name'] )
				)
			);

			if ( StatusSync::sync( $order ) !== $status_before ) {
				$report['basculees'][] = $order->get_order_number();
			}
		}

		/*
		 * Solde du compteur « commandé au fournisseur ».
		 *
		 * Seul le RÉSIDU est retiré : ce que les lignes ont déjà absorbé a été
		 * décompté par la conversion dans Items::set_quantity(), le soustraire une
		 * seconde fois viderait le compteur à tort.
		 *
		 * Exemple : compteur à 2, lignes couvertes à 3, réception de 3. La
		 * conversion consomme les 3 au niveau des lignes, le résidu vaut zéro, et
		 * le compteur reste à 2 — les deux unités commandées pour le stock sont
		 * toujours attendues.
		 *
		 * Le résidu représente des unités PHYSIQUEMENT arrivées avec ce colis
		 * mais qu'aucune ligne n'a converties (le FIFO les a servies à une
		 * commande qui n'avait elle-même aucune réserve fournisseur). Puiser
		 * uniquement dans le pool libre écrête en silence dès que ce pool est
		 * insuffisant, laissant une ligne détentrice croire que du stock est
		 * encore en route alors que ce colis a déjà tout livré : au-delà du
		 * pool, le reliquat est donc repris directement sur les lignes qui
		 * détiennent encore une réserve, exactement comme une annulation
		 * fournisseur — ce colis a livré tout ce qu'il devait livrer.
		 */
		$residual = max( 0, $qty - $converted );

		if ( $residual > 0 ) {
			$from_pool = min( $residual, max( 0, Supply::get( $product_id ) ) );

			if ( $from_pool > 0 ) {
				Supply::adjust( $product_id, -$from_pool );
			}

			$still_residual = $residual - $from_pool;

			if ( $still_residual > 0 ) {
				$reclaim = self::reclaim_ordered_from_holders( $product_id, $still_residual );

				foreach ( $reclaim['lines'] as $line ) {
					$line['order']->add_order_note(
						sprintf(
							/* translators: 1: quantité, 2: nom de la référence. */
							__( 'Réception fournisseur : le solde de %1$d × %2$s encore attendu sur cette commande vient d’un autre colis, il n’est plus en route.', 'real-stock-manager-for-woocommerce' ),
							$line['qty'],
							wp_strip_all_tags( Labels::get( $product_id )['name'] )
						)
					);
				}
			}
		}

		$report['converti'] = $converted;
		$report['libre']    = Stock::get( $product_id );
		$report['commande'] = Supply::get( $product_id );

		Demand::flush();
		Log::info(
			sprintf(
				'Réception %d × #%d : %d affecté(s), %d converti(s) depuis le commandé, %d libre(s).',
				$qty,
				$product_id,
				$report['affecte'],
				$converted,
				$report['libre']
			)
		);

		return $report;
	}

	/**
	 * Enregistre une commande passée au fournisseur.
	 *
	 * Miroir de receive(), sans marchandise : le compteur monte, puis l'attribution
	 * FIFO le consomme au profit des commandes clients les plus anciennes.
	 *
	 * Ne synchronise JAMAIS le statut : la marchandise n'est pas arrivée, une
	 * commande ne peut donc pas devenir « À empaqueter ».
	 *
	 * @param int $product_id Produit ou variation.
	 * @param int $qty        Quantité commandée.
	 *
	 * @return array Compte rendu.
	 */
	public static function order_from_supplier( $product_id, $qty ): array {
		$product_id = (int) $product_id;
		$qty        = (int) $qty;

		$report = array(
			'produit'  => $product_id,
			'commande' => $qty,
			'affecte'  => 0,
			'libre'    => 0,
			'lignes'   => array(),
		);

		if ( $product_id <= 0 || $qty <= 0 ) {
			return $report;
		}

		Supply::adjust( $product_id, $qty );

		$remaining = $qty;

		foreach ( Demand::active_order_ids() as $order_id ) {

			if ( $remaining <= 0 ) {
				break;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$allocated = 0;

			foreach ( $order->get_items() as $item ) {

				if ( $remaining <= 0 ) {
					break;
				}

				if ( Items::key( $item ) !== $product_id ) {
					continue;
				}

				$ordered = Items::ordered( $item );
				$needed  = (int) $item->get_quantity() - Items::prepared( $item ) - $ordered;

				if ( $needed <= 0 ) {
					continue;
				}

				$take = min( $needed, $remaining );

				$written = Items::set_ordered( $item, $ordered + $take );
				$applied = $written - $ordered;

				if ( $applied !== $take ) {
					Log::error( sprintf( 'Référence #%d : bornage sur set_ordered(), %d appliqué au lieu de %d.', $product_id, $applied, $take ) );
				}

				Supply::adjust( $product_id, -$applied );

				$remaining         -= $applied;
				$allocated         += $applied;
				$report['affecte'] += $applied;
			}

			if ( $allocated <= 0 ) {
				continue;
			}

			$report['lignes'][] = self::order_summary( $order, $allocated );

			$order->add_order_note(
				sprintf(
					/* translators: 1: quantité réservée, 2: nom de la référence. */
					__( 'Commande fournisseur : %1$d × %2$s réservé(s) pour cette commande.', 'real-stock-manager-for-woocommerce' ),
					$allocated,
					wp_strip_all_tags( Labels::get( $product_id )['name'] )
				)
			);
		}

		$report['libre'] = Supply::get( $product_id );

		Demand::flush();
		Log::info(
			sprintf(
				'Commande fournisseur %d × #%d : %d réservé(s), %d libre(s).',
				$qty,
				$product_id,
				$report['affecte'],
				$report['libre']
			)
		);

		return $report;
	}

	/**
	 * Annule tout ou partie d'une commande fournisseur.
	 *
	 * Miroir exact du retrait : puise d'abord dans le commandé non attribué, puis
	 * reprend aux commandes clients de la plus récente à la plus ancienne — celle
	 * qui attend depuis le moins longtemps est la moins pénalisée.
	 *
	 * Ne touche ni au stock physique ni au statut.
	 *
	 * @param int $product_id Produit ou variation.
	 * @param int $qty        Quantité à annuler.
	 *
	 * @return array Compte rendu.
	 */
	public static function cancel_supplier_order( $product_id, $qty ): array {
		$product_id = (int) $product_id;
		$qty        = (int) $qty;

		$report = array(
			'produit'  => $product_id,
			'demande'  => $qty,
			'du_libre' => 0,
			'repris'   => 0,
			'lignes'   => array(),
			'manquant' => 0,
			'libre'    => 0,
		);

		if ( $product_id <= 0 || $qty <= 0 ) {
			return $report;
		}

		$taken = min( $qty, max( 0, Supply::get( $product_id ) ) );

		if ( $taken > 0 ) {
			Supply::adjust( $product_id, -$taken );
			$report['du_libre'] = $taken;
		}

		$remaining = $qty - $taken;

		if ( $remaining > 0 ) {
			$reclaim = self::reclaim_ordered_from_holders( $product_id, $remaining );

			foreach ( $reclaim['lines'] as $line ) {
				$report['repris']  += $line['qty'];
				$report['lignes'][] = self::order_summary( $line['order'], $line['qty'] );

				$line['order']->add_order_note(
					sprintf(
						/* translators: 1: quantité annulée, 2: nom de la référence. */
						__( 'Commande fournisseur annulée : %1$d × %2$s n’est plus attendu pour cette commande.', 'real-stock-manager-for-woocommerce' ),
						$line['qty'],
						wp_strip_all_tags( Labels::get( $product_id )['name'] )
					)
				);
			}

			$remaining -= $reclaim['reclaimed'];
		}

		$report['manquant'] = max( 0, $remaining );
		$report['libre']    = Supply::get( $product_id );

		Demand::flush();
		Log::info(
			sprintf(
				'Annulation fournisseur %d × #%d : %d du libre, %d repris aux commandes, %d introuvable(s).',
				$qty,
				$product_id,
				$report['du_libre'],
				$report['repris'],
				$report['manquant']
			)
		);

		return $report;
	}

	/**
	 * Retire des articles du stock physique.
	 *
	 * Puise d'abord dans le stock libre : tant qu'il en reste, aucune commande
	 * n'est perturbée. Une fois épuisé, reprend aux commandes de la plus récente
	 * à la plus ancienne — la plus récente est celle qui attend depuis le moins
	 * longtemps.
	 *
	 * @param int    $product_id Produit ou variation.
	 * @param int    $qty        Quantité à écarter.
	 * @param string $reason     Motif porté dans les notes de commande.
	 *
	 * @return array Compte rendu.
	 */
	public static function withdraw( $product_id, $qty, string $reason = '' ): array {
		$product_id = (int) $product_id;
		$qty        = (int) $qty;

		$report = array(
			'produit'  => $product_id,
			'demande'  => $qty,
			'du_libre' => 0,
			'repris'   => 0,
			'lignes'   => array(),
			'rendues'  => array(),
			'manquant' => 0,
			'libre'    => 0,
		);

		if ( $product_id <= 0 || $qty <= 0 ) {
			return $report;
		}

		// Le retrait ne doit pas déclencher de réattribution : une commande qui
		// redescend de « À empaqueter » repasserait sinon par l'attribution
		// automatique et reprendrait aussitôt le stock qu'on vient d'écarter.
		return self::without_auto_allocation(
			static function () use ( $product_id, $qty, $reason, $report ) {
				return self::run_withdraw( $product_id, $qty, $reason, $report );
			}
		);
	}

	/**
	 * Corps du retrait, exécuté avec l'attribution automatique neutralisée.
	 *
	 * @param int    $product_id Produit ou variation.
	 * @param int    $qty        Quantité à écarter.
	 * @param string $reason     Motif.
	 * @param array  $report     Compte rendu initial.
	 *
	 * @return array
	 */
	private static function run_withdraw( int $product_id, int $qty, string $reason, array $report ): array {

		/*
		 * 1. Stock libre.
		 *
		 * Le plancher à zéro sur la lecture corrige un défaut présent dans le
		 * snippet d'origine : sur une référence au stock hérité négatif, min()
		 * renvoyait la valeur négative, et $remaining = $qty - $taken dépassait
		 * la quantité demandée. Un retrait de 3 sur un stock à -5 reprenait
		 * 8 articles aux commandes clients. Tous les autres points de lecture
		 * appliquent déjà ce plancher.
		 */
		$taken = min( $qty, max( 0, Stock::get( $product_id ) ) );

		if ( $taken > 0 ) {
			Stock::adjust( $product_id, -$taken );
			$report['du_libre'] = $taken;
		}

		$remaining = $qty - $taken;

		// 2. Reprise sur les commandes, de la plus récente à la plus ancienne.
		foreach ( Demand::holder_order_ids() as $order_id ) {

			if ( $remaining <= 0 ) {
				break;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$status_before = $order->get_status();
			$reclaimed     = 0;

			foreach ( $order->get_items() as $item ) {

				if ( $remaining <= 0 ) {
					break;
				}

				if ( Items::key( $item ) !== $product_id ) {
					continue;
				}

				/*
				 * Normalisation défensive : Items::prepared() n'est jamais bornée
				 * par get_quantity(). Si la quantité de la ligne a été réduite après
				 * pointage (édition manuelle de la commande, sans normalisation de
				 * _mh_prep_qty à ce jour), la valeur brute peut dépasser la quantité
				 * réelle. Sans ce plafond, le take ci-dessous porterait sur un
				 * pointage fantôme et le mécanisme de mise au rebut plus bas
				 * scraperait le rattrapage de désynchronisation EN PLUS du retrait
				 * demandé.
				 */
				$prepared = min( (int) $item->get_quantity(), Items::prepared( $item ) );

				if ( $prepared <= 0 ) {
					continue;
				}

				$take = min( $prepared, $remaining );

				/*
				 * Dépointer restitue au stock libre TOUT l'écart entre la valeur
				 * brute (éventuellement désynchronisée) et la cible visée ici — pas
				 * seulement $take. Seule la part réellement demandée en retrait doit
				 * disparaître (mise au rebut) ; l'éventuel surplus de rattrapage doit
				 * rester légitimement au libre. D'où le plafond à $take sur ce qui
				 * est repris, distinct de ce que set_quantity() vient de restituer.
				 */
				$before = Stock::get( $product_id );
				Items::set_quantity( $item, $prepared - $take );
				$returned = Stock::get( $product_id ) - $before;
				$scrap    = min( $take, max( 0, $returned ) );

				if ( $scrap > 0 ) {
					Stock::adjust( $product_id, -$scrap );
				}

				$remaining        -= $take;
				$reclaimed        += $take;
				$report['repris'] += $take;
			}

			if ( $reclaimed <= 0 ) {
				continue;
			}

			$order->add_order_note(
				sprintf(
					/* translators: 1: motif entre parenthèses ou chaîne vide, 2: nombre d'articles. */
					__( 'Retrait de stock%1$s : %2$d article(s) dépointé(s) de cette commande.', 'real-stock-manager-for-woocommerce' ),
					$reason ? ' (' . $reason . ')' : '',
					$reclaimed
				)
			);

			$report['lignes'][] = self::order_summary( $order, $reclaimed );

			$fresh        = wc_get_order( $order_id );
			$status_after = $fresh instanceof \WC_Order ? StatusSync::sync( $fresh ) : $status_before;

			if ( $status_after !== $status_before ) {
				$report['rendues'][] = $order->get_order_number();
			}
		}

		$report['manquant'] = $remaining;
		$report['libre']    = Stock::get( $product_id );

		Demand::flush();
		Log::info(
			sprintf(
				'Retrait %d × #%d : %d du libre, %d repris aux commandes, %d introuvable(s).',
				$qty,
				$product_id,
				$report['du_libre'],
				$report['repris'],
				$report['manquant']
			)
		);

		return $report;
	}

	/**
	 * Distribue tout le stock libre disponible sur les commandes en attente.
	 *
	 * Parcourt les commandes de la plus ancienne à la plus récente ; à l'intérieur
	 * d'une commande, chaque ligne prend ce qu'elle peut. C'est équivalent à un
	 * FIFO par référence, en une seule passe.
	 *
	 * @param bool $dry_run Simule sans rien écrire.
	 *
	 * @return array Compte rendu.
	 */
	public static function reallocate_all( bool $dry_run = false ): array {

		$free         = Stock::free_map();
		$free_ordered = Supply::free_map();

		$report = array(
			'dry'              => $dry_run,
			'total'            => 0,
			'produits'         => array(),
			'commandes'        => array(),
			'basculees'        => array(),
			'commande_total'   => 0,
			'commande_lignes'  => array(),
		);

		if ( empty( $free ) && empty( $free_ordered ) ) {
			return $report;
		}

		// Quantités préparées telles qu'elles seront APRÈS la première passe.
		// En simulation rien n'est écrit : sans cette projection, la seconde passe
		// relirait la base et couvrirait une seconde fois des lignes que la
		// première vient déjà de servir.
		$projected_prepared = array();

		foreach ( Demand::active_order_ids() as $order_id ) {

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$status_before = $order->get_status();
			$projected     = array();
			$taken_here    = 0;

			foreach ( $order->get_items() as $item_id => $item ) {

				$product_id = Items::key( $item );

				if ( empty( $free[ $product_id ] ) || $free[ $product_id ] <= 0 ) {
					continue;
				}

				$prepared = Items::prepared( $item );
				$needed   = (int) $item->get_quantity() - $prepared;

				if ( $needed <= 0 ) {
					continue;
				}

				$take = min( $needed, $free[ $product_id ] );

				if ( ! $dry_run ) {
					Items::set_quantity( $item, $prepared + $take );
				}

				$projected[ $item_id ]              = $prepared + $take;
				$projected_prepared[ (int) $item_id ] = $prepared + $take;
				$free[ $product_id ]               -= $take;
				$taken_here                        += $take;
				$report['total']                   += $take;

				if ( ! isset( $report['produits'][ $product_id ] ) ) {
					$report['produits'][ $product_id ] = 0;
				}

				$report['produits'][ $product_id ] += $take;
			}

			if ( $taken_here <= 0 ) {
				/*
				 * Rien pris cette passe : rattraper malgré tout une commande déjà
				 * complète mais jamais synchronisée (timeout d'une réaffectation
				 * précédente, aller-retour de statut). Sans effet en simulation.
				 */
				if ( ! $dry_run && Items::order_is_ready( $order ) ) {
					$fresh = wc_get_order( $order_id );

					if ( $fresh instanceof \WC_Order && StatusSync::sync( $fresh ) !== $status_before ) {
						$report['basculees'][] = $fresh->get_order_number();
					}
				}

				continue;
			}

			// Complétude évaluée sur la vue projetée : en simulation, rien n'a été écrit.
			$complete = true;

			foreach ( $order->get_items() as $item_id => $item ) {
				$prepared = isset( $projected[ $item_id ] ) ? $projected[ $item_id ] : Items::prepared( $item );

				if ( $prepared < (int) $item->get_quantity() ) {
					$complete = false;
					break;
				}
			}

			if ( ! $dry_run ) {

				$order->add_order_note(
					sprintf(
						/* translators: %d: nombre d'articles pointés. */
						__( 'Réaffectation du stock libre : %d article(s) pointé(s).', 'real-stock-manager-for-woocommerce' ),
						$taken_here
					)
				);

				$fresh = wc_get_order( $order_id );

				if ( $fresh instanceof \WC_Order && StatusSync::sync( $fresh ) !== $status_before ) {
					$report['basculees'][] = $fresh->get_order_number();
				}
			} elseif ( $complete ) {
				$report['basculees'][] = $order->get_order_number();
			}

			$summary         = self::order_summary( $order, $taken_here );
			$summary['full'] = $complete;

			$report['commandes'][] = $summary;
		}

		/*
		 * Seconde passe : couverture par le commandé fournisseur non attribué.
		 *
		 * Distincte de la première, et volontairement après elle : le stock
		 * physique doit toujours servir en premier, une ligne servie par du stock
		 * réel n'a pas à consommer en plus une commande fournisseur.
		 */
		foreach ( Demand::active_order_ids() as $order_id ) {

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$taken_here = 0;

			foreach ( $order->get_items() as $item_id => $item ) {

				$product_id = Items::key( $item );

				if ( empty( $free_ordered[ $product_id ] ) || $free_ordered[ $product_id ] <= 0 ) {
					continue;
				}

				$prepared = isset( $projected_prepared[ (int) $item_id ] )
					? $projected_prepared[ (int) $item_id ]
					: Items::prepared( $item );

				$ordered = Items::ordered( $item );
				$needed  = (int) $item->get_quantity() - $prepared - $ordered;

				if ( $needed <= 0 ) {
					continue;
				}

				$take = min( $needed, $free_ordered[ $product_id ] );

				if ( ! $dry_run ) {
					$written = Items::set_ordered( $item, $ordered + $take );
					$applied = $written - $ordered;

					if ( $applied !== $take ) {
						Log::error( sprintf( 'Référence #%d : bornage sur set_ordered(), %d appliqué au lieu de %d.', $product_id, $applied, $take ) );
					}

					Supply::adjust( $product_id, -$applied );
				} else {
					// Simulation : rien n'est écrit, la projection suit la demande brute.
					$applied = $take;
				}

				$free_ordered[ $product_id ] -= $applied;
				$taken_here                  += $applied;
				$report['commande_total']    += $applied;
			}

			if ( $taken_here <= 0 ) {
				continue;
			}

			if ( ! $dry_run ) {
				$order->add_order_note(
					sprintf(
						/* translators: %d: nombre d'articles réservés. */
						__( 'Réaffectation des commandes fournisseur : %d article(s) réservé(s).', 'real-stock-manager-for-woocommerce' ),
						$taken_here
					)
				);
			}

			$report['commande_lignes'][] = self::order_summary( $order, $taken_here );
		}

		if ( ! $dry_run ) {
			Demand::flush();
			Log::info(
				sprintf(
					'Réaffectation : %d article(s) sur %d commande(s), %d article(s) couverts par une commande fournisseur.',
					$report['total'],
					count( $report['commandes'] ),
					$report['commande_total']
				)
			);
		}

		return $report;
	}

	/**
	 * Exécute un traitement avec l'attribution automatique neutralisée.
	 *
	 * Le drapeau est restauré dans un `finally` : le laisser à `true` après une
	 * exception désactiverait silencieusement l'attribution pour le reste de la requête.
	 *
	 * @param callable $callback Traitement.
	 *
	 * @return mixed Valeur retournée par le traitement.
	 */
	public static function without_auto_allocation( callable $callback ) {
		$previous         = self::$suppressed;
		self::$suppressed = true;

		try {
			return $callback();
		} finally {
			self::$suppressed = $previous;
		}
	}

	/**
	 * Restitue au libre ce qu'une ligne détient : stock physique prélevé et
	 * réserve fournisseur. Cible 0 dans les deux cas — contrairement au
	 * retrait (run_withdraw), aucune part n'est mise au rebut ici : la
	 * commande quitte le système suivi, ce qu'elle détenait doit intégralement
	 * redevenir disponible pour les autres.
	 *
	 * Idempotente : une ligne déjà à 0/0 ne produit aucune écriture.
	 *
	 * @param \WC_Order_Item_Product $item Ligne de commande.
	 *
	 * @return int Quantité totale restituée (préparé + commandé fournisseur).
	 */
	private static function release_item( $item ): int {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return 0;
		}

		$product_id = Items::key( $item );
		$released   = 0;

		$ordered = Items::ordered( $item );

		if ( $ordered > 0 ) {
			Items::set_ordered( $item, 0 );
			Supply::adjust( $product_id, $ordered );
			$released += $ordered;
		}

		$prepared = Items::prepared( $item );

		if ( $prepared > 0 ) {
			Items::set_quantity( $item, 0 );
			$released += $prepared;
		}

		return $released;
	}

	/**
	 * Applique release_item() à toutes les lignes d'une commande, journalise
	 * et pose une note si quelque chose a effectivement été restitué.
	 *
	 * @param \WC_Order $order Commande.
	 */
	private static function release_order( \WC_Order $order ): void {
		$released = 0;

		foreach ( $order->get_items() as $item ) {
			$released += self::release_item( $item );
		}

		if ( $released > 0 ) {
			$order->add_order_note(
				sprintf(
					/* translators: %d: nombre d'articles restitués. */
					__( 'Sortie du périmètre de préparation : %d article(s) restitué(s) au stock libre.', 'real-stock-manager-for-woocommerce' ),
					$released
				)
			);

			Demand::flush();
			Log::info( sprintf( 'Commande %d sortie du périmètre : %d article(s) restitué(s).', $order->get_id(), $released ) );
		}
	}

	/**
	 * Reprend $remaining unités de commande fournisseur sur les lignes qui en
	 * détiennent, de la commande la plus récente à la plus ancienne.
	 *
	 * Sans note ni log : ceux-ci restent de la responsabilité de l'appelant,
	 * qui seul sait dans quel contexte cette reprise a lieu (annulation
	 * explicite, ou solde d'une réception qui n'a pas suffi à couvrir tout le
	 * commandé). Utilise la valeur RÉELLEMENT écrite par Items::set_ordered()
	 * (bornée par la place disponible sur la ligne), jamais la quantité
	 * demandée : un bornage qui mord doit se voir dans le compte rendu de
	 * l'appelant, pas disparaître en silence.
	 *
	 * @param int $product_id Produit ou variation.
	 * @param int $remaining  Quantité encore à reprendre.
	 *
	 * @return array{reclaimed:int, lines:array<int, array{order:\WC_Order, qty:int}>}
	 */
	private static function reclaim_ordered_from_holders( int $product_id, int $remaining ): array {
		$reclaimed_total = 0;
		$lines           = array();

		foreach ( Demand::holder_order_ids() as $order_id ) {

			if ( $remaining <= 0 ) {
				break;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$reclaimed_here = 0;

			foreach ( $order->get_items() as $item ) {

				if ( $remaining <= 0 ) {
					break;
				}

				if ( Items::key( $item ) !== $product_id ) {
					continue;
				}

				$ordered = Items::ordered( $item );

				if ( $ordered <= 0 ) {
					continue;
				}

				$take    = min( $ordered, $remaining );
				$written = Items::set_ordered( $item, $ordered - $take );
				$applied = $ordered - $written;

				if ( $applied !== $take ) {
					Log::error(
						sprintf(
							'Commande %d, référence #%d : bornage sur set_ordered(), %d repris au lieu de %d demandés.',
							$order_id,
							$product_id,
							$applied,
							$take
						)
					);
				}

				$remaining      -= $applied;
				$reclaimed_here += $applied;
			}

			if ( $reclaimed_here <= 0 ) {
				continue;
			}

			$reclaimed_total += $reclaimed_here;
			$lines[]          = array(
				'order' => $order,
				'qty'   => $reclaimed_here,
			);
		}

		return array(
			'reclaimed' => $reclaimed_total,
			'lines'     => $lines,
		);
	}

	/**
	 * Résumé d'une commande pour les comptes rendus.
	 *
	 * @param \WC_Order $order    Commande.
	 * @param int       $quantity Quantité concernée.
	 *
	 * @return array
	 */
	private static function order_summary( $order, int $quantity ): array {
		$created = $order->get_date_created();

		return array(
			'order'  => $order->get_id(),
			'num'    => $order->get_order_number(),
			'url'    => $order->get_edit_order_url(),
			'date'   => $created ? $created->date_i18n( 'd/m/Y' ) : '',
			'qty'    => $quantity,
			'client' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
