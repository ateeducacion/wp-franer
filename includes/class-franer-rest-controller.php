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

		// Resolve and authorize the target activity (slug, visibility, schedule,
		// submissions-open and rate-limit checks). Returns a WP_Error on any gate.
		$context = $this->resolve_submission_context( $request, $user_id );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$post     = $context['post'];
		$settings = $context['settings'];

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

		// Validate the schema version and payload, applying the payload filter and
		// enforcing the size limit. Returns the validated JSON string or a WP_Error.
		$payload_json = $this->build_validated_payload( $body, $post, $settings, $user_id, $request );

		if ( is_wp_error( $payload_json ) ) {
			return $payload_json;
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

		// Record which version of the form this answer was given against, so an
		// admin can later tell submissions apart if the activity HTML changes.
		$form_version     = Franer_Submissions_Repository::form_version_hash( $post->post_content );
		$site_modified_at = isset( $post->post_modified_gmt ) ? (string) $post->post_modified_gmt : '';

		$result = $this->submissions->save_submission(
			(int) $settings['id'],
			$user_id,
			$payload_json,
			$settings['allow_multiple'],
			$settings['allow_overwrite'],
			$form_version,
			$site_modified_at
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

		return new WP_REST_Response( $this->build_submission_response( $result, $settings, $user_id ), 201 );
	}

	/**
	 * Resolve and authorize the activity targeted by a submission request.
	 *
	 * Runs every pre-payload gate in order — slug, existence, visibility/role,
	 * schedule window, submissions-open and the per-user rate limit — so
	 * create_submission() stays readable. Each failed gate returns the WP_Error
	 * (with the correct HTTP status) that the endpoint must surface.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @param int             $user_id The authenticated user ID.
	 * @return array|WP_Error { @type WP_Post $post; @type array $settings } or WP_Error.
	 */
	private function resolve_submission_context( WP_REST_Request $request, $user_id ) {
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

		$rate_limited = $this->check_rate_limit( (int) $settings['id'], $user_id, $settings );

		if ( is_wp_error( $rate_limited ) ) {
			return $rate_limited;
		}

		return array(
			'post'     => $post,
			'settings' => $settings,
		);
	}

	/**
	 * Validate a submission body and return its stored JSON payload string.
	 *
	 * Checks the schema version, requires a JSON-object payload, applies the
	 * `franer_submission_payload` filter, re-encodes and enforces the site's
	 * maximum payload size. Returns a WP_Error (with HTTP status) on any failure.
	 *
	 * @param array           $body     Decoded request body.
	 * @param WP_Post         $post     Franer site post.
	 * @param array           $settings Typed Franer site settings.
	 * @param int             $user_id  Current user ID.
	 * @param WP_REST_Request $request  Original REST request.
	 * @return string|WP_Error Validated JSON payload string, or WP_Error.
	 */
	private function build_validated_payload( $body, $post, $settings, $user_id, WP_REST_Request $request ) {
		$schema_version = isset( $body['schema_version'] ) ? (string) $body['schema_version'] : '';

		if ( '1.0' !== $schema_version ) {
			return new WP_Error(
				'franer_invalid_payload',
				__( 'Unsupported or missing schema version.', 'franer' ),
				array( 'status' => 400 )
			);
		}

		$data = isset( $body['data'] ) ? $body['data'] : null;

		// The payload must be a JSON object with named fields, not a list or scalar.
		if ( ! Franer_Sanitizer::is_json_object( $data ) ) {
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

		return $payload_json;
	}

	/**
	 * Build the successful-submission REST response data (with the filter applied).
	 *
	 * @param array $result   Save result from the submissions repository.
	 * @param array $settings Typed Franer site settings.
	 * @param int   $user_id  Current user ID.
	 * @return array The response data array.
	 */
	private function build_submission_response( $result, $settings, $user_id ) {
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

		return $response_data;
	}

	/**
	 * Enforce a per-user, per-site submission rate limit.
	 *
	 * Stops a logged-in user — or a runaway/abusive activity script firing
	 * postMessage in a loop, which the parent shell relays as authenticated POSTs
	 * — from hammering the endpoint and exhausting submission storage. Uses a
	 * short-lived transient counter as a sliding window. Both the maximum count
	 * and the window are filterable; returning a limit of 0 (or less) disables
	 * Franer's built-in throttle (e.g. to defer to an external rate limiter).
	 *
	 * @param int   $site_id  The site post ID.
	 * @param int   $user_id  The current user ID.
	 * @param array $settings Typed Franer site settings.
	 * @return true|WP_Error True when allowed, or a 429 WP_Error when throttled.
	 */
	private function check_rate_limit( $site_id, $user_id, $settings ) {
		/**
		 * Filters the maximum number of submissions a single user may make to a
		 * single site within the rate-limit window. Return 0 or less to disable.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $limit    Maximum submissions per window. Default 10.
		 * @param int   $site_id  Franer site ID.
		 * @param int   $user_id  Current user ID.
		 * @param array $settings Typed Franer site settings.
		 */
		$limit = (int) apply_filters( 'franer_submission_rate_limit', 10, $site_id, $user_id, $settings );

		if ( $limit <= 0 ) {
			return true;
		}

		/**
		 * Filters the rate-limit window, in seconds.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $window   Window length in seconds. Default MINUTE_IN_SECONDS.
		 * @param int   $site_id  Franer site ID.
		 * @param int   $user_id  Current user ID.
		 * @param array $settings Typed Franer site settings.
		 */
		$window = (int) apply_filters( 'franer_submission_rate_window', MINUTE_IN_SECONDS, $site_id, $user_id, $settings );

		if ( $window < 1 ) {
			$window = MINUTE_IN_SECONDS;
		}

		$key   = 'franer_rl_' . $site_id . '_' . $user_id;
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'franer_rate_limited',
				__( 'You are submitting too quickly. Please wait a moment and try again.', 'franer' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, $window );

		return true;
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
