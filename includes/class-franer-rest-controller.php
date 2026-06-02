<?php
/**
 * REST API controller for Franer.
 *
 * @package Franer
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Registers and handles the Franer REST endpoints.
 */
class Franer_Rest_Controller {

	/**
	 * The REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'franer/v1';

	/**
	 * Site repository.
	 *
	 * @var Franer_Site_Repository
	 */
	private $sites;

	/**
	 * Submissions repository.
	 *
	 * @var Franer_Submissions_Repository
	 */
	private $submissions;

	/**
	 * Constructor.
	 *
	 * @param Franer_Site_Repository|null        $sites       Optional site repository.
	 * @param Franer_Submissions_Repository|null $submissions Optional submissions repository.
	 */
	public function __construct( $sites = null, $submissions = null ) {
		$this->sites       = $sites instanceof Franer_Site_Repository ? $sites : new Franer_Site_Repository();
		$this->submissions = $submissions instanceof Franer_Submissions_Repository ? $submissions : new Franer_Submissions_Repository();
	}

	/**
	 * Register the REST routes on rest_api_init.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/sites/(?P<slug>[a-z0-9-]+)/submissions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_submission' ),
				'permission_callback' => array( $this, 'permission_logged_in' ),
				'args'                => array(
					'slug' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sites/(?P<slug>[a-z0-9-]+)/my-submission',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_my_submission' ),
				'permission_callback' => array( $this, 'permission_logged_in' ),
				'args'                => array(
					'slug' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: require a logged-in user.
	 *
	 * The nonce is verified by WordPress via the X-WP-Nonce header; role
	 * checks are performed inside the handlers so they can return 403.
	 *
	 * @return bool True when the user is logged in.
	 */
	public function permission_logged_in() {
		return is_user_logged_in();
	}

	/**
	 * Handle POST submission creation.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error The response or error.
	 */
	public function create_submission( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return new WP_Error(
				'franer_not_logged_in',
				__( 'You must be logged in.', 'franer' ),
				array( 'status' => 401 )
			);
		}

		$slug = Franer_Sanitizer::sanitize_slug( $request['slug'] );

		if ( is_wp_error( $slug ) ) {
			return new WP_Error(
				'franer_not_found',
				__( 'Activity not found.', 'franer' ),
				array( 'status' => 404 )
			);
		}

		$post = $this->sites->get_by_slug( $slug );

		if ( ! $post ) {
			return new WP_Error(
				'franer_not_found',
				__( 'Activity not found.', 'franer' ),
				array( 'status' => 404 )
			);
		}

		$settings = $this->sites->get_settings( $post->ID );

		if ( ! Franer_Permissions::user_can_view( $settings, $user_id ) ) {
			return new WP_Error(
				'franer_forbidden',
				__( 'You are not allowed to access this activity.', 'franer' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $settings['accepts_submissions'] ) {
			return new WP_Error(
				'franer_submissions_closed',
				__( 'This activity is not accepting submissions.', 'franer' ),
				array( 'status' => 403 )
			);
		}

		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$schema_version = isset( $body['schema_version'] ) ? (string) $body['schema_version'] : '';

		if ( '1.0' !== $schema_version ) {
			return new WP_Error(
				'franer_invalid_payload',
				__( 'Unsupported or missing schema version.', 'franer' ),
				array( 'status' => 400 )
			);
		}

		$data = isset( $body['data'] ) ? $body['data'] : null;

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'franer_invalid_payload',
				__( 'The submitted payload is invalid or empty.', 'franer' ),
				array( 'status' => 400 )
			);
		}

		$payload_json = wp_json_encode( $data );
		$max_bytes    = (int) $settings['max_payload_size'] * 1024;

		$validated = Franer_Sanitizer::validate_payload( $payload_json, $max_bytes );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$result = $this->submissions->save_submission(
			(int) $settings['id'],
			$user_id,
			$payload_json,
			$settings['allow_multiple'],
			$settings['allow_overwrite']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'submission_id' => (int) $result['submission_id'],
				'status'        => $result['status'],
			),
			201
		);
	}

	/**
	 * Handle GET of the current user's latest submission.
	 *
	 * Submissions need not be open to read; the site must be visible and the
	 * user's role must be allowed.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error The response or error.
	 */
	public function get_my_submission( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return new WP_Error(
				'franer_not_logged_in',
				__( 'You must be logged in.', 'franer' ),
				array( 'status' => 401 )
			);
		}

		$slug = Franer_Sanitizer::sanitize_slug( $request['slug'] );

		if ( is_wp_error( $slug ) ) {
			return new WP_Error(
				'franer_not_found',
				__( 'Activity not found.', 'franer' ),
				array( 'status' => 404 )
			);
		}

		$post = $this->sites->get_by_slug( $slug );

		if ( ! $post ) {
			return new WP_Error(
				'franer_not_found',
				__( 'Activity not found.', 'franer' ),
				array( 'status' => 404 )
			);
		}

		$settings = $this->sites->get_settings( $post->ID );

		if ( ! Franer_Permissions::user_can_view( $settings, $user_id ) ) {
			return new WP_Error(
				'franer_forbidden',
				__( 'You are not allowed to access this activity.', 'franer' ),
				array( 'status' => 403 )
			);
		}

		$submission = $this->submissions->get_latest_user_submission( (int) $settings['id'], $user_id );

		if ( ! $submission ) {
			return new WP_Error(
				'franer_not_found',
				__( 'No submission found.', 'franer' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			array(
				'submission_id' => (int) $submission['id'],
				'payload'       => json_decode( (string) $submission['payload_json'], true ),
				'created_at'    => $submission['created_at'],
				'updated_at'    => $submission['updated_at'],
			),
			200
		);
	}
}
