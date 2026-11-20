<?php
/**
 * Reads a product CSV into associative rows keyed by canonical field names.
 *
 * Pure PHP with no WordPress dependencies, so it can be unit tested on its own.
 *
 * @package WCPS
 */

namespace WCPS\Import;

final class CsvParser {

	/** Header aliases people commonly use in spreadsheets, mapped to canonical fields. */
	private const ALIASES = array(
		'sku'               => 'sku',
		'product_sku'       => 'sku',
		'name'              => 'name',
		'title'             => 'name',
		'product_name'      => 'name',
		'regular_price'     => 'regular_price',
		'price'             => 'regular_price',
		'sale_price'        => 'sale_price',
		'stock'             => 'stock_quantity',
		'stock_quantity'    => 'stock_quantity',
		'qty'               => 'stock_quantity',
		'quantity'          => 'stock_quantity',
		'status'            => 'status',
		'description'       => 'description',
		'short_description' => 'short_description',
		'categories'        => 'categories',
		'category'          => 'categories',
		'weight'            => 'weight',
	);

	public const REQUIRED_HEADERS = array( 'sku', 'name' );

	/**
	 * @return array<int, array{row_number:int, data:array<string,string>, error:?string}>
	 * @throws ImportException When the file can't be read or required columns are missing.
	 */
	public function parse_file( string $path ): array {
		if ( ! is_readable( $path ) ) {
			throw new ImportException( sprintf( 'CSV file "%s" is not readable.', basename( $path ) ) );
		}
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			throw new ImportException( 'Could not read the CSV file.' );
		}
		return $this->parse_string( $contents );
	}

	/**
	 * @return array<int, array{row_number:int, data:array<string,string>, error:?string}>
	 * @throws ImportException When required columns are missing.
	 */
	public function parse_string( string $csv ): array {
		// Strip a UTF-8 byte-order mark, which Excel adds on "CSV UTF-8" export.
		$csv = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );

		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $csv );
		rewind( $handle );

		$header = fgetcsv( $handle, 0, ',', '"', '' );
		if ( false === $header || array( null ) === $header ) {
			fclose( $handle );
			throw new ImportException( 'The CSV file is empty.' );
		}

		$columns = array_map( array( $this, 'canonical_header' ), $header );
		$missing = array_diff( self::REQUIRED_HEADERS, $columns );
		if ( $missing ) {
			fclose( $handle );
			throw new ImportException( 'Missing required column(s): ' . implode( ', ', $missing ) . '.' );
		}

		$rows   = array();
		$line   = 1;
		$fields = count( $columns );

		while ( true ) {
			$cells = fgetcsv( $handle, 0, ',', '"', '' );
			if ( false === $cells ) {
				break;
			}
			++$line;

			// Skip blank lines.
			if ( array( null ) === $cells || '' === trim( implode( '', $cells ) ) ) {
				continue;
			}

			if ( count( $cells ) !== $fields ) {
				$rows[] = array(
					'row_number' => $line,
					'data'       => array(),
					'error'      => sprintf( 'Expected %d columns but found %d.', $fields, count( $cells ) ),
				);
				continue;
			}

			$data = array();
			foreach ( $columns as $i => $column ) {
				if ( null === $column ) {
					continue; // Unknown column, ignored.
				}
				$data[ $column ] = trim( (string) $cells[ $i ] );
			}

			$rows[] = array(
				'row_number' => $line,
				'data'       => $data,
				'error'      => null,
			);
		}

		fclose( $handle );
		return $rows;
	}

	/**
	 * "Regular Price " -> "regular_price". Unknown headers map to null.
	 */
	public function canonical_header( ?string $header ): ?string {
		$key = strtolower( trim( (string) $header ) );
		$key = preg_replace( '/[^a-z0-9]+/', '_', $key );
		$key = trim( $key, '_' );
		return self::ALIASES[ $key ] ?? null;
	}
}
