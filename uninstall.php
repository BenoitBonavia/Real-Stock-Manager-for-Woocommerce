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

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- désinstallation ponctuelle.
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_mh_stock_reel' ) );
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_rsmw_stock_ordered' ) );

	foreach ( array( '_mh_prep_qty', '_mh_prep_from_stock', '_mh_prep_date', '_mh_prep_user', '_rsmw_prep_ordered' ) as $item_meta_key ) {
		$wpdb->delete( $wpdb->prefix . 'woocommerce_order_itemmeta', array( 'meta_key' => $item_meta_key ) );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

if ( is_multisite() ) {
	$rsmw_site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $rsmw_site_ids as $rsmw_site_id ) {
		switch_to_blog( (int) $rsmw_site_id );
		rsmw_uninstall_delete_options();
		restore_current_blog();
	}
} else {
	rsmw_uninstall_delete_options();
}
