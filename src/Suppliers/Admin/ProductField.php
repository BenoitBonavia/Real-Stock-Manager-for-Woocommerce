<?php
/**
 * Champ « Fournisseur » sur la fiche produit.
 *
 * @package RealStockManager
 */

namespace RSMW\Suppliers\Admin;

use RSMW\Suppliers\Resolver;
use RSMW\Suppliers\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Un fournisseur, et un seul, par produit.
 *
 * Le champ est posé sur le PRODUIT, jamais sur la variation : un fournisseur
 * fournit une référence achetée, pas une taille. Toutes les déclinaisons d'un
 * produit variable partagent donc son fournisseur, et la page « Besoins & stock »
 * remonte au parent pour l'afficher.
 *
 * Volontairement hors des classes `show_if_simple` : le champ doit apparaître sur
 * tous les types de produit, à commencer par les produits variables, qui sont
 * justement ceux dont les variations peuplent la table des besoins.
 */
final class ProductField {

	/**
	 * Nom du champ dans le formulaire.
	 */
	private const FIELD = 'rsmw_supplier_term';

	/**
	 * Accroche le champ et son enregistrement.
	 */
	public static function register(): void {
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'render' ), 20 );

		// Priorité 20 : après le CRUD de WooCommerce, accroché en 10, pour la même
		// raison que les autres champs de l'extension.
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ), 20 );
	}

	/**
	 * Affiche la liste déroulante.
	 */
	public static function render(): void {
		global $post;

		if ( ! $post ) {
			return;
		}

		$options = array( '' => __( '— Aucun fournisseur —', 'real-stock-manager-for-woocommerce' ) );

		foreach ( Resolver::all() as $term ) {
			$options[ (string) $term->term_id ] = $term->name;
		}

		echo '<div class="options_group">';

		woocommerce_wp_select(
			array(
				'id'          => self::FIELD,
				'label'       => __( 'Fournisseur', 'real-stock-manager-for-woocommerce' ),
				'options'     => $options,

				/*
				 * `value` est passé EXPLICITEMENT. Sans cela, woocommerce_wp_select()
				 * irait lire une métadonnée du produit portant l'identifiant du champ
				 * — or le fournisseur est une taxonomie, pas une méta : le champ
				 * s'afficherait toujours vide.
				 */
				'value'       => (string) ( Resolver::term_id_for( $post->ID ) ?: '' ),
				'desc_tip'    => false,
				'description' => self::hint(),
			)
		);

		echo '</div>';
	}

	/**
	 * Phrase de contexte affichée sous le champ.
	 *
	 * @return string
	 */
	private static function hint(): string {
		if ( empty( Resolver::all() ) ) {
			return sprintf(
				/* translators: %s: lien vers l'écran de gestion des fournisseurs. */
				__( 'Aucun fournisseur n’est encore enregistré. %s', 'real-stock-manager-for-woocommerce' ),
				'<a href="' . esc_url( Taxonomy::manage_url() ) . '">'
					. esc_html__( 'En créer un', 'real-stock-manager-for-woocommerce' )
					. '</a>'
			);
		}

		return __( 'Regroupe la référence dans l’onglet de ce fournisseur sur la page « Besoins & stock ». Sur un produit à variations, s’applique à toutes les déclinaisons.', 'real-stock-manager-for-woocommerce' );
	}

	/**
	 * Enregistre l'affectation.
	 *
	 * Le nonce est vérifié par WooCommerce avant ce hook.
	 *
	 * @param int $post_id Produit.
	 */
	public static function save( $post_id ): void {
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- vérifié en amont par WC_Meta_Box_Product_Data.
		if ( ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- idem.
		Resolver::assign( $post_id, (int) wp_unslash( $_POST[ self::FIELD ] ) );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
