<?php
/**
 * Module « Besoins Back in Stock ».
 *
 * @package RealStockManager
 */

namespace RSMW\Modules;

use RSMW\BackInStock\Admin\Page;

defined( 'ABSPATH' ) || exit;

/**
 * Confronte les inscriptions à une alerte de retour en stock — posées via
 * « Back In Stock Notifier for WooCommerce » — au stock libre du module
 * Préparation.
 *
 * Dépendance souple, pas déclarée en dur : l'extension hôte peut être
 * absente ou inactive sans rien casser, voir `RSMW\BackInStock\Host`. C'est
 * pourquoi ce module reste désactivé par défaut — contrairement aux autres,
 * il ne sert à rien sur une boutique qui ne gère pas de liste d'attente de
 * retour en stock.
 */
final class BackInStockNeeds extends AbstractModule {

	/**
	 * Identifiant du module.
	 *
	 * @var string
	 */
	protected $id = 'back_in_stock_needs';

	/**
	 * {@inheritDoc}
	 */
	protected $enabled_by_default = false;

	/**
	 * Libellé affiché dans les réglages.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Besoins Back in Stock', 'real-stock-manager-for-woocommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		if ( is_admin() ) {
			Page::register();
		}
	}
}
