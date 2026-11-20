<?php
/**
 * Outcome of validating one row: the cleaned data plus any errors.
 *
 * @package WCPS
 */

namespace WCPS\Import;

final class ValidationResult {

	/**
	 * @param array<string,mixed> $data   Normalized row data.
	 * @param string[]            $errors Human-readable problems with the row.
	 */
	public function __construct(
		public readonly array $data,
		public readonly array $errors = array()
	) {}

	public function is_valid(): bool {
		return array() === $this->errors;
	}

	public function with_error( string $error ): self {
		return new self( $this->data, array_merge( $this->errors, array( $error ) ) );
	}
}
