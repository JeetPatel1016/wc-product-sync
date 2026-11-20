<?php
/**
 * Validates and normalizes product rows before they touch the database.
 *
 * Pure PHP with no WordPress dependencies, so it can be unit tested on its own.
 * Extra site-specific rules are added through the `wcps_validate_row` filter in
 * ImportService rather than here.
 *
 * @package WCPS
 */

namespace WCPS\Import;

final class RowValidator {

	public const STATUSES = array( 'publish', 'draft', 'private', 'pending' );

	/**
	 * Validate a whole batch, which also lets us catch SKUs repeated within one file.
	 *
	 * @param array<int, array<string,string>> $rows Rows keyed by their CSV line number.
	 * @return array<int, ValidationResult> Results keyed by the same line numbers.
	 */
	public function validate_batch( array $rows ): array {
		$results = array();
		$seen    = array();

		foreach ( $rows as $line => $row ) {
			$result = $this->validate( $row );
			$sku    = strtolower( (string) ( $result->data['sku'] ?? '' ) );

			if ( '' !== $sku ) {
				if ( isset( $seen[ $sku ] ) ) {
					$result = $result->with_error( sprintf( 'Duplicate SKU, already used on row %d.', $seen[ $sku ] ) );
				} else {
					$seen[ $sku ] = $line;
				}
			}
			$results[ $line ] = $result;
		}

		return $results;
	}

	/**
	 * @param array<string,string> $row Raw row from the parser or the REST API.
	 */
	public function validate( array $row ): ValidationResult {
		$errors = array();
		$data   = array();

		// SKU: required, used as the match key for create-vs-update.
		$sku = trim( (string) ( $row['sku'] ?? '' ) );
		if ( '' === $sku ) {
			$errors[] = 'SKU is required.';
		} elseif ( strlen( $sku ) > 100 ) {
			$errors[] = 'SKU must be 100 characters or fewer.';
		} elseif ( ! preg_match( '/^[A-Za-z0-9._\-]+$/', $sku ) ) {
			$errors[] = 'SKU may only contain letters, numbers, dots, dashes and underscores.';
		}
		$data['sku'] = $sku;

		// Name: required.
		$name = trim( (string) ( $row['name'] ?? '' ) );
		if ( '' === $name ) {
			$errors[] = 'Name is required.';
		} elseif ( mb_strlen( $name ) > 200 ) {
			$errors[] = 'Name must be 200 characters or fewer.';
		}
		$data['name'] = $name;

		// Prices: optional, but must be valid money amounts when present.
		foreach ( array( 'regular_price', 'sale_price' ) as $field ) {
			if ( ! isset( $row[ $field ] ) || '' === trim( (string) $row[ $field ] ) ) {
				continue;
			}
			$price = $this->normalize_price( (string) $row[ $field ] );
			if ( null === $price ) {
				$errors[] = sprintf( '%s "%s" is not a valid price.', $this->label( $field ), $row[ $field ] );
				continue;
			}
			$data[ $field ] = $price;
		}

		if ( isset( $data['sale_price'] ) ) {
			if ( ! isset( $data['regular_price'] ) ) {
				$errors[] = 'Sale price needs a regular price.';
			} elseif ( (float) $data['sale_price'] >= (float) $data['regular_price'] ) {
				$errors[] = 'Sale price must be lower than the regular price.';
			}
		}

		// Stock: optional whole number >= 0.
		if ( isset( $row['stock_quantity'] ) && '' !== trim( (string) $row['stock_quantity'] ) ) {
			$stock = trim( (string) $row['stock_quantity'] );
			if ( ! preg_match( '/^\d+$/', $stock ) ) {
				$errors[] = sprintf( 'Stock quantity "%s" must be a whole number of 0 or more.', $stock );
			} else {
				$data['stock_quantity'] = (int) $stock;
			}
		}

		// Status: optional, defaults to publish.
		$status = strtolower( trim( (string) ( $row['status'] ?? '' ) ) );
		if ( '' === $status ) {
			$status = 'publish';
		} elseif ( ! in_array( $status, self::STATUSES, true ) ) {
			$errors[] = sprintf( 'Status "%s" must be one of: %s.', $status, implode( ', ', self::STATUSES ) );
		}
		$data['status'] = $status;

		// Weight: optional positive number.
		if ( isset( $row['weight'] ) && '' !== trim( (string) $row['weight'] ) ) {
			$weight = trim( (string) $row['weight'] );
			if ( ! is_numeric( $weight ) || (float) $weight < 0 ) {
				$errors[] = sprintf( 'Weight "%s" must be a number of 0 or more.', $weight );
			} else {
				$data['weight'] = $weight;
			}
		}

		// Categories: "Shirts | Summer > Sale" becomes a de-duplicated list.
		if ( isset( $row['categories'] ) && '' !== trim( (string) $row['categories'] ) ) {
			$categories         = array_filter( array_map( 'trim', explode( '|', (string) $row['categories'] ) ), 'strlen' );
			$data['categories'] = array_values( array_unique( $categories ) );
		}

		foreach ( array( 'description', 'short_description' ) as $field ) {
			if ( isset( $row[ $field ] ) && '' !== trim( (string) $row[ $field ] ) ) {
				$data[ $field ] = trim( (string) $row[ $field ] );
			}
		}

		return new ValidationResult( $data, $errors );
	}

	/**
	 * "$1,299.5" -> "1299.50". Returns null for anything that isn't a valid amount.
	 */
	public function normalize_price( string $value ): ?string {
		$clean = str_replace( array( '$', ',', ' ' ), '', trim( $value ) );
		if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', $clean ) ) {
			return null;
		}
		return number_format( (float) $clean, 2, '.', '' );
	}

	private function label( string $field ): string {
		return ucfirst( str_replace( '_', ' ', $field ) );
	}
}
