<?php

namespace WCPS\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WCPS\Import\RowValidator;

final class RowValidatorTest extends TestCase {

	private RowValidator $validator;

	protected function setUp(): void {
		$this->validator = new RowValidator();
	}

	public function test_valid_row_is_normalized(): void {
		$result = $this->validator->validate(
			array(
				'sku'            => ' TS-1 ',
				'name'           => 'T-Shirt',
				'regular_price'  => '$1,299.5',
				'sale_price'     => '999',
				'stock_quantity' => '12',
				'status'         => 'Draft',
				'categories'     => 'Clothing > Shirts | Sale | Sale',
			)
		);

		$this->assertTrue( $result->is_valid(), implode( ' ', $result->errors ) );
		$this->assertSame( 'TS-1', $result->data['sku'] );
		$this->assertSame( '1299.50', $result->data['regular_price'] );
		$this->assertSame( '999.00', $result->data['sale_price'] );
		$this->assertSame( 12, $result->data['stock_quantity'] );
		$this->assertSame( 'draft', $result->data['status'] );
		$this->assertSame( array( 'Clothing > Shirts', 'Sale' ), $result->data['categories'] );
	}

	public function test_status_defaults_to_publish(): void {
		$result = $this->validator->validate( array( 'sku' => 'A', 'name' => 'B' ) );

		$this->assertSame( 'publish', $result->data['status'] );
	}

	public function test_optional_empty_fields_are_left_out(): void {
		$result = $this->validator->validate( array( 'sku' => 'A', 'name' => 'B', 'regular_price' => '', 'stock_quantity' => ' ' ) );

		$this->assertTrue( $result->is_valid() );
		$this->assertArrayNotHasKey( 'regular_price', $result->data );
		$this->assertArrayNotHasKey( 'stock_quantity', $result->data );
	}

	/**
	 * @return array<string, array{array<string,string>, string}>
	 */
	public static function invalid_rows(): array {
		return array(
			'missing sku'             => array( array( 'name' => 'Mug' ), 'SKU is required.' ),
			'bad sku characters'      => array( array( 'sku' => 'MUG 1', 'name' => 'Mug' ), 'SKU may only contain' ),
			'missing name'            => array( array( 'sku' => 'M-1' ), 'Name is required.' ),
			'bad price'               => array( array( 'sku' => 'M-1', 'name' => 'Mug', 'regular_price' => 'free' ), 'is not a valid price' ),
			'three decimals'          => array( array( 'sku' => 'M-1', 'name' => 'Mug', 'regular_price' => '9.999' ), 'is not a valid price' ),
			'negative stock'          => array( array( 'sku' => 'M-1', 'name' => 'Mug', 'stock_quantity' => '-2' ), 'whole number' ),
			'fractional stock'        => array( array( 'sku' => 'M-1', 'name' => 'Mug', 'stock_quantity' => '2.5' ), 'whole number' ),
			'unknown status'          => array( array( 'sku' => 'M-1', 'name' => 'Mug', 'status' => 'live' ), 'must be one of' ),
			'sale above regular'      => array( array( 'sku' => 'M-1', 'name' => 'Mug', 'regular_price' => '10', 'sale_price' => '12' ), 'lower than the regular price' ),
			'sale without regular'    => array( array( 'sku' => 'M-1', 'name' => 'Mug', 'sale_price' => '5' ), 'needs a regular price' ),
			'negative weight'         => array( array( 'sku' => 'M-1', 'name' => 'Mug', 'weight' => '-1' ), 'Weight' ),
		);
	}

	/**
	 * @param array<string,string> $row
	 */
	#[DataProvider( 'invalid_rows' )]
	public function test_invalid_rows_are_rejected( array $row, string $expected ): void {
		$result = $this->validator->validate( $row );

		$this->assertFalse( $result->is_valid() );
		$this->assertStringContainsString( $expected, implode( ' ', $result->errors ) );
	}

	public function test_reports_every_problem_in_a_row(): void {
		$result = $this->validator->validate( array( 'regular_price' => 'abc', 'status' => 'live' ) );

		$this->assertCount( 4, $result->errors );
	}

	public function test_batch_flags_duplicate_skus_case_insensitively(): void {
		$results = $this->validator->validate_batch(
			array(
				2 => array( 'sku' => 'MUG-1', 'name' => 'Mug' ),
				3 => array( 'sku' => 'cup-1', 'name' => 'Cup' ),
				4 => array( 'sku' => 'mug-1', 'name' => 'Mug again' ),
			)
		);

		$this->assertTrue( $results[2]->is_valid() );
		$this->assertTrue( $results[3]->is_valid() );
		$this->assertFalse( $results[4]->is_valid() );
		$this->assertSame( array( 'Duplicate SKU, already used on row 2.' ), $results[4]->errors );
	}

	public function test_normalize_price(): void {
		$this->assertSame( '10.00', $this->validator->normalize_price( '10' ) );
		$this->assertSame( '0.50', $this->validator->normalize_price( '0.5' ) );
		$this->assertSame( '2499.00', $this->validator->normalize_price( '$2,499' ) );
		$this->assertNull( $this->validator->normalize_price( '-5' ) );
		$this->assertNull( $this->validator->normalize_price( '1e3' ) );
	}
}
