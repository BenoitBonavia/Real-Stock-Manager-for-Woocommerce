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
 */
function rsmw_uninstall_delete_options(): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- désinstallation ponctuelle, pas de cache pertinent.
	$option_names = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rsmw\\_%'"
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	foreach ( (array) $option_names as $option_name ) {
		delete_option( $option_name );
	}

	wp_clear_scheduled_hook( 'rsmw_daily_maintenance' );
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
