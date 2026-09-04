<?php
/**
 * Module « Précommandes ».
 *
 * @package RealStockManager
 */

namespace RSMW\Modules;

use RSMW\PreOrder\Admin\OrdersView;
use RSMW\PreOrder\Admin\ProductDateField;
use RSMW\PreOrder\Front;
use RSMW\PreOrder\Marker;
use RSMW\PreOrder\Migration;
use RSMW\PreOrder\SnippetGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Ouverture de précommandes quand le fournisseur fabrique à la demande.
 *
 * Remplace cinq snippets. Le statut de commande « Précommande » est, lui,
 * enregistré hors module (cf. Plugin::boot) : des commandes en production le
 * portent déjà, et le désactiver les ferait disparaître des écrans.
 *
 * Différence de fond avec les snippets remplacés : le statut ne porte plus la
 * traçabilité. Elle vit dans des métas immuables posées à l'achat, si bien
 * qu'elle survit à toutes les transitions de statut, jusqu'à l'expédition et
 * au-delà.
 */
final class PreOrders extends AbstractModule {

	/**
	 * Identifiant du module.
	 *
	 * @var string
	 */
	protected $id = 'preorders';

	/**
	 * Libellé affiché dans les réglages.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Précommandes', 'real-stock-manager-for-woocommerce' );
	}

	/**
	 * Le module reste en veille tant que les snippets remplacés sont actifs.
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
		// La pose des marqueurs doit tourner sur le tunnel de commande, donc hors
		// administration.
		Marker::register();
		Front::register();

		if ( is_admin() ) {
			ProductDateField::register();
			OrdersView::register();
			Migration::register();
		}
	}
}
