<?php
/**
 * Compteur des quantités commandées au fournisseur.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Ce qui a été commandé au fournisseur et n'est pas encore arrivé.
 *
 * Troisième état du stock, à côté du physique (Stock) et du pointé (Items) :
 * la marchandise n'est pas là, mais elle est engagée — il ne faut donc pas la
 * recommander. Structure volontairement identique à Stock, pour que les deux
 * compteurs se lisent et se manipulent de la même façon.
 *
 * Ces clés sont NEUVES : contrairement aux clés _mh_* héritées du snippet, rien
 * n'oblige à les figer, d'où le préfixe du plugin.
 */
final class Supply {

	/** Commandé au fournisseur et non encore attribué à une commande client. */
	public const META = '_rsmw_stock_ordered';

	/** Part d'une ligne de commande client couverte par une commande fournisseur. */
	public const ITEM_META = '_rsmw_prep_ordered';

	/**
	 * Quantité commandée et non encore attribuée, pour une référence.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return int
	 */
	public static function get( $product_id ): int {
		$value = get_post_meta( (int) $product_id, self::META, true );

		return '' === $value ? 0 : (int) $value;
	}

	/**
	 * Fixe la quantité commandée non attribuée. Plancher à zéro, comme le stock.
	 *
	 * @param int $product_id Produit ou variation.
	 * @param int $qty        Quantité.
	 */
	public static function set( $product_id, $qty ): void {
		update_post_meta( (int) $product_id, self::META, max( 0, (int) $qty ) );
	}

	/**
	 * Ajuste la quantité commandée non attribuée.
	 *
	 * @param int $product_id Produit ou variation.
	 * @param int $delta      Variation, positive ou négative.
	 *
	 * @return int Nouvelle valeur.
	 */
	public static function adjust( $product_id, $delta ): int {
		$new = max( 0, self::get( $product_id ) + (int) $delta );

		self::set( $product_id, $new );

		return $new;
	}

	/**
	 * Références disposant d'une quantité commandée non attribuée.
	 *
	 * @return array<int, int> product_id => quantité.
	 */
	public static function free_map(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lecture agrégée sur meta_key indexée, appelée à la demande.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value
				   FROM {$wpdb->postmeta}
				  WHERE meta_key = %s
				    AND CAST( meta_value AS SIGNED ) > 0",
				self::META
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->post_id ] = (int) $row->meta_value;
		}

		return $map;
	}

	/**
	 * Nombre de références portant une commande fournisseur en cours.
	 *
	 * Sert au panneau de diagnostic.
	 *
	 * @return int
	 */
	public static function tracked_reference_count(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- diagnostic, à la demande.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
				  WHERE meta_key = %s AND CAST( meta_value AS SIGNED ) > 0",
				self::META
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
