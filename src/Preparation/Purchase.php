<?php
/**
 * Commande fournisseur saisie en lot.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre en une fois toutes les lignes d'une commande passée à un fournisseur.
 *
 * Image miroir de {@see Reception} : la réception fait entrer de la marchandise,
 * la commande annonce qu'elle est en route. Même forme — saisie par référence,
 * vérification avant écriture, puis enregistrement — parce que c'est la forme qui
 * marche déjà et que le marchand la connaît.
 *
 * Ce que cette classe corrige. Jusqu'ici, enregistrer une commande fournisseur
 * imposait de passer par « Gestion stock → Mouvement à l'unité », une référence à
 * la fois. Pour une commande de quinze lignes, quinze allers-retours. En pratique
 * le marchand ne le faisait pas — et comme il ne le faisait pas, la colonne
 * « Reste à commander » ne baissait jamais et la page lui redemandait chaque
 * semaine de commander ce qu'il avait déjà commandé.
 *
 * L'écriture n'est jamais faite ici : elle est déléguée à
 * {@see Allocator::order_from_supplier()}, qui monte le compteur puis attribue en
 * FIFO aux commandes clients les plus anciennes. Une commande fournisseur ne fait
 * JAMAIS basculer une commande client en « À empaqueter » : la marchandise n'est
 * pas arrivée.
 */
final class Purchase {

	/**
	 * Vérifie la saisie sans rien écrire.
	 *
	 * @param array $rows Quantités saisies, indexées par identifiant de référence.
	 *
	 * @return array Compte rendu.
	 */
	public static function simulate( array $rows ): array {
		$rows   = self::normalise( $rows );
		$report = self::empty_report( true );

		if ( empty( $rows ) ) {
			return $report;
		}

		$map = Demand::map( false );

		/*
		 * Projection PARTAGÉE entre toutes les lignes, comme pour la réception :
		 * deux références de la même commande fournisseur peuvent servir la même
		 * commande client. Les traiter séparément compterait deux fois la même
		 * couverture.
		 */
		$projected = array();

		foreach ( $rows as $product_id => $qty ) {

			$data      = isset( $map[ $product_id ] ) ? $map[ $product_id ] : array();
			$remaining = isset( $data['restant'] ) ? (int) $data['restant'] : 0;
			$reserved  = isset( $data['commande'] ) ? (int) $data['commande'] : 0;

			// Ce que les commandes clients attendent encore sans être déjà couvert,
			// ni par du préparé, ni par une commande fournisseur antérieure.
			$uncovered = max( 0, $remaining - $reserved - ( isset( $projected[ $product_id ] ) ? $projected[ $product_id ] : 0 ) );

			$covers = min( $qty, $uncovered );

			$projected[ $product_id ] = ( isset( $projected[ $product_id ] ) ? $projected[ $product_id ] : 0 ) + $covers;

			$info = Labels::get( $product_id );

			$report['lines'][] = array(
				'id'      => $product_id,
				'label'   => self::label_for( $product_id ),
				'missing' => self::missing_for( $product_id, $data ),
				'qty'     => $qty,
				'covers'  => $covers,
				'free'    => $qty - $covers,
				'value'   => $qty * (float) $info['price'],
			);

			$report['qty_total']    += $qty;
			$report['covers_total'] += $covers;
			$report['free_total']   += $qty - $covers;
			$report['value_total']  += $qty * (float) $info['price'];
			++$report['references'];

			self::collect_warnings( $report, $product_id, $qty, $data );
		}

		return $report;
	}

	/**
	 * Enregistre la commande fournisseur.
	 *
	 * @param array $rows Quantités saisies, indexées par identifiant de référence.
	 *
	 * @return array Compte rendu.
	 */
	public static function apply( array $rows ): array {
		$rows   = self::normalise( $rows );
		$report = self::empty_report( false );

		if ( empty( $rows ) ) {
			return $report;
		}

		$orders = array();

		foreach ( $rows as $product_id => $qty ) {

			$result = Allocator::order_from_supplier( $product_id, $qty );
			$info   = Labels::get( $product_id );

			$report['lines'][] = array(
				'id'      => $product_id,
				'label'   => self::label_for( $product_id ),
				'missing' => 0,
				'qty'     => $qty,
				'covers'  => (int) $result['affecte'],
				'free'    => (int) $result['libre'],
				'value'   => $qty * (float) $info['price'],
			);

			$report['qty_total']    += $qty;
			$report['covers_total'] += (int) $result['affecte'];
			$report['free_total']   += (int) $result['libre'];
			$report['value_total']  += $qty * (float) $info['price'];
			++$report['references'];

			/*
			 * Allocator a déjà chargé chaque commande servie et en a extrait le
			 * numéro et l'adresse d'édition. On dédoublonne sur son identifiant
			 * plutôt que de relire les commandes : deux références du même colis
			 * servent souvent la même commande client.
			 */
			foreach ( (array) $result['lignes'] as $line ) {
				if ( isset( $line['order'] ) ) {
					$orders[ (int) $line['order'] ] = $line;
				}
			}
		}

		$report['orders'] = array_values( $orders );

		Demand::flush();

		Log::info(
			sprintf(
				'Commande fournisseur enregistrée : %d article(s) sur %d référence(s).',
				$report['qty_total'],
				$report['references']
			)
		);

		return $report;
	}

	/**
	 * Compte rendu vide.
	 *
	 * @param bool $dry Vérification sans écriture.
	 *
	 * @return array
	 */
	private static function empty_report( bool $dry ): array {
		return array(
			'dry'          => $dry,
			'lines'        => array(),
			'orders'       => array(),
			'warnings'     => array(),
			'references'   => 0,
			'qty_total'    => 0,
			'covers_total' => 0,
			'free_total'   => 0,
			'value_total'  => 0.0,
		);
	}

	/**
	 * Ne conserve que les quantités strictement positives.
	 *
	 * Une ligne à zéro n'est pas une erreur : c'est le geste normal du marchand qui
	 * écarte une référence de la commande en cours.
	 *
	 * @param array $rows Saisie brute.
	 *
	 * @return array<int, int>
	 */
	private static function normalise( array $rows ): array {
		$clean = array();

		foreach ( $rows as $product_id => $qty ) {
			$product_id = (int) $product_id;
			$qty        = (int) $qty;

			if ( $product_id > 0 && $qty > 0 ) {
				$clean[ $product_id ] = $qty;
			}
		}

		return $clean;
	}

	/**
	 * Ce qu'il restait à commander sur la référence.
	 *
	 * @param int   $product_id Référence.
	 * @param array $data       Entrée de la table des besoins.
	 *
	 * @return int
	 */
	private static function missing_for( $product_id, array $data ): int {
		$remaining = isset( $data['restant'] ) ? (int) $data['restant'] : 0;
		$ordered   = ( isset( $data['commande'] ) ? (int) $data['commande'] : 0 ) + Supply::get( $product_id );

		return max( 0, $remaining - max( 0, Stock::get( $product_id ) ) - $ordered );
	}

	/**
	 * Signale les saisies qui méritent un regard avant enregistrement.
	 *
	 * @param array $report     Compte rendu, modifié sur place.
	 * @param int   $product_id Référence.
	 * @param int   $qty        Quantité saisie.
	 * @param array $data       Entrée de la table des besoins.
	 */
	private static function collect_warnings( array &$report, $product_id, int $qty, array $data ): void {
		$missing = self::missing_for( $product_id, $data );

		if ( $qty > $missing && $missing >= 0 ) {
			$report['warnings'][] = sprintf(
				/* translators: 1: libellé de la référence, 2: quantité saisie, 3: quantité manquante. */
				__( '%1$s : %2$d commandé(s) pour %3$d manquant(s). L’excédent restera disponible pour les prochaines commandes clients.', 'real-stock-manager-for-woocommerce' ),
				self::label_for( $product_id ),
				$qty,
				$missing
			);
		}
	}

	/**
	 * Libellé lisible d'une référence.
	 *
	 * @param int $product_id Référence.
	 *
	 * @return string
	 */
	private static function label_for( $product_id ): string {
		$info  = Labels::get( $product_id );
		$label = $info['name'];

		if ( '' !== $info['variant'] ) {
			$label .= ' — ' . $info['variant'];
		}

		return $label;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
