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
			if ( self::allocate_order( $order ) > 0 ) {
				$fresh = wc_get_order( $key );

				if ( $fresh instanceof \WC_Order ) {
					StatusSync::sync( $fresh );
				}
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
			'lignes'    => array(),
			'basculees' => array(),
		);

		if ( $product_id <= 0 || $qty <= 0 ) {
			return $report;
		}

		// Le stock entre d'abord en entier, l'affectation le consomme ensuite.
		Stock::adjust( $product_id, $qty );

		$remaining = $qty;

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

				Items::set_quantity( $item, Items::prepared( $item ) + $take );

				$remaining         -= $take;
				$allocated         += $take;
				$report['affecte'] += $take;
			}

			if ( $allocated <= 0 ) {
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

		$report['libre'] = Stock::get( $product_id );

		Demand::flush();
		Log::info(
			sprintf(
				'Réception %d × #%d : %d affecté(s), %d libre(s).',
				$qty,
				$product_id,
				$report['affecte'],
				$report['libre']
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

				$prepared = Items::prepared( $item );

				if ( $prepared <= 0 ) {
					continue;
				}

				$take = min( $prepared, $remaining );

				// Dépointer restitue au stock libre la part qui en venait. L'article
				// étant écarté, on la retire aussitôt : le compteur reste net.
				$before = Stock::get( $product_id );
				Items::set_quantity( $item, $prepared - $take );
				$returned = Stock::get( $product_id ) - $before;

				if ( $returned > 0 ) {
					Stock::adjust( $product_id, -$returned );
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

		$free = Stock::free_map();

		$report = array(
			'dry'       => $dry_run,
			'total'     => 0,
			'produits'  => array(),
			'commandes' => array(),
			'basculees' => array(),
		);

		if ( empty( $free ) ) {
			return $report;
		}

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

				$projected[ $item_id ] = $prepared + $take;
				$free[ $product_id ]  -= $take;
				$taken_here           += $take;
				$report['total']      += $take;

				if ( ! isset( $report['produits'][ $product_id ] ) ) {
					$report['produits'][ $product_id ] = 0;
				}

				$report['produits'][ $product_id ] += $take;
			}

			if ( $taken_here <= 0 ) {
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

		if ( ! $dry_run ) {
			Demand::flush();
			Log::info(
				sprintf(
					'Réaffectation : %d article(s) sur %d commande(s).',
					$report['total'],
					count( $report['commandes'] )
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
