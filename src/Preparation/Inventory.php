<?php
/**
 * Vue d'ensemble du catalogue : stock réel et commandé, par référence.
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
 * C'est une correction DIRECTE de la valeur affichée, au même titre que les
 * champs « Stock physique libre » / « Commandé au fournisseur » de la fiche
 * produit (`ProductFields`). Ce n'est pas un mouvement : pas de sens, pas de
 * réaffectation via `Allocator`, pas d'entrée au journal ligne par ligne — un
 * seul résumé dans les journaux WooCommerce, comme `Purchase::apply()`.
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

		$rows = array();

		foreach ( $ids as $id ) {
			$info  = Labels::get( $id );
			$terms = isset( $categories[ $parents[ $id ] ] ) ? $categories[ $parents[ $id ] ] : array();

			$rows[] = array(
				'id'             => $id,
				'name'           => $info['name'],
				'variant'        => $info['variant'],
				'sku'            => $info['sku'],
				'edit'           => $info['edit'],
				'libre'          => Stock::get( $id ),
				'commande'       => Supply::get( $id ),
				'categories'     => wp_list_pluck( $terms, 'name' ),
				'category_slugs' => wp_list_pluck( $terms, 'slug' ),
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
	 * Enregistre les corrections saisies.
	 *
	 * N'écrit que les valeurs qui diffèrent réellement de celles en base : le
	 * formulaire soumet TOUTES les références, filtrées ou non côté client, et
	 * la grande majorité n'aura pas été touchée.
	 *
	 * @param array<int, array{libre?:int, commande?:int}> $rows Saisie, indexée par référence.
	 *
	 * @return array{changed:int}
	 */
	public static function apply( array $rows ): array {
		$changed = 0;

		foreach ( $rows as $product_id => $values ) {
			$product_id = (int) $product_id;

			if ( $product_id <= 0 || ! is_array( $values ) ) {
				continue;
			}

			$touched = false;

			if ( isset( $values['libre'] ) ) {
				$libre = max( 0, (int) $values['libre'] );

				if ( $libre !== Stock::get( $product_id ) ) {
					Stock::set( $product_id, $libre );
					$touched = true;
				}
			}

			if ( isset( $values['commande'] ) ) {
				$commande = max( 0, (int) $values['commande'] );

				if ( $commande !== Supply::get( $product_id ) ) {
					Supply::set( $product_id, $commande );
					$touched = true;
				}
			}

			if ( $touched ) {
				++$changed;
			}
		}

		if ( $changed > 0 ) {
			// Le compteur « unités affectables » dépend de Stock::free_map() ;
			// sans ce flush, il resterait périmé jusqu'à l'expiration de son
			// propre transient.
			Demand::flush();

			Log::info(
				sprintf(
					'Inventaire mis à jour : %d référence(s) modifiée(s).',
					$changed
				)
			);
		}

		return array( 'changed' => $changed );
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
