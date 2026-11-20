<?php

namespace WCPS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCPS\Import\CsvParser;
use WCPS\Import\ImportException;

final class CsvParserTest extends TestCase {

	private CsvParser $parser;

	protected function setUp(): void {
		$this->parser = new CsvParser();
	}

	public function test_parses_rows_with_line_numbers(): void {
		$rows = $this->parser->parse_string( "sku,name,regular_price\nTS-1,T-Shirt,19.99\nTS-2,Hoodie,49\n" );

		$this->assertCount( 2, $rows );
		$this->assertSame( 2, $rows[0]['row_number'] );
		$this->assertSame( array( 'sku' => 'TS-1', 'name' => 'T-Shirt', 'regular_price' => '19.99' ), $rows[0]['data'] );
		$this->assertSame( 3, $rows[1]['row_number'] );
	}

	public function test_maps_header_aliases_and_ignores_unknown_columns(): void {
		$rows = $this->parser->parse_string( "Product SKU,Title, Price ,Qty,Internal Notes\nA-1,Mug,12,5,ignore me\n" );

		$this->assertSame(
			array( 'sku' => 'A-1', 'name' => 'Mug', 'regular_price' => '12', 'stock_quantity' => '5' ),
			$rows[0]['data']
		);
	}

	public function test_strips_utf8_bom_from_excel_exports(): void {
		$rows = $this->parser->parse_string( "\xEF\xBB\xBFsku,name\nA-1,Mug\n" );

		$this->assertSame( 'A-1', $rows[0]['data']['sku'] );
	}

	public function test_handles_quoted_commas_and_newlines(): void {
		$rows = $this->parser->parse_string( "sku,name,description\nA-1,\"Mug, large\",\"Line one\nLine two\"\n" );

		$this->assertSame( 'Mug, large', $rows[0]['data']['name'] );
		$this->assertSame( "Line one\nLine two", $rows[0]['data']['description'] );
	}

	public function test_skips_blank_lines_but_keeps_true_line_numbers(): void {
		$rows = $this->parser->parse_string( "sku,name\nA-1,Mug\n\n,\nA-2,Cup\n" );

		$this->assertCount( 2, $rows );
		$this->assertSame( 5, $rows[1]['row_number'] );
	}

	public function test_flags_rows_with_wrong_column_count(): void {
		$rows = $this->parser->parse_string( "sku,name,regular_price\nA-1,Mug\n" );

		$this->assertSame( 'Expected 3 columns but found 2.', $rows[0]['error'] );
	}

	public function test_rejects_missing_required_columns(): void {
		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'Missing required column(s): name.' );

		$this->parser->parse_string( "sku,price\nA-1,10\n" );
	}

	public function test_rejects_empty_input(): void {
		$this->expectException( ImportException::class );

		$this->parser->parse_string( '' );
	}

	public function test_parses_the_example_file(): void {
		$rows = $this->parser->parse_file( dirname( __DIR__, 2 ) . '/examples/sample-products.csv' );

		$this->assertNotEmpty( $rows );
		$this->assertArrayHasKey( 'categories', $rows[0]['data'] );
	}
}
