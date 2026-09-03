<?php
/**
 * Module « Préparation des commandes & stock physique ».
 *
 * @package RealStockManager
 */

namespace RSMW\Modules;

use RSMW\Preparation\Admin\Ajax;
use RSMW\Preparation\Admin\Metabox;
use RSMW\Preparation\Admin\OrdersColumn;
use RSMW\Preparation\Admin\Pages;
use RSMW\Preparation\Admin\ProductFields;
use RSMW\Preparation\Allocator;
use RSMW\Preparation\Demand;
use RSMW\Preparation\SnippetGuard;
use RSMW\Preparation\StatusSync;

defined( 'ABSPATH' ) || exit;

/**
 * Gère un stock physique réel, distinct du stock WooCommerce.
 *
 * Remplace le snippet du même nom. Le statut de commande « À empaqueter » est,
 * lui, enregistré hors module (cf. Plugin::boot) : le désactiver ne doit pas
 * rendre invisibles les commandes qui le portent déjà.
 */
final class OrderPreparation extends AbstractModule {

	/**
	 * Identifiant du module.
	 *
	 * @var string
	 */
	protected $id = 'order_preparation';

	/**
	 * Libellé affiché dans les réglages.
	 *
	 * Défini par la méthode plutôt que par la propriété : une propriété ne peut
	 * pas être initialisée par un appel de fonction.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Préparation des commandes & stock physique', 'real-stock-manager-for-woocommerce' );
	}

	/**
	 * Le module reste en veille tant que le snippet qu'il remplace est actif.
	 *
	 * {@inheritDoc}
	 */
	public function is_enabled(): bool {
		if ( SnippetGuard::snippet_is_active() ) {
			return false;
		}

		return parent::is_enabled();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		StatusSync::register();
		Allocator::register();

		self::register_cache_invalidation();

		if ( is_admin() ) {
			Metabox::register();
			Ajax::register();
			OrdersColumn::register();
			Pages::register();
			ProductFields::register();
		}
	}

	/**
	 * Invalide la table des besoins sur tout événement de commande.
	 *
	 * La page « Besoins & stock » recalcule de toute façon systématiquement : ces
	 * hooks ne servent qu'aux lectures secondaires, comme l'indicateur des fiches
	 * produit ou la notice de réaffectation.
	 */
	private static function register_cache_invalidation(): void {
		$events = array(
			'woocommerce_order_status_changed',
			'woocommerce_new_order',
			'woocommerce_update_order',
			'woocommerce_checkout_order_created',
			'woocommerce_saved_order_items',
			'woocommerce_trash_order',
			'woocommerce_untrash_order',
			'woocommerce_delete_order',
		);

		foreach ( $events as $event ) {
			add_action( $event, array( Demand::class, 'flush' ) );
		}
	}
}
