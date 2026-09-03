<?php
/**
 * Activation / désactivation du plugin.
 *
 * @package RealStockManager
 */

namespace RSMW;

defined( 'ABSPATH' ) || exit;

/**
 * Gère les routines d'installation et de nettoyage à chaud.
 */
final class Installer {

	/**
	 * Option stockant la version installée (utile pour les futures migrations).
	 */
	public const VERSION_OPTION = 'rsmw_version';

	/**
	 * Routine d'activation.
	 */
	public static function activate(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( RSMW_BASENAME );

			wp_die(
				esc_html__(
					'Real Stock Manager for WooCommerce nécessite WooCommerce. Activez WooCommerce puis réessayez.',
					'real-stock-manager-for-woocommerce'
				),
				esc_html__( 'Prérequis manquant', 'real-stock-manager-for-woocommerce' ),
				array( 'back_link' => true )
			);
		}

		self::maybe_upgrade();

		do_action( 'rsmw_activated' );
	}

	/**
	 * Routine de désactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'rsmw_daily_maintenance' );

		do_action( 'rsmw_deactivated' );
	}

	/**
	 * Exécute les migrations entre versions puis met à jour le marqueur.
	 */
	private static function maybe_upgrade(): void {
		$installed = (string) get_option( self::VERSION_OPTION, '' );

		if ( RSMW_VERSION === $installed ) {
			return;
		}

		/**
		 * Point d'accroche pour les migrations de données.
		 *
		 * @param string $installed Version précédemment installée ('' si première install).
		 */
		do_action( 'rsmw_upgrade', $installed );

		update_option( self::VERSION_OPTION, RSMW_VERSION, false );
	}
}
