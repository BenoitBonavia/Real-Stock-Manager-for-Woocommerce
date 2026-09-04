<?php
/**
 * Cumul des articles reçus défectueux.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Compteur cumulatif, par référence, des articles arrivés hors d'usage.
 *
 * Ce n'est pas un état de stock : ces articles n'entrent jamais en stock et ne
 * couvrent aucune commande. Le compteur ne sert qu'à documenter la qualité d'une
 * référence — taux de défaut, réclamation fournisseur — là où le journal des
 * mouvements ne le peut pas : il est borné et destiné à l'exploitation courante.
 *
 * Cumulatif et jamais décrémenté automatiquement : une remise à zéro est un
 * geste explicite, après règlement du litige.
 */
final class Defects {

	/** Cumul des défectueux constatés sur cette référence. */
	public const META = '_rsmw_stock_defective';

	/**
	 * Cumul des défectueux d'une référence.
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
	 * Fixe le cumul des défectueux.
	 *
	 * @param int $product_id Produit ou variation.
	 * @param int $qty        Quantité.
	 */
	public static function set( $product_id, $qty ): void {
		update_post_meta( (int) $product_id, self::META, max( 0, (int) $qty ) );
	}

	/**
	 * Ajoute des défectueux au cumul.
	 *
	 * @param int $product_id Produit ou variation.
	 * @param int $qty        Quantité constatée.
	 *
	 * @return int Nouveau cumul.
	 */
	public static function add( $product_id, $qty ): int {
		$new = max( 0, self::get( $product_id ) + max( 0, (int) $qty ) );

		self::set( $product_id, $new );

		return $new;
	}

	/**
	 * Nombre de références ayant déjà connu un défaut.
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
	 * Total des défectueux constatés, toutes références confondues.
	 *
	 * @return int
	 */
	public static function total(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- diagnostic, à la demande.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM( CAST( meta_value AS SIGNED ) ) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return max( 0, (int) $total );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
