<?php
/**
 * Creates or updates a WooCommerce product from one validated row, matched by SKU.
 *
 * Uses the WooCommerce CRUD classes (never direct post/meta writes), so lookup
 * tables, caches and HPOS-related hooks stay consistent.
 *
 * @package WCPS
 */

namespace WCPS\Import;

defined( 'ABSPATH' ) || exit;

final class ProductUpserter implements ProductWriter {

	/**
	 * @param array<string,mixed> $data Normalized row data from RowValidator.
	 * @return array{action:string, product_id:int}
	 * @throws ImportException When the product can't be saved.
	 */
	public function upsert( array $data ): array {
		/**
		 * Filters row data right before it is written to a product.
		 *
		 * @param array<string,mixed> $data
		 */
		$data = (array) apply_filters( 'wcps_product_data', $data );

		$existing_id = wc_get_product_id_by_sku( $data['sku'] );
		$product     = $existing_id ? wc_get_product( $existing_id ) : new \WC_Product_Simple();

		if ( ! $product instanceof \WC_Product ) {
			throw new ImportException( 'Could not load the existing product for this SKU.' );
		}
		if ( $existing_id && ! $product->is_type( 'simple' ) ) {
			throw new ImportException( sprintf( 'SKU belongs to a %s product; only simple products are synced.', $product->get_type() ) );
		}

		try {
			$this->apply( $product, $data, ! $existing_id );
			$product_id = $product->save();
		} catch ( \WC_Data_Exception $e ) {
			throw new ImportException( $e->getMessage(), 0, $e );
		}

		if ( ! $product_id ) {
			throw new ImportException( 'WooCommerce did not save the product.' );
		}

		$action = $existing_id ? ImportRepository::ROW_UPDATED : ImportRepository::ROW_CREATED;

		/**
		 * Fires after a product has been created or updated by an import.
		 *
		 * @param \WC_Product         $product
		 * @param array<string,mixed> $data
		 * @param string              $action "created" or "updated".
		 */
		do_action( 'wcps_product_saved', $product, $data, $action );

		return array(
			'action'     => $action,
			'product_id' => (int) $product_id,
		);
	}

	/**
	 * Only fields present in the row are written, so an update CSV with just
	 * "sku,name,stock" leaves prices and descriptions alone.
	 *
	 * @throws \WC_Data_Exception From WooCommerce setters (e.g. duplicate SKU).
	 */
	private function apply( \WC_Product $product, array $data, bool $is_new ): void {
		if ( $is_new ) {
			$product->set_sku( $data['sku'] );
		}

		$product->set_name( $data['name'] );
		$product->set_status( $data['status'] ?? 'publish' );

		if ( isset( $data['regular_price'] ) ) {
			$product->set_regular_price( $data['regular_price'] );
		}
		if ( array_key_exists( 'sale_price', $data ) ) {
			$product->set_sale_price( $data['sale_price'] );
		} elseif ( isset( $data['regular_price'] ) && '' !== $product->get_sale_price() && (float) $product->get_sale_price() >= (float) $data['regular_price'] ) {
			// A new regular price would make the old sale price invalid, so drop it.
			$product->set_sale_price( '' );
		}

		if ( isset( $data['stock_quantity'] ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $data['stock_quantity'] );
		}
		if ( isset( $data['weight'] ) ) {
			$product->set_weight( $data['weight'] );
		}
		if ( isset( $data['description'] ) ) {
			$product->set_description( wp_kses_post( $data['description'] ) );
		}
		if ( isset( $data['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $data['short_description'] ) );
		}
		if ( ! empty( $data['categories'] ) ) {
			$product->set_category_ids( $this->resolve_categories( $data['categories'] ) );
		}
	}

	/**
	 * Turns ["Clothing > Shirts", "Sale"] into term IDs, creating any missing
	 * categories (and parents) along the way.
	 *
	 * @param string[] $paths
	 * @return int[]
	 */
	private function resolve_categories( array $paths ): array {
		$ids = array();

		foreach ( $paths as $path ) {
			$parent = 0;
			foreach ( array_map( 'trim', explode( '>', $path ) ) as $name ) {
				if ( '' === $name ) {
					continue;
				}
				$term = term_exists( $name, 'product_cat', $parent );
				if ( ! $term ) {
					$term = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
				}
				if ( is_wp_error( $term ) ) {
					throw new ImportException( sprintf( 'Could not create category "%s": %s', $name, $term->get_error_message() ) );
				}
				$parent = (int) $term['term_id'];
			}
			if ( $parent ) {
				$ids[] = $parent;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
