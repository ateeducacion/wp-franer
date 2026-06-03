<?php
/**
 * Tests for the Franer REST controller.
 *
 * @package Franer
 */

/**
 * Drives the registered REST routes with WP_REST_Request objects.
 */
class RestControllerTest extends WP_UnitTestCase {

	/**
	 * The REST server.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * A subscriber user ID (allowed role).
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * An editor user ID (not an allowed role).
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Boot the REST server and create the table.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Franer_Activator::activate();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Tear down the REST server.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Create a franer_site with the given settings.
	 *
	 * @param array $args Overrides for slug/visibility/etc.
	 * @return int The created post ID.
	 */
	private function create_site( array $args = array() ) {
		$args = array_merge(
			array(
				'slug'                => 'mcode40',
				'is_visible'          => true,
				'accepts_submissions' => true,
				'allowed_roles'       => array( 'subscriber' ),
				'allow_multiple'      => false,
				'allow_overwrite'     => false,
				'max_payload_size'    => 256,
			),
			$args
		);

		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'franer_site',
				// Visibility is the post status: published = visible, draft = hidden.
				'post_status'  => $args['is_visible'] ? 'publish' : 'draft',
				'post_title'   => 'Test Activity',
				'post_content' => '<p>activity</p>',
			)
		);

		update_post_meta( $post_id, '_franer_slug', $args['slug'] );
		update_post_meta( $post_id, '_franer_accepts_submissions', $args['accepts_submissions'] ? '1' : '' );
		update_post_meta( $post_id, '_franer_allowed_roles', $args['allowed_roles'] );
		update_post_meta( $post_id, '_franer_allow_multiple_submissions', $args['allow_multiple'] ? '1' : '' );
		update_post_meta( $post_id, '_franer_allow_overwrite', $args['allow_overwrite'] ? '1' : '' );
		update_post_meta( $post_id, '_franer_max_payload_size', $args['max_payload_size'] );

		return $post_id;
	}

	/**
	 * Build a POST submission request.
	 *
	 * @param string $slug The activity slug.
	 * @param mixed  $body The JSON body (array or raw string).
	 * @return WP_REST_Request The request.
	 */
	private function post_request( $slug, $body ) {
		$request = new WP_REST_Request( 'POST', "/franer/v1/sites/{$slug}/submissions" );
		$request->add_header( 'Content-Type', 'application/json' );

		if ( is_string( $body ) ) {
			$request->set_body( $body );
		} else {
			$request->set_body( wp_json_encode( $body ) );
		}

		return $request;
	}

	/**
	 * Valid submission by an allowed, logged-in user should return 201 saved.
	 *
	 * @return void
	 */
	public function test_success_returns_201_saved() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		$request  = $this->post_request(
			'mcode40',
			array(
				'schema_version' => '1.0',
				'activity_id'    => 'mcode40',
				'data'           => array( 'q1' => 'yes' ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'saved', $data['status'] );
		$this->assertGreaterThan( 0, $data['submission_id'] );
	}

	/**
	 * A logged-out request should be rejected with 401.
	 *
	 * @return void
	 */
	public function test_logged_out_returns_401() {
		$this->create_site();
		wp_set_current_user( 0 );

		$request  = $this->post_request(
			'mcode40',
			array(
				'schema_version' => '1.0',
				'data'           => array( 'q1' => 'yes' ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * An unknown slug should return 404.
	 *
	 * @return void
	 */
	public function test_unknown_site_returns_404() {
		wp_set_current_user( $this->subscriber_id );

		$request  = $this->post_request(
			'does-not-exist',
			array(
				'schema_version' => '1.0',
				'data'           => array( 'q1' => 'yes' ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A user without an allowed role should be forbidden (403).
	 *
	 * @return void
	 */
	public function test_role_not_allowed_returns_403() {
		$this->create_site( array( 'allowed_roles' => array( 'subscriber' ) ) );
		wp_set_current_user( $this->editor_id );

		$request  = $this->post_request(
			'mcode40',
			array(
				'schema_version' => '1.0',
				'data'           => array( 'q1' => 'yes' ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A hidden site (unpublished draft) should not be found (404), so its
	 * existence is never leaked.
	 *
	 * @return void
	 */
	public function test_hidden_site_returns_404() {
		$this->create_site( array( 'is_visible' => false ) );
		wp_set_current_user( $this->subscriber_id );

		$request  = $this->post_request(
			'mcode40',
			array(
				'schema_version' => '1.0',
				'data'           => array( 'q1' => 'yes' ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A site with submissions closed should return 403.
	 *
	 * @return void
	 */
	public function test_submissions_closed_returns_403() {
		$this->create_site( array( 'accepts_submissions' => false ) );
		wp_set_current_user( $this->subscriber_id );

		$request  = $this->post_request(
			'mcode40',
			array(
				'schema_version' => '1.0',
				'data'           => array( 'q1' => 'yes' ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A wrong or missing schema version should return 400.
	 *
	 * @return void
	 */
	public function test_wrong_schema_version_returns_400() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		$request  = $this->post_request(
			'mcode40',
			array(
				'schema_version' => '9.9',
				'data'           => array( 'q1' => 'yes' ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * An empty data object should return 400.
	 *
	 * @return void
	 */
	public function test_empty_payload_returns_400() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		$request  = $this->post_request(
			'mcode40',
			array(
				'schema_version' => '1.0',
				'data'           => array(),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Missing the data key entirely should return 400.
	 *
	 * @return void
	 */
	public function test_missing_data_returns_400() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		$request  = $this->post_request( 'mcode40', array( 'schema_version' => '1.0' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * An oversize payload should return 413.
	 *
	 * @return void
	 */
	public function test_oversize_payload_returns_413() {
		$this->create_site( array( 'max_payload_size' => 1 ) );
		wp_set_current_user( $this->subscriber_id );

		$request  = $this->post_request(
			'mcode40',
			array(
				'schema_version' => '1.0',
				'data'           => array( 'blob' => str_repeat( 'x', 4096 ) ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 413, $response->get_status() );
	}

	/**
	 * A duplicate submission without multiple/overwrite should return 409.
	 *
	 * @return void
	 */
	public function test_duplicate_returns_409() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		$first = $this->server->dispatch(
			$this->post_request(
				'mcode40',
				array(
					'schema_version' => '1.0',
					'data'           => array( 'q1' => 'first' ),
				)
			)
		);
		$this->assertSame( 201, $first->get_status() );

		$second = $this->server->dispatch(
			$this->post_request(
				'mcode40',
				array(
					'schema_version' => '1.0',
					'data'           => array( 'q1' => 'second' ),
				)
			)
		);

		$this->assertSame( 409, $second->get_status() );
	}

	/**
	 * A resubmission with overwrite allowed should return 201 updated.
	 *
	 * @return void
	 */
	public function test_overwrite_returns_updated() {
		$this->create_site( array( 'allow_overwrite' => true ) );
		wp_set_current_user( $this->subscriber_id );

		$first = $this->server->dispatch(
			$this->post_request(
				'mcode40',
				array(
					'schema_version' => '1.0',
					'data'           => array( 'q1' => 'first' ),
				)
			)
		);
		$this->assertSame( 201, $first->get_status() );

		$second = $this->server->dispatch(
			$this->post_request(
				'mcode40',
				array(
					'schema_version' => '1.0',
					'data'           => array( 'q1' => 'second' ),
				)
			)
		);

		$this->assertSame( 201, $second->get_status() );
		$this->assertSame( 'updated', $second->get_data()['status'] );
	}

	/**
	 * The my-submission endpoint should return the latest payload.
	 *
	 * @return void
	 */
	public function test_my_submission_returns_latest() {
		$this->create_site( array( 'allow_multiple' => true ) );
		wp_set_current_user( $this->subscriber_id );

		$this->server->dispatch(
			$this->post_request(
				'mcode40',
				array(
					'schema_version' => '1.0',
					'data'           => array( 'q1' => 'value' ),
				)
			)
		);

		$request  = new WP_REST_Request( 'GET', '/franer/v1/sites/mcode40/my-submission' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'q1' => 'value' ), $response->get_data()['payload'] );
	}

	/**
	 * my-submission should return 404 when the user has no submission.
	 *
	 * @return void
	 */
	public function test_my_submission_returns_404_when_none() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/franer/v1/sites/mcode40/my-submission' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A JSON array (list) as the data payload should return 400, not be stored.
	 *
	 * @return void
	 */
	public function test_list_payload_returns_400() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		$request  = $this->post_request(
			'mcode40',
			array(
				'schema_version' => '1.0',
				'data'           => array( 'x', 'y', 'z' ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'franer_invalid_payload', $response->get_data()['code'] );
	}

	/**
	 * Exceeding the per-user/per-site rate limit should return 429.
	 *
	 * @return void
	 */
	public function test_rate_limit_returns_429() {
		$this->create_site( array( 'allow_multiple' => true ) );
		wp_set_current_user( $this->subscriber_id );

		// Tighten the limit to make the test deterministic.
		add_filter( 'franer_submission_rate_limit', static fn() => 3 );

		for ( $i = 0; $i < 3; $i++ ) {
			$response = $this->server->dispatch(
				$this->post_request(
					'mcode40',
					array(
						'schema_version' => '1.0',
						'data'           => array( 'n' => $i ),
					)
				)
			);
			$this->assertSame( 201, $response->get_status(), "Submission {$i} should be accepted." );
		}

		$blocked = $this->server->dispatch(
			$this->post_request(
				'mcode40',
				array(
					'schema_version' => '1.0',
					'data'           => array( 'n' => 99 ),
				)
			)
		);

		$this->assertSame( 429, $blocked->get_status() );
		$this->assertSame( 'franer_rate_limited', $blocked->get_data()['code'] );
	}

	/**
	 * A rate limit of 0 (or less) disables Franer's built-in throttle.
	 *
	 * @return void
	 */
	public function test_rate_limit_disabled_when_zero() {
		$this->create_site( array( 'allow_multiple' => true ) );
		wp_set_current_user( $this->subscriber_id );

		add_filter( 'franer_submission_rate_limit', '__return_zero' );

		for ( $i = 0; $i < 12; $i++ ) {
			$response = $this->server->dispatch(
				$this->post_request(
					'mcode40',
					array(
						'schema_version' => '1.0',
						'data'           => array( 'n' => $i ),
					)
				)
			);
			$this->assertSame( 201, $response->get_status() );
		}
	}
}
