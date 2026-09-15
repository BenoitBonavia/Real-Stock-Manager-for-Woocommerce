<?php
/**
 * Purge des pointages gelés hors périmètre, hérités d'avant la libération automatique.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

use RSMW\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Avant la v3.6.0 (chantier 6), une commande qui sortait du périmètre suivi
 * (annulation, remboursement, corbeille...) emportait son stock pointé sans
 * restitution. Ces créances gelées subsistent en base sur des commandes déjà
 * hors périmètre : invisibles partout, mais toujours armées — le premier
 * événement qui repasse par ces commandes (y compris le vidage automatique de
 * la corbeille WordPress à 30 jours) les crédite au stock libre, dupliquant des
 * unités déjà recomptées manuellement entre-temps.
 *
 * Balaie une fois, par lots, à chaque chargement de l'administration, sur le
 * patron de `PreOrder\Migration`. Purge à sec : contrairement à
 * `Allocator::release_item()`, RIEN n'est recrédité au stock libre ni au
 * commandé fournisseur — la purge ne fait que neutraliser une créance qui n'a
 * plus de commande active pour la porter. Le rapport produit dit au marchand
 * quoi recompter.
 */
final class FrozenHolds {

	/** Clé de réglage portant l'avancement. */
	private const STATE_KEY = 'frozen_holds_state';

	/** Curseur du balayage, sur l'identifiant de ligne de commande. */
	private const CURSOR_KEY = 'frozen_holds_cursor';

	/** Clé de réglage portant le compte rendu cumulé. */
	private const REPORT_KEY = 'frozen_holds_report';

	/** Lignes de commande traitées par requête. */
	private const BATCH = 200;

	/** Nombre maximal de références détaillées dans le rapport. */
	private const MAX_REFS = 200;

	/** Nombre maximal de commandes distinctes suivies dans le rapport. */
	private const MAX_ORDERS = 500;

	/** Nombre maximal de commandes listées par référence dans le rapport. */
	private const MAX_ORDERS_PER_REF = 20;

	/**
	 * Accroche l'avancement du balayage.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ) );
	}

	/**
	 * Le balayage est-il terminé ?
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

		if ( self::process_batch() ) {
			return;
		}

		Settings::update( self::STATE_KEY, 'done' );
	}

	/**
	 * Compte rendu cumulé, fusionné avec ses valeurs par défaut.
	 *
	 * @return array{time:int, items:int, units:int, ordered:int, refs:array, order_ids:array, truncated:bool}
	 */
	public static function report(): array {
		$defaults = array(
			'time'      => 0,
			'items'     => 0,
			'units'     => 0,
			'ordered'   => 0,
			'refs'      => array(),
			'order_ids' => array(),
			'truncated' => false,
		);

		$stored = Settings::get( self::REPORT_KEY, array() );

		return is_array( $stored ) ? array_merge( $defaults, $stored ) : $defaults;
	}

	/**
	 * Efface le compte rendu, une fois le marchand recompté.
	 *
	 * N'affecte pas l'avancement du balayage : la purge elle-même reste acquise,
	 * seul l'affichage du compte rendu disparaît.
	 */
	public static function acknowledge_report(): void {
		Settings::update( self::REPORT_KEY, array() );
	}

	/**
	 * Traite un lot de lignes portant encore un pointage ou une réserve
	 * fournisseur non nuls.
	 *
	 * Une seule requête sur l'itemmeta, sans jointure sur une table de
	 * commandes : identique sous HPOS et en stockage historique, et visible
	 * jusque dans la corbeille — aucun `wc_get_orders()` du dépôt ne sait
	 * l'interroger.
	 *
	 * @return bool Vrai s'il reste probablement du travail.
	 */
	private static function process_batch(): bool {
		global $wpdb;

		$cursor = (int) Settings::get( self::CURSOR_KEY, 0 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- balayage ponctuel par lots.
		$item_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT order_item_id
				   FROM {$wpdb->prefix}woocommerce_order_itemmeta
				  WHERE meta_key IN ( %s, %s )
				    AND CAST( meta_value AS SIGNED ) > 0
				    AND order_item_id > %d
				  ORDER BY order_item_id ASC
				  LIMIT %d",
				Legacy::ITEM_QTY_META,
				Supply::ITEM_META,
				$cursor,
				self::BATCH
			)
		);

		$item_ids = array_map( 'intval', (array) $item_ids );

		if ( empty( $item_ids ) ) {
			return false;
		}

		Settings::update( self::CURSOR_KEY, max( $item_ids ) );

		$placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- emplacements générés, valeurs préparées juste au-dessus.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_item_id, order_id
				   FROM {$wpdb->prefix}woocommerce_order_items
				  WHERE order_item_id IN ( {$placeholders} )",
				$item_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$order_of_item = array();

		foreach ( (array) $rows as $row ) {
			$order_of_item[ (int) $row->order_item_id ] = (int) $row->order_id;
		}

		$holders       = array_flip( Demand::holder_order_ids() );
		$report        = self::report();
		$order_totals  = array();

		foreach ( $item_ids as $item_id ) {
			$order_id = isset( $order_of_item[ $item_id ] ) ? $order_of_item[ $item_id ] : 0;

			// Toujours détentrice légitime : rien à purger, la commande est
			// encore suivie ou prête à empaqueter.
			if ( $order_id > 0 && isset( $holders[ $order_id ] ) ) {
				continue;
			}

			$item = \WC_Order_Factory::get_order_item( $item_id );

			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			// Même condition que le SELECT ci-dessus (prepared ou ordered > 0),
			// relue ici pour ne rien purger qu'une désynchronisation aurait laissé
			// filtrer entre les deux requêtes.
			if ( Items::prepared( $item ) <= 0 && Items::ordered( $item ) <= 0 ) {
				continue;
			}

			// Ce qui est réellement prélevé sur le stock physique : le chiffre à
			// recompter. Peut différer de prepared() par le repli de compatibilité
			// documenté sur Items::from_stock().
			$units   = Items::from_stock( $item );
			$ordered = Items::ordered( $item );

			$item->update_meta_data( Legacy::ITEM_QTY_META, 0 );
			$item->update_meta_data( Legacy::ITEM_SOURCE_META, 0 );
			$item->update_meta_data( Supply::ITEM_META, 0 );
			$item->save();

			self::record( $report, Items::key( $item ), $units, $ordered, $order_id );

			if ( $order_id > 0 ) {
				$order_totals[ $order_id ] = ( isset( $order_totals[ $order_id ] ) ? $order_totals[ $order_id ] : 0 ) + $units;
			}
		}

		$report['time'] = time();

		Settings::update( self::REPORT_KEY, $report );

		if ( ! empty( $order_totals ) ) {
			Demand::flush();
			self::annotate_orders( $order_totals );
			Log::info( sprintf( 'Nettoyage des pointages gelés : %d commande(s) traitée(s) sur ce lot.', count( $order_totals ) ) );
		}

		return count( $item_ids ) >= self::BATCH;
	}

	/**
	 * Verse une ligne purgée au compte rendu cumulé, plafonné pour que l'option
	 * reste petite.
	 *
	 * @param array $report     Compte rendu, modifié par référence.
	 * @param int   $product_id Produit ou variation.
	 * @param int   $units      Unités prélevées neutralisées.
	 * @param int   $ordered    Réserve fournisseur neutralisée.
	 * @param int   $order_id   Commande d'origine, 0 si la ligne est orpheline.
	 */
	private static function record( array &$report, int $product_id, int $units, int $ordered, int $order_id ): void {
		$units   = max( 0, $units );
		$ordered = max( 0, $ordered );

		++$report['items'];
		$report['units']   += $units;
		$report['ordered'] += $ordered;

		if ( $order_id > 0 ) {
			if ( isset( $report['order_ids'][ $order_id ] ) || count( $report['order_ids'] ) < self::MAX_ORDERS ) {
				$report['order_ids'][ $order_id ] = true;
			} else {
				$report['truncated'] = true;
			}
		}

		if ( ! isset( $report['refs'][ $product_id ] ) ) {
			if ( count( $report['refs'] ) >= self::MAX_REFS ) {
				$report['truncated'] = true;

				return;
			}

			$report['refs'][ $product_id ] = array(
				'units'   => 0,
				'ordered' => 0,
				'orders'  => array(),
			);
		}

		$report['refs'][ $product_id ]['units']   += $units;
		$report['refs'][ $product_id ]['ordered'] += $ordered;

		if ( $order_id > 0 && ! in_array( $order_id, $report['refs'][ $product_id ]['orders'], true ) ) {
			if ( count( $report['refs'][ $product_id ]['orders'] ) < self::MAX_ORDERS_PER_REF ) {
				$report['refs'][ $product_id ]['orders'][] = $order_id;
			} else {
				$report['truncated'] = true;
			}
		}
	}

	/**
	 * Pose une note sur chaque commande dont une ligne a été purgée.
	 *
	 * @param array<int, int> $order_totals Unités neutralisées, par commande.
	 */
	private static function annotate_orders( array $order_totals ): void {
		foreach ( $order_totals as $order_id => $units ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$order->add_order_note(
				sprintf(
					/* translators: %d: nombre d'articles neutralisés. */
					__( 'Nettoyage des pointages gelés : %d article(s) encore pointé(s) sur cette commande hors périmètre ont été neutralisés. Le stock libre n’a pas été modifié.', 'real-stock-manager-for-woocommerce' ),
					$units
				)
			);
		}
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
