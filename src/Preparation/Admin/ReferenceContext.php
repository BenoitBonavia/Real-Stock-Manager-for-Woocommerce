<?php
/**
 * État d'une référence, servi au panneau de contexte.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Demand;
use RSMW\Preparation\Labels;
use RSMW\Preparation\Stock;
use RSMW\Preparation\Supply;

defined( 'ABSPATH' ) || exit;

/**
 * Renvoie, pour une référence donnée, son stock libre et la pression que les
 * commandes exercent dessus.
 *
 * Sert à ne plus saisir un mouvement à l'aveugle : avant d'enregistrer une
 * entrée ou un retrait, l'opérateur voit ce qui est déjà en stock et ce que les
 * commandes attendent.
 */
final class ReferenceContext {

	/** Action AJAX. */
	public const ACTION = 'rsmw_reference_context';

	/** Nonce associé. */
	public const NONCE = 'rsmw_reference_context';

	/**
	 * Accroche l'action AJAX.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Traite la requête.
	 */
	public static function handle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Droits insuffisants.', 'real-stock-manager-for-woocommerce' ) );
		}

		$product_id = isset( $_POST['product'] ) ? absint( wp_unslash( $_POST['product'] ) ) : 0;

		// Repli SKU : la saisie au clavier d'une référence doit alimenter le
		// panneau au même titre que la sélection dans la liste.
		if ( $product_id <= 0 && ! empty( $_POST['sku'] ) ) {
			$raw        = sanitize_text_field( wp_unslash( $_POST['sku'] ) );
			$product_id = (int) wc_get_product_id_by_sku( $raw );

			if ( $product_id <= 0 && ctype_digit( $raw ) ) {
				$product_id = (int) $raw;
			}
		}

		if ( $product_id <= 0 || ! wc_get_product( $product_id ) ) {
			wp_send_json_error( __( 'Référence introuvable.', 'real-stock-manager-for-woocommerce' ) );
		}

		wp_send_json_success( self::describe( $product_id ) );
	}

	/**
	 * État complet d'une référence.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return array
	 */
	public static function describe( int $product_id ): array {
		$info = Labels::get( $product_id );
		$map  = Demand::map();

		$data = isset( $map[ $product_id ] )
			? $map[ $product_id ]
			: array(
				'restant'    => 0,
				'commandes'  => 0,
				'plus_vieux' => null,
			);

		$free      = Stock::get( $product_id );
		$remaining = (int) $data['restant'];

		/*
		 * Commandé au fournisseur pour cette référence : la part déjà réservée sur
		 * des commandes clients, plus le reliquat non encore attribué. Lecture
		 * défensive de la carte : un transient écrit par une version antérieure ne
		 * porte pas encore cette clé.
		 */
		$ordered = ( isset( $data['commande'] ) ? (int) $data['commande'] : 0 )
			+ Supply::get( $product_id );

		return array(
			'label'     => trim( $info['name'] . ( '' !== $info['variant'] ? ' — ' . $info['variant'] : '' ) ),
			'sku'       => $info['sku'],
			'free'      => $free,
			'remaining' => $remaining,
			'ordered'   => $ordered,
			'orders'    => (int) $data['commandes'],
			// Ce qu'il reste réellement à commander : ni en stock, ni déjà commandé.
			'missing'   => max( 0, $remaining - max( 0, $free ) - $ordered ),
			'oldest'    => self::oldest( $data['plus_vieux'] ),
		);
	}

	/**
	 * Résumé de la commande la plus ancienne en attente de cette référence.
	 *
	 * @param int|null $order_id Identifiant de commande.
	 *
	 * @return array|null
	 */
	private static function oldest( $order_id ): ?array {
		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$created = $order->get_date_created();

		return array(
			'num'  => (string) $order->get_order_number(),
			'date' => $created ? $created->date_i18n( 'd/m/Y' ) : '',
			'url'  => $order->get_edit_order_url(),
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
