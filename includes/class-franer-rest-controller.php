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

		$schedule_state = Franer_Permissions::schedule_state( $settings );

		if ( 'not_yet' === $schedule_state ) {
			return new WP_Error(
				'franer_not_open',
				__( 'This activity is not open yet.', 'franer' ),
				array( 'status' => 403 )
			);
		}

		if ( 'ended' === $schedule_state ) {
			return new WP_Error(
				'franer_closed',
				__( 'This activity is closed.', 'franer' ),
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

		/**
		 * Fires before a Franer submission payload is processed.
		 *
		 * Runs after authentication, site resolution, visibility/role checks and
		 * the submission-open checks. Security-sensitive: it must not be used to
		 * bypass validation or permission logic.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $body     Raw request JSON body as an array.
		 * @param WP_Post         $post     Franer site post.
		 * @param array           $settings Typed Franer site settings.
		 * @param int             $user_id  Current user ID.
		 * @param WP_REST_Request $request  Original REST request.
		 */
		do_action( 'franer_before_process_submission', $body, $post, $settings, $user_id, $request );

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

		/**
		 * Filters the submitted Franer payload data before it is validated and stored.
		 *
		 * May enrich, normalize or transform the submitted JSON object. The
		 * returned value must be an array and is still encoded and validated
		 * against the site maximum payload size, so it cannot bypass that limit.
		 *
		 * Security-sensitive: this filter must not be used to bypass schema
		 * version checks, payload size checks, duplicate handling, authentication
		 * or authorization. Non-array return values are ignored.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $data     Submitted payload data.
		 * @param array           $body     Full request JSON body.
		 * @param WP_Post         $post     Franer site post.
		 * @param array           $settings Typed Franer site settings.
		 * @param int             $user_id  Current user ID.
		 * @param WP_REST_Request $request  Original REST request.
		 * @return array Filtered payload data.
		 */
		$filtered_data = apply_filters( 'franer_submission_payload', $data, $body, $post, $settings, $user_id, $request );

		if ( is_array( $filtered_data ) ) {
			$data = $filtered_data;
		}

		$payload_json = wp_json_encode( $data );

		if ( false === $payload_json ) {
			return new WP_Error(
				'franer_invalid_payload',
				__( 'The submitted payload is invalid or empty.', 'franer' ),
				array( 'status' => 400 )
			);
		}

		$max_bytes = (int) $settings['max_payload_size'] * 1024;

		$validated = Franer_Sanitizer::validate_payload( $payload_json, $max_bytes );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		/**
		 * Fires immediately before a Franer submission is saved.
		 *
		 * The payload has already passed schema and size validation.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $site_id      Franer site ID.
		 * @param int    $user_id      Current user ID.
		 * @param string $payload_json Validated JSON payload string.
		 * @param array  $settings     Typed Franer site settings.
		 */
		do_action( 'franer_before_save_submission', (int) $settings['id'], $user_id, $payload_json, $settings );

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

		/**
		 * Fires after a Franer submission has been saved successfully.
		 *
		 * Only fires on a successful save; never when saving returns an error.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $submission_id Saved submission ID.
		 * @param string $status        Save status ('saved' or 'updated').
		 * @param int    $site_id       Franer site ID.
		 * @param int    $user_id       Current user ID.
		 * @param string $payload_json  Stored JSON payload string.
		 * @param array  $settings      Typed Franer site settings.
		 */
		do_action(
			'franer_after_save_submission',
			(int) $result['submission_id'],
			(string) $result['status'],
			(int) $settings['id'],
			$user_id,
			$payload_json,
			$settings
		);

		$response_data = array(
			'submission_id' => (int) $result['submission_id'],
			'status'        => $result['status'],
		);

		/**
		 * Filters the successful Franer submission REST response data.
		 *
		 * Intended for adding non-sensitive integration metadata. Do not include
		 * raw IP addresses, user agents or sensitive server-side data. Non-array
		 * return values are ignored.
		 *
		 * @since 1.0.0
		 *
		 * @param array $response_data REST response data.
		 * @param array $result        Save result.
		 * @param array $settings      Typed Franer site settings.
		 * @param int   $user_id       Current user ID.
		 * @return array Filtered response data.
		 */
		$filtered_response = apply_filters( 'franer_submission_response', $response_data, $result, $settings, $user_id );

		if ( is_array( $filtered_response ) ) {
			$response_data = $filtered_response;
		}

		return new WP_REST_Response( $response_data, 201 );
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

		$payload = json_decode( (string) $submission['payload_json'], true );

		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		/**
		 * Filters the payload returned by the current user's latest submission.
		 *
		 * Runs only after authentication and permission checks.
		 *
		 * @since 1.0.0
		 *
		 * @param array $payload    Decoded submission payload.
		 * @param array $submission Stored submission row.
		 * @param array $settings   Typed Franer site settings.
		 * @param int   $user_id    Current user ID.
		 * @return array Filtered payload (non-array returns are ignored).
		 */
		$filtered_payload = apply_filters( 'franer_my_submission_payload', $payload, $submission, $settings, $user_id );

		if ( is_array( $filtered_payload ) ) {
			$payload = $filtered_payload;
		}

		return new WP_REST_Response(
			array(
				'submission_id' => (int) $submission['id'],
				'payload'       => $payload,
				'created_at'    => $submission['created_at'],
				'updated_at'    => $submission['updated_at'],
			),
			200
		);
	}
}
