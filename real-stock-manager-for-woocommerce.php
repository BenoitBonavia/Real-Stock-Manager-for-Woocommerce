<?php
/**
 * Plugin Name:          Real Stock Manager for WooCommerce
 * Plugin URI:           https://github.com/benoitbonavia/real-stock-manager-for-woocommerce
 * Description:          Centralise la gestion des stocks réels de WooCommerce : règles, automatismes et outils regroupés dans un plugin unique plutôt que dans des snippets épars.
 * Version:              1.1.0
 * Requires at least:    6.8
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Benoit Bonavia
 * Author URI:           https://github.com/benoitbonavia
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          real-stock-manager-for-woocommerce
 * Domain Path:          /languages
 * Update URI:           false
 * WC requires at least: 9.9
 * WC tested up to:      11.0
 *
 * @package RealStockManager
 */

namespace RSMW;

defined( 'ABSPATH' ) || exit;

define( 'RSMW_VERSION', '1.1.0' );
define( 'RSMW_FILE', __FILE__ );
define( 'RSMW_PATH', plugin_dir_path( __FILE__ ) );
define( 'RSMW_URL', plugin_dir_url( __FILE__ ) );
define( 'RSMW_BASENAME', plugin_basename( __FILE__ ) );

/** Version minimale de WooCommerce requise. */
define( 'RSMW_MIN_WC_VERSION', '9.9' );

require_once RSMW_PATH . 'src/Autoloader.php';
Autoloader::register();

require_once RSMW_PATH . 'src/functions.php';

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

/**
 * Déclare la compatibilité avec les fonctionnalités récentes de WooCommerce.
 *
 * Doit être appelé sur `before_woocommerce_init`. WooCommerce n'affiche ces
 * informations que pour les extensions déclarant « WC tested up to » dans leur
 * en-tête : garder cette valeur à jour fait partie du contrat.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RSMW_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', RSMW_FILE, true );
	}
);

/**
 * Point d'entrée : instancie le plugin une fois toutes les extensions chargées.
 */
add_action( 'plugins_loaded', array( Plugin::class, 'instance' ), 20 );
