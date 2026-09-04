<?php
/**
 * Page « Besoins & stock ».
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation\Admin;

use RSMW\Preparation\Allocator;
use RSMW\Preparation\Config;
use RSMW\Preparation\Demand;
use RSMW\Preparation\Labels;
use RSMW\Preparation\Legacy;
use RSMW\Preparation\OrderStatus;
use RSMW\Preparation\Stock;
use RSMW\Preparation\Supply;

defined( 'ABSPATH' ) || exit;

/**
 * Confronte ce qu'il reste à préparer sur les commandes en attente au stock
 * physique libre, référence par référence.
 */
final class NeedsPage {

	/**
	 * Affiche la page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Droits insuffisants.', 'real-stock-manager-for-woocommerce' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple invalidation de cache, sans effet de bord.
		if ( isset( $_GET['mh_flush'] ) ) {
			Demand::flush();
		}

		$reallocation = self::handle_reallocation();
		$repaired     = self::handle_repair();

		// Recalcul systématique : la page affichée doit refléter l'état réel des
		// commandes, sans dépendre du déclenchement des hooks d'invalidation.
		$map = Demand::map( false );

		Labels::prime( array_keys( $map ) );

		$rows   = array();
		$totals = array(
			'restant'     => 0,
			'commande'    => 0,
			'manque'      => 0,
			'valeur'      => 0.0,
			'refs_manque' => 0,
		);

		foreach ( $map as $product_id => $data ) {

			$info      = Labels::get( $product_id );
			$free      = Stock::get( $product_id );
			$remaining = (int) $data['restant'];

			/*
			 * Commandé au fournisseur : la part déjà réservée sur des commandes
			 * clients, plus le reliquat non attribué. Lecture défensive de la carte,
			 * un transient d'une version antérieure ne porte pas encore cette clé.
			 */
			$ordered = ( isset( $data['commande'] ) ? (int) $data['commande'] : 0 )
				+ Supply::get( $product_id );

			// Ce qu'il reste RÉELLEMENT à commander : ni en stock, ni déjà commandé.
			$missing = max( 0, $remaining - max( 0, $free ) - $ordered );

			$rows[] = array(
				'id'        => (int) $product_id,
				'name'      => $info['name'],
				'variant'   => $info['variant'],
				'sku'       => $info['sku'],
				'demande'   => (int) $data['demande'],
				'pointe'    => (int) $data['pointe'],
				'restant'   => $remaining,
				'libre'     => $free,
				'commande'  => $ordered,
				'manque'    => $missing,
				'commandes' => (int) $data['commandes'],
				'oldest'    => self::oldest_order( $data['plus_vieux'] ),
				'valeur'    => $missing * $info['price'],
			);

			$totals['restant']  += $remaining;
			$totals['commande'] += $ordered;
			$totals['manque']   += $missing;
			$totals['valeur']   += $missing * $info['price'];

			if ( $missing > 0 ) {
				++$totals['refs_manque'];
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['manque'] !== $b['manque'] ) {
					return $b['manque'] <=> $a['manque'];
				}

				return strcmp( $a['name'], $b['name'] );
			}
		);

		View::render(
			'needs-page',
			array(
				'rows'             => $rows,
				'totals'           => $totals,
				'statuses'         => Config::statuses(),
				'unknown_statuses' => self::unknown_statuses(),
				'negatives'        => Stock::negative_ids(),
				'repaired'         => $repaired,
				'reallocation'     => $reallocation,
				'allocatable'      => Demand::allocatable_count( false ),
				'cache_meta'       => Demand::meta(),
				'outside'          => Demand::orders_outside(),
				'auto_allocate'    => Config::auto_allocate(),
				'status_label'     => OrderStatus::label(),
				'status_ok'        => OrderStatus::is_registered() && OrderStatus::is_declared(),
				'status_declared'  => OrderStatus::is_declared(),
				'status_count'     => OrderStatus::order_count(),
				'stock_page_url'   => admin_url( 'admin.php?page=' . Legacy::PAGE_STOCK ),
				'refresh_url'      => add_query_arg( 'mh_flush', time() ),
			)
		);
	}

	/**
	 * Traite le formulaire de réaffectation.
	 *
	 * @return array|null Compte rendu, ou null si aucune demande.
	 */
	private static function handle_reallocation(): ?array {
		if ( ! isset( $_POST['mh_prep_realloc'] ) ) {
			return null;
		}

		check_admin_referer( 'mh_prep_realloc' );

		$mode   = sanitize_text_field( wp_unslash( $_POST['mh_prep_realloc'] ) );
		$report = Allocator::reallocate_all( 'simuler' === $mode );

		if ( ! $report['dry'] ) {
			Demand::flush();
		}

		return $report;
	}

	/**
	 * Traite la remise à zéro des stocks négatifs hérités.
	 *
	 * @return int|null Nombre de références corrigées, ou null si aucune demande.
	 */
	private static function handle_repair(): ?int {
		if ( ! isset( $_POST['mh_prep_repair'] ) ) {
			return null;
		}

		check_admin_referer( 'mh_prep_repair' );

		$negatives = Stock::negative_ids();

		foreach ( $negatives as $product_id ) {
			Stock::set( $product_id, 0 );
		}

		Demand::flush();

		return count( $negatives );
	}

	/**
	 * Résumé de la commande la plus ancienne attendant une référence.
	 *
	 * @param int|null $order_id Identifiant de commande.
	 *
	 * @return array|null
	 */
	private static function oldest_order( $order_id ): ?array {
		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$created = $order->get_date_created();

		return array(
			'num'  => $order->get_order_number(),
			'date' => $created ? $created->date_i18n( 'd/m/Y' ) : '',
			'url'  => $order->get_edit_order_url(),
		);
	}

	/**
	 * Statuts configurés qui n'existent pas sur cette boutique.
	 *
	 * Un slug inconnu ne lève aucune erreur : la requête renvoie simplement zéro
	 * commande. Ce contrôle rend la panne visible.
	 *
	 * @return string[]
	 */
	private static function unknown_statuses(): array {
		$known = array_map(
			static function ( $status ) {
				return preg_replace( '/^wc-/', '', $status );
			},
			array_keys( wc_get_order_statuses() )
		);

		return array_values( array_diff( Config::statuses(), $known ) );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
