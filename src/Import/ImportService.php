<?php
/**
 * Entry point for starting an import from any source (admin upload, REST, CLI).
 *
 * Parses and validates everything up front, stores every row with its outcome,
 * then hands the valid rows to the background processor.
 *
 * @package WCPS
 */

namespace WCPS\Import;

defined( 'ABSPATH' ) || exit;

final class ImportService {

	public const MAX_ROWS = 10000;

	public function __construct(
		private ImportRepository $repository,
		private RowValidator $validator,
		private CsvParser $parser = new CsvParser()
	) {}

	/**
	 * @throws ImportException When the file can't be parsed.
	 */
	public function start_from_csv( string $path, string $filename, string $source, int $user_id ): int {
		$parsed = $this->parser->parse_file( $path );
		return $this->start( $parsed, $filename, $source, $user_id );
	}

	/**
	 * Start an import from already-structured rows (e.g. a JSON REST request).
	 *
	 * @param array<int, array<string,mixed>> $rows
	 * @throws ImportException When there are no rows or too many.
	 */
	public function start_from_rows( array $rows, string $source, int $user_id ): int {
		$parsed = array();
		foreach ( array_values( $rows ) as $i => $row ) {
			$parsed[] = array(
				'row_number' => $i + 1,
				'data'       => array_map( 'strval', array_filter( (array) $row, 'is_scalar' ) ),
				'error'      => null,
			);
		}
		return $this->start( $parsed, '', $source, $user_id );
	}

	/**
	 * @param array<int, array{row_number:int, data:array<string,string>, error:?string}> $parsed
	 */
	private function start( array $parsed, string $filename, string $source, int $user_id ): int {
		/** Filters the maximum number of rows accepted in one import. */
		$max = (int) apply_filters( 'wcps_max_rows', self::MAX_ROWS );

		if ( array() === $parsed ) {
			throw new ImportException( __( 'There are no product rows to import.', 'wc-product-sync' ) );
		}
		if ( count( $parsed ) > $max ) {
			/* translators: %d: maximum rows */
			throw new ImportException( sprintf( __( 'Imports are limited to %d rows. Split the file and try again.', 'wc-product-sync' ), $max ) );
		}

		// Rows the parser already rejected (wrong column count) skip validation.
		$to_validate = array();
		foreach ( $parsed as $row ) {
			if ( null === $row['error'] ) {
				$to_validate[ $row['row_number'] ] = $row['data'];
			}
		}
		$results = $this->validator->validate_batch( $to_validate );

		$records = array();
		foreach ( $parsed as $row ) {
			$line = $row['row_number'];

			if ( null !== $row['error'] ) {
				$records[] = $this->record( $line, '', array(), array( $row['error'] ) );
				continue;
			}

			$result = $results[ $line ];

			/**
			 * Filters the validation errors for a row, so a site can add its own rules
			 * (e.g. "SKU must start with ACME-").
			 *
			 * @param string[]            $errors Errors found so far.
			 * @param array<string,mixed> $data   Normalized row data.
			 * @param int                 $line   Row number in the source.
			 */
			$errors = (array) apply_filters( 'wcps_validate_row', $result->errors, $result->data, $line );

			$records[] = $this->record( $line, (string) ( $result->data['sku'] ?? '' ), $result->data, $errors );
		}

		$import_id = $this->repository->create_import( $source, sanitize_file_name( $filename ), $user_id );
		$this->repository->insert_rows( $import_id, $records );

		if ( $this->repository->pending_count( $import_id ) > 0 ) {
			do_action( 'wcps_import_queued', $import_id );
		} else {
			$this->repository->set_status( $import_id, ImportRepository::STATUS_COMPLETED );
		}

		return $import_id;
	}

	private function record( int $line, string $sku, array $data, array $errors ): array {
		return array(
			'row_number' => $line,
			'sku'        => $sku,
			'payload'    => $data,
			'status'     => $errors ? ImportRepository::ROW_FAILED : ImportRepository::ROW_PENDING,
			'message'    => implode( ' ', $errors ),
		);
	}
}
