<?php
/**
 * REST API routes under thisismyurl/v1/image-support/.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all plugin REST routes with permission_callback and arg schemas.
 */
class TIMU_IC_REST {

	const REST_NAMESPACE = 'thisismyurl/v1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = self::REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/image-support/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_audit' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/image-support/orphans',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_orphans' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/image-support/broken',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_broken' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/image-support/cleanup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_cleanup' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'ids'     => array(
						'required'          => true,
						'type'              => 'array',
						'items'             => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'sanitize_callback' => static function ( $value ) {
							return array_values( array_filter( array_map( 'absint', (array) $value ) ) );
						},
						'validate_callback' => static function ( $value ) {
							return is_array( $value ) && ! empty( $value );
						},
					),
					'dry_run' => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/image-support/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_restore' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'attachment_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return absint( $value ) > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * Permission gate for all routes — manage_options only.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to access this endpoint.', 'thisismyurl-image-support' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * GET /image-support/audit — summary counts.
	 *
	 * @return WP_REST_Response
	 */
	public static function route_audit() {
		$orphans = TIMU_IC_Audit::get_orphan_images();
		$broken  = TIMU_IC_Audit::get_broken_attachments();
		$no_alt  = TIMU_IC_Audit::get_missing_alt_text();

		return new WP_REST_Response(
			array(
				'orphan_count' => count( $orphans ),
				'broken_count' => count( $broken ),
				'no_alt_count' => count( $no_alt ),
				'queue_status' => TIMU_IC_Scheduler::get_queue_status(),
			),
			200
		);
	}

	/**
	 * GET /image-support/orphans — full orphan list.
	 *
	 * @return WP_REST_Response
	 */
	public static function route_orphans() {
		$orphans    = TIMU_IC_Audit::get_orphan_images();
		$upload_dir = wp_upload_dir();
		$basedir    = trailingslashit( $upload_dir['basedir'] );

		$items = array_map(
			static function ( $abs_path ) use ( $basedir ) {
				return array(
					'path'     => $abs_path,
					'relative' => ltrim( str_replace( $basedir, '', $abs_path ), '/' ),
					'size'     => file_exists( $abs_path ) ? (int) filesize( $abs_path ) : 0,
				);
			},
			$orphans
		);

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * GET /image-support/broken — full broken attachment list.
	 *
	 * @return WP_REST_Response
	 */
	public static function route_broken() {
		$broken = TIMU_IC_Audit::get_broken_attachments();

		$items = array_map(
			static function ( $post ) {
				return array(
					'id'            => (int) $post->ID,
					'title'         => $post->post_title,
					'expected_path' => (string) get_attached_file( $post->ID ),
					'edit_url'      => (string) get_edit_post_link( $post->ID, 'raw' ),
				);
			},
			$broken
		);

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * POST /image-support/cleanup — enqueue or preview batch cleanup.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public static function route_cleanup( WP_REST_Request $request ) {
		$ids     = (array) $request->get_param( 'ids' );
		$dry_run = (bool) $request->get_param( 'dry_run' );

		if ( $dry_run ) {
			$plans = array();
			foreach ( $ids as $id ) {
				$plan    = TIMU_IC_File_Ops::plan_attachment_changes( $id );
				$plans[] = is_wp_error( $plan )
					? array( 'id' => $id, 'error' => $plan->get_error_message() )
					: $plan;
			}

			return new WP_REST_Response( array( 'dry_run' => true, 'proposed' => $plans ), 200 );
		}

		TIMU_IC_Scheduler::enqueue_batch( $ids, 'cleanup' );

		return new WP_REST_Response( array( 'enqueued' => count( $ids ) ), 202 );
	}

	/**
	 * POST /image-support/restore — restore a single attachment.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public static function route_restore( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'attachment_id' );
		$result = TIMU_IC_File_Ops::restore_image( $id );

		if ( $result ) {
			return new WP_REST_Response( array( 'restored' => $id ), 200 );
		}

		return new WP_REST_Response(
			array( 'error' => __( 'Restore failed. No backup found or file error.', 'thisismyurl-image-support' ) ),
			422
		);
	}
}
