<?php
/**
 * Compteur de stock physique libre.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Le stock physique est ce qui est réellement chez le marchand et n'est encore
 * affecté à aucune commande. Il est distinct du stock WooCommerce, qui reflète
 * la disponibilité fournisseur.
 *
 * Stocké en postmeta sur le produit ou la variation. Ce n'est pas un champ indexé
 * par `wc_product_meta_lookup` : y écrire directement ne désynchronise rien.
 */
final class Stock {

	/**
	 * Stock libre d'une référence.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return int
	 */
	public static function get( $product_id ): int {
		$value = get_post_meta( (int) $product_id, Legacy::STOCK_META, true );

		return '' === $value ? 0 : (int) $value;
	}

	/**
	 * Fixe le stock libre d'une référence.
	 *
	 * Plancher à zéro : pointer une ligne sans stock déclaré vaut entrée en stock
	 * implicite, jamais une dette. Cette règle est structurante, la page « Besoins »
	 * propose d'ailleurs de remettre à zéro les références héritées d'une version
	 * antérieure qui autorisait le négatif.
	 *
	 * @param int $product_id Produit ou variation.
	 * @param int $qty        Quantité.
	 */
	public static function set( $product_id, $qty ): void {
		update_post_meta( (int) $product_id, Legacy::STOCK_META, max( 0, (int) $qty ) );
	}

	/**
	 * Ajuste le stock libre d'une référence.
	 *
	 * @param int $product_id Produit ou variation.
	 * @param int $delta      Variation, positive ou négative.
	 *
	 * @return int Nouveau stock.
	 */
	public static function adjust( $product_id, $delta ): int {
		$new = max( 0, self::get( $product_id ) + (int) $delta );

		self::set( $product_id, $new );

		return $new;
	}

	/**
	 * Références disposant de stock libre.
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
				Legacy::STOCK_META
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
	 * Références encore en stock négatif, héritées d'une règle antérieure.
	 *
	 * @return int[]
	 */
	public static function negative_ids(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- idem free_map().
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id
				   FROM {$wpdb->postmeta}
				  WHERE meta_key = %s
				    AND CAST( meta_value AS SIGNED ) < 0",
				Legacy::STOCK_META
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Nombre de références portant un stock physique, quelle que soit sa valeur.
	 *
	 * Sert au panneau de diagnostic : c'est la preuve que le plugin voit bien les
	 * données laissées par le snippet.
	 *
	 * @return int
	 */
	public static function tracked_reference_count(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- diagnostic, à la demande.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Legacy::STOCK_META
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
