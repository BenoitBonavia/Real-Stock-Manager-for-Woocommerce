<?php
/**
 * Résorption des commandes « À empaqueter » jamais pointées.
 *
 * @package RealStockManager
 */

namespace RSMW\Preparation;

use RSMW\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tout le module repose sur un invariant implicite : une commande en
 * « À empaqueter » a toutes ses lignes pointées. C'est ce qui autorise
 * `Demand::active_order_ids()` à exclure ce statut du périmètre « à servir ».
 *
 * L'action groupée native « Marquer À empaqueter », le menu de statut de la
 * fiche commande ou une automatisation externe pouvaient le violer en silence :
 * la commande devenait invisible des trois fonctions qui distribuent le stock,
 * son besoin disparaissait de la clé `restant` de `Demand::map()` — ce qui
 * désactivait au passage le raccourci d'écriture directe de l'onglet Inventaire
 * pour la référence entière — et son stock n'était jamais décompté.
 * `Allocator::reconcile_pack_status()` empêche désormais cet état de se créer.
 *
 * Reste l'arriéré déjà en base, qu'aucun événement ne viendra rattraper. Cette
 * classe le balaie une fois, par lots, à chaque chargement de l'administration,
 * sur le patron de `FrozenHolds` et de `PreOrder\Migration`. Chaque commande
 * passe par `Allocator::serve_or_demote_pack()` : une seule implémentation de
 * la règle, partagée avec le gestionnaire de hook.
 *
 * Contrairement à `FrozenHolds`, ce balayage ÉCRIT du stock — il pointe ce qui
 * est disponible, donc décrémente le compteur libre. C'est l'écriture qui
 * aurait dû avoir lieu à l'arrivée de la commande ; la différer plus longtemps
 * ne la rendrait pas plus sûre, et le compte rendu dit exactement ce qui a
 * bougé.
 */
final class StalePacks {

	/** Clé de réglage portant l'avancement. */
	private const STATE_KEY = 'stale_packs_state';

	/** Curseur du balayage, sur l'identifiant de commande. */
	private const CURSOR_KEY = 'stale_packs_cursor';

	/** Clé de réglage portant le compte rendu cumulé. */
	private const REPORT_KEY = 'stale_packs_report';

	/**
	 * Commandes traitées par requête.
	 *
	 * Bien plus bas que les 200 lignes de `FrozenHolds`, et délibérément : là
	 * où cette dernière écrit trois métas par ligne, l'unité de travail est ici
	 * une commande entière — chargement, attribution, et le plus souvent un
	 * `WC_Order::save()` qui rejoue tous les écouteurs de
	 * `woocommerce_order_status_changed`, y compris ceux des autres extensions.
	 * Ce travail s'exécute sur `admin_init`, donc sur une page que le marchand
	 * attend : mieux vaut trente passages discrets qu'un seul qui se voit.
	 */
	private const BATCH = 10;

	/** Nombre maximal de commandes listées dans le compte rendu. */
	private const MAX_ORDERS = 200;

	/**
	 * Accroche l'avancement du balayage.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ) );
	}

	/**
	 * Le balayage est-il terminé ?
	 *
	 * @return bool
	 */
	public static function is_done(): bool {
		return 'done' === Settings::get( self::STATE_KEY );
	}

	/**
	 * Traite un lot, si nécessaire.
	 */
	public static function maybe_run(): void {
		if ( self::is_done() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( self::process_batch() ) {
			return;
		}

		Settings::update( self::STATE_KEY, 'done' );
	}

	/**
	 * Compte rendu cumulé, fusionné avec ses valeurs par défaut.
	 *
	 * @return array{time:int, served:int, demoted:int, units:int, order_ids:array<int, bool>, truncated:bool}
	 */
	public static function report(): array {
		$defaults = array(
			'time'      => 0,
			'served'    => 0,
			'demoted'   => 0,
			'units'     => 0,
			'order_ids' => array(),
			'truncated' => false,
		);

		$stored = Settings::get( self::REPORT_KEY, array() );

		return is_array( $stored ) ? array_merge( $defaults, $stored ) : $defaults;
	}

	/**
	 * Efface le compte rendu, une fois le marchand informé.
	 *
	 * N'affecte pas l'avancement : la résorption reste acquise, seul
	 * l'affichage disparaît.
	 */
	public static function acknowledge_report(): void {
		Settings::update( self::REPORT_KEY, array() );
	}

	/**
	 * Traite un lot de commandes « À empaqueter » au pointage incomplet.
	 *
	 * Le curseur porte sur l'identifiant de commande, jamais sur un `offset` :
	 * une commande traitée quitte le plus souvent le statut, ce qui décalerait
	 * la fenêtre d'une pagination par rang et ferait sauter des commandes.
	 *
	 * La liste vient de `wc_get_orders()` plutôt que d'une requête directe :
	 * le statut vit dans `wp_wc_orders` sous HPOS et dans `wp_posts` en
	 * stockage historique, et c'est précisément l'indirection que cette
	 * fonction sait résoudre seule. Elle ne retourne que des identifiants, sur
	 * une colonne de statut indexée, et le périmètre est une file
	 * d'empaquetage — un ensemble petit par nature, plus petit encore que
	 * celui de `Demand::holder_order_ids()`, qui interroge déjà sans plafond.
	 *
	 * Le tri est fait en PHP, pas par la requête : les valeurs d'`orderby`
	 * acceptées diffèrent entre `OrdersTableQuery` (HPOS) et `WP_Query`
	 * (stockage historique), et trier ici évite d'en dépendre.
	 *
	 * @return bool Vrai s'il reste probablement du travail.
	 */
	private static function process_batch(): bool {
		$cursor = (int) Settings::get( self::CURSOR_KEY, 0 );

		$ids = wc_get_orders(
			array(
				'status' => array( Legacy::STATUS_SLUG ),
				'type'   => 'shop_order',
				'limit'  => -1,
				'return' => 'ids',
			)
		);

		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();

		// Au-delà du curseur seulement : une commande déjà examinée et restée
		// dans le statut (parce qu'elle a pu être servie en entier) ne doit pas
		// être réexaminée à chaque lot, sans quoi le balayage ne finit jamais.
		$pending = array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $cursor ) {
					return $id > $cursor;
				}
			)
		);

		if ( empty( $pending ) ) {
			return false;
		}

		sort( $pending );

		$batch  = array_slice( $pending, 0, self::BATCH );
		$report = self::report();
		$moved  = false;

		Settings::update( self::CURSOR_KEY, max( $batch ) );

		foreach ( $batch as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			// Relu à neuf : le statut a pu changer depuis la requête ci-dessus,
			// et une commande sans ligne est écartée pour la même raison que
			// dans Allocator::reconcile_pack_status().
			if ( Legacy::STATUS_SLUG !== $order->get_status() || empty( $order->get_items() ) ) {
				continue;
			}

			if ( Items::order_is_ready( $order ) ) {
				continue;
			}

			$before = (int) Items::order_coverage( $order )[0];

			$status_after = Allocator::serve_or_demote_pack( $order );

			$fresh = wc_get_order( $order_id );
			$after = $fresh instanceof \WC_Order ? (int) Items::order_coverage( $fresh )[0] : $before;

			self::record( $report, $order_id, max( 0, $after - $before ), Legacy::STATUS_SLUG === $status_after );

			$moved = true;
		}

		if ( $moved ) {
			$report['time'] = time();

			Settings::update( self::REPORT_KEY, $report );
			Demand::flush();

			Log::info(
				sprintf(
					'Résorption des commandes « À empaqueter » non pointées : %d servie(s), %d redescendue(s), %d unité(s) pointée(s) au total.',
					$report['served'],
					$report['demoted'],
					$report['units']
				)
			);
		}

		return count( $pending ) > count( $batch );
	}

	/**
	 * Verse une commande traitée au compte rendu cumulé, plafonné pour que
	 * l'option reste petite.
	 *
	 * Le type exact du compte rendu est décrit sur `report()` ; le répéter ici
	 * imposerait un alignement de docbloc illisible, et `FrozenHolds::record()`
	 * fait le même choix.
	 *
	 * @param array $report   Compte rendu, modifié par référence.
	 * @param int   $order_id Commande traitée.
	 * @param int   $units    Unités nouvellement pointées, donc retirées du libre.
	 * @param bool  $served   La commande est restée « À empaqueter », servie en entier.
	 */
	private static function record( array &$report, int $order_id, int $units, bool $served ): void {
		$report['units'] += max( 0, $units );

		if ( $served ) {
			++$report['served'];
		} else {
			++$report['demoted'];
		}

		if ( isset( $report['order_ids'][ $order_id ] ) ) {
			return;
		}

		if ( count( $report['order_ids'] ) >= self::MAX_ORDERS ) {
			$report['truncated'] = true;

			return;
		}

		$report['order_ids'][ $order_id ] = true;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
