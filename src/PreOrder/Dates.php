<?php
/**
 * Date d'expédition annoncée pour une précommande.
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Résout et met en forme la date d'expédition d'un produit ou d'une variation.
 *
 * La date vit sur la variation quand elle en a une, sinon sur le produit parent :
 * une gamme entière peut partager une date, tout en laissant une taille
 * particulière annoncer la sienne.
 */
final class Dates {

	/**
	 * Date brute (AAAA-MM-JJ) applicable à un produit ou une variation.
	 *
	 * @param \WC_Product|null $product Produit ou variation.
	 *
	 * @return string Chaîne vide si aucune date n'est renseignée.
	 */
	public static function raw( $product ): string {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$raw = $product->get_meta( Legacy::DATE_META, true );

		if ( empty( $raw ) && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );

			if ( $parent instanceof \WC_Product ) {
				$raw = $parent->get_meta( Legacy::DATE_META, true );
			}
		}

		return $raw ? (string) $raw : '';
	}

	/**
	 * Met une date brute au format lisible.
	 *
	 * @param string $raw    Date au format AAAA-MM-JJ.
	 * @param string $format Format de date WordPress. « j F » donne « 30 août ».
	 *
	 * @return string Chaîne vide si la date est absente ou illisible.
	 */
	public static function format( string $raw, string $format = 'j F' ): string {
		if ( '' === $raw ) {
			return '';
		}

		// Midi plutôt que minuit : évite tout décalage de date lors de la
		// conversion vers le fuseau du site.
		$timestamp = strtotime( $raw . ' 12:00:00' );

		if ( ! $timestamp ) {
			return '';
		}

		return wp_date( $format, $timestamp );
	}

	/**
	 * Date lisible applicable à un produit.
	 *
	 * @param \WC_Product|null $product Produit ou variation.
	 * @param string           $format  Format de date.
	 *
	 * @return string
	 */
	public static function readable( $product, string $format = 'j F' ): string {
		return self::format( self::raw( $product ), $format );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
