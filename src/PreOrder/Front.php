<?php
/**
 * Affichage côté boutique d'un article précommandable.
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Libellé du bouton, message de disponibilité et badge promotionnel.
 *
 * Ces trois filtres sont ceux du thème classique. Les blocs produit récents de
 * WooCommerce n'honorent pas `woocommerce_sale_flash` : si la fiche produit passe
 * un jour aux blocs, la surcharge du badge cessera d'agir. Les deux autres restent
 * utilisés par la plupart des thèmes.
 */
final class Front {

	/**
	 * Accroche les filtres d'affichage.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'button_text' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'button_text' ), 10, 2 );
		add_filter( 'woocommerce_get_availability_text', array( __CLASS__, 'availability_text' ), 10, 2 );
		add_filter( 'woocommerce_sale_flash', array( __CLASS__, 'sale_flash' ), 10, 3 );
	}

	/**
	 * L'article part-il en précommande ?
	 *
	 * @param mixed $product Produit ou variation.
	 * @param int   $quantity Quantité envisagée.
	 *
	 * @return bool
	 */
	public static function is_preorder( $product, int $quantity = 1 ): bool {
		return $product instanceof \WC_Product && $product->is_on_backorder( $quantity );
	}

	/**
	 * « Ajouter au panier » devient « Précommander ».
	 *
	 * @param string $text    Libellé.
	 * @param mixed  $product Produit.
	 *
	 * @return string
	 */
	public static function button_text( $text, $product ) {
		if ( ! self::is_preorder( $product ) ) {
			return $text;
		}

		return __( 'Précommander', 'real-stock-manager-for-woocommerce' );
	}

	/**
	 * Message de disponibilité affiché sous le prix.
	 *
	 * @param string $availability Message calculé par WooCommerce.
	 * @param mixed  $product      Produit.
	 *
	 * @return string
	 */
	public static function availability_text( $availability, $product ) {
		if ( ! self::is_preorder( $product ) ) {
			return $availability;
		}

		$date = Dates::readable( $product );

		if ( '' !== $date ) {
			return sprintf(
				/* translators: %s: date d'expédition annoncée. */
				__( 'En précommande — Expédition à partir du %s', 'real-stock-manager-for-woocommerce' ),
				$date
			);
		}

		// Aucune date renseignée, ni sur la variation ni sur le produit parent.
		return __( 'Précommande, un délai peut être à prévoir', 'real-stock-manager-for-woocommerce' );
	}

	/**
	 * Badge promotionnel.
	 *
	 * Surchargé UNIQUEMENT pour une précommande : partout ailleurs le badge natif
	 * de WooCommerce est rendu tel quel.
	 *
	 * @param string   $html    Balisage du badge.
	 * @param \WP_Post $post    Publication du produit.
	 * @param mixed    $product Produit.
	 *
	 * @return string
	 */
	public static function sale_flash( $html, $post, $product ) {
		unset( $post );

		if ( ! self::is_preorder( $product ) ) {
			return $html;
		}

		return '<span class="onsale">'
			. esc_html__( 'Réduction précommande', 'real-stock-manager-for-woocommerce' )
			. '</span>';
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
