<?php
/**
 * Pages d'administration du module de préparation.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * Déclare les deux entrées du menu WooCommerce et leurs ressources.
 *
 * Les slugs de page sont ceux du snippet remplacé : les changer casserait les
 * signets et les liens déjà partagés.
 */
final class Pages {

	/**
	 * Accroche le menu et les ressources.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 60 );
		add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'declare_screens' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Identifiants d'écran des deux pages.
	 *
	 * @return array{needs:string, stock:string}
	 */
	public static function screen_ids(): array {
		return array(
			'needs' => 'woocommerce_page_' . Legacy::PAGE_NEEDS,
			'stock' => 'woocommerce_page_' . Legacy::PAGE_STOCK,
		);
	}

	/**
	 * Ajoute les entrées au menu WooCommerce.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Besoins & stock', 'real-stock-manager-for-woocommerce' ),
			__( 'Besoins & stock', 'real-stock-manager-for-woocommerce' ),
			'manage_woocommerce',
			Legacy::PAGE_NEEDS,
			array( NeedsPage::class, 'render' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Gestion stock', 'real-stock-manager-for-woocommerce' ),
			__( 'Gestion stock', 'real-stock-manager-for-woocommerce' ),
			'manage_woocommerce',
			Legacy::PAGE_STOCK,
			array( StockPage::class, 'render' )
		);
	}

	/**
	 * Fait reconnaître les pages comme écrans WooCommerce.
	 *
	 * Nécessaire pour que WooCommerce y charge ses ressources d'administration,
	 * dont la recherche de produits en select2 et son jeton de sécurité.
	 *
	 * @param array $ids Écrans WooCommerce.
	 *
	 * @return array
	 */
	public static function declare_screens( $ids ) {
		return array_merge( (array) $ids, array_values( self::screen_ids() ) );
	}

	/**
	 * Charge les ressources des deux pages.
	 *
	 * @param string $hook_suffix Écran courant.
	 */
	public static function enqueue( $hook_suffix ): void {
		$screens = self::screen_ids();

		if ( ! in_array( $hook_suffix, $screens, true ) ) {
			return;
		}

		/*
		 * `wp-theme` expose les jetons du WordPress Design System (--wpds-*).
		 * Enregistrée depuis WordPress 7.1 mais chargée seulement sur certains
		 * écrans : on la déclare en dépendance pour garantir que le bloc :root
		 * soit analysé avant notre feuille. Absente, la feuille reste valide
		 * grâce aux valeurs de repli.
		 */
		$style_deps = array( 'woocommerce_admin_styles' );

		if ( wp_style_is( 'wp-theme', 'registered' ) ) {
			array_unshift( $style_deps, 'wp-theme' );
		}

		wp_enqueue_style(
			'rsmw-preparation-admin',
			RSMW_URL . 'assets/css/preparation-admin.css',
			$style_deps,
			RSMW_VERSION
		);

		if ( $screens['needs'] === $hook_suffix ) {
			wp_enqueue_script(
				'rsmw-needs-table',
				RSMW_URL . 'assets/js/needs-table.js',
				array(),
				RSMW_VERSION,
				array( 'in_footer' => true )
			);
		}

		if ( $screens['stock'] === $hook_suffix ) {
			wp_enqueue_script( 'wc-enhanced-select' );

			wp_enqueue_script(
				'rsmw-stock-movement',
				RSMW_URL . 'assets/js/stock-movement.js',
				array( 'jquery', 'wc-enhanced-select' ),
				RSMW_VERSION,
				array( 'in_footer' => true )
			);
		}
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
