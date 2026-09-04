<?php
/**
 * Nettoyage à la suppression du plugin.
 *
 * @package RealStockManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Sécurité : on ne supprime rien si la constante ci-dessous est définie dans
 * wp-config.php. Pratique pour conserver les réglages entre deux réinstalls.
 */
if ( defined( 'RSMW_KEEP_DATA_ON_UNINSTALL' ) && RSMW_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

/**
 * Supprime toutes les options préfixées rsmw_ du site courant.
 *
 * Les données métier (stock physique, pointages, journal) ne sont effacées que
 * si l'utilisateur l'a explicitement demandé dans les réglages : elles sont
 * partagées avec le snippet que ce plugin remplace, et les supprimer par défaut
 * rendrait tout retour arrière impossible.
 */
function rsmw_uninstall_delete_options(): void {
	global $wpdb;

	$delete_data = 'yes' === get_option( 'rsmw_delete_data_on_uninstall', 'no' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- désinstallation ponctuelle, pas de cache pertinent.
	$option_names = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rsmw\\_%'"
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	foreach ( (array) $option_names as $option_name ) {
		delete_option( $option_name );
	}

	wp_clear_scheduled_hook( 'rsmw_daily_maintenance' );

	/*
	 * Résidus de la bibliothèque de mise à jour. Elle nettoie normalement elle-même
	 * sur l'action `uninstall_{plugin}`, mais la présence de ce fichier uninstall.php
	 * court-circuite cette action : le ménage doit donc être fait ici.
	 */
	$puc_slug = 'real-stock-manager-for-woocommerce';

	delete_option( 'external_updates-' . $puc_slug );
	delete_site_option( 'external_updates-' . $puc_slug );
	wp_clear_scheduled_hook( 'puc_cron_check_updates-' . $puc_slug );

	// Caches de la table des besoins : sans valeur, toujours reconstructibles.
	delete_transient( 'mh_prep_demand_v1' );
	delete_transient( 'mh_prep_demand_v1_meta' );
	delete_transient( 'rsmw_prep_allocatable' );

	if ( ! $delete_data ) {
		return;
	}

	// Données métier, sur demande explicite uniquement.
	delete_option( 'mh_prep_receptions' );

	/*
	 * Fournisseurs. `wp_delete_term()` détache chaque produit et purge les lignes
	 * de relation : c'est justement l'avantage de la taxonomie sur un type de
	 * contenu, qui laisserait des identifiants pointant dans le vide. La
	 * taxonomie n'est plus enregistrée à cet instant — le plugin est désactivé —
	 * d'où la déclaration minimale avant suppression, sans laquelle
	 * `wp_delete_term()` refuserait de travailler.
	 */
	if ( ! taxonomy_exists( 'rsmw_supplier' ) ) {
		register_taxonomy( 'rsmw_supplier', array( 'product' ), array( 'public' => false ) );
	}

	$rsmw_supplier_terms = get_terms(
		array(
			'taxonomy'   => 'rsmw_supplier',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);

	if ( ! is_wp_error( $rsmw_supplier_terms ) ) {
		foreach ( (array) $rsmw_supplier_terms as $rsmw_term_id ) {
			wp_delete_term( (int) $rsmw_term_id, 'rsmw_supplier' );
		}
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- désinstallation ponctuelle.
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_mh_stock_reel' ) );
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_rsmw_stock_ordered' ) );
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_rsmw_stock_defective' ) );

	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_mh_preorder_date' ) );

	/*
	 * Métas de COMMANDE. Elles ne vivent dans postmeta qu'en stockage historique :
	 * sous HPOS elles sont dans wc_orders_meta, et supprimer les unes sans les
	 * autres laisserait la moitié des traces en base. On balaie les deux tables,
	 * en vérifiant l'existence de la seconde — une boutique n'ayant jamais activé
	 * HPOS ne l'a pas.
	 */
	$order_meta_keys = array(
		'_rsmw_has_preorder',
		'_rsmw_preorder_date_max',
		'_rsmw_preorder_status_applied',
		'_mh_prep_prev_status',
	);

	$order_meta_tables = array( $wpdb->postmeta );
	$hpos_meta_table   = $wpdb->prefix . 'wc_orders_meta';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta_table ) ) === $hpos_meta_table ) {
		$order_meta_tables[] = $hpos_meta_table;
	}

	foreach ( $order_meta_tables as $order_meta_table ) {
		foreach ( $order_meta_keys as $order_meta_key ) {
			$wpdb->delete( $order_meta_table, array( 'meta_key' => $order_meta_key ) );
		}
	}

	$item_meta_keys = array(
		'_mh_prep_qty',
		'_mh_prep_from_stock',
		'_mh_prep_date',
		'_mh_prep_user',
		'_rsmw_prep_ordered',
		// Précommandes. « Expédition estimée » est une clé littérale, pas une
		// traduction : c'est le libellé que le client voit sur sa commande.
		'_mh_preorder_date',
		'_rsmw_preorder_qty',
		'_rsmw_preorder_filled_at',
		'Expédition estimée',
	);

	foreach ( $item_meta_keys as $item_meta_key ) {
		$wpdb->delete( $wpdb->prefix . 'woocommerce_order_itemmeta', array( 'meta_key' => $item_meta_key ) );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

if ( is_multisite() ) {
	// 'number' => 0 : sans quoi get_sites() s'arrête aux 100 premiers sites et
	// le reste du réseau garderait ses données en base, silencieusement.
	$rsmw_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $rsmw_site_ids as $rsmw_site_id ) {
		switch_to_blog( (int) $rsmw_site_id );
		rsmw_uninstall_delete_options();
		restore_current_blog();
	}
} else {
	rsmw_uninstall_delete_options();
}
