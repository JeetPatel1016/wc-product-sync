<?php
/**
 * Writes one validated row to the store. ProductUpserter is the WooCommerce
 * implementation; tests (or other stores) can provide their own.
 *
 * @package WCPS
 */

namespace WCPS\Import;

interface ProductWriter {

	/**
	 * @param array<string,mixed> $data Normalized row data from RowValidator.
	 * @return array{action:string, product_id:int} action is "created" or "updated".
	 * @throws ImportException When the row can't be written.
	 */
	public function upsert( array $data ): array;
}
