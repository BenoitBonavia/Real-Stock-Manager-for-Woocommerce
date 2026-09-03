<?php
/**
 * Colonne « Préparation » de la liste des commandes.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Config;
use RSMW\Preparation\Items;
use RSMW\Preparation\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * Affiche l'avancement de la préparation, sous HPOS comme en stockage historique.
 */
final class OrdersColumn {

	/**
	 * Identifiant de la colonne.
	 */
	private const COLUMN = 'mh_prep';

	/**
	 * Accroche la colonne.
	 */
	public static function register(): void {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ), 20 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 20 );

		// Sous HPOS le second argument est un objet WC_Order ; en stockage
		// historique, c'est un identifiant de publication. D'où deux entrées.
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render' ), 10, 2 );
	}

	/**
	 * Insère la colonne après celle du statut.
	 *
	 * @param array $columns Colonnes existantes.
	 *
	 * @return array
	 */
	public static function add_column( $columns ) {
		$out = array();

		foreach ( (array) $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$out[ self::COLUMN ] = __( 'Préparation', 'real-stock-manager-for-woocommerce' );
			}
		}

		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Préparation', 'real-stock-manager-for-woocommerce' );
		}

		return $out;
	}

	/**
	 * Affiche l'avancement.
	 *
	 * @param string           $column        Colonne courante.
	 * @param \WC_Order|int    $order_or_id   Commande (HPOS) ou identifiant (stockage historique).
	 */
	public static function render( $column, $order_or_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( $order_or_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$tracked = array_merge( Config::statuses(), array( Legacy::STATUS_SLUG ) );

		if ( ! in_array( $order->get_status(), $tracked, true ) ) {
			echo '<span class="rsmw-prep-progress__none">·</span>';

			return;
		}

		list( $done, $total ) = Items::order_progress( $order );

		$percent = $total > 0 ? (int) round( $done / $total * 100 ) : 0;

		if ( 100 === $percent ) {
			$modifier = 'rsmw-prep-progress--complete';
		} elseif ( $done > 0 ) {
			$modifier = 'rsmw-prep-progress--partial';
		} else {
			$modifier = '';
		}

		printf(
			'<span class="rsmw-prep-progress %1$s"><span class="rsmw-prep-progress__track"><span class="rsmw-prep-progress__fill" style="width:%2$d%%"></span></span><span class="rsmw-prep-progress__label">%3$d/%4$d</span></span>',
			esc_attr( $modifier ),
			(int) $percent,
			(int) $done,
			(int) $total
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
