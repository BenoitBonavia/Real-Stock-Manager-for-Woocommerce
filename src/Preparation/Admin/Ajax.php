<?php
/**
 * Point d'entrée AJAX du pointage.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Items;
use RSMW\Preparation\Legacy;
use RSMW\Preparation\StatusSync;
use RSMW\Preparation\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * Applique un pointage et renvoie l'état à jour de la commande.
 */
final class Ajax {

	/**
	 * Accroche l'action AJAX.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . Legacy::AJAX_ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Traite la requête de pointage.
	 */
	public static function handle(): void {
		check_ajax_referer( Legacy::AJAX_NONCE, 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( 'Droits insuffisants.', 'real-stock-manager-for-woocommerce' ) );
		}

		$order_id = isset( $_POST['order'] ) ? absint( wp_unslash( $_POST['order'] ) ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( __( 'Commande introuvable.', 'real-stock-manager-for-woocommerce' ) );
		}

		$status_before = $order->get_status();

		if ( isset( $_POST['all'] ) ) {

			$full = (bool) absint( wp_unslash( $_POST['all'] ) );

			foreach ( $order->get_items() as $item ) {
				Items::set_quantity( $item, $full ? (int) $item->get_quantity() : 0 );
			}
		} else {

			$item_id = isset( $_POST['item'] ) ? absint( wp_unslash( $_POST['item'] ) ) : 0;
			$delta   = isset( $_POST['delta'] ) ? (int) wp_unslash( $_POST['delta'] ) : 0;
			$item    = $order->get_item( $item_id );

			if ( ! $item ) {
				wp_send_json_error( __( 'Ligne introuvable.', 'real-stock-manager-for-woocommerce' ) );
			}

			Items::set_quantity( $item, Items::prepared( $item ) + $delta );
		}

		// Relecture depuis la base : les objets en mémoire sont périmés.
		$order = wc_get_order( $order_id );

		$status_after = StatusSync::sync( $order );

		$lines = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$lines[] = array(
				'item' => (int) $item_id,
				'qty'  => min( (int) $item->get_quantity(), Items::prepared( $item ) ),
				'free' => Stock::get( Items::key( $item ) ),
			);
		}

		list( $done, $total ) = Items::order_progress( $order );

		$message = __( 'Enregistré.', 'real-stock-manager-for-woocommerce' );
		$reload  = false;

		if ( $status_after !== $status_before ) {
			$reload = true;

			$message = Legacy::STATUS_SLUG === $status_after
				? __( 'Commande complète : passage en « À empaqueter ».', 'real-stock-manager-for-woocommerce' )
				: sprintf(
					/* translators: %s: libellé du statut de retour. */
					__( 'Commande incomplète : retour en « %s ».', 'real-stock-manager-for-woocommerce' ),
					wc_get_order_status_name( $status_after )
				);
		}

		wp_send_json_success(
			array(
				'lines'   => $lines,
				'done'    => $done,
				'total'   => $total,
				'pct'     => $total > 0 ? (int) round( $done / $total * 100 ) : 0,
				'message' => $message,
				'reload'  => $reload,
			)
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
