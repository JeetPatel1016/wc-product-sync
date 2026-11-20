<?php
/**
 * Removes the plugin's tables, options and scheduled jobs on uninstall.
 * Products that were imported are left untouched.
 *
 * @package WCPS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wcps_process_batch', array(), 'wc-product-sync' );
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcps_import_rows" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcps_imports" );
// phpcs:enable

delete_option( 'wcps_db_version' );
