<?php
/**
 * Coût d'achat d'une référence.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Le plugin ne stocke aucun coût d'achat lui-même : il lit celui déclaré par une
 * source tierce, par ordre de préférence :
 *
 * 1. Cost of Goods Sold, natif WooCommerce depuis la 10.3 — `WC_Product::get_cogs_total_value()`,
 *    qui gère seule l'héritage parent → variation et le drapeau « additif ».
 * 2. Cost of Goods for WooCommerce (WPFactory) — `wpfcogs()->core->products->get_product_cost()`,
 *    qui gère le même repli sur le parent pour ce plugin-ci.
 * 3. Aucune : le coût est nul, et le panneau de diagnostic le signale plutôt que
 *    de laisser croire à un stock gratuit.
 *
 * Appeler les getters `get_cogs_*()` de WooCommerce quand la fonctionnalité est
 * désactivée déclenche un `wc_doing_it_wrong()` : la fonctionnalité est donc
 * toujours testée avant d'être lue, jamais après.
 */
final class Cost {

	/** Aucune source de coût disponible. */
	public const SOURCE_NONE = 'none';

	/** Cost of Goods Sold, natif WooCommerce. */
	public const SOURCE_WOOCOMMERCE = 'woocommerce';

	/** Cost of Goods for WooCommerce, WPFactory. */
	public const SOURCE_WPFACTORY = 'wpfactory';

	/**
	 * Source active, mémoïsée pour la requête en cours.
	 *
	 * @var string|null
	 */
	private static $source = null;

	/**
	 * Source de coût d'achat active sur cette boutique.
	 *
	 * @return string Une des constantes SOURCE_*.
	 */
	public static function source(): string {
		if ( null !== self::$source ) {
			return self::$source;
		}

		if (
			class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class )
			&& \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'cost_of_goods_sold' )
		) {
			self::$source = self::SOURCE_WOOCOMMERCE;
		} elseif ( function_exists( 'wpfcogs' ) ) {
			self::$source = self::SOURCE_WPFACTORY;
		} else {
			self::$source = self::SOURCE_NONE;
		}

		return self::$source;
	}

	/**
	 * Libellé de la source active, pour le panneau de diagnostic.
	 *
	 * @return string
	 */
	public static function source_label(): string {
		switch ( self::source() ) {
			case self::SOURCE_WOOCOMMERCE:
				return __( 'Cost of Goods Sold (natif WooCommerce)', 'real-stock-manager-for-woocommerce' );
			case self::SOURCE_WPFACTORY:
				return __( 'Cost of Goods for WooCommerce (WPFactory)', 'real-stock-manager-for-woocommerce' );
			default:
				return __( 'aucune', 'real-stock-manager-for-woocommerce' );
		}
	}

	/**
	 * Coût d'achat unitaire d'un produit.
	 *
	 * @param \WC_Product $product Produit ou variation.
	 *
	 * @return float
	 */
	public static function for_product( \WC_Product $product ): float {
		switch ( self::source() ) {
			case self::SOURCE_WOOCOMMERCE:
				$cost = method_exists( $product, 'get_cogs_total_value' ) ? $product->get_cogs_total_value() : 0.0;
				break;

			case self::SOURCE_WPFACTORY:
				$cost = (float) wpfcogs()->core->products->get_product_cost( $product->get_id() );
				break;

			default:
				$cost = 0.0;
		}

		/**
		 * Filtre le coût d'achat unitaire résolu pour un produit.
		 *
		 * @param float       $cost    Coût résolu, éventuellement nul.
		 * @param \WC_Product $product Produit ou variation.
		 */
		return (float) apply_filters( 'rsmw_product_cost', (float) $cost, $product );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
