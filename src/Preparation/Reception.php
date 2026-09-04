<?php
/**
 * Réception d'un colis fournisseur, en lot.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

defined( 'ABSPATH' ) || exit;

/**
 * Saisie d'un colis entier : pour chaque référence attendue, la quantité reçue
 * conforme et la quantité défectueuse.
 *
 * Les défectueux ne sont JAMAIS reçus puis écartés. L'aller-retour paraît neutre
 * mais ne l'est pas : `Allocator::receive()` sert les commandes de la plus
 * ancienne à la plus récente, tandis que `Allocator::withdraw()` reprend de la
 * plus récente à la plus ancienne. Le couple transfère donc du stock réel d'une
 * commande vers une autre, avec deux changements de statut et deux notes sur des
 * commandes étrangères au colis. Un défectueux passe par
 * `Allocator::cancel_supplier_order()` : il cesse d'être attendu, sans jamais
 * toucher au stock physique ni aux statuts.
 */
final class Reception {

	/**
	 * Références attendues, c'est-à-dire portant une commande fournisseur en cours.
	 *
	 * La liste est l'UNION de deux sources. La carte des besoins ne parcourt que
	 * les commandes clients actives : une référence commandée pour le stock, sans
	 * aucune commande client en attente, n'y figure pas du tout et serait donc
	 * impossible à réceptionner si l'on s'y fiait seule.
	 *
	 * @return array[] Une entrée par référence, triée par libellé.
	 */
	public static function pending(): array {
		$map  = Demand::map( false );
		$pool = Supply::free_map();

		$ids = array_keys( $pool );

		foreach ( $map as $product_id => $data ) {
			if ( ! empty( $data['commande'] ) ) {
				$ids[] = (int) $product_id;
			}
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		Labels::prime( $ids );

		$rows = array();

		foreach ( $ids as $product_id ) {
			$reserved = isset( $map[ $product_id ]['commande'] ) ? (int) $map[ $product_id ]['commande'] : 0;
			$free     = isset( $pool[ $product_id ] ) ? (int) $pool[ $product_id ] : 0;
			$expected = $reserved + $free;

			if ( $expected <= 0 ) {
				continue;
			}

			$info = Labels::get( $product_id );

			$rows[] = array(
				'id'       => $product_id,
				'name'     => $info['name'],
				'variant'  => $info['variant'],
				'sku'      => $info['sku'],
				'expected' => $expected,
				'reserved' => $reserved,
				'free'     => $free,
				'orders'   => isset( $map[ $product_id ]['commandes'] ) ? (int) $map[ $product_id ]['commandes'] : 0,
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $a['name'] . $a['variant'], $b['name'] . $b['variant'] );
			}
		);

		return $rows;
	}

	/**
	 * Quantité actuellement attendue pour une référence.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return int
	 */
	public static function expected_for( $product_id ): int {
		return Demand::ordered_for( $product_id ) + Supply::get( $product_id );
	}

	/**
	 * Simule la réception sans rien écrire.
	 *
	 * La projection des quantités préparées est PARTAGÉE entre toutes les
	 * références du colis : une même commande client peut attendre deux
	 * références du même carton, et ne bascule qu'une fois les deux servies.
	 * Simuler référence par référence manquerait cette bascule.
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

		$orders    = self::load_active_orders();
		$projected = array();

		// Complétude AVANT : seules les commandes qui deviennent complètes
		// grâce à ce colis doivent être annoncées comme basculant.
		$complete_before = array();

		foreach ( $orders as $order_id => $order ) {
			$complete_before[ $order_id ] = Items::order_is_ready( $order );
		}

		foreach ( $rows as $product_id => $quantities ) {

			$expected  = self::expected_for( $product_id );
			$remaining = $quantities['ok'];
			$allocated = 0;

			foreach ( $orders as $order ) {

				if ( $remaining <= 0 ) {
					break;
				}

				foreach ( $order->get_items() as $item_id => $item ) {

					if ( $remaining <= 0 ) {
						break;
					}

					if ( Items::key( $item ) !== $product_id ) {
						continue;
					}

					$prepared = isset( $projected[ $item_id ] )
						? $projected[ $item_id ]
						: Items::prepared( $item );

					$needed = (int) $item->get_quantity() - $prepared;

					if ( $needed <= 0 ) {
						continue;
					}

					$take = min( $needed, $remaining );

					$projected[ $item_id ] = $prepared + $take;
					$remaining            -= $take;
					$allocated            += $take;
				}
			}

			$report['lines'][] = array(
				'id'        => $product_id,
				'label'     => self::label_for( $product_id ),
				'expected'  => $expected,
				'ok'        => $quantities['ok'],
				'defective' => $quantities['defective'],
				'allocated' => $allocated,
				'free'      => $remaining,
			);

			$report['ok_total']        += $quantities['ok'];
			$report['defective_total'] += $quantities['defective'];
			$report['allocated_total'] += $allocated;
			$report['free_total']      += $remaining;

			self::collect_warnings( $report, $product_id, $quantities, $expected, $allocated );
		}

		foreach ( $orders as $order_id => $order ) {

			if ( ! empty( $complete_before[ $order_id ] ) ) {
				continue;
			}

			if ( self::would_be_complete( $order, $projected ) ) {
				$report['completed'][] = array(
					'num' => (string) $order->get_order_number(),
					'url' => $order->get_edit_order_url(),
				);
			}
		}

		return $report;
	}

	/**
	 * Enregistre la réception.
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

		$batch = 'rcp-' . gmdate( 'Ymd-His' ) . '-' . wp_rand( 100, 999 );

		$report['batch'] = $batch;

		foreach ( $rows as $product_id => $quantities ) {

			$allocated = 0;
			$free      = 0;

			if ( $quantities['ok'] > 0 ) {
				$received = Allocator::receive( $product_id, $quantities['ok'] );

				$allocated = (int) $received['affecte'];
				$free      = max( 0, $quantities['ok'] - $allocated );

				foreach ( $received['basculees'] as $number ) {
					$report['completed'][] = array(
						'num' => (string) $number,
						'url' => '',
					);
				}

				foreach ( $received['lignes'] as $line ) {
					self::merge_order_line( $report, $line );
				}
			}

			if ( $quantities['defective'] > 0 ) {
				$cancelled = Allocator::cancel_supplier_order( $product_id, $quantities['defective'] );

				Defects::add( $product_id, $quantities['defective'] );

				$report['missing_defective'] += (int) $cancelled['manquant'];
			}

			$report['lines'][] = array(
				'id'        => $product_id,
				'label'     => self::label_for( $product_id ),
				'expected'  => self::expected_for( $product_id ),
				'ok'        => $quantities['ok'],
				'defective' => $quantities['defective'],
				'allocated' => $allocated,
				'free'      => $free,
			);

			$report['ok_total']        += $quantities['ok'];
			$report['defective_total'] += $quantities['defective'];
			$report['allocated_total'] += $allocated;
			$report['free_total']      += $free;

			Journal::add(
				array(
					'time'      => time(),
					'user'      => wp_get_current_user()->display_name,
					'type'      => 'reception',
					'batch'     => $batch,
					'product'   => $product_id,
					'label'     => self::label_for( $product_id ),
					'qty'       => $quantities['ok'],
					'defective' => $quantities['defective'],
					'orders'    => $allocated,
					'libre'     => Stock::get( $product_id ),
					'commande'  => Supply::get( $product_id ),
					'motif'     => '',
				)
			);
		}

		Demand::flush();

		Log::info(
			sprintf(
				'Réception %s : %d référence(s), %d conforme(s), %d défectueux.',
				$batch,
				count( $rows ),
				$report['ok_total'],
				$report['defective_total']
			)
		);

		return $report;
	}

	/**
	 * Structure vide d'un compte rendu.
	 *
	 * @param bool $dry Simulation ou non.
	 *
	 * @return array
	 */
	private static function empty_report( bool $dry ): array {
		return array(
			'dry'               => $dry,
			'batch'             => '',
			'lines'             => array(),
			'ok_total'          => 0,
			'defective_total'   => 0,
			'allocated_total'   => 0,
			'free_total'        => 0,
			'completed'         => array(),
			'orders'            => array(),
			'warnings'          => array(),
			'missing_defective' => 0,
		);
	}

	/**
	 * Nettoie et borne les quantités saisies.
	 *
	 * Les lignes entièrement vides sont écartées : ne rien saisir signifie
	 * « pas reçu dans ce colis », et ne doit produire aucune écriture.
	 *
	 * @param array $rows Saisie brute.
	 *
	 * @return array<int, array{ok:int, defective:int}>
	 */
	private static function normalise( array $rows ): array {
		$clean = array();

		foreach ( $rows as $product_id => $quantities ) {
			$product_id = (int) $product_id;

			if ( $product_id <= 0 ) {
				continue;
			}

			$ok        = isset( $quantities['ok'] ) ? max( 0, (int) $quantities['ok'] ) : 0;
			$defective = isset( $quantities['defective'] ) ? max( 0, (int) $quantities['defective'] ) : 0;

			if ( $ok <= 0 && $defective <= 0 ) {
				continue;
			}

			$clean[ $product_id ] = array(
				'ok'        => $ok,
				'defective' => $defective,
			);
		}

		return $clean;
	}

	/**
	 * Commandes clients à préparer, chargées une seule fois.
	 *
	 * @return \WC_Order[] Indexées par identifiant, de la plus ancienne à la plus récente.
	 */
	private static function load_active_orders(): array {
		$orders = array();

		foreach ( Demand::active_order_ids() as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof \WC_Order ) {
				$orders[ (int) $order_id ] = $order;
			}
		}

		return $orders;
	}

	/**
	 * Toutes les lignes de la commande seraient-elles complètes ?
	 *
	 * @param \WC_Order $order     Commande.
	 * @param array     $projected Quantités préparées projetées, par ligne.
	 *
	 * @return bool
	 */
	private static function would_be_complete( $order, array $projected ): bool {
		$has_item = false;

		foreach ( $order->get_items() as $item_id => $item ) {
			$has_item = true;

			$prepared = isset( $projected[ $item_id ] )
				? $projected[ $item_id ]
				: Items::prepared( $item );

			if ( $prepared < (int) $item->get_quantity() ) {
				return false;
			}
		}

		return $has_item;
	}

	/**
	 * Ajoute les avertissements d'une ligne au compte rendu.
	 *
	 * @param array $report     Compte rendu, complété par référence.
	 * @param int   $product_id Référence.
	 * @param array $quantities Quantités saisies.
	 * @param int   $expected   Quantité attendue.
	 * @param int   $allocated  Quantité affectée à des commandes.
	 */
	private static function collect_warnings( array &$report, $product_id, array $quantities, int $expected, int $allocated ): void {
		$total = $quantities['ok'] + $quantities['defective'];
		$label = self::label_for( $product_id );

		if ( $total > $expected ) {
			$report['warnings'][] = sprintf(
				/* translators: 1: référence, 2: quantité saisie, 3: quantité attendue, 4: surplus. */
				__( '%1$s : %2$d saisis pour %3$d attendus. Le surplus de %4$d partira en stock libre.', 'real-stock-manager-for-woocommerce' ),
				$label,
				$total,
				$expected,
				$total - $expected
			);
		}

		if ( $quantities['defective'] > $expected ) {
			$report['warnings'][] = sprintf(
				/* translators: 1: référence, 2: nombre de défectueux, 3: quantité attendue. */
				__( '%1$s : %2$d défectueux pour %3$d attendus. L’annulation ne trouvera pas de quoi reprendre.', 'real-stock-manager-for-woocommerce' ),
				$label,
				$quantities['defective'],
				$expected
			);
		}

		if ( $quantities['ok'] > 0 && 0 === $allocated ) {
			$report['warnings'][] = sprintf(
				/* translators: %s: référence. */
				__( '%s : aucune commande client n’attend cette référence, tout partira en stock libre.', 'real-stock-manager-for-woocommerce' ),
				$label
			);
		}
	}

	/**
	 * Fusionne le résumé d'une commande, qui peut apparaître pour plusieurs références.
	 *
	 * @param array $report Compte rendu.
	 * @param array $line   Résumé produit par Allocator.
	 */
	private static function merge_order_line( array &$report, array $line ): void {
		$key = (int) $line['order'];

		if ( isset( $report['orders'][ $key ] ) ) {
			$report['orders'][ $key ]['qty'] += (int) $line['qty'];

			return;
		}

		$report['orders'][ $key ] = $line;
	}

	/**
	 * Libellé complet d'une référence.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return string
	 */
	private static function label_for( $product_id ): string {
		$info = Labels::get( $product_id );

		return trim( $info['name'] . ( '' !== $info['variant'] ? ' — ' . $info['variant'] : '' ) );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
