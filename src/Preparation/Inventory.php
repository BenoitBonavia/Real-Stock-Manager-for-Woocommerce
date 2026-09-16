<?php
/**
 * Vue d'ensemble du catalogue : totaux de stock physique et de commandé
 * fournisseur, déjà attribué et stock WooCommerce, par référence.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Contrairement au reste du module, qui part toujours de la demande (commandes
 * clients) ou du stock déjà déclaré, cette classe parcourt le CATALOGUE ENTIER :
 * un produit simple par référence, une variation par référence pour un produit
 * à variations — jamais le produit variable parent lui-même.
 *
 * C'est une correction de comptage, mais c'est désormais un vrai MOUVEMENT :
 * le marchand saisit un TOTAL (stock physique, commandé au fournisseur), et
 * l'écart avec le total courant est routé vers `Allocator`, qui l'attribue
 * automatiquement en FIFO/LIFO aux commandes clients en attente — exactement
 * comme l'onglet « Mouvement à l'unité ». Une correction ici peut donc
 * dépointer des commandes clients, en faire basculer certaines vers
 * « À empaqueter » ou les en faire redescendre ; le compte rendu à l'écran en
 * tient compte. Seule la fiche produit (`ProductFields`) reste une correction
 * directe du compteur LIBRE, sans passage par `Allocator`.
 */
final class Inventory {

	/**
	 * Statuts de post retenus. `trash` et `auto-draft` sont exclus : un produit
	 * dans la corbeille n'a plus sa place dans un inventaire.
	 *
	 * @var string[]
	 */
	private const STATUSES = array( 'publish', 'private', 'draft' );

	/**
	 * Toutes les références du catalogue, triées par nom.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all_rows(): array {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( self::STATUSES ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; aucune fonction WooCommerce ne permet de mélanger produits et variations dans un seul appel.
		$sql = $wpdb->prepare(
			"SELECT p.ID, p.post_type, p.post_parent
			   FROM {$wpdb->posts} p
			  WHERE p.post_status IN ( {$placeholders} )
			    AND (
			          p.post_type = 'product_variation'
			       OR ( p.post_type = 'product' AND NOT EXISTS (
			              SELECT 1 FROM {$wpdb->posts} v
			               WHERE v.post_type = 'product_variation'
			                 AND v.post_parent = p.ID
			                 AND v.post_status IN ( {$placeholders} )
			            ) )
			        )",
			array_merge( self::STATUSES, self::STATUSES )
		);

		$posts = $wpdb->get_results( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $posts ) ) {
			return array();
		}

		$ids     = array();
		$parents = array();

		foreach ( $posts as $post ) {
			$id = (int) $post->ID;

			$ids[]           = $id;
			$parents[ $id ]  = 'product_variation' === $post->post_type ? (int) $post->post_parent : $id;
		}

		Labels::prime( $ids );

		$categories = self::categories_by_product( array_values( array_unique( $parents ) ) );

		// Recalcul systématique, sans cache : comme sur « Besoins pour commande »,
		// l'écran doit refléter l'état réel des commandes clients.
		$demand = Demand::map( false );

		$rows = array();

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product ) {
				continue;
			}

			$info         = Labels::get( $id );
			$terms        = isset( $categories[ $parents[ $id ] ] ) ? $categories[ $parents[ $id ] ] : array();
			$woo_stock    = self::woo_stock_for( $product );
			$woo_editable = $product->get_stock_managed_by_id() === $id;

			/*
			 * Totaux affichés — invariants I1/I2 : le physique total et le
			 * fournisseur total sont chacun la somme du libre et de la part déjà
			 * attribuée à des commandes clients (`detenu` / `commande_detenu`,
			 * scopées au périmètre détenteur par Demand::map()).
			 *
			 * `max( 0, … )` sur les compteurs libres : une dette héritée négative
			 * n'est plus affichée ici — l'afficher ferait diverger le delta calculé
			 * à l'enregistrement, puisque Allocator::receive()/credit_found_stock()
			 * soldent la dette avant de créditer. Elle reste traitée par
			 * Stock::negative_ids() sur la page Besoins.
			 */
			$free_stock  = max( 0, Stock::get( $id ) );
			$free_supply = max( 0, Supply::get( $id ) );
			$held_stock  = isset( $demand[ $id ]['detenu'] ) ? (int) $demand[ $id ]['detenu'] : 0;
			$held_supply = isset( $demand[ $id ]['commande_detenu'] ) ? (int) $demand[ $id ]['commande_detenu'] : 0;

			$rows[] = array(
				'id'                => $id,
				'name'              => $info['name'],
				'variant'           => $info['variant'],
				'sku'               => $info['sku'],
				'edit'              => $info['edit'],
				// Taille de vignette carrée de WooCommerce, jamais 'thumbnail' :
				// cette dernière dépend des réglages Médias de WordPress et n'est
				// pas garantie carrée, contrairement à 'woocommerce_thumbnail'
				// (recadrage forcé). `get_image()` retombe elle-même sur le
				// placeholder WooCommerce si le produit n'a pas d'image — même
				// logique que sur sa fiche.
				'thumbnail'         => $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'rsmw-thumb' ) ),
				'stock_total'       => $free_stock + $held_stock,
				'supply_total'      => $free_supply + $held_supply,
				// Base 100 % de la barre d'attribution : uniquement ce qui est
				// attribué, pas le total — voir le gabarit pour le partage
				// bleu (stock) / orange (commandé).
				'attribue'          => $held_stock + $held_supply,
				'attribue_stock'    => $held_stock,
				'attribue_commande' => $held_supply,
				'woo_managed'       => null !== $woo_stock,
				// Distinct de woo_managed : une variation à stock mutualisé au
				// niveau du parent AFFICHE la valeur héritée mais ne doit pas
				// pouvoir l'ÉDITER depuis cette ligne — sinon plusieurs lignes
				// du même formulaire s'écrasent silencieusement l'une l'autre.
				'woo_editable'      => $woo_editable && null !== $woo_stock,
				'woo_stock'         => $woo_stock,
				'categories'        => wp_list_pluck( $terms, 'name' ),
				'category_slugs'    => wp_list_pluck( $terms, 'slug' ),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'] . $a['variant'], $b['name'] . $b['variant'] );
			}
		);

		return $rows;
	}

	/**
	 * Écrit le stock WooCommerce si la référence gère bien un stock, et si la
	 * valeur saisie diffère réellement de celle en base.
	 *
	 * Ne pose jamais `manage_stock` à la volée : une référence qui ne suit pas
	 * son stock aujourd'hui n'a pas de champ modifiable pour ce compteur (voir
	 * le gabarit), donc `$quantity` ne peut arriver ici que pour une référence
	 * déjà suivie.
	 *
	 * @param int $product_id Produit ou variation.
	 * @param int $quantity   Quantité saisie.
	 *
	 * @return bool Une écriture a-t-elle eu lieu ?
	 */
	private static function apply_woo_stock( int $product_id, int $quantity ): bool {
		$product = wc_get_product( $product_id );
		$current = $product ? self::woo_stock_for( $product ) : null;

		if ( null === $current || $quantity === $current ) {
			return false;
		}

		// Défense contre un POST forgé ou une incohérence de cache : le
		// gabarit ne rend plus de champ éditable pour une référence dont le
		// stock est mutualisé au niveau du parent (voir woo_editable dans
		// all_rows()), donc cet identifiant ne devrait jamais arriver ici
		// sans être son propre gestionnaire.
		if ( $product->get_stock_managed_by_id() !== $product_id ) {
			return false;
		}

		// `wc_update_product_stock()` résout elle-même l'indirection vers le
		// parent quand le stock est mutualisé entre variations, recalcule le
		// statut « en stock »/« rupture », et enregistre le produit.
		wc_update_product_stock( $product_id, $quantity, 'set' );

		return true;
	}

	/**
	 * Stock WooCommerce affiché au client, celui qui gouverne « en stock » /
	 * « rupture » sur la boutique — distinct du stock réel interne au plugin.
	 *
	 * Une variation peut suivre sa PROPRE quantité, ou hériter de celle de son
	 * produit parent (réglage « Gérer le stock ? » laissé sur « Parent » côté
	 * variation, quantité mutualisée entre toutes les déclinaisons).
	 * `get_stock_managed_by_id()` résout cette indirection — c'est la même
	 * méthode que `wc_update_product_stock()` utilise en écriture, ce qui
	 * garantit que lecture et écriture visent toujours le même compteur.
	 *
	 * @param \WC_Product $product Produit ou variation, déjà chargé.
	 *
	 * @return int|null `null` si aucun stock n'est géré pour cette référence.
	 */
	private static function woo_stock_for( \WC_Product $product ): ?int {
		$managed_id = $product->get_stock_managed_by_id();
		$managed    = $managed_id === $product->get_id() ? $product : wc_get_product( $managed_id );

		if ( ! $managed || ! $managed->managing_stock() ) {
			return null;
		}

		return (int) $managed->get_stock_quantity();
	}

	/**
	 * Catégories WooCommerce utilisées par au moins un produit, pour peupler
	 * le filtre.
	 *
	 * @return \WP_Term[]
	 */
	public static function categories(): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		return is_wp_error( $terms ) ? array() : (array) $terms;
	}

	/**
	 * Plafond de références routées vers `Allocator` par soumission.
	 *
	 * Chaque appel parcourt les commandes actives (`wc_get_orders( limit => -1 )`
	 * puis chargement des commandes concernées), dans un POST exécuté sur
	 * `load-{écran}` avant l'envoi des en-têtes. Au-delà, la référence est
	 * comptée en `skipped` et reprise à la soumission suivante — le verrou
	 * optimiste (voir `$refs` ci-dessous) rend cette reprise sûre.
	 */
	private const MAX_MOVEMENTS = 100;

	/**
	 * Enregistre les totaux saisis, comme un mouvement de stock.
	 *
	 * L'écart entre le total saisi et le total courant (`Stock::get()` /
	 * `Supply::get()` relus en base, jamais `$refs`) est routé vers `Allocator`,
	 * qui l'attribue automatiquement en FIFO (hausse) ou reprend en LIFO
	 * (baisse) — la répartition libre/attribué n'est jamais décidée ici.
	 *
	 * Ordre par ligne : stock d'abord, commandé fournisseur ensuite. Un
	 * mouvement de stock peut convertir du commandé attribué en préparé
	 * (`Items::set_quantity()`), ce qui change le total commandé courant AVANT
	 * que son propre champ ne soit traité.
	 *
	 * @param array<int, array{stock?:int, supply?:int, woo?:int}> $rows Saisie, indexée par référence.
	 * @param array<int, array{stock:int, supply:int}>             $refs Totaux affichés au rendu du formulaire — verrou optimiste, jamais base du delta.
	 *
	 * @return array{changed:int, moved:int, conflicts:int, skipped:int, missing:int, lines:array<int, array<string,mixed>>, switched:string[], dropped:string[], truncated:bool}
	 */
	public static function apply( array $rows, array $refs ): array {
		$demand = Demand::map( false ); // Un seul instantané, jamais relu dans la boucle.
		$reason = __( 'correction d’inventaire', 'real-stock-manager-for-woocommerce' );

		return Allocator::without_auto_allocation(
			static function () use ( $rows, $refs, $demand, $reason ) {
				$report = array(
					'changed'   => 0,
					'moved'     => 0,
					'conflicts' => 0,
					'skipped'   => 0,
					'missing'   => 0,
					'lines'     => array(),
					'switched'  => array(),
					'dropped'   => array(),
					'truncated' => false,
				);

				$movements = 0;

				foreach ( $rows as $id => $values ) {
					$id = (int) $id;

					if ( $id <= 0 || ! is_array( $values ) || ! isset( $refs[ $id ] ) ) {
						++$report['skipped'];
						continue;
					}

					$row_changed = false;
					$row_moved   = false;

					/*
					 * Stock physique — invariant I1 : total = libre + détenu. Le
					 * `restant` de l'instantané mesure ce qu'il reste à préparer
					 * AVANT tout mouvement de cette ligne : encore valide ici,
					 * périmé dès qu'un mouvement de stock a eu lieu (voir plus bas).
					 */
					$free_stock  = max( 0, Stock::get( $id ) );
					$held_stock  = isset( $demand[ $id ]['detenu'] ) ? (int) $demand[ $id ]['detenu'] : 0;
					$stock_total = $free_stock + $held_stock;
					$pending     = isset( $demand[ $id ]['restant'] ) ? (int) $demand[ $id ]['restant'] : 0;

					/*
					 * Commandé fournisseur — invariant I2 : total = libre + détenu.
					 * Calculé ICI, avant tout mouvement de stock sur cette ligne, et
					 * pas recalculé plus bas : un mouvement de stock (credit_found_stock())
					 * peut convertir du commandé attribué en préparé, ce qui fait
					 * MONTER Supply::get() (libre) et BAISSER `commande_detenu` (détenu)
					 * d'autant — le TOTAL est invariant, mais seulement si les deux
					 * moitiés sont lues au même instant. Les recalculer séparément à
					 * deux moments différents (libre relu frais après le mouvement,
					 * détenu resté à sa valeur d'avant) gonflerait le total à tort et
					 * ferait échouer le verrou optimiste sur une ligne pourtant
					 * légitime.
					 */
					$held_supply  = isset( $demand[ $id ]['commande_detenu'] ) ? (int) $demand[ $id ]['commande_detenu'] : 0;
					$supply_total = max( 0, Supply::get( $id ) ) + $held_supply;

					if ( isset( $values['stock'] ) && $values['stock'] !== $refs[ $id ]['stock'] ) {

						if ( $refs[ $id ]['stock'] !== $stock_total ) {
							// L'état a changé depuis le rendu du formulaire (rejeu de
							// POST, double-clic, deux onglets) : toute la ligne est
							// sautée, stock ET commandé, plutôt que d'appliquer un
							// delta calculé sur une base périmée.
							++$report['conflicts'];
							continue;
						}

						if ( ! wc_get_product( $id ) ) {
							++$report['skipped'];
							continue;
						}

						if ( $movements >= self::MAX_MOVEMENTS ) {
							++$report['skipped'];
							continue;
						}

						$delta = (int) $values['stock'] - $stock_total;

						if ( 0 !== $delta ) {
							if ( $delta > 0 && 0 === $pending ) {
								// I6 : personne n'attend cette référence, rien à
								// attribuer — écriture directe, sans parcourir les
								// commandes actives.
								Stock::set( $id, $free_stock + $delta );
							} elseif ( $delta > 0 ) {
								self::merge_stock_report( $report, Allocator::credit_found_stock( $id, $delta ) );
								++$movements;
								$row_moved = true;
							} elseif ( -$delta <= $free_stock ) {
								// Le retrait tient entièrement dans le libre :
								// aucune commande touchée.
								Stock::set( $id, $free_stock + $delta );
							} else {
								self::merge_stock_report( $report, Allocator::withdraw( $id, -$delta, $reason ) );
								++$movements;
								$row_moved = true;
							}

							$row_changed = true;
						}
					}

					if ( isset( $values['supply'] ) && $values['supply'] !== $refs[ $id ]['supply'] ) {

						if ( $refs[ $id ]['supply'] !== $supply_total ) {
							++$report['conflicts'];
							continue;
						}

						if ( ! wc_get_product( $id ) ) {
							++$report['skipped'];
							continue;
						}

						if ( $movements >= self::MAX_MOVEMENTS ) {
							++$report['skipped'];
							continue;
						}

						$delta = (int) $values['supply'] - $supply_total;

						// Relu frais, APRÈS un éventuel mouvement de stock sur cette
						// même ligne : la conversion commandé->préparé a pu déplacer
						// des unités du détenu vers le libre (voir plus haut). $delta,
						// lui, reste correct : il porte sur $supply_total, invariant.
						$free_supply = max( 0, Supply::get( $id ) );

						// Le raccourci « rien à réserver » n'est valable que si
						// AUCUN mouvement de stock n'a eu lieu sur cette même ligne :
						// sinon $pending est périmé. order_from_supplier() et
						// cancel_supplier_order() se recalculent eux-mêmes, ils
						// restent toujours sûrs.
						if ( 0 !== $delta ) {
							if ( $delta > 0 && ! $row_moved && 0 === $pending ) {
								Supply::set( $id, $free_supply + $delta );
							} elseif ( $delta > 0 ) {
								self::merge_supply_report( $report, Allocator::order_from_supplier( $id, $delta ) );
								++$movements;
								$row_moved = true;
							} elseif ( -$delta <= $free_supply ) {
								Supply::set( $id, $free_supply + $delta );
							} else {
								self::merge_supply_report( $report, Allocator::cancel_supplier_order( $id, -$delta ) );
								++$movements;
								$row_moved = true;
							}

							$row_changed = true;
						}
					}

					if ( isset( $values['woo'] ) && self::apply_woo_stock( $id, (int) $values['woo'] ) ) {
						$row_changed = true;
					}

					if ( $row_changed ) {
						++$report['changed'];
					}

					if ( $row_moved ) {
						++$report['moved'];
					}
				}

				$report['truncated'] = count( $report['lines'] ) > 20 || count( $report['switched'] ) > 20 || count( $report['dropped'] ) > 20;
				$report['lines']     = array_slice( $report['lines'], 0, 20 );
				$report['switched']  = array_slice( array_unique( $report['switched'] ), 0, 20 );
				$report['dropped']   = array_slice( array_unique( $report['dropped'] ), 0, 20 );

				if ( $report['changed'] > 0 ) {
					// Le compteur « unités affectables » dépend de Stock::free_map() ;
					// sans ce flush, il resterait périmé jusqu'à l'expiration de son
					// propre transient. Les mouvements Allocator l'ont déjà fait
					// individuellement, ce flush couvre aussi les écritures directes.
					Demand::flush();
				}

				Log::info(
					sprintf(
						'Inventaire mis à jour : %d référence(s) modifiée(s), %d mouvement(s), %d conflit(s), %d ligne(s) reportée(s), %d unité(s) manquante(s).',
						$report['changed'],
						$report['moved'],
						$report['conflicts'],
						$report['skipped'],
						$report['missing']
					)
				);

				return $report;
			}
		);
	}

	/**
	 * Fusionne le compte rendu d'un mouvement de stock physique
	 * (`Allocator::credit_found_stock()` / `Allocator::withdraw()`) dans le
	 * compte rendu global de l'Inventaire.
	 *
	 * @param array $report Compte rendu global, complété par référence.
	 * @param array $moved  Compte rendu du mouvement.
	 */
	private static function merge_stock_report( array &$report, array $moved ): void {
		if ( ! empty( $moved['lignes'] ) ) {
			array_push( $report['lines'], ...$moved['lignes'] );
		}

		if ( ! empty( $moved['basculees'] ) ) {
			array_push( $report['switched'], ...$moved['basculees'] );
		}

		if ( ! empty( $moved['rendues'] ) ) {
			array_push( $report['dropped'], ...$moved['rendues'] );
		}

		if ( ! empty( $moved['manquant'] ) ) {
			$report['missing'] += (int) $moved['manquant'];

			Log::error(
				sprintf(
					'Correction d’inventaire, référence #%d : %d unité(s) n’ont pas pu être retirées, le total obtenu reste supérieur au total saisi.',
					(int) $moved['produit'],
					(int) $moved['manquant']
				)
			);
		}
	}

	/**
	 * Fusionne le compte rendu d'un mouvement de commandé fournisseur
	 * (`Allocator::order_from_supplier()` / `Allocator::cancel_supplier_order()`)
	 * dans le compte rendu global de l'Inventaire.
	 *
	 * @param array $report Compte rendu global, complété par référence.
	 * @param array $moved  Compte rendu du mouvement.
	 */
	private static function merge_supply_report( array &$report, array $moved ): void {
		if ( ! empty( $moved['lignes'] ) ) {
			array_push( $report['lines'], ...$moved['lignes'] );
		}

		if ( ! empty( $moved['manquant'] ) ) {
			$report['missing'] += (int) $moved['manquant'];

			Log::error(
				sprintf(
					'Correction d’inventaire, référence #%d : %d unité(s) commandées n’ont pas pu être annulées, le total obtenu reste supérieur au total saisi.',
					(int) $moved['produit'],
					(int) $moved['manquant']
				)
			);
		}
	}

	/**
	 * Catégories de chaque produit, en une seule requête groupée.
	 *
	 * Les variations n'ont pas leurs propres catégories : l'appelant doit
	 * transmettre des identifiants de PRODUITS (parents), jamais de variations.
	 * `wp_get_object_terms()` est préféré à `get_the_terms()` par ligne, qui
	 * coûterait une requête par produit.
	 *
	 * @param int[] $product_ids Identifiants de produits.
	 *
	 * @return array<int, \WP_Term[]>
	 */
	private static function categories_by_product( array $product_ids ): array {
		if ( empty( $product_ids ) || ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = wp_get_object_terms(
			$product_ids,
			'product_cat',
			array(
				'fields'                 => 'all_with_object_id',
				'update_term_meta_cache' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$map = array();

		foreach ( $terms as $term ) {
			$map[ (int) $term->object_id ][] = $term;
		}

		return $map;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
