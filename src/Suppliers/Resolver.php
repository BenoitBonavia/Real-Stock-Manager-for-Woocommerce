<?php
/**
 * Résolution du fournisseur d'une référence.
 *
 * @package RealStockManager
 */

namespace RSMW\Suppliers;

defined( 'ABSPATH' ) || exit;

/**
 * Retrouve le fournisseur de chaque référence, en une seule requête.
 *
 * Une « référence » du module de préparation est une identité de STOCK : l'ID de
 * la variation quand la ligne de commande en porte une, sinon celui du produit.
 * Or la taxonomie est rattachée au produit parent — un fournisseur fournit une
 * référence achetée, pas une taille. La résolution remonte donc au parent, ce
 * qui est aussi le patron de WooCommerce pour la classe de livraison d'une
 * variation.
 *
 * Conséquence assumée : toutes les déclinaisons d'un produit variable partagent
 * un fournisseur.
 */
final class Resolver {

	/**
	 * Fournisseur applicable à chaque référence.
	 *
	 * UNE requête pour toute la page, quel que soit le nombre de lignes. On évite
	 * délibérément `wc_get_product_terms()`, qui retombe sur `wp_get_post_terms()`
	 * et coûte une requête PAR produit — invisible sur une fiche, ruineux sur un
	 * tableau de plusieurs centaines de références.
	 *
	 * @param array<int, int> $reference_ids Références (produits ou variations).
	 * @param array<int, int> $parents       Parent de chaque référence, si connu,
	 *                                       indexé par référence. Le module de
	 *                                       préparation le tient déjà de sa requête
	 *                                       de demande : le transmettre évite de le
	 *                                       relire.
	 *
	 * @return array<int, \WP_Term> Fournisseur par référence. Les références sans
	 *                              fournisseur sont absentes du tableau.
	 */
	public static function map_for( array $reference_ids, array $parents = array() ): array {
		$reference_ids = array_values( array_unique( array_filter( array_map( 'intval', $reference_ids ) ) ) );

		if ( empty( $reference_ids ) || ! taxonomy_exists( Taxonomy::NAME ) ) {
			return array();
		}

		$owner = self::owners( $reference_ids, $parents );

		$terms = wp_get_object_terms(
			array_values( array_unique( $owner ) ),
			Taxonomy::NAME,
			array(
				'fields'                 => 'all_with_object_id',
				'update_term_meta_cache' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		// Un produit n'a qu'un fournisseur : le premier terme rencontré fait foi.
		// L'unicité est garantie à l'écriture, pas par le schéma — une affectation
		// multiple héritée d'un import ne doit pas pour autant fausser la page.
		$by_owner = array();

		foreach ( $terms as $term ) {
			if ( ! isset( $by_owner[ (int) $term->object_id ] ) ) {
				$by_owner[ (int) $term->object_id ] = $term;
			}
		}

		$map = array();

		foreach ( $owner as $reference_id => $owner_id ) {
			if ( isset( $by_owner[ $owner_id ] ) ) {
				$map[ $reference_id ] = $by_owner[ $owner_id ];
			}
		}

		return $map;
	}

	/**
	 * Produit porteur du fournisseur, pour chaque référence.
	 *
	 * @param array<int, int> $reference_ids Références.
	 * @param array<int, int> $parents       Parents déjà connus, indexés par référence.
	 *
	 * @return array<int, int> Identifiant du porteur, indexé par référence.
	 */
	private static function owners( array $reference_ids, array $parents ): array {
		$owner   = array();
		$unknown = array();

		foreach ( $reference_ids as $reference_id ) {
			$parent = isset( $parents[ $reference_id ] ) ? (int) $parents[ $reference_id ] : 0;

			if ( $parent > 0 ) {
				$owner[ $reference_id ] = $parent;

				continue;
			}

			$unknown[] = $reference_id;
		}

		if ( empty( $unknown ) ) {
			return $owner;
		}

		/*
		 * Repli pour les références dont l'appelant ne connaît pas le parent —
		 * typiquement celles qui viennent du stock commandé sans aucune demande
		 * client. `get_post()` lit le cache que Labels::prime() a déjà amorcé.
		 */
		foreach ( $unknown as $reference_id ) {
			$post = get_post( $reference_id );

			$owner[ $reference_id ] = ( $post && 'product_variation' === $post->post_type && $post->post_parent )
				? (int) $post->post_parent
				: $reference_id;
		}

		return $owner;
	}

	/**
	 * Tous les fournisseurs déclarés, y compris ceux sans aucun produit.
	 *
	 * Sert à construire la liste blanche des onglets : un fournisseur momentanément
	 * sans rien à commander doit rester atteignable par son adresse, sinon un
	 * signet posé la semaine précédente retomberait silencieusement sur « Général ».
	 *
	 * @return \WP_Term[]
	 */
	public static function all(): array {
		if ( ! taxonomy_exists( Taxonomy::NAME ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => Taxonomy::NAME,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		return is_wp_error( $terms ) ? array() : (array) $terms;
	}

	/**
	 * Fournisseur d'un seul produit, pour la fiche produit.
	 *
	 * @param int $product_id Produit.
	 *
	 * @return int Identifiant du terme, 0 si aucun.
	 */
	public static function term_id_for( $product_id ): int {
		$product_id = (int) $product_id;

		if ( $product_id <= 0 || ! taxonomy_exists( Taxonomy::NAME ) ) {
			return 0;
		}

		$terms = get_the_terms( $product_id, Taxonomy::NAME );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}

		$first = current( $terms );

		return $first instanceof \WP_Term ? (int) $first->term_id : 0;
	}

	/**
	 * Affecte un fournisseur à un produit, ou le détache.
	 *
	 * `$append` à false et un tableau d'un seul élément : c'est ainsi que
	 * WooCommerce impose l'unicité de la classe de livraison. Le schéma, lui,
	 * autorise plusieurs termes — l'unicité se tient à l'écriture et à la lecture.
	 *
	 * @param int $product_id Produit.
	 * @param int $term_id    Fournisseur, ou 0 pour détacher.
	 */
	public static function assign( $product_id, $term_id ): void {
		$product_id = (int) $product_id;
		$term_id    = (int) $term_id;

		if ( $product_id <= 0 || ! taxonomy_exists( Taxonomy::NAME ) ) {
			return;
		}

		$terms = $term_id > 0 && term_exists( $term_id, Taxonomy::NAME ) ? array( $term_id ) : array();

		wp_set_object_terms( $product_id, $terms, Taxonomy::NAME, false );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
