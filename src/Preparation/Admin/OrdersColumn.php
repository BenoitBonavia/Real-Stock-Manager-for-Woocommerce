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

		list( $prepared, $ordered, $total ) = Items::order_coverage( $order );

		$prepared_pct = $total > 0 ? (int) round( $prepared / $total * 100 ) : 0;
		$ordered_pct  = $total > 0 ? (int) round( $ordered / $total * 100 ) : 0;

		// Les deux arrondis peuvent dépasser 100 % à eux deux : on borne le second
		// à la place réellement restante.
		$ordered_pct = min( max( 0, 100 - $prepared_pct ), $ordered_pct );

		if ( $total > 0 && $prepared >= $total ) {
			$modifier = 'rsmw-prep-progress--complete';
		} elseif ( $prepared > 0 ) {
			$modifier = 'rsmw-prep-progress--partial';
		} else {
			$modifier = '';
		}

		$label = sprintf( '%d/%d', (int) $prepared, (int) $total );

		if ( $ordered > 0 ) {
			$label .= sprintf(
				' <span class="rsmw-prep-progress__ordered">+%d</span>',
				(int) $ordered
			);
		}

		printf(
			'<span class="rsmw-prep-progress %1$s" title="%2$s">'
				. '<span class="rsmw-prep-progress__track">'
					. '<span class="rsmw-prep-progress__fill" style="width:%3$d%%"></span>'
					. '<span class="rsmw-prep-progress__fill--ordered" style="width:%4$d%%"></span>'
				. '</span>'
				. '<span class="rsmw-prep-progress__label">%5$s</span>'
			. '</span>',
			esc_attr( $modifier ),
			esc_attr(
				sprintf(
					/* translators: 1: quantité préparée, 2: quantité commandée au fournisseur, 3: total. */
					__( '%1$d préparé(s), %2$d en commande fournisseur, sur %3$d', 'real-stock-manager-for-woocommerce' ),
					(int) $prepared,
					(int) $ordered,
					(int) $total
				)
			),
			(int) $prepared_pct,
			(int) $ordered_pct,
			wp_kses_post( $label )
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
