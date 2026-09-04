<?php
/**
 * Statut de commande « Précommande ».
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Déclare le statut auprès de WordPress puis de WooCommerce.
 *
 * Enregistré indépendamment du module : des commandes en production portent déjà
 * ce statut. Si le module était désactivé sans que le statut reste enregistré,
 * elles disparaîtraient des écrans d'administration — la liste HPOS croise
 * `wc_get_order_statuses()` avec `get_post_stati()` — et sortiraient du périmètre
 * « à préparer » du module Préparation.
 *
 * Depuis la reprise des snippets, ce statut ne porte PLUS la traçabilité : il
 * n'est qu'un état de flux, posé à la main quand le marchand veut communiquer.
 * La trace vit dans les métas, cf. Marker.
 */
final class OrderStatus {

	/**
	 * Accroche la déclaration du statut.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_status' ), 9 );

		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_to_order_statuses' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_style' ) );

		/*
		 * Sémantique du statut, enregistrée ICI et non dans le module.
		 *
		 * Ces trois filtres décrivent ce que « Précommande » SIGNIFIE pour
		 * WooCommerce, pas la façon dont il est posé. Les laisser dans le module
		 * ferait qu'en le désactivant, les commandes qui portent le statut
		 * sortiraient des rapports et perdraient leurs téléchargements — alors
		 * qu'elles n'ont pas bougé.
		 */
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'add_status' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'add_status' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( __CLASS__, 'add_status' ) );
		add_filter( 'woocommerce_order_is_download_permitted', array( __CLASS__, 'allow_downloads' ), 10, 2 );
	}

	/**
	 * Ajoute le statut à une liste de statuts de WooCommerce.
	 *
	 * @param array $statuses Statuts existants.
	 *
	 * @return array
	 */
	public static function add_status( $statuses ) {
		$statuses = (array) $statuses;

		if ( ! in_array( Legacy::STATUS_SLUG, $statuses, true ) ) {
			$statuses[] = Legacy::STATUS_SLUG;
		}

		return $statuses;
	}

	/**
	 * Accorde les téléchargements comme si la commande était « En cours ».
	 *
	 * `WC_Order::is_download_permitted()` teste « Terminée » ou « En cours » en
	 * dur, sans passer par une liste filtrable : une précommande perdrait donc
	 * l'accès aux fichiers. On réplique exactement la règle de « En cours »,
	 * option de la boutique comprise.
	 *
	 * @param bool            $permitted Décision de WooCommerce.
	 * @param \WC_Order|mixed $order     Commande.
	 *
	 * @return bool
	 */
	public static function allow_downloads( $permitted, $order ) {
		if ( $permitted || ! $order instanceof \WC_Order ) {
			return $permitted;
		}

		if ( ! $order->has_status( Legacy::STATUS_SLUG ) ) {
			return $permitted;
		}

		return 'yes' === get_option( 'woocommerce_downloads_grant_access_after_payment' );
	}

	/**
	 * Libellé du statut.
	 *
	 * @return string
	 */
	public static function label(): string {
		return _x( 'Précommande', 'Statut de commande', 'real-stock-manager-for-woocommerce' );
	}

	/**
	 * Enregistre le post status auprès de WordPress.
	 *
	 * `public` vaut false, comme pour tous les statuts de WooCommerce et pour le
	 * statut « À empaqueter » de ce plugin. Le snippet remplacé mettait true, ce
	 * qui n'a aucun effet visible sur un type de contenu non public — et aucun du
	 * tout sous HPOS, où les commandes ne sont plus des publications.
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
					'Précommande <span class="count">(%s)</span>',
					'Précommandes <span class="count">(%s)</span>',
					'real-stock-manager-for-woocommerce'
				),
			)
		);
	}

	/**
	 * Déclare le statut auprès de WooCommerce.
	 *
	 * Inséré juste après « En attente de paiement », comme dans le snippet.
	 *
	 * Depuis la 0.7.0, il EST ajouté à `woocommerce_order_is_paid_statuses`
	 * (cf. register). Le snippet remplacé ne le faisait pas, et je m'étais aligné
	 * sur lui — à tort dès lors que la bascule est automatique : une commande
	 * peut alors séjourner des semaines dans ce statut en attendant le
	 * fournisseur, et son chiffre d'affaires sortait des rapports pendant tout ce
	 * temps alors que l'argent est encaissé. La correction est rétroactive, ce
	 * qui répare une sous-estimation au lieu d'en créer une.
	 *
	 * @param array $statuses Statuts existants, clés préfixées « wc- ».
	 *
	 * @return array
	 */
	public static function add_to_order_statuses( $statuses ) {
		$out = array();

		foreach ( (array) $statuses as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'wc-pending' === $key ) {
				$out[ Legacy::STATUS_FULL ] = self::label();
			}
		}

		if ( ! isset( $out[ Legacy::STATUS_FULL ] ) ) {
			$out[ Legacy::STATUS_FULL ] = self::label();
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
	 * Nombre de commandes portant le statut.
	 *
	 * @return int
	 */
	public static function order_count(): int {
		return function_exists( 'wc_orders_count' ) ? (int) wc_orders_count( Legacy::STATUS_SLUG ) : 0;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
