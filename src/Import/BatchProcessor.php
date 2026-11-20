<?php
/**
 * Processes pending import rows in small batches in the background using
 * Action Scheduler (bundled with WooCommerce), so a 10,000-row file never
 * runs inside a single web request.
 *
 * @package WCPS
 */

namespace WCPS\Import;

defined( 'ABSPATH' ) || exit;

final class BatchProcessor {

	public const HOOK       = 'wcps_process_batch';
	public const GROUP      = 'wc-product-sync';
	public const BATCH_SIZE = 25;

	public function __construct(
		private ImportRepository $repository,
		private ProductWriter $writer
	) {}

	public function register(): void {
		add_action( 'wcps_import_queued', array( $this, 'schedule' ) );
		add_action( self::HOOK, array( $this, 'process_batch' ) );
	}

	public function schedule( int $import_id ): void {
		if ( ! as_has_scheduled_action( self::HOOK, array( $import_id ), self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK, array( $import_id ), self::GROUP );
		}
	}

	/**
	 * Process one batch, then queue the next one if rows are still pending.
	 */
	public function process_batch( int $import_id ): void {
		// A short lock stops two runners from processing the same import at once.
		$lock = 'wcps_lock_' . $import_id;
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$remaining = $this->run_batch( $import_id );
		} finally {
			delete_transient( $lock );
		}

		if ( $remaining > 0 ) {
			as_enqueue_async_action( self::HOOK, array( $import_id ), self::GROUP );
		}
	}

	/**
	 * Process every pending row synchronously. Used by WP-CLI.
	 *
	 * @param callable|null $on_batch Called with the import record after each batch.
	 */
	public function run_until_done( int $import_id, ?callable $on_batch = null ): void {
		do {
			$remaining = $this->run_batch( $import_id );
			if ( $on_batch ) {
				$on_batch( $this->repository->get_import( $import_id ) );
			}
		} while ( $remaining > 0 );
	}

	/**
	 * @return int Rows still pending after this batch.
	 */
	private function run_batch( int $import_id ): int {
		$import = $this->repository->get_import( $import_id );
		if ( ! $import || ImportRepository::STATUS_COMPLETED === $import['status'] ) {
			return 0;
		}

		$this->repository->set_status( $import_id, ImportRepository::STATUS_PROCESSING );

		/** Filters how many rows are processed per background batch. */
		$size = max( 1, (int) apply_filters( 'wcps_batch_size', self::BATCH_SIZE ) );

		foreach ( $this->repository->claim_pending_rows( $import_id, $size ) as $row ) {
			try {
				$result = $this->writer->upsert( $row['payload'] );
				$this->repository->mark_row( $row['id'], $result['action'], '', $result['product_id'] );
			} catch ( \Throwable $e ) {
				// One bad row must never stop the rest of the import.
				$this->repository->mark_row( $row['id'], ImportRepository::ROW_FAILED, $e->getMessage() );
			}
		}

		$this->repository->refresh_counts( $import_id );
		$remaining = $this->repository->pending_count( $import_id );

		if ( 0 === $remaining ) {
			$this->repository->set_status( $import_id, ImportRepository::STATUS_COMPLETED );

			/**
			 * Fires once every row of an import has been processed.
			 *
			 * @param array $import The final import record with its counts.
			 */
			do_action( 'wcps_import_completed', $this->repository->get_import( $import_id ) );
		}

		return $remaining;
	}
}
