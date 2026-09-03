<?php
/**
 * Page « Gestion stock » : entrées et retraits.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Allocator;
use RSMW\Preparation\Journal;
use RSMW\Preparation\Labels;
use RSMW\Preparation\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * Saisie des mouvements de stock physique.
 *
 * Une entrée est affectée automatiquement aux commandes les plus anciennes qui
 * l'attendent ; une sortie puise d'abord dans le stock libre, puis reprend aux
 * commandes les plus récentes.
 */
final class StockPage {

	/**
	 * Affiche la page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Droits insuffisants.', 'real-stock-manager-for-woocommerce' ) );
		}

		$errors = array();

		$incoming = self::handle_receive( $errors );
		$outgoing = self::handle_withdraw( $errors );

		View::render(
			'stock-page',
			array(
				'incoming'      => $incoming,
				'outgoing'      => $outgoing,
				'errors'        => $errors,
				'journal'       => Journal::all(),
				'reasons'       => self::reasons(),
				'search_nonce'  => wp_create_nonce( 'search-products' ),
				'needs_page_url' => admin_url( 'admin.php?page=' . Legacy::PAGE_NEEDS ),
			)
		);
	}

	/**
	 * Motifs de retrait proposés.
	 *
	 * @return array<string, string>
	 */
	private static function reasons(): array {
		return array(
			'défaut'                  => __( 'Défaut', 'real-stock-manager-for-woocommerce' ),
			'casse'                   => __( 'Casse', 'real-stock-manager-for-woocommerce' ),
			'perte'                   => __( 'Perte', 'real-stock-manager-for-woocommerce' ),
			'retour fournisseur'      => __( 'Retour fournisseur', 'real-stock-manager-for-woocommerce' ),
			'correction d’inventaire' => __( 'Correction d’inventaire', 'real-stock-manager-for-woocommerce' ),
		);
	}

	/**
	 * Traite le formulaire d'entrée en stock.
	 *
	 * @param array $errors Messages d'erreur, complétés par référence.
	 *
	 * @return array|null Compte rendu, ou null si aucune demande.
	 */
	private static function handle_receive( array &$errors ): ?array {
		if ( ! isset( $_POST['mh_prep_receive'] ) ) {
			return null;
		}

		check_admin_referer( 'mh_prep_receive' );

		$product_id = self::resolve_product( 'mh_prep_product', 'mh_prep_sku' );
		$quantity   = isset( $_POST['mh_prep_qty'] ) ? absint( wp_unslash( $_POST['mh_prep_qty'] ) ) : 0;

		if ( $product_id <= 0 || $quantity <= 0 ) {
			$errors[] = __( 'Produit introuvable ou quantité vide. Rien n’a été enregistré.', 'real-stock-manager-for-woocommerce' );

			return null;
		}

		$report = Allocator::receive( $product_id, $quantity );

		Journal::add(
			array(
				'time'   => time(),
				'user'   => wp_get_current_user()->display_name,
				'type'   => 'in',
				'label'  => self::reference_label( $product_id ),
				'qty'    => $quantity,
				'orders' => $report['affecte'],
				'libre'  => $report['libre'],
				'motif'  => '',
			)
		);

		return $report;
	}

	/**
	 * Traite le formulaire de retrait de stock.
	 *
	 * @param array $errors Messages d'erreur, complétés par référence.
	 *
	 * @return array|null Compte rendu, ou null si aucune demande.
	 */
	private static function handle_withdraw( array &$errors ): ?array {
		if ( ! isset( $_POST['mh_prep_withdraw'] ) ) {
			return null;
		}

		check_admin_referer( 'mh_prep_withdraw' );

		$product_id = self::resolve_product( 'mh_prep_out_product', 'mh_prep_out_sku' );
		$quantity   = isset( $_POST['mh_prep_out_qty'] ) ? absint( wp_unslash( $_POST['mh_prep_out_qty'] ) ) : 0;
		$reason     = isset( $_POST['mh_prep_out_motif'] ) ? sanitize_text_field( wp_unslash( $_POST['mh_prep_out_motif'] ) ) : '';

		if ( $product_id <= 0 || $quantity <= 0 ) {
			$errors[] = __( 'Produit introuvable ou quantité vide. Rien n’a été enregistré.', 'real-stock-manager-for-woocommerce' );

			return null;
		}

		$report = Allocator::withdraw( $product_id, $quantity, $reason );

		Journal::add(
			array(
				'time'   => time(),
				'user'   => wp_get_current_user()->display_name,
				'type'   => 'out',
				'label'  => self::reference_label( $product_id ),
				'qty'    => $report['du_libre'] + $report['repris'],
				'orders' => $report['repris'],
				'libre'  => $report['libre'],
				'motif'  => $reason,
			)
		);

		return $report;
	}

	/**
	 * Libellé complet d'une référence, pour le journal.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return string
	 */
	private static function reference_label( int $product_id ): string {
		$info = Labels::get( $product_id );

		return trim( $info['name'] . ( '' !== $info['variant'] ? ' — ' . $info['variant'] : '' ) );
	}

	/**
	 * Résout un produit depuis le champ de recherche, avec repli SKU puis identifiant.
	 *
	 * Le nonce du formulaire est vérifié par l'appelant.
	 *
	 * @param string $select_field Nom du champ de recherche.
	 * @param string $sku_field    Nom du champ SKU.
	 *
	 * @return int Identifiant du produit, ou 0 si introuvable.
	 */
	private static function resolve_product( string $select_field, string $sku_field ): int {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- vérifié par l'appelant via check_admin_referer().
		$product_id = isset( $_POST[ $select_field ] ) ? absint( wp_unslash( $_POST[ $select_field ] ) ) : 0;

		if ( $product_id <= 0 && ! empty( $_POST[ $sku_field ] ) ) {
			$raw        = sanitize_text_field( wp_unslash( $_POST[ $sku_field ] ) );
			$product_id = (int) wc_get_product_id_by_sku( $raw );

			if ( $product_id <= 0 && ctype_digit( $raw ) ) {
				$product_id = (int) $raw;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return ( $product_id > 0 && wc_get_product( $product_id ) ) ? $product_id : 0;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
