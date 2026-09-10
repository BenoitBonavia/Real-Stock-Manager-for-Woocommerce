<?php
/**
 * Demande de retour en stock : ce que les inscrits attendent, par référence.
 *
 * @package RealStockManager
 */

namespace RSMW\BackInStock;

defined( 'ABSPATH' ) || exit;

/**
 * Agrège les inscriptions à une alerte de retour en stock, directement en
 * base — jamais via les classes `CWG_*` de l'hôte, pour rester une dépendance
 * souple. Patron identique à `EBISN\Matrix\DemandMatrix` du plugin frère
 * (même auteur, même extension hôte), qui a déjà validé ces requêtes en
 * production.
 */
final class Demand {

	/**
	 * Demande agrégée par référence (`cwginstock_pid`).
	 *
	 * Le regroupement se fait SEULEMENT sur `cwginstock_pid`, jamais sur
	 * `cwginstock_bypass_pid` : c'est le choix déjà fait par
	 * `EBISN\Matrix\DemandMatrix::fetch_demands()` pour ce même genre
	 * d'agrégat « toutes déclinaisons confondues ». Une personne inscrite au
	 * niveau d'un produit variable veut CE produit, pas la déclinaison
	 * précise qui a déclenché sa dernière alerte — lui rattacher la demande
	 * à `bypass_pid` la figerait à tort sur une seule taille.
	 *
	 * Les deux jointures passent par une sous-requête `GROUP BY post_id` —
	 * jamais un LEFT/INNER JOIN direct sur `wp_postmeta` — pour qu'une
	 * inscription reste sur UNE SEULE ligne du résultat même si une clé de
	 * métadonnée est dupliquée en base, ce que rien n'empêche côté hôte. Sans
	 * cela, `SUM( units )` compterait deux fois la quantité d'une inscription
	 * apparue sur deux lignes de jointure.
	 *
	 * La quantité est castée en `SIGNED`, jamais `UNSIGNED` : MySQL
	 * transforme un entier négatif casté en `UNSIGNED` en son complément
	 * positif (~18 446 744 073 709 551 615 pour -1) au lieu de le rejeter,
	 * ce que `GREATEST( ..., 1 )` ne peut plus corriger une fois la valeur
	 * déjà démesurée.
	 *
	 * @return array<int, int> Référence => unités demandées.
	 */
	public static function fetch(): array {
		global $wpdb;

		$statuses = Config::statuses();

		if ( empty( $statuses ) || ! post_type_exists( Host::SUBSCRIBER_TYPE ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; l'écran appelant recalcule à chaque affichage, comme la table des besoins.
		$sql = $wpdb->prepare(
			"SELECT ref.pid AS pid,
			        SUM( GREATEST( CAST( COALESCE( NULLIF( qty.qty, '' ), '1' ) AS SIGNED ), 1 ) ) AS units
			   FROM {$wpdb->posts} p
			  INNER JOIN (
			        SELECT post_id, MIN( meta_value ) AS pid
			          FROM {$wpdb->postmeta}
			         WHERE meta_key = %s
			           AND meta_value <> ''
			         GROUP BY post_id
			      ) ref
			          ON ref.post_id = p.ID
			   LEFT JOIN (
			        SELECT post_id, MAX( meta_value ) AS qty
			          FROM {$wpdb->postmeta}
			         WHERE meta_key = %s
			         GROUP BY post_id
			      ) qty
			          ON qty.post_id = p.ID
			  WHERE p.post_type = %s
			    AND p.post_status IN ( {$placeholders} )
			  GROUP BY ref.pid",
			array_merge( array( Host::META_PID, Host::META_QUANTITY, Host::SUBSCRIBER_TYPE ), $statuses )
		);

		$rows = $wpdb->get_results( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$demand = array();

		foreach ( (array) $rows as $row ) {
			$pid = (int) $row->pid;

			if ( $pid <= 0 ) {
				continue;
			}

			$demand[ $pid ] = (int) $row->units;
		}

		return $demand;
	}

	/**
	 * Type et parent de chaque référence attendue, pour distinguer une
	 * variation d'un produit simple ou d'un produit variable pris dans son
	 * ensemble.
	 *
	 * @param int[] $ids Références (`cwginstock_pid`).
	 *
	 * @return array<int, array{type:string, parent:int}>
	 */
	public static function fetch_posts( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- les marqueurs %d sont bien présents, construits dynamiquement : le sniff ne les voit pas dans le littéral.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_parent, post_type FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} )",
				$ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$posts = array();

		foreach ( (array) $rows as $row ) {
			$posts[ (int) $row->ID ] = array(
				'type'   => (string) $row->post_type,
				'parent' => (int) $row->post_parent,
			);
		}

		return $posts;
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
