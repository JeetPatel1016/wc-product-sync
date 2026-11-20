<?php
/**
 * Custom database tables for imports and their rows.
 *
 * @package WCPS
 */

namespace WCPS\Install;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const DB_VERSION        = '1.0.0';
	public const DB_VERSION_OPTION = 'wcps_db_version';

	public static function imports_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wcps_imports';
	}

	public static function rows_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wcps_import_rows';
	}

	/**
	 * Create or update the tables. dbDelta() diffs the definitions against the
	 * live schema, so this is safe to run on every version bump.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$imports = self::imports_table();
		$rows    = self::rows_table();

		dbDelta(
			"CREATE TABLE {$imports} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source varchar(20) NOT NULL DEFAULT 'csv',
				filename varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'queued',
				total_rows int(10) unsigned NOT NULL DEFAULT 0,
				processed_rows int(10) unsigned NOT NULL DEFAULT 0,
				created_count int(10) unsigned NOT NULL DEFAULT 0,
				updated_count int(10) unsigned NOT NULL DEFAULT 0,
				failed_count int(10) unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				finished_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$rows} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				import_id bigint(20) unsigned NOT NULL,
				line_number int(10) unsigned NOT NULL,
				sku varchar(100) NOT NULL DEFAULT '',
				payload longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				message text NULL,
				product_id bigint(20) unsigned NULL DEFAULT NULL,
				processed_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY import_status (import_id,status),
				KEY sku (sku)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
