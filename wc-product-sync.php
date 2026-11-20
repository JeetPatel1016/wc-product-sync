<?php
/**
 * Plugin Name:       WC Product Sync
 * Plugin URI:        https://github.com/jeetpatel/wc-product-sync
 * Description:       Import and sync WooCommerce products from CSV files or a REST API, with row-level validation, background batch processing and a full import log.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Jeet Patel
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-product-sync
 * WC requires at least: 8.0
 *
 * @package WCPS
 */

defined( 'ABSPATH' ) || exit;

define( 'WCPS_VERSION', '1.0.0' );
define( 'WCPS_FILE', __FILE__ );
define( 'WCPS_DIR', plugin_dir_path( __FILE__ ) );

// Use Composer's autoloader when present, otherwise a small PSR-4 fallback so the
// plugin also works when installed from a plain zip.
if ( file_exists( WCPS_DIR . 'vendor/autoload.php' ) ) {
	require_once WCPS_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( 0 !== strpos( $class_name, 'WCPS\\' ) ) {
				return;
			}
			$path = WCPS_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, 5 ) ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

register_activation_hook( __FILE__, array( \WCPS\Install\Schema::class, 'install' ) );

// Declare compatibility with WooCommerce High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'WC Product Sync requires WooCommerce to be installed and active.', 'wc-product-sync' ) .
						'</p></div>';
				}
			);
			return;
		}
		\WCPS\Plugin::instance()->boot();
	}
);
