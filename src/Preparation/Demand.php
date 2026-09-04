<?php
/**
 * Table des besoins : ce qu'il reste à préparer, par référence.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Agrège les lignes des commandes à préparer.
 *
 * Une seule requête SQL sur les tables d'items, partagées à l'identique par HPOS
 * et le stockage historique. Les identifiants de commande viennent de
 * `wc_get_orders()` : aucune jointure sur `wp_posts`, donc rien à adapter selon
 * le mode de stockage des commandes.
 */
final class Demand {

	/** Transient du nombre d'unités affectables. */
	private const ALLOCATABLE_TRANSIENT = 'rsmw_prep_allocatable';

	/** Durée de vie du compteur d'unités affectables, en secondes. */
	private const ALLOCATABLE_TTL = 60;

	/**
	 * Invalide les caches de la table des besoins.
	 */
	public static function flush(): void {
		delete_transient( Legacy::CACHE_KEY );
		delete_transient( Legacy::CACHE_META_KEY );
		delete_transient( self::ALLOCATABLE_TRANSIENT );
	}

	/**
	 * Horodatage et périmètre du dernier calcul.
	 *
	 * @return array{time:int, orders:int}
	 */
	public static function meta(): array {
		$meta = get_transient( Legacy::CACHE_META_KEY );

		return is_array( $meta ) ? $meta : array(
			'time'   => 0,
			'orders' => 0,
		);
	}

	/**
	 * Commandes à préparer, de la plus ancienne à la plus récente.
	 *
	 * L'ordre chronologique porte l'équité : une réception se distribue toujours
	 * en commençant par la commande qui attend depuis le plus longtemps.
	 *
	 * @return int[]
	 */
	public static function active_order_ids(): array {
		$statuses = Config::statuses();

		if ( empty( $statuses ) ) {
			return array();
		}

		$ids = wc_get_orders(
			array(
				'status'  => $statuses,
				'type'    => 'shop_order',
				'limit'   => -1,
				'orderby' => 'date',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Commandes détenant du stock pointé, de la plus récente à la plus ancienne.
	 *
	 * Inclut « À empaqueter » : ce sont justement les commandes intégralement
	 * servies, donc celles qui détiennent le plus de stock. Les omettre rendrait
	 * leur stock impossible à reprendre lors d'un retrait.
	 *
	 * @return int[]
	 */
	public static function holder_order_ids(): array {
		$statuses = array_merge( Config::statuses(), array( Legacy::STATUS_SLUG ) );

		$ids = wc_get_orders(
			array(
				'status'  => $statuses,
				'type'    => 'shop_order',
				'limit'   => -1,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Table des besoins : une entrée par référence.
	 *
	 * @param bool $use_cache Lire le cache si disponible.
	 *
	 * @return array<int, array{demande:int, pointe:int, restant:int, commande:int, commandes:int, plus_vieux:?int, parent:int}>
	 */
	public static function map( bool $use_cache = true ): array {
		if ( $use_cache ) {
			$cached = get_transient( Legacy::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;

		$order_ids = self::active_order_ids();
		$map       = array();
		$ttl       = Config::cache_ttl();

		set_transient(
			Legacy::CACHE_META_KEY,
			array(
				'time'   => time(),
				'orders' => count( $order_ids ),
			),
			$ttl
		);

		if ( empty( $order_ids ) ) {
			set_transient( Legacy::CACHE_KEY, $map, $ttl );

			return $map;
		}

		// Rang chronologique, pour retrouver la commande la plus ancienne par référence.
		$rank         = array_flip( $order_ids );
		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- placeholders générés, valeurs passées à prepare() ; résultat mis en cache par transient.
		$sql = $wpdb->prepare(
			"SELECT oi.order_id,
			        oi.order_item_id,
			        MAX( CASE WHEN m.meta_key = '_product_id'   THEN m.meta_value END ) AS pid,
			        MAX( CASE WHEN m.meta_key = '_variation_id' THEN m.meta_value END ) AS vid,
			        MAX( CASE WHEN m.meta_key = '_qty'          THEN m.meta_value END ) AS qty,
			        MAX( CASE WHEN m.meta_key = %s              THEN m.meta_value END ) AS prep,
			        MAX( CASE WHEN m.meta_key = %s              THEN m.meta_value END ) AS ord
			   FROM {$wpdb->prefix}woocommerce_order_items          AS oi
			   INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS m
			           ON m.order_item_id = oi.order_item_id
			  WHERE oi.order_item_type = 'line_item'
			    AND oi.order_id IN ( {$placeholders} )
			  GROUP BY oi.order_item_id",
			array_merge( array( Legacy::ITEM_QTY_META, Supply::ITEM_META ), $order_ids )
		);

		$rows = $wpdb->get_results( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $row ) {

			$key = (int) $row->vid > 0 ? (int) $row->vid : (int) $row->pid;

			if ( $key <= 0 ) {
				continue;
			}

			$quantity = (int) $row->qty;
			$prepared = max( 0, min( $quantity, (int) $row->prep ) );
			$order_id = (int) $row->order_id;

			// Borné par ce qui reste à couvrir : préparé + commandé ne peut
			// dépasser la quantité voulue par le client.
			$ordered = max( 0, min( $quantity - $prepared, (int) $row->ord ) );

			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = array(
					'demande'    => 0,
					'pointe'     => 0,
					'restant'    => 0,
					'commande'   => 0,
					'commandes'  => array(),
					'plus_vieux' => null,

					/*
					 * Produit parent d'une variation, égal à la clé pour un produit
					 * simple. WooCommerce garantit que `_product_id` porte le parent
					 * sur une ligne de variation. On le retenait déjà pour calculer
					 * la clé quelques lignes plus haut, sans le conserver : le
					 * reporter ici coûte zéro requête et donne au regroupement par
					 * fournisseur son point d'accroche, la taxonomie étant rattachée
					 * au parent.
					 */
					'parent'     => (int) $row->pid > 0 ? (int) $row->pid : $key,
				);
			}

			$map[ $key ]['demande']  += $quantity;
			$map[ $key ]['pointe']   += $prepared;
			$map[ $key ]['restant']  += max( 0, $quantity - $prepared );
			$map[ $key ]['commande'] += $ordered;

			if ( $quantity - $prepared > 0 ) {
				$map[ $key ]['commandes'][ $order_id ] = true;

				$current = $map[ $key ]['plus_vieux'];

				if ( null === $current || $rank[ $order_id ] < $rank[ $current ] ) {
					$map[ $key ]['plus_vieux'] = $order_id;
				}
			}
		}

		foreach ( $map as $key => $data ) {
			$map[ $key ]['commandes'] = count( $data['commandes'] );
		}

		set_transient( Legacy::CACHE_KEY, $map, $ttl );

		return $map;
	}

	/**
	 * Reste à préparer pour une référence.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return int
	 */
	public static function remaining_for( $product_id ): int {
		$map = self::map();

		return isset( $map[ (int) $product_id ]['restant'] ) ? (int) $map[ (int) $product_id ]['restant'] : 0;
	}

	/**
	 * Quantité déjà couverte par une commande fournisseur, pour une référence.
	 *
	 * La lecture est défensive : un transient écrit par une version antérieure du
	 * plugin ne contient pas encore cette clé, et vit jusqu'à son expiration.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return int
	 */
	public static function ordered_for( $product_id ): int {
		$map = self::map();

		return isset( $map[ (int) $product_id ]['commande'] ) ? (int) $map[ (int) $product_id ]['commande'] : 0;
	}

	/**
	 * Nombre d'unités actuellement affectables, sans rien écrire.
	 *
	 * Mis en cache brièvement : cette valeur alimente une notice affichée sur
	 * chaque fiche produit, et son calcul déclenche une requête sur les métadonnées.
	 *
	 * @param bool $use_cache Lire le cache si disponible.
	 *
	 * @return int
	 */
	public static function allocatable_count( bool $use_cache = true ): int {
		if ( $use_cache ) {
			$cached = get_transient( self::ALLOCATABLE_TRANSIENT );

			if ( false !== $cached ) {
				return (int) $cached;
			}
		}

		$free  = Stock::free_map();
		$total = 0;

		if ( ! empty( $free ) ) {
			$demand = self::map();

			foreach ( $free as $product_id => $quantity ) {
				if ( ! empty( $demand[ $product_id ]['restant'] ) ) {
					$total += min( $quantity, (int) $demand[ $product_id ]['restant'] );
				}
			}
		}

		set_transient( self::ALLOCATABLE_TRANSIENT, $total, self::ALLOCATABLE_TTL );

		return $total;
	}

	/**
	 * Commandes hors périmètre, par statut.
	 *
	 * Cause la plus fréquente d'une commande « invisible » dans la table des
	 * besoins : elle est encore en attente de paiement, donc dans un statut non suivi.
	 *
	 * @return array{count:int, detail:array<int, array{slug:string, label:string, count:int}>}
	 */
	public static function orders_outside(): array {
		$ignored = array_merge(
			Config::statuses(),
			array( 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash', Legacy::STATUS_SLUG )
		);

		$all = array_map(
			static function ( $status ) {
				return preg_replace( '/^wc-/', '', $status );
			},
			array_keys( wc_get_order_statuses() )
		);

		$labels  = wc_get_order_statuses();
		$total   = 0;
		$detail  = array();
		$outside = array_values( array_diff( $all, $ignored ) );

		foreach ( $outside as $slug ) {
			$count = function_exists( 'wc_orders_count' ) ? (int) wc_orders_count( $slug ) : 0;

			if ( $count <= 0 ) {
				continue;
			}

			$total   += $count;
			$detail[] = array(
				'slug'  => $slug,
				'label' => isset( $labels[ 'wc-' . $slug ] ) ? $labels[ 'wc-' . $slug ] : $slug,
				'count' => $count,
			);
		}

		return array(
			'count'  => $total,
			'detail' => $detail,
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
