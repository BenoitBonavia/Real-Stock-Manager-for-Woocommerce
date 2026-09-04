<?php
/**
 * Pointage des lignes de commande.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture et écriture de la quantité préparée sur une ligne de commande, et
 * mouvement de stock physique associé.
 */
final class Items {

	/**
	 * Identifiant de stock d'une ligne : la variation si elle existe, sinon le produit.
	 *
	 * @param \WC_Order_Item_Product $item Ligne de commande.
	 *
	 * @return int
	 */
	public static function key( $item ): int {
		$variation_id = (int) $item->get_variation_id();

		return $variation_id > 0 ? $variation_id : (int) $item->get_product_id();
	}

	/**
	 * Quantité déjà pointée sur une ligne.
	 *
	 * @param \WC_Order_Item_Product $item Ligne de commande.
	 *
	 * @return int
	 */
	public static function prepared( $item ): int {
		return max( 0, (int) $item->get_meta( Legacy::ITEM_QTY_META ) );
	}

	/**
	 * Part de la ligne réellement prélevée sur le stock physique.
	 *
	 * COMPATIBILITÉ — ne pas simplifier. Les lignes pointées avant l'introduction
	 * de cette meta décrémentaient systématiquement le stock : leur part prélevée
	 * vaut donc, par construction, leur quantité pointée. Retourner zéro à la
	 * place ferait perdre du stock à chaque dépointage d'une ligne ancienne.
	 *
	 * @param \WC_Order_Item_Product $item Ligne de commande.
	 *
	 * @return int
	 */
	public static function from_stock( $item ): int {
		$raw = $item->get_meta( Legacy::ITEM_SOURCE_META );

		if ( '' === $raw || null === $raw ) {
			return self::prepared( $item );
		}

		return max( 0, (int) $raw );
	}

	/**
	 * Quantité de cette ligne couverte par une commande fournisseur en cours.
	 *
	 * @param \WC_Order_Item_Product $item Ligne de commande.
	 *
	 * @return int
	 */
	public static function ordered( $item ): int {
		return max( 0, (int) $item->get_meta( Supply::ITEM_META ) );
	}

	/**
	 * Fixe la part de la ligne couverte par une commande fournisseur.
	 *
	 * Bornée par ce qui reste à couvrir : préparé + commandé ne peut jamais
	 * dépasser la quantité voulue par le client.
	 *
	 * @param \WC_Order_Item_Product $item    Ligne de commande.
	 * @param int                    $new_qty Quantité visée.
	 *
	 * @return int Quantité réellement enregistrée.
	 */
	public static function set_ordered( $item, $new_qty ): int {
		$room    = max( 0, (int) $item->get_quantity() - self::prepared( $item ) );
		$new_qty = max( 0, min( $room, (int) $new_qty ) );

		$item->update_meta_data( Supply::ITEM_META, $new_qty );
		$item->save();

		Demand::flush();

		return $new_qty;
	}

	/**
	 * Applique une quantité préparée à une ligne et ajuste le stock physique.
	 *
	 * Pointer consomme le stock libre disponible ; au-delà, la ligne vaut entrée
	 * en stock implicite et le compteur reste à zéro. Dépointer ne restitue que
	 * ce qui avait réellement été prélevé.
	 *
	 * @param \WC_Order_Item_Product $item    Ligne de commande.
	 * @param int                    $new_qty Quantité visée.
	 *
	 * @return array{delta:int, qty:int, converted:int}
	 */
	public static function set_quantity( $item, $new_qty ): array {
		$max     = (int) $item->get_quantity();
		$new_qty = max( 0, min( $max, (int) $new_qty ) );
		$old_qty = self::prepared( $item );
		$delta   = $new_qty - $old_qty;

		if ( 0 === $delta ) {
			return array(
				'delta'     => 0,
				'qty'       => $new_qty,
				'converted' => 0,
			);
		}

		$product_id = self::key( $item );
		$from_stock = self::from_stock( $item );

		if ( $delta > 0 ) {
			$taken = min( $delta, max( 0, Stock::get( $product_id ) ) );

			if ( $taken > 0 ) {
				Stock::adjust( $product_id, -$taken );
			}

			$from_stock += $taken;
		} else {
			$returned = min( -$delta, $from_stock );

			if ( $returned > 0 ) {
				Stock::adjust( $product_id, $returned );
			}

			$from_stock -= $returned;
		}

		/*
		 * Conversion du commandé en préparé — la règle qui évite le double comptage.
		 *
		 * Toute variation de la quantité préparée passe par ici. Quand elle monte,
		 * la marchandise attendue vient d'arriver : la part commandée doit fondre
		 * d'autant, sinon la même unité serait comptée deux fois, une fois en
		 * préparé et une fois en commande fournisseur.
		 *
		 * Quand elle baisse, la place disponible augmente et le min() laisse la
		 * valeur intacte : dépointer ne ressuscite pas une commande fournisseur.
		 */
		$converted   = 0;
		$ordered     = self::ordered( $item );
		$max_ordered = max( 0, $max - $new_qty );

		if ( $ordered > $max_ordered ) {
			$converted = $ordered - $max_ordered;
			$item->update_meta_data( Supply::ITEM_META, $max_ordered );
		}

		$item->update_meta_data( Legacy::ITEM_QTY_META, $new_qty );
		$item->update_meta_data( Legacy::ITEM_SOURCE_META, max( 0, $from_stock ) );
		$item->update_meta_data( Legacy::ITEM_DATE_META, time() );
		$item->update_meta_data( Legacy::ITEM_USER_META, get_current_user_id() );
		$item->save();

		Demand::flush();

		return array(
			'delta'     => $delta,
			'qty'       => $new_qty,
			'converted' => $converted,
		);
	}

	/**
	 * Toutes les lignes de la commande sont-elles complètes ?
	 *
	 * Une commande sans ligne n'est jamais « prête » : elle ne doit pas basculer.
	 *
	 * @param \WC_Order $order Commande.
	 *
	 * @return bool
	 */
	public static function order_is_ready( $order ): bool {
		$has_item = false;

		foreach ( $order->get_items() as $item ) {
			$has_item = true;

			if ( self::prepared( $item ) < (int) $item->get_quantity() ) {
				return false;
			}
		}

		return $has_item;
	}

	/**
	 * Couverture d'une commande : préparé, commandé, total.
	 *
	 * Les trois valeurs sont disjointes et bornées par la quantité de chaque
	 * ligne : préparé + commandé ne dépasse jamais le total. C'est ce qui permet
	 * de tracer une barre à deux segments sans jamais dépasser 100 %.
	 *
	 * @param \WC_Order $order Commande.
	 *
	 * @return array{0:int, 1:int, 2:int} Préparé, commandé, total.
	 */
	public static function order_coverage( $order ): array {
		$prepared = 0;
		$ordered  = 0;
		$total    = 0;

		foreach ( $order->get_items() as $item ) {
			$quantity = (int) $item->get_quantity();
			$done     = min( $quantity, self::prepared( $item ) );

			$total    += $quantity;
			$prepared += $done;
			$ordered  += min( max( 0, $quantity - $done ), self::ordered( $item ) );
		}

		return array( $prepared, $ordered, $total );
	}

	/**
	 * Nombre de lignes de commande couvertes par une commande fournisseur.
	 *
	 * Sert au panneau de diagnostic.
	 *
	 * @return int
	 */
	public static function ordered_line_count(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- diagnostic, à la demande.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta
				  WHERE meta_key = %s AND CAST( meta_value AS SIGNED ) > 0",
				Supply::ITEM_META
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}

	/**
	 * Nombre de lignes de commande portant un pointage.
	 *
	 * Sert au panneau de diagnostic.
	 *
	 * @return int
	 */
	public static function prepared_line_count(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- diagnostic, à la demande.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = %s",
				Legacy::ITEM_QTY_META
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
