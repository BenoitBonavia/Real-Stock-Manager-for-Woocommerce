<?php
/**
 * Vérification des prérequis d'exécution.
 *
 * @package RealStockManager
 */

namespace RSMW;

defined( 'ABSPATH' ) || exit;

/**
 * Vérifie que WooCommerce est présent et suffisamment récent.
 */
final class Requirements {

	/**
	 * Code de la dernière raison d'échec ('missing_wc' ou 'outdated_wc').
	 *
	 * Les chaînes ne sont traduites qu'à l'affichage : les prérequis sont
	 * évalués sur `plugins_loaded`, soit avant que les traductions ne soient
	 * disponibles (WordPress 6.7 signale les chargements trop précoces).
	 *
	 * @var string
	 */
	private static $failure_code = '';

	/**
	 * Indique si l'environnement permet de charger le plugin.
	 *
	 * @return bool
	 */
	public static function are_met(): bool {
		if ( ! class_exists( 'WooCommerce' ) ) {
			self::$failure_code = 'missing_wc';

			return false;
		}

		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, RSMW_MIN_WC_VERSION, '<' ) ) {
			self::$failure_code = 'outdated_wc';

			return false;
		}

		self::$failure_code = '';

		return true;
	}

	/**
	 * Affiche l'avertissement d'administration en cas de prérequis manquant.
	 */
	public static function render_notice(): void {
		if ( '' === self::$failure_code || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( 'outdated_wc' === self::$failure_code ) {
			$message = sprintf(
				/* translators: %s: numéro de version minimale de WooCommerce. */
				__( 'WooCommerce %s ou supérieur est requis.', 'real-stock-manager-for-woocommerce' ),
				RSMW_MIN_WC_VERSION
			);
		} else {
			$message = __( 'WooCommerce doit être installé et activé.', 'real-stock-manager-for-woocommerce' );
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> — %s</p></div>',
			esc_html__( 'Real Stock Manager for WooCommerce', 'real-stock-manager-for-woocommerce' ),
			esc_html( $message )
		);
	}
}
