<?php
/**
 * Taxonomie « Fournisseur ».
 *
 * @package RealStockManager
 */

namespace RSMW\Suppliers;

defined( 'ABSPATH' ) || exit;

/**
 * Déclare le fournisseur comme une taxonomie de produit.
 *
 * Le choix de la taxonomie plutôt que d'un type de contenu tient en deux points.
 *
 * `show_ui => true` ouvre `wp-admin/edit-tags.php`, qui ne teste rien d'autre que
 * cette propriété et la capacité : créer, renommer et supprimer un fournisseur
 * est donc écrit par WordPress. Le contre-exemple chiffre l'alternative — la
 * classe de livraison de WooCommerce a `show_ui => false`, et il a fallu lui
 * réécrire tout un écran en Backbone et en AJAX.
 *
 * Surtout, `wp_delete_term()` détache proprement chaque produit du terme
 * supprimé. Un type de contenu laisserait, lui, des identifiants pointant dans
 * le vide sur des centaines de produits, à charge d'écrire le ménage soi-même.
 *
 * Enregistrée HORS module, comme les statuts de commande : une taxonomie qui
 * porte des données doit rester déclarée, sinon les fournisseurs et leur
 * affectation disparaissent des écrans d'administration.
 */
final class Taxonomy {

	/**
	 * Identifiant de la taxonomie.
	 */
	public const NAME = 'rsmw_supplier';

	/**
	 * Accroche l'enregistrement et l'entrée de menu.
	 */
	public static function register(): void {
		// Priorité 6 : WooCommerce déclare les siennes sur `init` en 5, et le type
		// de contenu `product` doit exister avant qu'on s'y rattache.
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ), 6 );

		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 61 );
		add_filter( 'parent_file', array( __CLASS__, 'keep_menu_open' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'keep_submenu_open' ), 10, 2 );
	}

	/**
	 * Déclare la taxonomie.
	 */
	public static function register_taxonomy(): void {
		register_taxonomy(
			self::NAME,
			array( 'product' ),
			array(
				'labels'                => self::labels(),

				/*
				 * Un fournisseur n'a pas de parent. Le caractère non hiérarchique
				 * choisirait par défaut la métabox à saisie libre, qui créerait des
				 * doublons à la moindre faute de frappe : `meta_box_cb => false`
				 * l'écarte, le champ vit dans l'onglet Inventaire, en liste fermée.
				 */
				'hierarchical'          => false,
				'meta_box_cb'           => false,

				// Donnée interne : ni archive publique, ni réécriture d'URL.
				'public'                => false,
				'publicly_queryable'    => false,
				'show_in_nav_menus'     => false,
				'query_var'             => false,
				'rewrite'               => false,

				/*
				 * show_ui ouvre l'écran natif de gestion des termes ; show_in_menu
				 * l'empêche d'apparaître sous « Produits ». Les deux sont
				 * indépendants — c'est exactement la combinaison que WooCommerce
				 * utilise pour ses attributs de produit.
				 */
				'show_ui'               => true,
				'show_in_menu'          => false,
				'show_in_quick_edit'    => false,
				'show_admin_column'     => true,

				'update_count_callback' => '_wc_term_recount',
				'capabilities'          => array(
					'manage_terms' => 'manage_woocommerce',
					'edit_terms'   => 'manage_woocommerce',
					'delete_terms' => 'manage_woocommerce',
					'assign_terms' => 'manage_woocommerce',
				),
			)
		);
	}

	/**
	 * Libellés de l'écran de gestion.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return array(
			'name'                       => _x( 'Fournisseurs', 'Taxonomie', 'real-stock-manager-for-woocommerce' ),
			'singular_name'              => _x( 'Fournisseur', 'Taxonomie', 'real-stock-manager-for-woocommerce' ),
			'menu_name'                  => __( 'Fournisseurs', 'real-stock-manager-for-woocommerce' ),
			'search_items'               => __( 'Rechercher un fournisseur', 'real-stock-manager-for-woocommerce' ),
			'all_items'                  => __( 'Tous les fournisseurs', 'real-stock-manager-for-woocommerce' ),
			'edit_item'                  => __( 'Modifier le fournisseur', 'real-stock-manager-for-woocommerce' ),
			'update_item'                => __( 'Mettre à jour le fournisseur', 'real-stock-manager-for-woocommerce' ),
			'add_new_item'               => __( 'Ajouter un fournisseur', 'real-stock-manager-for-woocommerce' ),
			'new_item_name'              => __( 'Nom du nouveau fournisseur', 'real-stock-manager-for-woocommerce' ),
			'not_found'                  => __( 'Aucun fournisseur pour l’instant.', 'real-stock-manager-for-woocommerce' ),
			'no_terms'                   => __( 'Aucun fournisseur', 'real-stock-manager-for-woocommerce' ),
			'back_to_items'              => __( '← Retour aux fournisseurs', 'real-stock-manager-for-woocommerce' ),
			'separate_items_with_commas' => __( 'Un seul fournisseur par produit.', 'real-stock-manager-for-woocommerce' ),
		);
	}

	/**
	 * Adresse de l'écran de gestion des fournisseurs.
	 *
	 * @return string
	 */
	public static function manage_url(): string {
		return admin_url( 'edit-tags.php?taxonomy=' . self::NAME . '&post_type=product' );
	}

	/**
	 * Slug d'administration de l'écran de gestion, tel que WordPress l'attend.
	 *
	 * @return string
	 */
	private static function menu_slug(): string {
		return 'edit-tags.php?taxonomy=' . self::NAME . '&post_type=product';
	}

	/**
	 * Ajoute l'entrée « Fournisseurs » sous le menu WooCommerce.
	 *
	 * Le slug contient « .php » : WordPress le traite alors comme un fichier à
	 * ouvrir, et non comme une page à rendre par un rappel. C'est le mécanisme
	 * standard, celui-là même qui place les attributs de produit sous Produits.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Fournisseurs', 'real-stock-manager-for-woocommerce' ),
			__( 'Fournisseurs', 'real-stock-manager-for-woocommerce' ),
			'manage_woocommerce',
			self::menu_slug(),
			''
		);
	}

	/**
	 * Cette requête est-elle l'écran de gestion des fournisseurs ?
	 *
	 * @return bool
	 */
	private static function is_manage_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture de contexte d'affichage.
		return isset( $_GET['taxonomy'] ) && self::NAME === sanitize_key( wp_unslash( $_GET['taxonomy'] ) );
	}

	/**
	 * Garde le menu WooCommerce ouvert sur l'écran des fournisseurs.
	 *
	 * `edit-tags.php` force le menu parent sur « Produits », puisque la taxonomie
	 * y est rattachée. Sans ces deux filtres, cliquer sur « Fournisseurs » depuis
	 * WooCommerce refermerait ce menu et en ouvrirait un autre — l'utilisateur
	 * aurait l'impression d'avoir changé de section.
	 *
	 * @param string $parent_file Fichier parent calculé par WordPress.
	 *
	 * @return string
	 */
	public static function keep_menu_open( $parent_file ) {
		return self::is_manage_screen() ? 'woocommerce' : $parent_file;
	}

	/**
	 * Surligne l'entrée « Fournisseurs » du menu WooCommerce.
	 *
	 * @param string $submenu_file Entrée calculée par WordPress.
	 * @param string $parent_file  Fichier parent.
	 *
	 * @return string
	 */
	public static function keep_submenu_open( $submenu_file, $parent_file ) {
		unset( $parent_file );

		return self::is_manage_screen() ? self::menu_slug() : $submenu_file;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
