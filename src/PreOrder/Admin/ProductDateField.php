<?php
/**
 * Champ « date d'expédition précommande » sur les fiches produit.
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder\Admin;

use RSMW\PreOrder\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * Une date par produit, et une par variation.
 *
 * La variation qui n'a pas de date propre hérite de celle du parent : une gamme
 * entière peut partager une date tout en laissant une taille particulière
 * annoncer la sienne.
 */
final class ProductDateField {

	/**
	 * Nom du champ de variation, conservé du snippet remplacé.
	 */
	private const VARIATION_FIELD = 'mh_preorder_date_variation';

	/**
	 * Accroche les champs et leur enregistrement.
	 */
	public static function register(): void {
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'render_simple' ) );
		add_action( 'woocommerce_variation_options_inventory', array( __CLASS__, 'render_variation' ), 10, 3 );

		// Priorité 20 : après le CRUD de WooCommerce, accroché en 10, qui écraserait
		// sinon la valeur au moment de son propre enregistrement.
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_simple' ), 20 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 20, 2 );
	}

	/**
	 * Champ du produit simple ou du produit parent.
	 */
	public static function render_simple(): void {
		global $post;

		if ( ! $post ) {
			return;
		}

		woocommerce_wp_text_input(
			array(
				'id'          => Legacy::DATE_META,
				'type'        => 'date',
				'label'       => __( 'Expédition précommande', 'real-stock-manager-for-woocommerce' ),
				'value'       => get_post_meta( $post->ID, Legacy::DATE_META, true ),
				'desc_tip'    => true,
				'description' => __( 'Date annoncée au client quand le produit part en précommande. Sert aussi de valeur par défaut aux variations qui n’ont pas de date propre. Vide : message générique sans date.', 'real-stock-manager-for-woocommerce' ),
			)
		);
	}

	/**
	 * Enregistre la date du produit.
	 *
	 * Le nonce est vérifié par WooCommerce avant ce hook.
	 *
	 * @param int $post_id Produit.
	 */
	public static function save_simple( $post_id ): void {
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- vérifié en amont par WC_Meta_Box_Product_Data.
		if ( ! isset( $_POST[ Legacy::DATE_META ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- idem.
		update_post_meta( $post_id, Legacy::DATE_META, self::sanitize( wp_unslash( $_POST[ Legacy::DATE_META ] ) ) );
	}

	/**
	 * Champ d'une variation.
	 *
	 * @param int      $loop           Index de la variation dans le formulaire.
	 * @param array    $variation_data Données de la variation.
	 * @param \WP_Post $variation      Publication de la variation.
	 */
	public static function render_variation( $loop, $variation_data, $variation ): void {
		unset( $variation_data );

		woocommerce_wp_text_input(
			array(
				'id'            => Legacy::DATE_META . '_' . $loop,
				'name'          => self::VARIATION_FIELD . '[' . $loop . ']',
				'value'         => get_post_meta( $variation->ID, Legacy::DATE_META, true ),
				'type'          => 'date',
				'label'         => __( 'Expédition précommande', 'real-stock-manager-for-woocommerce' ),
				'wrapper_class' => 'form-row form-row-full',
				'desc_tip'      => true,
				'description'   => __( 'Vide : la date du produit parent s’applique.', 'real-stock-manager-for-woocommerce' ),
			)
		);
	}

	/**
	 * Enregistre la date d'une variation.
	 *
	 * @param int $variation_id Variation.
	 * @param int $loop         Index de la variation dans le formulaire.
	 */
	public static function save_variation( $variation_id, $loop ): void {
		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- vérifié en amont par WC_AJAX::save_variations().
		if ( ! isset( $_POST[ self::VARIATION_FIELD ][ $loop ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- idem.
		$raw = wp_unslash( $_POST[ self::VARIATION_FIELD ][ $loop ] );

		update_post_meta( $variation_id, Legacy::DATE_META, self::sanitize( $raw ) );
	}

	/**
	 * Ne conserve qu'une date au format AAAA-MM-JJ, ou la chaîne vide.
	 *
	 * Le champ est de type `date`, mais rien n'empêche un envoi forgé : la valeur
	 * finit affichée au client et comparée comme une chaîne pour trouver la date
	 * la plus lointaine, elle doit donc être d'un format sûr.
	 *
	 * @param mixed $raw Valeur soumise.
	 *
	 * @return string
	 */
	private static function sanitize( $raw ): string {
		$value = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
