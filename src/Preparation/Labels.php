<?php
/**
 * Libellés lisibles des références.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Met en forme le nom d'un produit ou d'une variation pour l'affichage.
 */
final class Labels {

	/**
	 * Nom lisible d'une référence.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return array{name:string, variant:string, sku:string, price:float, edit:string}
	 */
	public static function get( $product_id ): array {
		$product_id = (int) $product_id;
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return array(
				'name'    => sprintf(
					/* translators: %d: identifiant du produit supprimé. */
					__( 'Produit supprimé (#%d)', 'real-stock-manager-for-woocommerce' ),
					$product_id
				),
				'variant' => '',
				'sku'     => '',
				'price'   => 0.0,
				'edit'    => '',
			);
		}

		$variant = '';

		if ( $product->is_type( 'variation' ) ) {
			$variant = wc_get_formatted_variation( $product, true, false );
			$parent  = wc_get_product( $product->get_parent_id() );
			$name    = $parent ? $parent->get_name() : $product->get_name();
		} else {
			$name = $product->get_name();
		}

		$edit_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product_id;

		return array(
			'name'    => $name,
			'variant' => (string) $variant,
			'sku'     => (string) $product->get_sku(),
			'price'   => (float) $product->get_price(),
			'edit'    => (string) get_edit_post_link( $edit_id, '' ),
		);
	}

	/**
	 * Charge en une fois les produits qui vont être libellés.
	 *
	 * Sans cela, la page « Besoins & stock » déclenche une requête par ligne :
	 * `wc_get_product()` sur une référence non mise en cache va chercher le post
	 * puis ses métadonnées. Sur un catalogue de plusieurs centaines de variations,
	 * cela représente l'essentiel du temps de rendu.
	 *
	 * @param int[] $product_ids Produits et variations à précharger.
	 */
	public static function prime( array $product_ids ): void {
		$product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );

		if ( empty( $product_ids ) ) {
			return;
		}

		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $product_ids, false, true );
		}

		// Les variations ont besoin du produit parent pour reconstituer leur nom.
		$parent_ids = array();

		foreach ( $product_ids as $product_id ) {
			$post = get_post( $product_id );

			if ( $post && 'product_variation' === $post->post_type && $post->post_parent ) {
				$parent_ids[] = (int) $post->post_parent;
			}
		}

		$parent_ids = array_values( array_diff( array_unique( $parent_ids ), $product_ids ) );

		if ( ! empty( $parent_ids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $parent_ids, false, true );
		}
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
