<?php
/**
 * WP-CLI commands for running and inspecting imports.
 *
 * @package WCPS
 */

namespace WCPS\Cli;

use WCPS\Import\BatchProcessor;
use WCPS\Import\ImportException;
use WCPS\Import\ImportRepository;
use WCPS\Import\ImportService;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Import and sync WooCommerce products.
 */
final class ImportCommand {

	public function __construct(
		private ImportService $service,
		private ImportRepository $repository,
		private BatchProcessor $processor
	) {}

	/**
	 * Import products from a CSV file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file.
	 *
	 * [--async]
	 * : Queue the import for background processing instead of running it now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp product-sync import products.csv
	 *     wp product-sync import supplier-feed.csv --async
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function import( array $args, array $assoc_args ): void {
		$async = WP_CLI\Utils\get_flag_value( $assoc_args, 'async', false );

		// When running in the foreground, don't also queue a background job.
		if ( ! $async ) {
			remove_action( 'wcps_import_queued', array( $this->processor, 'schedule' ) );
		}

		try {
			$id = $this->service->start_from_csv( $args[0], basename( $args[0] ), 'cli', get_current_user_id() );
		} catch ( ImportException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		if ( $async ) {
			WP_CLI::success( sprintf( 'Import #%d queued. Check it with: wp product-sync status %d', $id, $id ) );
			return;
		}

		$import   = $this->repository->get_import( $id );
		$progress = WP_CLI\Utils\make_progress_bar( sprintf( 'Importing %d rows', $import['total_rows'] ), $import['total_rows'] );
		$done     = $import['processed_rows'];
		$progress->tick( $done );

		$this->processor->run_until_done(
			$id,
			static function ( array $import ) use ( $progress, &$done ): void {
				$progress->tick( $import['processed_rows'] - $done );
				$done = $import['processed_rows'];
			}
		);
		$progress->finish();

		$this->print_summary( $this->repository->get_import( $id ) );
	}

	/**
	 * Show an import's progress and its failed rows.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Import ID.
	 *
	 * [--format=<format>]
	 * : Output format for failed rows.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function status( array $args, array $assoc_args ): void {
		$import = $this->repository->get_import( (int) $args[0] );
		if ( ! $import ) {
			WP_CLI::error( 'Import not found.' );
		}
		$this->print_summary( $import );

		$failed = $this->repository->get_rows( $import['id'], ImportRepository::ROW_FAILED, 100 );
		if ( $failed ) {
			WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $failed, array( 'row_number', 'sku', 'message' ) );
		}
	}

	private function print_summary( array $import ): void {
		$message = sprintf(
			'Import #%d %s: %d created, %d updated, %d failed (%d rows).',
			$import['id'],
			$import['status'],
			$import['created_count'],
			$import['updated_count'],
			$import['failed_count'],
			$import['total_rows']
		);
		$import['failed_count'] > 0 ? WP_CLI::warning( $message ) : WP_CLI::success( $message );
	}
}
