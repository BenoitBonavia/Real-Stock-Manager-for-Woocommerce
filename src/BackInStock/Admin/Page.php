<?php
/**
 * Page « Besoins Back in Stock ».
 *
 * @package RealStockManager
 */

namespace RSMW\BackInStock\Admin;

use RSMW\BackInStock\Config;
use RSMW\BackInStock\Coverage;
use RSMW\BackInStock\Host;
use RSMW\Preparation\Admin\View;
use RSMW\Preparation\Legacy as PreparationLegacy;
use RSMW\Preparation\Purchase;
use RSMW\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Confronte les inscriptions à une alerte de retour en stock au stock libre
 * du module Préparation : combien de demandes le réassort en cours peut-il
 * honorer, combien il en manquerait.
 */
final class Page {

	/** Slug de la page. */
	public const SLUG = 'rsmw-bis-needs';

	/** Nonce du formulaire d'ajout au commandé fournisseur. */
	public const NONCE_ORDER = 'rsmw_bis_order';

	/**
	 * Accroche le menu, les ressources et le traitement du formulaire.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 61 );
		add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'declare_screen' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		/*
		 * Sur `load-{écran}`, et non depuis render() : c'est la seule fenêtre
		 * où une redirection reste possible. Sans elle, un rafraîchissement
		 * rejouerait l'écriture — anodin pour une ligne, mais une validation
		 * de dix références en enregistrerait vingt.
		 */
		add_action( 'load-' . self::screen_id(), array( __CLASS__, 'handle_post' ) );
	}

	/**
	 * Traite le formulaire d'ajout au commandé fournisseur, avant tout affichage.
	 */
	public static function handle_post(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! isset( $_POST['rsmw_bis_order_submit'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ORDER );

		$report = Purchase::apply( self::read_order_input() );

		self::flash( $report );
		self::redirect();
	}

	/**
	 * Identifiant d'écran de la page.
	 *
	 * @return string
	 */
	public static function screen_id(): string {
		return 'woocommerce_page_' . self::SLUG;
	}

	/**
	 * Ajoute l'entrée au menu WooCommerce.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Besoins Back in Stock', 'real-stock-manager-for-woocommerce' ),
			__( 'Besoins Back in Stock', 'real-stock-manager-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Fait reconnaître la page comme écran WooCommerce.
	 *
	 * @param array $ids Écrans WooCommerce.
	 *
	 * @return array
	 */
	public static function declare_screen( $ids ) {
		return array_merge( (array) $ids, array( self::screen_id() ) );
	}

	/**
	 * Charge les ressources de la page.
	 *
	 * @param string $hook_suffix Écran courant.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( self::screen_id() !== $hook_suffix ) {
			return;
		}

		/*
		 * Même feuille que la page « Besoins pour commande » : les deux
		 * partagent leur look & feel (cartes, KPI, tableau). Voir le
		 * commentaire d'en-tête de `preparation-admin.css` pour les jetons
		 * `--wpds-*` dont elle dépend.
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

		wp_enqueue_script(
			'rsmw-bis-needs-table',
			RSMW_URL . 'assets/js/bis-needs-table.js',
			array(),
			RSMW_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Affiche la page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Droits insuffisants.', 'real-stock-manager-for-woocommerce' ) );
		}

		if ( ! Host::is_active() ) {
			View::render(
				'needs-page',
				array(
					'host_active'    => false,
					'host_installed' => Host::is_installed(),
					'needs_page_url' => admin_url( 'admin.php?page=' . PreparationLegacy::PAGE_NEEDS ),
				),
				'back-in-stock'
			);

			return;
		}

		$rows = Coverage::rows();

		View::render(
			'needs-page',
			array(
				'host_active'             => true,
				'rows'                    => $rows,
				'totals'                  => Coverage::totals( $rows ),
				'statuses'                => Config::statuses(),
				'auto_delete'             => Host::auto_delete_enabled(),
				'auto_delete_days'        => Host::auto_delete_days(),
				'variable_any_variation'  => Host::variable_any_variation_enabled(),
				'preparation_active'      => null !== Plugin::instance()->get_module( 'order_preparation' ),
				'needs_page_url'          => admin_url( 'admin.php?page=' . PreparationLegacy::PAGE_NEEDS ),
				'settings_url'            => \RSMW\Admin\Admin::get_settings_url() . '&section=back_in_stock',
				'export_filename'         => __( 'besoins-back-in-stock', 'real-stock-manager-for-woocommerce' ),
				'refresh_url'             => admin_url( 'admin.php?page=' . self::SLUG ),
				'order_nonce'             => self::NONCE_ORDER,
				'purchase'                => self::take_flash(),
			),
			'back-in-stock'
		);
	}

	/**
	 * Lit les quantités saisies dans le formulaire d'ajout au commandé fournisseur.
	 *
	 * @return array<int, int>
	 */
	private static function read_order_input(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- vérifié par l'appelant.
		$raw = isset( $_POST['rsmw_bis_order'] ) ? wp_unslash( $_POST['rsmw_bis_order'] ) : array();

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();

		foreach ( $raw as $product_id => $qty ) {
			$rows[ (int) $product_id ] = absint( $qty );
		}

		return $rows;
	}

	/**
	 * Mémorise un compte rendu le temps d'une redirection.
	 *
	 * @param array $report Compte rendu de `Purchase::apply()`.
	 */
	private static function flash( array $report ): void {
		set_transient( 'rsmw_bis_flash_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Récupère et consomme le compte rendu mémorisé.
	 *
	 * @return array|null
	 */
	private static function take_flash(): ?array {
		$key   = 'rsmw_bis_flash_' . get_current_user_id();
		$flash = get_transient( $key );

		if ( ! is_array( $flash ) ) {
			return null;
		}

		delete_transient( $key );

		return $flash;
	}

	/**
	 * Redirige vers la page, pour qu'un rafraîchissement ne rejoue pas l'écriture.
	 */
	private static function redirect(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
