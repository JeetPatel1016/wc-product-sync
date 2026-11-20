<?php
/**
 * REST API: /wp-json/wc-product-sync/v1/imports
 *
 * Lets external systems (an ERP, a supplier feed, a script) push product data
 * and poll import progress. Authenticate with WordPress Application Passwords.
 *
 * @package WCPS
 */

namespace WCPS\Rest;

use WCPS\Import\BatchProcessor;
use WCPS\Import\ImportException;
use WCPS\Import\ImportRepository;
use WCPS\Import\ImportService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class ImportsController {

	public const NAMESPACE = 'wc-product-sync/v1';

	public function __construct(
		private ImportService $service,
		private ImportRepository $repository
	) {}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/imports',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_imports' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $this->pagination_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_import' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'products' => array(
							'description' => 'Array of product objects (sku, name, regular_price, ...). Alternatively upload a CSV as the "file" field.',
							'type'        => 'array',
							'items'       => array( 'type' => 'object' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/imports/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_import' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_import' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/imports/(?P<id>\d+)/rows',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_rows' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $this->pagination_args() + array(
					'status' => array(
						'type' => 'string',
						'enum' => array(
							ImportRepository::ROW_PENDING,
							ImportRepository::ROW_CREATED,
							ImportRepository::ROW_UPDATED,
							ImportRepository::ROW_FAILED,
						),
					),
				),
			)
		);
	}

	public function can_manage(): bool|WP_Error {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		return new WP_Error( 'wcps_forbidden', __( 'You are not allowed to manage product imports.', 'wc-product-sync' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function list_imports( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			$this->repository->list_imports( (int) $request['per_page'], (int) $request['page'] )
		);
	}

	public function create_import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$files = $request->get_file_params();

		try {
			if ( ! empty( $files['file']['tmp_name'] ) ) {
				$file = $files['file'];
				if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
					return new WP_Error( 'wcps_upload_failed', __( 'The file upload failed.', 'wc-product-sync' ), array( 'status' => 400 ) );
				}
				if ( 'csv' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) ) {
					return new WP_Error( 'wcps_not_csv', __( 'Only .csv files are accepted.', 'wc-product-sync' ), array( 'status' => 400 ) );
				}
				$id = $this->service->start_from_csv( $file['tmp_name'], (string) $file['name'], 'api', get_current_user_id() );
			} elseif ( is_array( $request['products'] ) ) {
				$id = $this->service->start_from_rows( $request['products'], 'api', get_current_user_id() );
			} else {
				return new WP_Error( 'wcps_no_data', __( 'Send a "products" array or a CSV "file".', 'wc-product-sync' ), array( 'status' => 400 ) );
			}
		} catch ( ImportException $e ) {
			return new WP_Error( 'wcps_invalid_import', $e->getMessage(), array( 'status' => 422 ) );
		}

		$response = rest_ensure_response( $this->repository->get_import( $id ) );
		$response->set_status( 202 ); // Accepted: rows are processed in the background.
		$response->header( 'Location', rest_url( self::NAMESPACE . '/imports/' . $id ) );
		return $response;
	}

	public function get_import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$import = $this->repository->get_import( (int) $request['id'] );
		return $import ? rest_ensure_response( $import ) : $this->not_found();
	}

	public function get_rows( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];
		if ( ! $this->repository->get_import( $id ) ) {
			return $this->not_found();
		}
		return rest_ensure_response(
			$this->repository->get_rows( $id, $request['status'] ? $request['status'] : null, (int) $request['per_page'], (int) $request['page'] )
		);
	}

	public function delete_import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];
		if ( ! $this->repository->get_import( $id ) ) {
			return $this->not_found();
		}
		as_unschedule_all_actions( BatchProcessor::HOOK, array( $id ), BatchProcessor::GROUP );
		$this->repository->delete_import( $id );
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	private function not_found(): WP_Error {
		return new WP_Error( 'wcps_not_found', __( 'Import not found.', 'wc-product-sync' ), array( 'status' => 404 ) );
	}

	private function pagination_args(): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			),
		);
	}
}
