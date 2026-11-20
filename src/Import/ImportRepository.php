<?php
/**
 * All database access for imports and import rows. Every query goes through
 * $wpdb->prepare() or $wpdb's insert/update helpers.
 *
 * @package WCPS
 */

namespace WCPS\Import;

use WCPS\Install\Schema;

defined( 'ABSPATH' ) || exit;

final class ImportRepository {

	public const ROW_PENDING = 'pending';
	public const ROW_CREATED = 'created';
	public const ROW_UPDATED = 'updated';
	public const ROW_FAILED  = 'failed';

	public const STATUS_QUEUED     = 'queued';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_COMPLETED  = 'completed';

	private const INSERT_CHUNK = 200;

	public function __construct( private \wpdb $db ) {}

	public function create_import( string $source, string $filename, int $user_id ): int {
		$this->db->insert(
			Schema::imports_table(),
			array(
				'source'     => $source,
				'filename'   => $filename,
				'status'     => self::STATUS_QUEUED,
				'created_by' => $user_id,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $this->db->insert_id;
	}

	/**
	 * Bulk-insert rows with multi-row INSERTs, a few hundred at a time, so large
	 * files don't cost one query per row.
	 *
	 * @param array<int, array{row_number:int, sku:string, payload:array, status:string, message:?string}> $rows
	 */
	public function insert_rows( int $import_id, array $rows ): void {
		$table = Schema::rows_table();

		foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
			$placeholders = array();
			$values       = array();

			foreach ( $chunk as $row ) {
				$placeholders[] = '(%d, %d, %s, %s, %s, %s)';
				array_push(
					$values,
					$import_id,
					$row['row_number'],
					$row['sku'],
					wp_json_encode( $row['payload'] ),
					$row['status'],
					(string) ( $row['message'] ?? '' )
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are built above.
			$sql = "INSERT INTO {$table} (import_id, line_number, sku, payload, status, message) VALUES " . implode( ', ', $placeholders );
			$this->db->query( $this->db->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$this->refresh_counts( $import_id );
	}

	/**
	 * @return array<int, array{id:int, row_number:int, sku:string, payload:array}>
	 */
	public function claim_pending_rows( int $import_id, int $limit ): array {
		$table = Schema::rows_table();
		$rows  = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, line_number, sku, payload FROM {$table} WHERE import_id = %d AND status = %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$import_id,
				self::ROW_PENDING,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'row_number' => (int) $row['line_number'],
				'sku'        => $row['sku'],
				'payload'    => (array) json_decode( $row['payload'], true ),
			),
			$rows ? $rows : array()
		);
	}

	public function mark_row( int $row_id, string $status, string $message = '', ?int $product_id = null ): void {
		$this->db->update(
			Schema::rows_table(),
			array(
				'status'       => $status,
				'message'      => $message,
				'product_id'   => $product_id,
				'processed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $row_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	public function set_status( int $import_id, string $status ): void {
		$data = array( 'status' => $status );
		if ( self::STATUS_COMPLETED === $status ) {
			$data['finished_at'] = current_time( 'mysql', true );
		}
		$this->db->update( Schema::imports_table(), $data, array( 'id' => $import_id ) );
	}

	/**
	 * Recompute the summary counters from the rows table in one GROUP BY query,
	 * so the counters can never drift from the real row states.
	 */
	public function refresh_counts( int $import_id ): void {
		$table  = Schema::rows_table();
		$counts = $this->db->get_results(
			$this->db->prepare(
				"SELECT status, COUNT(*) AS total FROM {$table} WHERE import_id = %d GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$import_id
			),
			OBJECT_K
		);

		$get     = static fn( string $status ): int => isset( $counts[ $status ] ) ? (int) $counts[ $status ]->total : 0;
		$total   = array_sum( array_map( static fn( $c ): int => (int) $c->total, $counts ? $counts : array() ) );
		$pending = $get( self::ROW_PENDING );

		$this->db->update(
			Schema::imports_table(),
			array(
				'total_rows'     => $total,
				'processed_rows' => $total - $pending,
				'created_count'  => $get( self::ROW_CREATED ),
				'updated_count'  => $get( self::ROW_UPDATED ),
				'failed_count'   => $get( self::ROW_FAILED ),
			),
			array( 'id' => $import_id ),
			array( '%d', '%d', '%d', '%d', '%d' ),
			array( '%d' )
		);
	}

	public function pending_count( int $import_id ): int {
		$table = Schema::rows_table();
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE import_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$import_id,
				self::ROW_PENDING
			)
		);
	}

	public function get_import( int $import_id ): ?array {
		$table = Schema::imports_table();
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$table} WHERE id = %d", $import_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? $this->cast_import( $row ) : null;
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public function list_imports( int $per_page = 20, int $page = 1 ): array {
		$table = Schema::imports_table();
		$rows  = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$per_page,
				max( 0, ( $page - 1 ) * $per_page )
			),
			ARRAY_A
		);
		return array_map( array( $this, 'cast_import' ), $rows ? $rows : array() );
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public function get_rows( int $import_id, ?string $status = null, int $per_page = 50, int $page = 1 ): array {
		$table  = Schema::rows_table();
		$where  = 'import_id = %d';
		$params = array( $import_id );

		if ( null !== $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		$params[] = $per_page;
		$params[] = max( 0, ( $page - 1 ) * $per_page );

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT line_number, sku, status, message, product_id FROM {$table} WHERE {$where} ORDER BY line_number ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'row_number' => (int) $row['line_number'],
				'sku'        => $row['sku'],
				'status'     => $row['status'],
				'message'    => $row['message'],
				'product_id' => null === $row['product_id'] ? null : (int) $row['product_id'],
			),
			$rows ? $rows : array()
		);
	}

	public function delete_import( int $import_id ): void {
		$this->db->delete( Schema::rows_table(), array( 'import_id' => $import_id ), array( '%d' ) );
		$this->db->delete( Schema::imports_table(), array( 'id' => $import_id ), array( '%d' ) );
	}

	private function cast_import( array $row ): array {
		foreach ( array( 'id', 'total_rows', 'processed_rows', 'created_count', 'updated_count', 'failed_count', 'created_by' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['progress'] = $row['total_rows'] > 0 ? round( $row['processed_rows'] / $row['total_rows'] * 100, 1 ) : 0.0;
		return $row;
	}
}
