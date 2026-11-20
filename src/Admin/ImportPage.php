<?php
/**
 * WooCommerce > Product Sync admin screen: upload a CSV, watch progress,
 * review failed rows and download an error report.
 *
 * @package WCPS
 */

namespace WCPS\Admin;

use WCPS\Import\ImportException;
use WCPS\Import\ImportRepository;
use WCPS\Import\ImportService;
use WCPS\Rest\ImportsController;

defined( 'ABSPATH' ) || exit;

final class ImportPage {

	private const SLUG       = 'wc-product-sync';
	private const CAPABILITY = 'manage_woocommerce';

	public function __construct(
		private ImportService $service,
		private ImportRepository $repository
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		add_action( 'admin_post_wcps_upload', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_wcps_error_report', array( $this, 'download_error_report' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Product Sync', 'wc-product-sync' ),
			__( 'Product Sync', 'wc-product-sync' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Poll the REST API every few seconds while an import is running, so the
	 * progress column updates without reloading the page.
	 */
	public function enqueue( string $hook ): void {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'wp-api-fetch' );
		wp_add_inline_script(
			'wp-api-fetch',
			sprintf(
				'(function(){
					var ns = %s;
					function poll(){
						var cells = document.querySelectorAll("[data-wcps-running]");
						if (!cells.length) { return; }
						cells.forEach(function(cell){
							wp.apiFetch({ path: "/" + ns + "/imports/" + cell.dataset.wcpsRunning }).then(function(i){
								cell.textContent = i.progress + "%% (" + i.processed_rows + "/" + i.total_rows + ")";
								if (i.status === "completed") { window.location.reload(); }
							});
						});
						setTimeout(poll, 3000);
					}
					document.addEventListener("DOMContentLoaded", poll);
				})();',
				wp_json_encode( ImportsController::NAMESPACE )
			)
		);
	}

	public function handle_upload(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import products.', 'wc-product-sync' ), 403 );
		}
		check_admin_referer( 'wcps_upload' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		$file = $_FILES['wcps_csv'] ?? null;

		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$this->redirect( array( 'wcps_error' => rawurlencode( __( 'Please choose a CSV file to upload.', 'wc-product-sync' ) ) ) );
		}

		$type = wp_check_filetype( sanitize_file_name( $file['name'] ), array( 'csv' => 'text/csv' ) );
		if ( 'csv' !== $type['ext'] ) {
			$this->redirect( array( 'wcps_error' => rawurlencode( __( 'Only .csv files are accepted.', 'wc-product-sync' ) ) ) );
		}

		try {
			$id = $this->service->start_from_csv( $file['tmp_name'], $file['name'], 'csv', get_current_user_id() );
			$this->redirect( array( 'wcps_started' => $id ) );
		} catch ( ImportException $e ) {
			$this->redirect( array( 'wcps_error' => rawurlencode( $e->getMessage() ) ) );
		}
	}

	/**
	 * Stream the failed rows as a CSV, ready to fix and re-upload.
	 */
	public function download_error_report(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wc-product-sync' ), 403 );
		}
		$id = isset( $_GET['import'] ) ? absint( $_GET['import'] ) : 0;
		check_admin_referer( 'wcps_error_report_' . $id );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="import-' . $id . '-errors.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download, not writing a file.
		fputcsv( $out, array( 'row', 'sku', 'error' ), ',', '"', '' );

		$page = 1;
		do {
			$rows    = $this->repository->get_rows( $id, ImportRepository::ROW_FAILED, 500, $page++ );
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				fputcsv( $out, array( $row['row_number'], $row['sku'], $row['message'] ), ',', '"', '' );
			}
		} while ( 500 === $fetched );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public function render(): void {
		$view = isset( $_GET['import'] ) ? absint( $_GET['import'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>' . esc_html__( 'Product Sync', 'wc-product-sync' ) . '</h1>';
		$this->render_notices();

		if ( $view ) {
			$this->render_detail( $view );
		} else {
			$this->render_upload_form();
			$this->render_history();
		}
		echo '</div>';
	}

	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['wcps_error'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['wcps_error'] ) ) ) ) );
		}
		if ( isset( $_GET['wcps_started'] ) ) {
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				/* translators: %d: import ID */
				esc_html( sprintf( __( 'Import #%d queued. Products are being processed in the background.', 'wc-product-sync' ), absint( $_GET['wcps_started'] ) ) )
			);
		}
		// phpcs:enable
	}

	private function render_upload_form(): void {
		?>
		<div class="card" style="max-width:720px">
			<h2><?php esc_html_e( 'Import products from CSV', 'wc-product-sync' ); ?></h2>
			<p>
				<?php esc_html_e( 'Required columns: sku, name. Optional: regular_price, sale_price, stock_quantity, status, weight, categories (separate with |, nest with >), description, short_description. Existing products are matched by SKU and updated; new SKUs are created.', 'wc-product-sync' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="wcps_upload" />
				<?php wp_nonce_field( 'wcps_upload' ); ?>
				<input type="file" name="wcps_csv" accept=".csv,text/csv" required />
				<?php submit_button( __( 'Upload and import', 'wc-product-sync' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private function render_history(): void {
		$imports = $this->repository->list_imports( 20 );
		echo '<h2>' . esc_html__( 'Recent imports', 'wc-product-sync' ) . '</h2>';

		if ( ! $imports ) {
			echo '<p>' . esc_html__( 'No imports yet.', 'wc-product-sync' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( '#', __( 'Source', 'wc-product-sync' ), __( 'File', 'wc-product-sync' ), __( 'Status', 'wc-product-sync' ), __( 'Progress', 'wc-product-sync' ), __( 'Created', 'wc-product-sync' ), __( 'Updated', 'wc-product-sync' ), __( 'Failed', 'wc-product-sync' ), __( 'Started', 'wc-product-sync' ) ) as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $imports as $import ) {
			$running = ImportRepository::STATUS_COMPLETED !== $import['status'];
			$url     = add_query_arg(
				array(
					'page'   => self::SLUG,
					'import' => $import['id'],
				),
				admin_url( 'admin.php' )
			);
			printf(
				'<tr><td><a href="%1$s">#%2$d</a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td %6$s>%7$s</td><td>%8$d</td><td>%9$d</td><td>%10$d</td><td>%11$s</td></tr>',
				esc_url( $url ),
				(int) $import['id'],
				esc_html( $import['source'] ),
				esc_html( '' !== $import['filename'] ? $import['filename'] : '-' ),
				esc_html( ucfirst( $import['status'] ) ),
				$running ? 'data-wcps-running="' . (int) $import['id'] . '"' : '',
				esc_html( $import['progress'] . '%' ),
				(int) $import['created_count'],
				(int) $import['updated_count'],
				(int) $import['failed_count'],
				esc_html( get_date_from_gmt( $import['created_at'], 'Y-m-d H:i' ) )
			);
		}
		echo '</tbody></table>';
	}

	private function render_detail( int $id ): void {
		$import = $this->repository->get_import( $id );
		$back   = add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );

		if ( ! $import ) {
			echo '<p>' . esc_html__( 'Import not found.', 'wc-product-sync' ) . '</p>';
			return;
		}

		printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( $back ), esc_html__( 'All imports', 'wc-product-sync' ) );
		printf(
			'<p><strong>#%d</strong> &middot; %s &middot; %d %s, %d %s, %d %s</p>',
			(int) $import['id'],
			esc_html( ucfirst( $import['status'] ) ),
			(int) $import['created_count'],
			esc_html__( 'created', 'wc-product-sync' ),
			(int) $import['updated_count'],
			esc_html__( 'updated', 'wc-product-sync' ),
			(int) $import['failed_count'],
			esc_html__( 'failed', 'wc-product-sync' )
		);

		if ( $import['failed_count'] > 0 ) {
			$report = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'wcps_error_report',
						'import' => $id,
					),
					admin_url( 'admin-post.php' )
				),
				'wcps_error_report_' . $id
			);
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $report ), esc_html__( 'Download error report (CSV)', 'wc-product-sync' ) );
		}

		$rows = $this->repository->get_rows( $id, ImportRepository::ROW_FAILED, 100 );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No failed rows.', 'wc-product-sync' ) . '</p>';
			return;
		}

		echo '<h2>' . esc_html__( 'Failed rows', 'wc-product-sync' ) . '</h2><table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'Row', 'wc-product-sync' ) . '</th><th>SKU</th><th>' . esc_html__( 'Error', 'wc-product-sync' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf( '<tr><td>%d</td><td>%s</td><td>%s</td></tr>', (int) $row['row_number'], esc_html( '' !== $row['sku'] ? $row['sku'] : '-' ), esc_html( $row['message'] ) );
		}
		echo '</tbody></table>';
	}

	private function redirect( array $args ): never {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
