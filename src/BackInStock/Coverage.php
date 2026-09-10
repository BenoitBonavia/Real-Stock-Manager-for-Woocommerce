<?php
/**
 * Confronte la demande de retour en stock au stock libre.
 *
 * @package RealStockManager
 */

namespace RSMW\BackInStock;

use RSMW\Preparation\Demand as PrepDemand;
use RSMW\Preparation\Labels;
use RSMW\Preparation\Stock;
use RSMW\Preparation\Supply;
use RSMW\Suppliers\Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Pour chaque référence en liste d'attente de retour en stock, calcule
 * combien d'unités demandées pourront être satisfaites par le stock libre —
 * déjà en boutique ou commandé au fournisseur et non attribué à une commande
 * client — et combien il en manquerait.
 *
 * Les commandes clients passent TOUJOURS avant la liste d'attente : le stock
 * disponible ici est exactement ce qui resterait une fois le module
 * Préparation servi, jamais l'inverse.
 */
final class Coverage {

	/**
	 * Construit les lignes de l'écran, triées manque décroissant puis nom.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows(): array {
		$demand = Demand::fetch();

		if ( empty( $demand ) ) {
			return array();
		}

		$ids   = array_keys( $demand );
		$posts = Demand::fetch_posts( $ids );

		Labels::prime( $ids );

		// Recalcul systématique, sans cache : l'écran doit refléter l'état réel
		// du stock et des commandes, comme la page « Besoins pour commande ».
		$prep_map = PrepDemand::map( false );

		$direct       = array();
		$parent_level = array();

		foreach ( $demand as $pid => $entry ) {
			if ( self::is_variable_parent( $pid, $posts ) ) {
				$parent_level[ $pid ] = $entry;
			} else {
				$direct[ $pid ] = $entry;
			}
		}

		$leftover = array();
		$parents  = array();
		$rows     = array();

		foreach ( $direct as $pid => $entry ) {
			$available       = self::available_breakdown( $pid, $prep_map );
			$parent_id       = self::parent_for( $pid, $posts );
			$parents[ $pid ] = $parent_id;

			$rows[ $pid ] = self::build_row( $pid, $entry, $available['libre'], $available['a_venir'], false );

			if ( $parent_id === $pid ) {
				continue;
			}

			/*
			 * Ce qui reste de stock libre une fois cette variation servie
			 * alimente le reliquat mutualisé du parent — physique et à venir
			 * suivis séparément, le physique étant consommé en premier.
			 */
			$used_libre   = min( $entry['units'], $available['libre'] );
			$used_a_venir = min( max( 0, $entry['units'] - $used_libre ), $available['a_venir'] );

			if ( ! isset( $leftover[ $parent_id ] ) ) {
				$leftover[ $parent_id ] = array(
					'libre'   => 0,
					'a_venir' => 0,
				);
			}

			$leftover[ $parent_id ]['libre']   += max( 0, $available['libre'] - $used_libre );
			$leftover[ $parent_id ]['a_venir'] += max( 0, $available['a_venir'] - $used_a_venir );
		}

		/*
		 * Une inscription au produit parent variable (réglage hôte
		 * `variable_any_variation_backinstock`) n'a pas de stock qui lui soit
		 * propre : elle ne peut être servie QUE par ce qui reste des
		 * variations après leurs propres demandes. Réglage inactif ⇒ ces
		 * inscrits ne seront prévenus que si le parent lui-même repasse en
		 * stock, ce que ce module ne peut pas prédire.
		 */
		$variable_ok = Host::variable_any_variation_enabled();

		foreach ( $parent_level as $pid => $entry ) {
			$parents[ $pid ] = $pid;

			$libre   = $variable_ok ? (int) ( $leftover[ $pid ]['libre'] ?? 0 ) : 0;
			$a_venir = $variable_ok ? (int) ( $leftover[ $pid ]['a_venir'] ?? 0 ) : 0;

			$rows[ $pid ] = self::build_row( $pid, $entry, $libre, $a_venir, true );
		}

		$suppliers = Resolver::map_for( array_keys( $rows ), $parents );

		foreach ( $rows as $pid => &$row ) {
			$info = Labels::get( $pid );

			$row['name']         = $info['name'];
			$row['variant']      = $info['variant'];
			$row['sku']          = $info['sku'];
			$row['edit']         = $info['edit'];
			$row['fournisseur']  = isset( $suppliers[ $pid ] ) ? $suppliers[ $pid ]->name : '';
			$row['supplierslug'] = isset( $suppliers[ $pid ] ) ? $suppliers[ $pid ]->slug : '';
		}
		unset( $row );

		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['manque'] !== $b['manque'] ) {
					return $b['manque'] <=> $a['manque'];
				}

				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array_values( $rows );
	}

	/**
	 * Totaux calculés sur un ensemble de lignes.
	 *
	 * Le taux global est PONDÉRÉ (satisfait total / demandé total), jamais la
	 * moyenne des taux de ligne : une référence à une seule demande ne doit
	 * pas peser autant qu'une référence à quarante.
	 *
	 * @param array $rows Lignes.
	 *
	 * @return array{refs:int, inscrits:int, demande:int, satisfait:int, manque:int, refs_manque:int, taux:float}
	 */
	public static function totals( array $rows ): array {
		$totals = array(
			'refs'        => count( $rows ),
			'inscrits'    => 0,
			'demande'     => 0,
			'satisfait'   => 0,
			'manque'      => 0,
			'refs_manque' => 0,
			'taux'        => 100.0,
		);

		foreach ( $rows as $row ) {
			$totals['inscrits']  += $row['inscrits'];
			$totals['demande']   += $row['demande'];
			$totals['satisfait'] += $row['satisfait'];
			$totals['manque']    += $row['manque'];

			if ( $row['manque'] > 0 ) {
				++$totals['refs_manque'];
			}
		}

		if ( $totals['demande'] > 0 ) {
			$totals['taux'] = min( 100.0, ( $totals['satisfait'] / $totals['demande'] ) * 100 );
		}

		return $totals;
	}

	/**
	 * Stock libre disponible pour une référence, au-delà de ce que réclament
	 * déjà les commandes clients en attente.
	 *
	 * Miroir de la colonne « Manque » de `NeedsPage::build_rows()` : là où
	 * elle calcule `max(0, restant − libre − commandé)`, on calcule le
	 * surplus, en consommant le physique avant le commandé fournisseur.
	 *
	 * @param int   $pid      Référence (produit ou variation).
	 * @param array $prep_map Table des besoins du module Préparation.
	 *
	 * @return array{libre:int, a_venir:int}
	 */
	private static function available_breakdown( int $pid, array $prep_map ): array {
		$restant  = isset( $prep_map[ $pid ]['restant'] ) ? (int) $prep_map[ $pid ]['restant'] : 0;
		$reserved = isset( $prep_map[ $pid ]['commande'] ) ? (int) $prep_map[ $pid ]['commande'] : 0;

		$to_cover = max( 0, $restant - $reserved );

		$libre   = Stock::get( $pid );
		$a_venir = Supply::get( $pid );

		$libre_net   = max( 0, $libre - $to_cover );
		$a_venir_net = max( 0, $a_venir - max( 0, $to_cover - $libre ) );

		return array(
			'libre'   => $libre_net,
			'a_venir' => $a_venir_net,
		);
	}

	/**
	 * Construit une ligne à partir de la demande et du stock disponible.
	 *
	 * @param int   $pid          Référence.
	 * @param array $entry        Demande agrégée (`subs`, `units`).
	 * @param int   $libre        Stock physique libre disponible.
	 * @param int   $a_venir      Réassort commandé disponible.
	 * @param bool  $parent_level Inscription au niveau du produit parent variable.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_row( int $pid, array $entry, int $libre, int $a_venir, bool $parent_level ): array {
		$disponible = $libre + $a_venir;
		$demande    = (int) $entry['units'];
		$satisfait  = min( $demande, $disponible );
		$manque     = max( 0, $demande - $disponible );
		$taux       = $demande > 0 ? min( 100.0, ( $satisfait / $demande ) * 100 ) : 100.0;

		return array(
			'id'           => $pid,
			'parent_level' => $parent_level,
			'inscrits'     => (int) $entry['subs'],
			'demande'      => $demande,
			'libre'        => $libre,
			'a_venir'      => $a_venir,
			'disponible'   => $disponible,
			'satisfait'    => $satisfait,
			'manque'       => $manque,
			'taux'         => $taux,
		);
	}

	/**
	 * La référence est-elle un produit variable pris dans son ensemble —
	 * c'est-à-dire une inscription posée au niveau du parent, sans variation
	 * précise ?
	 *
	 * @param int   $pid   Référence.
	 * @param array $posts Type et parent des références, déjà résolus.
	 *
	 * @return bool
	 */
	private static function is_variable_parent( int $pid, array $posts ): bool {
		if ( ! isset( $posts[ $pid ] ) || 'product' !== $posts[ $pid ]['type'] ) {
			return false;
		}

		$product = wc_get_product( $pid );

		return $product instanceof \WC_Product && $product->is_type( 'variable' );
	}

	/**
	 * Produit parent auquel rattacher une référence.
	 *
	 * @param int   $pid   Référence.
	 * @param array $posts Type et parent des références, déjà résolus.
	 *
	 * @return int
	 */
	private static function parent_for( int $pid, array $posts ): int {
		if ( isset( $posts[ $pid ] ) && 'product_variation' === $posts[ $pid ]['type'] && $posts[ $pid ]['parent'] > 0 ) {
			return $posts[ $pid ]['parent'];
		}

		return $pid;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
