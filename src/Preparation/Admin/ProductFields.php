<?php
/**
 * Champs de stock physique sur les fiches produit.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Demand;
use RSMW\Preparation\Legacy;
use RSMW\Preparation\Stock;
use RSMW\Preparation\Supply;

defined( 'ABSPATH' ) || exit;

/**
 * Ajoute le compteur de stock physique dans l'onglet Inventaire, pour les
 * produits simples comme pour chaque variation.
 */
final class ProductFields {

	/**
	 * Accroche les champs.
	 */
	public static function register(): void {
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'render_simple' ) );
		add_action( 'woocommerce_variation_options_inventory', array( __CLASS__, 'render_variation' ), 10, 3 );

		// Priorité 20 : après le CRUD de WooCommerce, accroché en 10, qui écraserait
		// sinon la valeur au moment de son propre save().
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_simple' ), 20 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 20, 2 );

		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Phrase de contexte affichée sous le champ.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return string
	 */
	private static function hint( $product_id ): string {
		$remaining = Demand::remaining_for( $product_id );

		if ( $remaining > 0 ) {
			return sprintf(
				/* translators: %d: nombre d'articles attendus par des commandes. */
				__( 'Non affecté à une commande. %d article(s) attendent cette référence.', 'real-stock-manager-for-woocommerce' ),
				$remaining
			);
		}

		return __( 'Non affecté à une commande. Aucune commande n’attend cette référence.', 'real-stock-manager-for-woocommerce' );
	}

	/**
	 * Phrase de contexte du champ « commandé au fournisseur ».
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return string
	 */
	private static function supply_hint( $product_id ): string {
		$reserved = Demand::ordered_for( $product_id );

		if ( $reserved > 0 ) {
			return sprintf(
				/* translators: %d: nombre d'articles déjà réservés sur des commandes. */
				__( 'Commandé et pas encore reçu, hors des %d article(s) déjà réservés sur des commandes clients.', 'real-stock-manager-for-woocommerce' ),
				$reserved
			);
		}

		return __( 'Commandé au fournisseur et pas encore reçu. Passez plutôt par la page Gestion du stock : la saisie y est répartie automatiquement sur les commandes en attente.', 'real-stock-manager-for-woocommerce' );
	}

	/**
	 * Champ des produits simples.
	 */
	public static function render_simple(): void {
		global $post;

		if ( ! $post ) {
			return;
		}

		echo '<div class="options_group show_if_simple show_if_external">';

		woocommerce_wp_text_input(
			array(
				'id'                => Legacy::STOCK_META,
				'label'             => __( 'Stock physique libre', 'real-stock-manager-for-woocommerce' ),
				'type'              => 'number',
				'value'             => Stock::get( $post->ID ),
				'custom_attributes' => array( 'step' => '1' ),
				'desc_tip'          => false,
				'description'       => self::hint( $post->ID ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => Supply::META,
				'label'             => __( 'Commandé au fournisseur', 'real-stock-manager-for-woocommerce' ),
				'type'              => 'number',
				'value'             => Supply::get( $post->ID ),
				'custom_attributes' => array( 'step' => '1' ),
				'desc_tip'          => false,
				'description'       => self::supply_hint( $post->ID ),
			)
		);

		echo '</div>';
	}

	/**
	 * Enregistre le champ d'un produit simple.
	 *
	 * Le nonce est vérifié par WooCommerce avant ce hook.
	 *
	 * @param int $post_id Produit.
	 */
	public static function save_simple( $post_id ): void {
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$changed = false;

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- vérifié en amont par WC_Meta_Box_Product_Data.
		if ( isset( $_POST[ Legacy::STOCK_META ] ) ) {
			Stock::set( $post_id, (int) wp_unslash( $_POST[ Legacy::STOCK_META ] ) );
			$changed = true;
		}

		if ( isset( $_POST[ Supply::META ] ) ) {
			Supply::set( $post_id, (int) wp_unslash( $_POST[ Supply::META ] ) );
			$changed = true;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $changed ) {
			Demand::flush();
		}
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
				'id'                => 'mh_stock_reel_' . $loop,
				'name'              => 'mh_stock_reel[' . $loop . ']',
				'label'             => __( 'Stock physique libre', 'real-stock-manager-for-woocommerce' ),
				'type'              => 'number',
				'value'             => Stock::get( $variation->ID ),
				'custom_attributes' => array( 'step' => '1' ),
				'wrapper_class'     => 'form-row form-row-full',
				'desc_tip'          => false,
				'description'       => self::hint( $variation->ID ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => 'rsmw_stock_ordered_' . $loop,
				'name'              => 'rsmw_stock_ordered[' . $loop . ']',
				'label'             => __( 'Commandé au fournisseur', 'real-stock-manager-for-woocommerce' ),
				'type'              => 'number',
				'value'             => Supply::get( $variation->ID ),
				'custom_attributes' => array( 'step' => '1' ),
				'wrapper_class'     => 'form-row form-row-full',
				'desc_tip'          => false,
				'description'       => self::supply_hint( $variation->ID ),
			)
		);
	}

	/**
	 * Enregistre le champ d'une variation.
	 *
	 * @param int $variation_id Variation.
	 * @param int $loop         Index de la variation dans le formulaire.
	 */
	public static function save_variation( $variation_id, $loop ): void {
		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}

		$changed = false;

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- vérifié en amont par WC_AJAX::save_variations().
		if ( isset( $_POST['mh_stock_reel'][ $loop ] ) ) {
			Stock::set( $variation_id, (int) wp_unslash( $_POST['mh_stock_reel'][ $loop ] ) );
			$changed = true;
		}

		if ( isset( $_POST['rsmw_stock_ordered'][ $loop ] ) ) {
			Supply::set( $variation_id, (int) wp_unslash( $_POST['rsmw_stock_ordered'][ $loop ] ) );
			$changed = true;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $changed ) {
			Demand::flush();
		}
	}

	/**
	 * Rappelle qu'une correction manuelle peut être affectée à des commandes.
	 */
	public static function render_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->id || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Compteur mis en cache : sans cela, ouvrir une fiche produit déclencherait
		// systématiquement une requête d'agrégation sur les métadonnées.
		$allocatable = Demand::allocatable_count();

		if ( $allocatable <= 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: nombre d'articles affectables. */
					__( '%d article(s) en stock libre correspondent à des commandes en attente.', 'real-stock-manager-for-woocommerce' ),
					$allocatable
				)
			),
			esc_url( admin_url( 'admin.php?page=' . Legacy::PAGE_NEEDS ) ),
			esc_html__( 'Réaffecter', 'real-stock-manager-for-woocommerce' )
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
