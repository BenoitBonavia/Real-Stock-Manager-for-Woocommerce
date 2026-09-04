<?php
/**
 * Colonne « Précommande » de la liste des commandes.
 *
 * @package RealStockManager
 */

namespace RSMW\PreOrder\Admin;

use RSMW\PreOrder\Dates;
use RSMW\PreOrder\Marker;

defined( 'ABSPATH' ) || exit;

/**
 * Rend une précommande repérable sans clic, en balayant la liste des commandes.
 *
 * C'est la contrepartie de la suppression de la bascule automatique de statut.
 * Le snippet remplacé faisait basculer la commande vers « Précommande », ce qui
 * colorait la colonne Statut : le marchand repérait donc ses précommandes d'un
 * coup d'œil. La bascule est partie — à raison, un statut ne peut pas porter à
 * la fois l'historique et l'état courant — mais le repérage visuel doit revenir.
 *
 * La colonne ne lit QUE le drapeau posé au niveau de la commande. Aucun test de
 * statut nulle part : c'est ce qui garantit qu'une commande expédiée ou terminée
 * continue d'afficher la puce, là précisément où le snippet perdait l'information
 * et où la colonne « Préparation » se tait.
 */
final class OrdersColumn {

	/**
	 * Identifiant de la colonne.
	 */
	private const COLUMN = 'rsmw_preorder';

	/**
	 * Accroche la colonne et son rendu.
	 */
	public static function register(): void {
		/*
		 * Sous HPOS on passe par le filtre indexé sur le TYPE de commande, et non
		 * par `manage_{écran}_columns`. L'identifiant d'écran vaut en effet
		 * `woocommerce_page_wc-orders` ou `admin_page_wc-orders` selon que
		 * l'utilisateur voit ou non l'entrée de menu WooCommerce
		 * (wc_get_page_screen_id, includes/admin/wc-admin-functions.php) : un
		 * filtre écrit en dur sur le premier disparaît pour les autres profils.
		 */
		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'add_column' ), 15 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 15 );

		// Sous HPOS le second argument est un WC_Order déjà hydraté ; en stockage
		// historique c'est un identifiant de publication. Même rappel : on tranche
		// sur le TYPE de l'argument, jamais sur une détection de mode de stockage.
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render' ), 10, 2 );
	}

	/**
	 * Insère la colonne après celle du statut.
	 *
	 * L'ordre final est « Statut | Préparation | Précommande » sur les deux modes
	 * de stockage, sans jamais nommer la clé de la colonne Préparation, qui est
	 * une constante privée d'un autre module :
	 *
	 * - sous HPOS, ce filtre-ci est appliqué à l'intérieur de ListTable::get_columns(),
	 *   elle-même accrochée en priorité 0 de `manage_{écran}_columns` — donc avant
	 *   toute autre entrée, quelle que soit la priorité écrite ici ;
	 * - en stockage historique, les deux colonnes partagent le même filtre et c'est
	 *   la priorité 15 contre 20 qui nous fait passer en premier.
	 *
	 * Dans les deux cas, la colonne « Préparation » s'insère ensuite juste après
	 * « Statut » et repousse la nôtre d'un cran. Deux marques bordeaux — la pastille
	 * du statut « Précommande » et notre puce — ne sont ainsi jamais adjacentes.
	 *
	 * @param array $columns Colonnes existantes.
	 *
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns = (array) $columns;

		// Les deux filtres ne se déclenchent jamais sur la même requête, mais un
		// tiers peut très bien rappeler celui-ci : l'ajout doit rester sans effet.
		if ( isset( $columns[ self::COLUMN ] ) ) {
			return $columns;
		}

		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$out[ self::COLUMN ] = self::header();
			}
		}

		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = self::header();
		}

		return $out;
	}

	/**
	 * Libellé de l'en-tête de colonne.
	 *
	 * @return string
	 */
	private static function header(): string {
		return __( 'Précommande', 'real-stock-manager-for-woocommerce' );
	}

	/**
	 * Rend la cellule.
	 *
	 * @param string        $column      Colonne en cours de rendu.
	 * @param \WC_Order|int $order_or_id Commande (HPOS) ou identifiant (historique).
	 */
	public static function render( $column, $order_or_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		if ( ! Marker::order_has_preorder( $order_or_id ) ) {
			// Même vocabulaire d'absence que la colonne « Préparation » : les deux
			// colonnes doivent se taire de la même façon.
			echo '<span class="rsmw-preorder-flag__none" aria-hidden="true">·</span>';

			return;
		}

		$raw   = Marker::order_preorder_date( $order_or_id );
		$short = Dates::format( $raw, 'j M' );
		$long  = Dates::format( $raw, 'j F Y' );

		/*
		 * « annoncée » et non « prévue » : cette date est celle qui a été promise
		 * au client au moment de l'achat. Elle est figée, elle n'est jamais
		 * recalculée depuis la fiche produit.
		 */
		$title = '' !== $long
			? sprintf(
				/* translators: %s: date d'expédition annoncée au client. */
				__( 'Contient des articles précommandés — expédition annoncée le %s', 'real-stock-manager-for-woocommerce' ),
				$long
			)
			: __( 'Contient des articles précommandés — aucune date annoncée', 'real-stock-manager-for-woocommerce' );

		$text = '<span class="rsmw-preorder-flag__label">'
			. esc_html__( 'Précommande', 'real-stock-manager-for-woocommerce' )
			. '</span>';

		if ( '' !== $short ) {
			$text .= '<span class="rsmw-preorder-flag__date">' . esc_html( $short ) . '</span>';
		}

		printf(
			'<span class="rsmw-preorder-flag" title="%1$s">'
				. '<span class="rsmw-preorder-flag__dot" aria-hidden="true"></span>'
				. '%2$s'
			. '</span>',
			esc_attr( $title ),
			wp_kses_post( $text )
		);
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
