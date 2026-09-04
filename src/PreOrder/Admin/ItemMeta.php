<?php
/**
 * Lisibilité des métas de précommande sur les écrans de commande.
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder\Admin;

use RSMW\PreOrder\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * Rend présentables les métas de ligne posées par le module.
 *
 * L'écran de modification d'une commande et la modale d'aperçu appellent tous
 * deux `get_all_formatted_meta_data( '' )` — avec un préfixe VIDE. Contrairement
 * au front, aux emails et à l'espace client, ils n'écartent donc PAS les clés
 * commençant par un souligné : sans ce qui suit, le marchand lit littéralement
 * « _rsmw_preorder_qty: 2 » et « _rsmw_preorder_filled_at: 1757 000 000 ».
 *
 * Deux traitements, selon l'utilité de la donnée pour le marchand :
 *
 * - masquer ce qui fait doublon ou n'est qu'un rouage interne ;
 * - renommer et mettre en forme ce qui l'intéresse vraiment.
 *
 * Aucun risque de fuite côté client : en front `get_formatted_meta_data()` garde
 * son préfixe « _ » par défaut et écarte les clés soulignées AVANT d'appliquer
 * le filtre de libellé (includes/class-wc-order-item.php, la boucle sort en
 * `continue` bien avant `woocommerce_order_item_display_meta_key`).
 */
final class ItemMeta {

	/**
	 * Accroche le masquage et le renommage.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_keys' ) );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'display_key' ), 10, 2 );
		add_filter( 'woocommerce_order_item_display_meta_value', array( __CLASS__, 'display_value' ), 10, 2 );
	}

	/**
	 * Masque les clés qui n'apprennent rien au marchand.
	 *
	 * Même mécanisme que WooCommerce pour ses propres rouages `_reduced_stock` et
	 * `_restock_refunded_items`. Effet de bord souhaitable : ces clés deviennent
	 * aussi non modifiables à la main sur l'écran de commande.
	 *
	 * @param array $keys Clés déjà masquées.
	 *
	 * @return array
	 */
	public static function hide_keys( $keys ) {
		$keys = (array) $keys;

		/*
		 * La date figée sur la ligne fait doublon avec la méta visible
		 * « Expédition estimée », qui porte exactement la même information sous
		 * une forme lisible. On garde celle que le client voit.
		 */
		$keys[] = Legacy::DATE_META;

		return $keys;
	}

	/**
	 * Renomme les clés techniques que le marchand a intérêt à lire.
	 *
	 * @param string $display_key Libellé calculé par WooCommerce.
	 * @param object $meta        Méta en cours d'affichage.
	 *
	 * @return string
	 */
	public static function display_key( $display_key, $meta ) {
		if ( ! is_object( $meta ) || ! isset( $meta->key ) ) {
			return $display_key;
		}

		switch ( $meta->key ) {
			case Legacy::ITEM_QTY_META:
				return __( 'Quantité précommandée', 'real-stock-manager-for-woocommerce' );

			case Legacy::ITEM_FILLED_META:
				return __( 'Précommande levée le', 'real-stock-manager-for-woocommerce' );
		}

		return $display_key;
	}

	/**
	 * Met en forme l'horodatage de la levée.
	 *
	 * Stocké en temps Unix parce que c'est un instant, pas une date de calendrier :
	 * il sert à comparer le délai promis au délai tenu. Illisible tel quel.
	 *
	 * @param string $display_value Valeur calculée par WooCommerce.
	 * @param object $meta          Méta en cours d'affichage.
	 *
	 * @return string
	 */
	public static function display_value( $display_value, $meta ) {
		if ( ! is_object( $meta ) || ! isset( $meta->key ) || Legacy::ITEM_FILLED_META !== $meta->key ) {
			return $display_value;
		}

		$timestamp = is_numeric( $meta->value ) ? (int) $meta->value : 0;

		if ( $timestamp <= 0 ) {
			return $display_value;
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
