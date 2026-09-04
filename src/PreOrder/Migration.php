<?php
/**
 * Reprise de l'historique des précommandes.
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder;

use RSMW\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pose le marqueur de traçabilité sur les commandes déjà en base.
 *
 * Deux sources, complémentaires : les commandes portant le statut « Précommande »,
 * et celles dont une ligne porte une date d'expédition figée par le snippet
 * remplacé. Aucune ne touche au statut.
 *
 * Ce qui n'est PAS récupérable : une commande déjà repassée en « En cours » et
 * dont aucune ligne ne porte de méta. C'est exactement le trou que le nouveau
 * modèle vient boucher, et il ne peut pas être comblé rétroactivement.
 *
 * Le traitement avance par lots, à chaque chargement de l'administration, pour ne
 * jamais dépasser le temps d'exécution sur une boutique chargée.
 */
final class Migration {

	/** Clé de réglage portant l'avancement. */
	private const STATE_KEY = 'preorder_migration';

	/** Curseur du balayage des lignes de commande. */
	private const CURSOR_KEY = 'preorder_migration_cursor';

	/** Commandes traitées par requête. */
	private const BATCH = 100;

	/**
	 * Accroche l'avancement de la migration.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ) );
	}

	/**
	 * La migration est-elle terminée ?
	 *
	 * @return bool
	 */
	public static function is_done(): bool {
		return 'done' === Settings::get( self::STATE_KEY );
	}

	/**
	 * Traite un lot, si nécessaire.
	 */
	public static function maybe_run(): void {
		if ( self::is_done() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$stamped = self::stamp_by_status();

		if ( $stamped >= self::BATCH ) {
			return;
		}

		if ( self::stamp_by_line_meta() ) {
			return;
		}

		Settings::update( self::STATE_KEY, 'done' );
	}

	/**
	 * Marque les commandes portant le statut « Précommande ».
	 *
	 * Pas de curseur : la requête exclut celles déjà marquées, elle avance donc
	 * d'elle-même à chaque passage.
	 *
	 * @return int Nombre de commandes traitées.
	 */
	private static function stamp_by_status(): int {
		$orders = wc_get_orders(
			array(
				'limit'      => self::BATCH,
				'type'       => 'shop_order',
				'status'     => Legacy::STATUS_SLUG,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'meta_query' => array(
					array(
						'key'     => Legacy::ORDER_FLAG_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		if ( ! is_array( $orders ) ) {
			return 0;
		}

		foreach ( $orders as $order ) {
			self::stamp( $order );
		}

		return count( $orders );
	}

	/**
	 * Marque les commandes dont une ligne porte une date d'expédition figée.
	 *
	 * Balayage par curseur sur l'identifiant de commande : la requête ne peut pas
	 * exclure les commandes déjà marquées, la meta recherchée étant sur la ligne
	 * et le marqueur sur la commande.
	 *
	 * @return bool Vrai s'il reste probablement du travail.
	 */
	private static function stamp_by_line_meta(): bool {
		global $wpdb;

		$cursor = (int) Settings::get( self::CURSOR_KEY, 0 );

		/*
		 * Deux sources sur la ligne, complémentaires : la date figée par le snippet
		 * remplacé, et la meta « Backordered » de WooCommerce. Cette dernière n'est
		 * écrite que si le réglage de rupture vaut « Autoriser, mais informer le
		 * client » ; la première n'existe que si le produit portait une date. Une
		 * commande peut n'avoir que l'une des deux.
		 */
		$keys         = array_merge( array( Legacy::DATE_META ), Marker::native_meta_keys() );
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- migration ponctuelle, par lots ; les emplacements sont générés, les valeurs restent préparées.
		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT oi.order_id
				   FROM {$wpdb->prefix}woocommerce_order_items          AS oi
				   INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS m
				           ON m.order_item_id = oi.order_item_id
				  WHERE m.meta_key IN ( {$placeholders} )
				    AND m.meta_value <> ''
				    AND oi.order_id > %d
				  ORDER BY oi.order_id ASC
				  LIMIT %d",
				array_merge( $keys, array( $cursor, self::BATCH ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$order_ids = array_map( 'intval', (array) $order_ids );

		if ( empty( $order_ids ) ) {
			return false;
		}

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof \WC_Order ) {
				self::stamp( $order );
			}
		}

		Settings::update( self::CURSOR_KEY, max( $order_ids ) );

		return true;
	}

	/**
	 * Pose le marqueur sur une commande, sans toucher au statut.
	 *
	 * @param \WC_Order|mixed $order Commande.
	 */
	private static function stamp( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$latest = '';

		/*
		 * Le balayage des lignes a lieu même si la commande est déjà marquée : la
		 * première phase pose le marqueur d'après le seul statut, sans regarder les
		 * lignes. Sortir ici priverait ces commandes de la reprise ligne à ligne.
		 */
		foreach ( $order->get_items() as $item ) {
			$raw = (string) $item->get_meta( Legacy::DATE_META );

			// Format AAAA-MM-JJ : ordonnable comme une chaîne.
			if ( '' !== $raw && $raw > $latest ) {
				$latest = $raw;
			}

			self::backfill_line_quantity( $item );
		}

		if ( $order->get_meta( Legacy::ORDER_FLAG_META ) ) {
			return;
		}

		$order->update_meta_data( Legacy::ORDER_FLAG_META, 1 );

		if ( '' !== $latest ) {
			$order->update_meta_data( Legacy::ORDER_DATE_MAX_META, $latest );
		}

		$order->save();
	}

	/**
	 * Reconstitue la quantité précommandée d'une ligne déjà en base.
	 *
	 * Uniquement depuis la meta « Backordered » de WooCommerce, qui donne le
	 * chiffre exact. Une ligne qui ne porte qu'une date d'expédition reste sans
	 * quantité : le snippet remplacé posait cette date sur toute ligne dont le
	 * produit en avait une, précommandée ou non. En déduire la quantité de la
	 * ligne gonflerait les statistiques d'un chiffre inventé — mieux vaut une
	 * donnée absente qu'une donnée fausse. La commande, elle, reste bien marquée.
	 *
	 * @param \WC_Order_Item $item Ligne de commande.
	 */
	private static function backfill_line_quantity( $item ): void {
		if ( $item->get_meta( Legacy::ITEM_QTY_META ) ) {
			return;
		}

		foreach ( Marker::native_meta_keys( $item ) as $key ) {
			$value = $item->get_meta( $key );

			if ( ! is_numeric( $value ) || (int) $value <= 0 ) {
				continue;
			}

			$item->update_meta_data( Legacy::ITEM_QTY_META, min( (int) $item->get_quantity(), (int) $value ) );
			$item->save();

			return;
		}
	}

	/**
	 * Nombre de commandes actuellement marquées.
	 *
	 * Sert au panneau de diagnostic, pour constater le volume repris.
	 *
	 * @return int
	 */
	public static function marked_order_count(): int {
		$results = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'paginate'   => true,
				'type'       => 'shop_order',
				'status'     => 'any',
				'meta_query' => array(
					array(
						'key'     => Legacy::ORDER_FLAG_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return is_object( $results ) && isset( $results->total ) ? (int) $results->total : 0;
	}

	/**
	 * Nombre de lignes de commande portant un marqueur de précommande.
	 *
	 * @return int
	 */
	public static function marked_line_count(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- diagnostic, à la demande.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta
				  WHERE meta_key = %s AND CAST( meta_value AS SIGNED ) > 0",
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
