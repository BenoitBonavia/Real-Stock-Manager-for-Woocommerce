<?php
/**
 * Valorisation du stock réel.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Ce que le stock physique a coûté, et ce qu'il rapporterait à la vente.
 *
 * Deux périmètres, pour chaque montant :
 * - le stock physique libre (Stock::free_map()) ;
 * - ce même stock, augmenté de ce qui est commandé au fournisseur et pas encore
 *   reçu (Supply::free_map()) — tout ce qui est engagé mais pas encore vendu.
 */
final class Valuation {

	/**
	 * Calcule la valorisation du stock réel.
	 *
	 * @return array{cost_free:float, cost_all:float, market_free:float, market_all:float, refs:int, priced:int}
	 */
	public static function compute(): array {
		$stock  = Stock::free_map();
		$supply = Supply::free_map();

		$ids = array_values( array_unique( array_merge( array_keys( $stock ), array_keys( $supply ) ) ) );

		Labels::prime( $ids );

		$totals = array(
			'cost_free'   => 0.0,
			'cost_all'    => 0.0,
			'market_free' => 0.0,
			'market_all'  => 0.0,
			'refs'        => count( $ids ),
			'priced'      => 0,
		);

		foreach ( $ids as $product_id ) {
			$free    = isset( $stock[ $product_id ] ) ? $stock[ $product_id ] : 0;
			$ordered = isset( $supply[ $product_id ] ) ? $supply[ $product_id ] : 0;
			$all     = $free + $ordered;

			$info = Labels::get( $product_id );

			if ( $info['cost'] > 0.0 ) {
				++$totals['priced'];
			}

			$totals['cost_free']   += $free * $info['cost'];
			$totals['cost_all']    += $all * $info['cost'];
			$totals['market_free'] += $free * $info['price'];
			$totals['market_all']  += $all * $info['price'];
		}

		return $totals;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
