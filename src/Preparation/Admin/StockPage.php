<?php
/**
 * Page « Gestion stock » : console de mouvement.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Allocator;
use RSMW\Preparation\Journal;
use RSMW\Preparation\Labels;
use RSMW\Preparation\Legacy;
use RSMW\Preparation\Stock;
use RSMW\Preparation\Supply;

defined( 'ABSPATH' ) || exit;

/**
 * Saisie des mouvements de stock physique.
 *
 * Un seul formulaire porte les deux sens : une entrée est affectée aux commandes
 * les plus anciennes qui l'attendent, une sortie puise d'abord dans le stock
 * libre puis reprend aux commandes les plus récentes.
 */
final class StockPage {

	/** Nonce du formulaire de mouvement. */
	private const NONCE = 'rsmw_stock_movement';

	/**
	 * Sens autorisés.
	 *
	 * `in` / `out` déplacent du stock physique ; `order` / `unorder` déplacent des
	 * quantités commandées au fournisseur, sans marchandise et sans effet sur le
	 * statut des commandes.
	 */
	private const DIRECTIONS = array( 'in', 'order', 'unorder', 'out' );

	/**
	 * Affiche la page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Droits insuffisants.', 'real-stock-manager-for-woocommerce' ) );
		}

		$errors   = array();
		$movement = self::handle_movement( $errors );

		View::render(
			'stock-page',
			array(
				'movement'       => $movement,
				'errors'         => $errors,
				'journal'        => Journal::all(),
				'reasons'        => self::reasons(),
				'nonce_field'    => wp_nonce_field( self::NONCE, '_wpnonce', true, false ),
				'search_nonce'   => wp_create_nonce( 'search-products' ),
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
	 * Traite le formulaire de mouvement.
	 *
	 * @param array $errors Messages d'erreur, complétés par référence.
	 *
	 * @return array|null Sens et compte rendu, ou null si aucune demande.
	 */
	private static function handle_movement( array &$errors ): ?array {
		if ( ! isset( $_POST['rsmw_stock_submit'] ) ) {
			return null;
		}

		check_admin_referer( self::NONCE );

		$direction = isset( $_POST['rsmw_movement_direction'] )
			? sanitize_key( wp_unslash( $_POST['rsmw_movement_direction'] ) )
			: '';

		if ( ! in_array( $direction, self::DIRECTIONS, true ) ) {
			$errors[] = __( 'Sens du mouvement invalide. Rien n’a été enregistré.', 'real-stock-manager-for-woocommerce' );

			return null;
		}

		$product_id = self::resolve_product( 'rsmw_movement_product', 'rsmw_movement_sku' );
		$quantity   = isset( $_POST['rsmw_movement_qty'] ) ? absint( wp_unslash( $_POST['rsmw_movement_qty'] ) ) : 0;

		if ( $product_id <= 0 ) {
			$errors[] = __( 'Référence introuvable. Choisissez-la dans la liste ou saisissez un SKU exact.', 'real-stock-manager-for-woocommerce' );
		}

		if ( $quantity <= 0 ) {
			$errors[] = __( 'La quantité doit être supérieure à zéro.', 'real-stock-manager-for-woocommerce' );
		}

		if ( ! empty( $errors ) ) {
			return null;
		}

		$reason = 'out' === $direction && isset( $_POST['rsmw_movement_reason'] )
			? sanitize_text_field( wp_unslash( $_POST['rsmw_movement_reason'] ) )
			: '';

		switch ( $direction ) {
			case 'in':
				$report   = Allocator::receive( $product_id, $quantity );
				$moved    = $quantity;
				$affected = (int) $report['affecte'];
				break;

			case 'order':
				$report   = Allocator::order_from_supplier( $product_id, $quantity );
				$moved    = $quantity;
				$affected = (int) $report['affecte'];
				break;

			case 'unorder':
				$report   = Allocator::cancel_supplier_order( $product_id, $quantity );
				$moved    = (int) $report['du_libre'] + (int) $report['repris'];
				$affected = (int) $report['repris'];
				break;

			default:
				$report   = Allocator::withdraw( $product_id, $quantity, $reason );
				$moved    = (int) $report['du_libre'] + (int) $report['repris'];
				$affected = (int) $report['repris'];
				break;
		}

		Journal::add(
			array(
				'time'     => time(),
				'user'     => wp_get_current_user()->display_name,
				'type'     => $direction,
				'label'    => self::reference_label( $product_id ),
				'qty'      => $moved,
				'orders'   => $affected,
				// Les deux compteurs sont relevés après coup, quel que soit le
				// sens : le journal doit rester lisible d'une ligne à l'autre.
				'libre'    => Stock::get( $product_id ),
				'commande' => Supply::get( $product_id ),
				'motif'    => $reason,
			)
		);

		return array(
			'direction' => $direction,
			'report'    => $report,
			'context'   => ReferenceContext::describe( $product_id ),
		);
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
