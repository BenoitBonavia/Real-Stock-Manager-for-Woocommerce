<?php
/**
 * Point d'entrée AJAX du pointage.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Allocator;
use RSMW\Preparation\Config;
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
		$order_id = isset( $_POST['order'] ) ? absint( wp_unslash( $_POST['order'] ) ) : 0;

		check_ajax_referer( self::nonce_action( $order_id ), 'nonce' );

		// Meta-capability par objet : sous HPOS comme en stockage historique,
		// WooCommerce résout correctement les droits propres à CETTE commande,
		// contrairement à la capability globale 'edit_shop_orders'.
		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_send_json_error( __( 'Droits insuffisants.', 'real-stock-manager-for-woocommerce' ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( __( 'Commande introuvable.', 'real-stock-manager-for-woocommerce' ) );
		}

		// Hors périmètre : ni de quoi rendre, ni de quoi resynchroniser — le
		// stock prélevé depuis un tel écran deviendrait irrécupérable, puisque
		// ni active_order_ids() ni holder_order_ids() ne verraient plus la commande.
		if ( ! in_array( $order->get_status(), array_merge( Config::statuses(), array( Legacy::STATUS_SLUG ) ), true ) ) {
			wp_send_json_error( __( 'Cette commande n’est plus dans le périmètre de préparation.', 'real-stock-manager-for-woocommerce' ) );
		}

		$status_before = $order->get_status();

		if ( isset( $_POST['all'] ) ) {

			$full = (bool) absint( wp_unslash( $_POST['all'] ) );

			Allocator::without_auto_allocation(
				static function () use ( $order, $full ) {
					foreach ( $order->get_items() as $item ) {
						Items::set_quantity( $item, $full ? (int) $item->get_quantity() : 0 );
					}
				}
			);
		} else {

			$item_id = isset( $_POST['item'] ) ? absint( wp_unslash( $_POST['item'] ) ) : 0;
			$delta   = isset( $_POST['delta'] ) ? (int) wp_unslash( $_POST['delta'] ) : 0;

			// $load_from_db = false : la résolution passe par le data store de LA
			// commande, dont la requête est scopée par order_id (vérifié y compris
			// sous HPOS). Avec le paramètre par défaut, WC_Order_Factory résout
			// l'identifiant de ligne GLOBALEMENT, ce qui permettrait d'écrire sur la
			// ligne d'une autre commande avec le nonce et l'order_id de celle-ci.
			$item = $order->get_item( $item_id, false );

			// Vérification d'appartenance défensive, en plus du scope de la requête
			// ci-dessus, et garde de type : une ligne 'fee'/'shipping' n'a pas de
			// get_variation_id(), utilisée par Items::key().
			if ( ! $item instanceof \WC_Order_Item_Product || (int) $item->get_order_id() !== $order_id ) {
				wp_send_json_error( __( 'Ligne introuvable.', 'real-stock-manager-for-woocommerce' ) );
			}

			Allocator::without_auto_allocation(
				static function () use ( $item, $delta ) {
					Items::set_quantity( $item, Items::prepared( $item ) + $delta );
				}
			);
		}

		// Relecture depuis la base : les objets en mémoire sont périmés.
		$order = wc_get_order( $order_id );

		$status_after = StatusSync::sync( $order );

		$lines = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$quantity = (int) $item->get_quantity();
			$prepared = min( $quantity, Items::prepared( $item ) );

			$lines[] = array(
				'item'    => (int) $item_id,
				'qty'     => $prepared,
				'ordered' => min( max( 0, $quantity - $prepared ), Items::ordered( $item ) ),
				'free'    => Stock::get( Items::key( $item ) ),
			);
		}

		list( $done, $ordered_total, $total ) = Items::order_coverage( $order );

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

		$percent = $total > 0 ? (int) round( $done / $total * 100 ) : 0;

		wp_send_json_success(
			array(
				'lines'      => $lines,
				'done'       => $done,
				'ordered'    => $ordered_total,
				'total'      => $total,
				'pct'        => $percent,
				// Borné à la place restante : les deux arrondis peuvent dépasser 100 %.
				'orderedPct' => $total > 0
					? min( max( 0, 100 - $percent ), (int) round( $ordered_total / $total * 100 ) )
					: 0,
				'message'    => $message,
				'reload'     => $reload,
			)
		);
	}

	/**
	 * Action de nonce scopée à une commande.
	 *
	 * Un nonce global valait pour n'importe quel couple (commande, ligne) : un
	 * jeton obtenu en ouvrant une commande permettait d'écrire sur les lignes
	 * d'une autre. Utilisée à la fois ici et par Metabox::render(), qui génère
	 * le jeton affiché.
	 *
	 * @param int $order_id Identifiant de commande.
	 *
	 * @return string
	 */
	public static function nonce_action( int $order_id ): string {
		return Legacy::AJAX_NONCE . '_' . $order_id;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
