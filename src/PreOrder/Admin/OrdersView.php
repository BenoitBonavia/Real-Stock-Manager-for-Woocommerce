<?php
/**
 * Vues « À traiter » et « Précommandes » dans la liste des commandes.
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder\Admin;

use RSMW\PreOrder\Legacy;
use RSMW\Preparation\Config;
use RSMW\Preparation\OrderStatus as PreparationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Deux filtres au-dessus de la liste des commandes.
 *
 * « À traiter » regroupe les statuts que le marchand a configurés comme étant à
 * préparer — il suit donc automatiquement sa configuration, là où le snippet
 * remplacé codait la liste en dur.
 *
 * « Précommandes » filtre sur le MARQUEUR, pas sur le statut. C'est toute la
 * différence : la vue reste juste une fois la commande expédiée, alors qu'un
 * filtre par statut la perdait de vue dès qu'elle avançait.
 */
final class OrdersView {

	/** Paramètre d'URL portant la vue courante. */
	private const PARAM = 'rsmw_view';

	/** Vue « À traiter ». */
	private const VIEW_TODO = 'a_traiter';

	/** Vue « Précommandes ». */
	private const VIEW_PREORDERS = 'precommandes';

	/** Transient du compteur de précommandes. */
	private const COUNT_TRANSIENT = 'rsmw_preorder_count';

	/**
	 * Accroche les vues et les filtres de requête.
	 */
	public static function register(): void {
		add_filter( 'views_woocommerce_page_wc-orders', array( __CLASS__, 'add_views' ) );
		add_filter( 'views_edit-shop_order', array( __CLASS__, 'add_views' ) );

		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'filter_hpos_query' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_legacy_query' ) );

		// Le compteur devient faux dès qu'une commande est marquée.
		add_action( 'woocommerce_new_order', array( __CLASS__, 'flush_count' ) );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'flush_count' ) );
	}

	/**
	 * Vue demandée, chaîne vide si aucune.
	 *
	 * @return string
	 */
	public static function current_view(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- simple lecture de contexte d'affichage.
		if ( isset( $_GET[ self::PARAM ] ) ) {
			$view = sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) );

			return in_array( $view, array( self::VIEW_TODO, self::VIEW_PREORDERS ), true ) ? $view : '';
		}

		// Alias du snippet remplacé : un signet existant continue de fonctionner.
		if ( isset( $_GET['filter_a_traiter'] ) && '1' === (string) wp_unslash( $_GET['filter_a_traiter'] ) ) {
			return self::VIEW_TODO;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '';
	}

	/**
	 * Ajoute les deux vues.
	 *
	 * Les clés sont préfixées : elles ne peuvent donc pas entrer en collision avec
	 * celles de WooCommerce ni avec la vue « À empaqueter » du module Préparation,
	 * qui filtre les mêmes hooks.
	 *
	 * @param array $views Vues existantes.
	 *
	 * @return array
	 */
	public static function add_views( $views ) {
		$current = self::current_view();
		$base    = self::base_url();

		$views[ 'rsmw_' . self::VIEW_TODO ] = self::link(
			add_query_arg( self::PARAM, self::VIEW_TODO, $base ),
			__( 'À traiter', 'real-stock-manager-for-woocommerce' ),
			self::todo_count(),
			self::VIEW_TODO === $current
		);

		$views[ 'rsmw_' . self::VIEW_PREORDERS ] = self::link(
			add_query_arg( self::PARAM, self::VIEW_PREORDERS, $base ),
			__( 'Précommandes', 'real-stock-manager-for-woocommerce' ),
			self::preorder_count(),
			self::VIEW_PREORDERS === $current
		);

		return (array) $views;
	}

	/**
	 * Restreint la requête sous stockage haute performance.
	 *
	 * @param array $args Arguments passés à wc_get_orders().
	 *
	 * @return array
	 */
	public static function filter_hpos_query( $args ) {
		$view = self::current_view();

		if ( self::VIEW_TODO === $view ) {
			$args['status'] = Config::statuses();
		} elseif ( self::VIEW_PREORDERS === $view ) {
			if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
				$args['meta_query'] = array();
			}

			$args['meta_query'][] = array(
				'key'     => Legacy::ORDER_FLAG_META,
				'compare' => 'EXISTS',
			);
		}

		return $args;
	}

	/**
	 * Restreint la requête sous stockage historique.
	 *
	 * @param \WP_Query $query Requête.
	 */
	public static function filter_legacy_query( $query ): void {
		if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return;
		}

		if ( 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}

		$view = self::current_view();

		if ( self::VIEW_TODO === $view ) {
			$query->set(
				'post_status',
				array_map(
					static function ( $status ) {
						return 'wc-' . $status;
					},
					Config::statuses()
				)
			);

			return;
		}

		if ( self::VIEW_PREORDERS === $view ) {
			// Fusion plutôt qu'écrasement : la liste des commandes pose déjà une
			// meta_query quand le marchand filtre par client.
			$meta_query = $query->get( 'meta_query' );
			$meta_query = is_array( $meta_query ) ? $meta_query : array();

			$meta_query[] = array(
				'key'     => Legacy::ORDER_FLAG_META,
				'compare' => 'EXISTS',
			);

			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Nombre de commandes dans les statuts à préparer.
	 *
	 * @return int
	 */
	private static function todo_count(): int {
		if ( ! function_exists( 'wc_orders_count' ) ) {
			return 0;
		}

		$total = 0;

		foreach ( Config::statuses() as $status ) {
			$total += (int) wc_orders_count( $status );
		}

		return $total;
	}

	/**
	 * Nombre de commandes portant le marqueur de précommande.
	 *
	 * Mis en cache brièvement : la liste des commandes est un écran très
	 * fréquenté, et ce décompte demande une requête sur les métadonnées.
	 *
	 * @return int
	 */
	private static function preorder_count(): int {
		$cached = get_transient( self::COUNT_TRANSIENT );

		if ( false !== $cached ) {
			return (int) $cached;
		}

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

		$total = is_object( $results ) && isset( $results->total ) ? (int) $results->total : 0;

		set_transient( self::COUNT_TRANSIENT, $total, MINUTE_IN_SECONDS );

		return $total;
	}

	/**
	 * Invalide le compteur de précommandes.
	 */
	public static function flush_count(): void {
		delete_transient( self::COUNT_TRANSIENT );
	}

	/**
	 * Adresse de la liste des commandes, selon le mode de stockage.
	 *
	 * @return string
	 */
	private static function base_url(): string {
		return PreparationStatus::hpos_enabled()
			? admin_url( 'admin.php?page=wc-orders' )
			: admin_url( 'edit.php?post_type=shop_order' );
	}

	/**
	 * Construit un lien de vue.
	 *
	 * @param string $url     Adresse.
	 * @param string $label   Libellé.
	 * @param int    $count   Compteur.
	 * @param bool   $current Vue courante.
	 *
	 * @return string
	 */
	private static function link( string $url, string $label, int $count, bool $current ): string {
		return sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
