# WC Product Sync

A WooCommerce plugin for importing and syncing products from CSV files or a REST API. Every row is validated before it touches the store, valid rows are processed in background batches with Action Scheduler, and each row's outcome is kept in an import log you can review, export and re-run.

![CI](https://github.com/jeetpatel/wc-product-sync/actions/workflows/ci.yml/badge.svg)

## Why

Most stores still receive product data as spreadsheets from suppliers or an ERP. WooCommerce's built-in importer runs inside a single browser request, stops on the first bad row and tells you little about what went wrong. This plugin is built for repeated, unattended syncs:

- **Validate first, write second.** The whole file is parsed and checked up front. Bad rows are logged with a clear reason and never block the good ones.
- **Background processing.** Rows are written in small batches through Action Scheduler, so a 10,000-row file never hits a PHP timeout.
- **Create or update by SKU.** New SKUs become products and existing SKUs are updated. Only the columns present in the file are changed.
- **Three ways in:** an admin upload screen, a REST API for other systems, and a WP-CLI command for cron jobs and servers.

## Features

| Area | What it does |
| --- | --- |
| CSV parsing | Header aliases (`Title`, `Price`, `Qty`...), UTF-8 BOM handling for Excel exports, quoted commas and newlines, blank-line skipping, column-count checks with real line numbers |
| Validation | Required SKU and name, SKU format, money amounts (`$1,299.5` becomes `1299.50`), sale price below regular price, whole-number stock, allowed statuses, weight, duplicate SKUs within one file |
| Products | Uses the WooCommerce CRUD API (`WC_Product_Simple`), so caches, lookup tables and hooks stay consistent. Creates nested categories (`Clothing > Shirts`) on the fly. Declares HPOS compatibility. |
| Data storage | Two custom tables created with `dbDelta()`: one for imports, one for import rows. Rows are bulk-inserted in chunks, and counters are recomputed with one `GROUP BY` query so they can't drift. |
| Background jobs | Action Scheduler batches, a per-import lock against double processing, configurable batch size, and a `wcps_import_completed` hook when an import finishes |
| REST API | Create, list, inspect, page through rows and delete imports, with `manage_woocommerce` permission checks and argument validation |
| Admin UI | WooCommerce > Product Sync: upload form, import history with live progress, failed-row view and a downloadable error-report CSV |
| WP-CLI | `wp product-sync import <file>` with a progress bar, plus `wp product-sync status <id>` |
| Quality | PHPUnit tests, WordPress Coding Standards (PHPCS), GitHub Actions CI on PHP 8.1 to 8.3 |

## Architecture

```
            Admin upload ─┐
   REST POST /imports  ───┼──► ImportService ──► CsvParser ──► RowValidator ──► wcps_validate_row filter
     wp product-sync   ───┘          │
                                     ▼
                           ImportRepository  (wp_wcps_imports, wp_wcps_import_rows)
                                     │  wcps_import_queued
                                     ▼
                     BatchProcessor (Action Scheduler, 25 rows per batch)
                                     │
                                     ▼
                   ProductWriter ◄── ProductUpserter (WooCommerce CRUD)
```

```
src/
├── Plugin.php                  Wires services together and registers hooks
├── Install/Schema.php          Custom tables and versioned upgrades via dbDelta()
├── Import/
│   ├── CsvParser.php           Framework-free CSV reader (unit tested)
│   ├── RowValidator.php        Framework-free validation and normalization (unit tested)
│   ├── ValidationResult.php
│   ├── ImportService.php       Entry point used by the admin page, REST API and CLI
│   ├── ImportRepository.php    All SQL, through $wpdb->prepare() and the insert/update helpers
│   ├── BatchProcessor.php      Action Scheduler batches
│   ├── ProductWriter.php       Interface for writing a row to the store
│   └── ProductUpserter.php     WooCommerce implementation
├── Rest/ImportsController.php  /wp-json/wc-product-sync/v1
├── Admin/ImportPage.php        WooCommerce > Product Sync screen
└── Cli/ImportCommand.php       wp product-sync ...
```

The parser and validator have no WordPress dependencies, so they can be unit tested in milliseconds without a database. Everything that talks to WordPress sits behind them.

## CSV format

| Column | Required | Notes |
| --- | --- | --- |
| `sku` | yes | Letters, numbers, `.`, `-`, `_`. Used to match existing products. |
| `name` | yes | |
| `regular_price` | | `19.99`, `$1,299.00` and `20` are all accepted |
| `sale_price` | | Must be lower than `regular_price` |
| `stock_quantity` | | Whole number. Turns on stock management. |
| `status` | | `publish` (default), `draft`, `private`, `pending` |
| `weight` | | |
| `categories` | | Separate with `\|`, nest with `>`: `Clothing > Shirts \| Sale` |
| `description`, `short_description` | | HTML is filtered through `wp_kses_post()` |

Try it with [`examples/sample-products.csv`](examples/sample-products.csv). It deliberately includes four bad rows so you can see the error reporting.

## REST API

Authenticate with an [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) for a user with the `manage_woocommerce` capability.

```bash
# Push products as JSON (returns 202 Accepted plus a Location header)
curl -u admin:APP_PASSWORD -H "Content-Type: application/json" \
  -d '{"products":[{"sku":"TEE-1","name":"Classic Tee","regular_price":"24.99","stock_quantity":40}]}' \
  https://example.com/wp-json/wc-product-sync/v1/imports

# ...or upload a CSV
curl -u admin:APP_PASSWORD -F "file=@products.csv" https://example.com/wp-json/wc-product-sync/v1/imports

# Poll progress
curl -u admin:APP_PASSWORD https://example.com/wp-json/wc-product-sync/v1/imports/12

# Rows that failed, and why
curl -u admin:APP_PASSWORD "https://example.com/wp-json/wc-product-sync/v1/imports/12/rows?status=failed"
```

| Method | Route | Description |
| --- | --- | --- |
| `GET` | `/imports` | List imports (`page`, `per_page`) |
| `POST` | `/imports` | Start an import from `products` (JSON array) or `file` (CSV upload) |
| `GET` | `/imports/{id}` | Status, counts and progress |
| `GET` | `/imports/{id}/rows` | Row outcomes, filterable by `status` |
| `DELETE` | `/imports/{id}` | Delete an import, its rows and any queued jobs (products are kept) |

## WP-CLI

```bash
wp product-sync import products.csv           # run now, with a progress bar
wp product-sync import feed.csv --async       # queue for background processing
wp product-sync status 12 --format=csv        # summary plus failed rows
```

## Extending

```php
// Add your own validation rule.
add_filter( 'wcps_validate_row', function ( array $errors, array $data ) {
	if ( ! str_starts_with( $data['sku'], 'ACME-' ) ) {
		$errors[] = 'SKUs must start with ACME-.';
	}
	return $errors;
}, 10, 2 );

// Change the data just before it is saved.
add_filter( 'wcps_product_data', fn ( array $data ) => $data + array( 'status' => 'draft' ) );

// React to saves and finished imports.
add_action( 'wcps_product_saved', function ( WC_Product $product, array $data, string $action ) { /* ... */ }, 10, 3 );
add_action( 'wcps_import_completed', function ( array $import ) { /* e.g. email a summary */ } );

// Tune throughput and limits.
add_filter( 'wcps_batch_size', fn () => 50 );
add_filter( 'wcps_max_rows', fn () => 25000 );
```

## Development

Requirements: PHP 8.1+, WordPress 6.4+, WooCommerce 8.0+.

```bash
composer install
composer test        # PHPUnit
composer lint        # PHPCS, WordPress Coding Standards
composer lint:fix    # phpcbf

# Local WordPress + WooCommerce in Docker
npx @wordpress/env start   # http://localhost:8888  (admin / password)
```

## Security

- Every admin action checks `manage_woocommerce` and a nonce. Every REST route has a `permission_callback`.
- All SQL goes through `$wpdb->prepare()` or `$wpdb->insert()` / `update()` / `delete()`.
- Uploads must be `.csv` files. Output is escaped with `esc_html()`, `esc_url()` and `esc_attr()`, and descriptions are filtered with `wp_kses_post()`.
- Uninstalling removes the plugin's tables, options and scheduled jobs. Imported products are kept.

## Roadmap

- Variable products and attributes
- Scheduled pulls from a remote CSV or JSON feed URL
- Image import from URLs
- Dry-run mode that validates without writing

## License

GPL-2.0-or-later
