<?php
/**
 * Main plugin container: builds the services and registers their hooks.
 *
 * @package WCPS
 */

namespace WCPS;

use WCPS\Admin\ImportPage;
use WCPS\Cli\ImportCommand;
use WCPS\Import\BatchProcessor;
use WCPS\Import\ImportRepository;
use WCPS\Import\ImportService;
use WCPS\Import\ProductUpserter;
use WCPS\Import\RowValidator;
use WCPS\Install\Schema;
use WCPS\Rest\ImportsController;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private ImportRepository $repository;
	private ImportService $service;
	private BatchProcessor $processor;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;

		$this->repository = new ImportRepository( $wpdb );
		$this->service    = new ImportService( $this->repository, new RowValidator() );
		$this->processor  = new BatchProcessor( $this->repository, new ProductUpserter() );
	}

	public function boot(): void {
		add_action( 'admin_init', array( Schema::class, 'maybe_upgrade' ) );

		$this->processor->register();

		add_action(
			'rest_api_init',
			function (): void {
				( new ImportsController( $this->service, $this->repository ) )->register_routes();
			}
		);

		if ( is_admin() ) {
			( new ImportPage( $this->service, $this->repository ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'product-sync', new ImportCommand( $this->service, $this->repository, $this->processor ) );
		}
	}

	public function service(): ImportService {
		return $this->service;
	}

	public function repository(): ImportRepository {
		return $this->repository;
	}
}
