<?php
/**
 * Statut de commande « À empaqueter ».
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Déclare le statut auprès de WordPress puis de WooCommerce.
 *
 * Les deux sont nécessaires, y compris sous HPOS : la liste des commandes HPOS
 * croise `wc_get_order_statuses()` avec `get_post_stati( array( 'show_in_admin_status_list' => true ) )`.
 * Sans `register_post_status()`, le statut fonctionne mais reste invisible dans
 * les filtres de la liste, sans le moindre avertissement.
 *
 * Cette classe est enregistrée indépendamment du module : si l'on désactivait le
 * module alors que des commandes portent encore ce statut, elles disparaîtraient
 * des écrans d'administration.
 */
final class OrderStatus {

	/**
	 * Accroche la déclaration du statut.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_status' ), 9 );

		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_to_order_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'add_to_paid_statuses' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'add_to_report_statuses' ) );

		foreach ( array( 'bulk_actions-woocommerce_page_wc-orders', 'bulk_actions-edit-shop_order' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'add_bulk_action' ) );
		}

		add_filter( 'views_woocommerce_page_wc-orders', array( __CLASS__, 'inject_view' ) );
		add_filter( 'views_edit-shop_order', array( __CLASS__, 'inject_view' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_style' ) );
	}

	/**
	 * Libellé du statut.
	 *
	 * @return string
	 */
	public static function label(): string {
		return _x( 'À empaqueter', 'Statut de commande', 'real-stock-manager-for-woocommerce' );
	}

	/**
	 * Enregistre le post status auprès de WordPress.
	 */
	public static function register_post_status(): void {
		register_post_status(
			Legacy::STATUS_FULL,
			array(
				'label'                     => self::label(),
				'public'                    => false,
				'internal'                  => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				// Obligatoire : la liste des commandes HPOS filtre sur cette propriété.
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop(
					'À empaqueter <span class="count">(%s)</span>',
					'À empaqueter <span class="count">(%s)</span>',
					'real-stock-manager-for-woocommerce'
				),
			)
		);
	}

	/**
	 * Déclare le statut auprès de WooCommerce.
	 *
	 * Inséré juste après « En cours » pour respecter l'ordre du flux. C'est ce
	 * tableau qui alimente les vues de la liste, le sélecteur de statut de la
	 * fiche commande et la validation des actions groupées.
	 *
	 * @param array $statuses Statuts existants, clés préfixées « wc- ».
	 *
	 * @return array
	 */
	public static function add_to_order_statuses( $statuses ) {
		$out = array();

		foreach ( (array) $statuses as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'wc-processing' === $key ) {
				$out[ Legacy::STATUS_FULL ] = self::label();
			}
		}

		if ( ! isset( $out[ Legacy::STATUS_FULL ] ) ) {
			$out[ Legacy::STATUS_FULL ] = self::label();
		}

		return $out;
	}

	/**
	 * Maintient la commande dans les statuts « payés ».
	 *
	 * Sans cela elle sortirait des rapports de chiffre d'affaires et certaines
	 * extensions la considéreraient comme impayée. Le slug attendu ici est nu,
	 * sans préfixe « wc- ».
	 *
	 * @param array $statuses Statuts payés.
	 *
	 * @return array
	 */
	public static function add_to_paid_statuses( $statuses ) {
		$statuses[] = Legacy::STATUS_SLUG;

		return $statuses;
	}

	/**
	 * Inclut le statut dans les anciens rapports WooCommerce.
	 *
	 * Note : WooCommerce → Analytics ignore ce filtre et s'appuie sur ses propres
	 * options. Le statut n'y apparaîtra donc pas — comportement inchangé.
	 *
	 * @param array $statuses Statuts pris en compte.
	 *
	 * @return array
	 */
	public static function add_to_report_statuses( $statuses ) {
		$statuses[] = Legacy::STATUS_SLUG;

		return $statuses;
	}

	/**
	 * Ajoute l'action groupée.
	 *
	 * Aucun gestionnaire à écrire : WooCommerce traite nativement toute action
	 * nommée « mark_{slug} » dès lors que le statut figure dans wc_get_order_statuses().
	 * En ajouter un provoquerait une double transition.
	 *
	 * @param array $actions Actions groupées.
	 *
	 * @return array
	 */
	public static function add_bulk_action( $actions ) {
		$actions[ 'mark_' . Legacy::STATUS_SLUG ] = sprintf(
			/* translators: %s: libellé du statut. */
			__( 'Marquer %s', 'real-stock-manager-for-woocommerce' ),
			self::label()
		);

		return $actions;
	}

	/**
	 * Force l'affichage du filtre en haut de la liste des commandes.
	 *
	 * WordPress et WooCommerce masquent les vues dont le compteur vaut zéro. Le
	 * lien n'apparaîtrait donc qu'une fois la première commande basculée, ce qui
	 * donne l'impression que le statut n'existe pas.
	 *
	 * Le garde `isset()` est essentiel : dès que le compteur dépasse zéro, la vue
	 * est générée nativement et l'injecter à nouveau produirait un lien en double.
	 *
	 * @param array $views Vues existantes.
	 *
	 * @return array
	 */
	public static function inject_view( $views ) {
		$key = Legacy::STATUS_FULL;

		if ( isset( $views[ $key ] ) ) {
			return $views;
		}

		$count = self::order_count();

		if ( self::hpos_enabled() ) {
			$url = admin_url( 'admin.php?page=wc-orders&status=' . $key );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture de contexte d'affichage.
			$current = isset( $_GET['status'] ) && $key === sanitize_text_field( wp_unslash( $_GET['status'] ) );
		} else {
			$url = admin_url( 'edit.php?post_type=shop_order&post_status=' . $key );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture de contexte d'affichage.
			$current = isset( $_GET['post_status'] ) && $key === sanitize_text_field( wp_unslash( $_GET['post_status'] ) );
		}

		$link = sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( self::label() ),
			esc_html( number_format_i18n( $count ) )
		);

		$out = array();

		foreach ( (array) $views as $slug => $view ) {
			$out[ $slug ] = $view;

			if ( 'wc-processing' === $slug ) {
				$out[ $key ] = $link;
			}
		}

		if ( ! isset( $out[ $key ] ) ) {
			$out[ $key ] = $link;
		}

		return $out;
	}

	/**
	 * Charge la pastille colorée de la colonne Statut.
	 */
	public static function enqueue_style(): void {
		wp_enqueue_style(
			'rsmw-order-status',
			RSMW_URL . 'assets/css/order-status.css',
			array(),
			RSMW_VERSION
		);
	}

	/**
	 * Le statut est-il correctement enregistré auprès de WordPress ?
	 *
	 * @return bool
	 */
	public static function is_registered(): bool {
		$object = get_post_status_object( Legacy::STATUS_FULL );

		return $object && ! empty( $object->show_in_admin_status_list );
	}

	/**
	 * Le statut est-il déclaré auprès de WooCommerce ?
	 *
	 * @return bool
	 */
	public static function is_declared(): bool {
		return array_key_exists( Legacy::STATUS_FULL, wc_get_order_statuses() );
	}

	/**
	 * Nombre de commandes portant le statut.
	 *
	 * @return int
	 */
	public static function order_count(): int {
		return function_exists( 'wc_orders_count' ) ? (int) wc_orders_count( Legacy::STATUS_SLUG ) : 0;
	}

	/**
	 * Le stockage haute performance des commandes est-il actif ?
	 *
	 * @return bool
	 */
	public static function hpos_enabled(): bool {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
