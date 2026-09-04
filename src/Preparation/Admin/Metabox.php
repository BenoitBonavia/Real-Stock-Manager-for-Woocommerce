<?php
/**
 * Métabox « Préparation » sur la fiche commande.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Items;
use RSMW\Preparation\Labels;
use RSMW\Preparation\Legacy;
use RSMW\Preparation\OrderStatus;
use RSMW\Preparation\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * Pointage ligne par ligne, à la quantité.
 */
final class Metabox {

	/**
	 * Identifiant de la métabox.
	 */
	private const BOX_ID = 'mh-prep-box';

	/**
	 * Accroche la métabox et ses ressources.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ), 40 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Écran de la fiche commande, selon le mode de stockage.
	 *
	 * Ne jamais coder « woocommerce_page_wc-orders » en dur : la fonction renvoie
	 * « admin_page_wc-orders » quand l'entrée de menu Commandes est masquée.
	 *
	 * @return string
	 */
	public static function screen_id(): string {
		if ( OrderStatus::hpos_enabled() && function_exists( 'wc_get_page_screen_id' ) ) {
			return (string) wc_get_page_screen_id( 'shop-order' );
		}

		return 'shop_order';
	}

	/**
	 * Déclare la métabox.
	 */
	public static function add(): void {
		add_meta_box(
			self::BOX_ID,
			__( 'Préparation', 'real-stock-manager-for-woocommerce' ),
			array( __CLASS__, 'render' ),
			self::screen_id(),
			'normal',
			'high'
		);
	}

	/**
	 * Charge CSS et JS sur la fiche commande uniquement.
	 *
	 * @param string $hook_suffix Écran courant.
	 */
	public static function enqueue( $hook_suffix ): void {
		unset( $hook_suffix );

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || self::screen_id() !== $screen->id ) {
			return;
		}

		wp_enqueue_style(
			'rsmw-preparation-metabox',
			RSMW_URL . 'assets/css/preparation-metabox.css',
			array(),
			RSMW_VERSION
		);

		wp_enqueue_script(
			'rsmw-preparation-metabox',
			RSMW_URL . 'assets/js/preparation-metabox.js',
			array(),
			RSMW_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Affiche la métabox.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Commande, ou son post sous stockage historique.
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order
			? $post_or_order
			: wc_get_order( isset( $post_or_order->ID ) ? $post_or_order->ID : 0 );

		if ( ! $order instanceof \WC_Order ) {
			echo '<p>' . esc_html__( 'Commande introuvable.', 'real-stock-manager-for-woocommerce' ) . '</p>';

			return;
		}

		list( $done, $ordered_total, $total ) = Items::order_coverage( $order );

		$items = $order->get_items();

		Labels::prime( array_map( array( Items::class, 'key' ), $items ) );

		$lines = array();

		foreach ( $items as $item_id => $item ) {
			$key      = Items::key( $item );
			$quantity = (int) $item->get_quantity();
			$info     = Labels::get( $key );
			$prepared = min( $quantity, Items::prepared( $item ) );

			$lines[] = array(
				'id'       => (int) $item_id,
				'name'     => $info['name'],
				'variant'  => $info['variant'],
				'quantity' => $quantity,
				'prepared' => $prepared,
				'ordered'  => min( max( 0, $quantity - $prepared ), Items::ordered( $item ) ),
				'free'     => Stock::get( $key ),
			);
		}

		$percent = $total > 0 ? (int) round( $done / $total * 100 ) : 0;

		View::render(
			'metabox',
			array(
				'order_id'        => $order->get_id(),
				'nonce'           => wp_create_nonce( Legacy::AJAX_NONCE ),
				'done'            => $done,
				'ordered'         => $ordered_total,
				'total'           => $total,
				'percent'         => $percent,
				// Borné à la place restante : les deux arrondis peuvent dépasser 100 %.
				'ordered_percent' => $total > 0
					? min( max( 0, 100 - $percent ), (int) round( $ordered_total / $total * 100 ) )
					: 0,
				'lines'           => $lines,
			)
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
