<?php
/**
 * Tests for the Franer developer hook API.
 *
 * Verifies that the documented actions/filters fire at the right lifecycle
 * points, that filters can modify data, and that defensive validation keeps
 * the security model intact (e.g. payload filters cannot bypass size limits).
 *
 * @package Franer
 */

/**
 * Exercises the franer_* developer hooks.
 */
class HooksTest extends WP_UnitTestCase {

	/**
	 * The REST server.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * A subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Boot the REST server and the submissions table.
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
	 * Create a published, submittable Franer site.
	 *
	 * @param array $args Optional overrides (slug, max_payload_size).
	 * @return int The created post ID.
	 */
	private function create_site( array $args = array() ) {
		$args = array_merge(
			array(
				'slug'             => 'mcode40',
				'max_payload_size' => 256,
			),
			$args
		);

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'franer_site',
				'post_status' => 'publish',
				'post_title'  => 'Hooks Activity',
			)
		);

		update_post_meta( $post_id, '_franer_slug', $args['slug'] );
		update_post_meta( $post_id, '_franer_html', '<p>activity</p>' );
		update_post_meta( $post_id, '_franer_accepts_submissions', '1' );
		update_post_meta( $post_id, '_franer_allowed_roles', array( 'subscriber' ) );
		update_post_meta( $post_id, '_franer_allow_multiple_submissions', '' );
		update_post_meta( $post_id, '_franer_allow_overwrite', '1' );
		update_post_meta( $post_id, '_franer_max_payload_size', $args['max_payload_size'] );
		update_post_meta( $post_id, '_franer_enabled', '1' );

		return $post_id;
	}

	/**
	 * Build a POST submission request.
	 *
	 * @param string $slug The site slug.
	 * @param array  $data The submission data array.
	 * @return WP_REST_Request The request object.
	 */
	private function submit_request( $slug, array $data ) {
		$request = new WP_REST_Request( 'POST', "/franer/v1/sites/{$slug}/submissions" );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'schema_version' => '1.0',
					'data'           => $data,
				)
			)
		);

		return $request;
	}

	/**
	 * The payload filter can enrich the submitted data before storage.
	 *
	 * @return void
	 */
	public function test_submission_payload_filter_enriches_data() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		add_filter(
			'franer_submission_payload',
			static function ( $data ) {
				$data['_added'] = 'yes';
				return $data;
			}
		);

		$response = $this->server->dispatch( $this->submit_request( 'mcode40', array( 'q1' => 'a' ) ) );
		$this->assertSame( 201, $response->get_status() );

		$repo       = new Franer_Submissions_Repository();
		$site       = ( new Franer_Site_Repository() )->get_by_slug( 'mcode40' );
		$submission = $repo->get_latest_user_submission( (int) $site->ID, $this->subscriber_id );

		$payload = json_decode( (string) $submission['payload_json'], true );
		$this->assertSame( 'yes', $payload['_added'] );
	}

	/**
	 * A non-array return from the payload filter is ignored.
	 *
	 * @return void
	 */
	public function test_submission_payload_filter_non_array_ignored() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		add_filter( 'franer_submission_payload', '__return_false' );

		$response = $this->server->dispatch( $this->submit_request( 'mcode40', array( 'q1' => 'kept' ) ) );
		$this->assertSame( 201, $response->get_status() );

		$site       = ( new Franer_Site_Repository() )->get_by_slug( 'mcode40' );
		$repo       = new Franer_Submissions_Repository();
		$submission = $repo->get_latest_user_submission( (int) $site->ID, $this->subscriber_id );
		$payload    = json_decode( (string) $submission['payload_json'], true );

		$this->assertSame( array( 'q1' => 'kept' ), $payload );
	}

	/**
	 * A payload filter cannot bypass the size limit: enriched data is still
	 * validated and an oversized result is rejected.
	 *
	 * @return void
	 */
	public function test_submission_payload_filter_still_size_validated() {
		$this->create_site( array( 'max_payload_size' => 1 ) );
		wp_set_current_user( $this->subscriber_id );

		add_filter(
			'franer_submission_payload',
			static function ( $data ) {
				$data['big'] = str_repeat( 'x', 5000 );
				return $data;
			}
		);

		$response = $this->server->dispatch( $this->submit_request( 'mcode40', array( 'q1' => 'a' ) ) );
		$this->assertSame( 413, $response->get_status() );
	}

	/**
	 * The before/after save actions fire on a successful submission.
	 *
	 * @return void
	 */
	public function test_save_actions_fire() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		$before = did_action( 'franer_before_save_submission' );
		$after  = did_action( 'franer_after_save_submission' );

		$response = $this->server->dispatch( $this->submit_request( 'mcode40', array( 'q1' => 'a' ) ) );
		$this->assertSame( 201, $response->get_status() );

		$this->assertSame( $before + 1, did_action( 'franer_before_save_submission' ) );
		$this->assertSame( $after + 1, did_action( 'franer_after_save_submission' ) );
	}

	/**
	 * The response filter can add non-sensitive metadata.
	 *
	 * @return void
	 */
	public function test_submission_response_filter() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		add_filter(
			'franer_submission_response',
			static function ( $response_data ) {
				$response_data['integration'] = 'ok';
				return $response_data;
			}
		);

		$response = $this->server->dispatch( $this->submit_request( 'mcode40', array( 'q1' => 'a' ) ) );
		$data     = $response->get_data();

		$this->assertSame( 'ok', $data['integration'] );
	}

	/**
	 * The my-submission payload filter can transform the returned payload.
	 *
	 * @return void
	 */
	public function test_my_submission_payload_filter() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		$this->server->dispatch( $this->submit_request( 'mcode40', array( 'q1' => 'a' ) ) );

		add_filter(
			'franer_my_submission_payload',
			static function ( $payload ) {
				$payload['_filtered'] = true;
				return $payload;
			}
		);

		$request  = new WP_REST_Request( 'GET', '/franer/v1/sites/mcode40/my-submission' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['payload']['_filtered'] );
	}

	/**
	 * The export rows filter can modify (e.g. anonymize) export rows.
	 *
	 * @return void
	 */
	public function test_export_rows_filter() {
		$site_id = $this->create_site();
		wp_set_current_user( $this->subscriber_id );
		$this->server->dispatch( $this->submit_request( 'mcode40', array( 'q1' => 'a' ) ) );

		add_filter(
			'franer_export_rows',
			static function ( $rows ) {
				foreach ( $rows as &$row ) {
					unset( $row['user_login'] );
					$row['anon'] = true;
				}
				return $rows;
			}
		);

		$repo = new Franer_Submissions_Repository();
		$rows = $repo->export_site_submissions( $site_id );

		$this->assertNotEmpty( $rows );
		$this->assertArrayNotHasKey( 'user_login', $rows[0] );
		$this->assertTrue( $rows[0]['anon'] );
	}

	/**
	 * The render markup filter can wrap output and the render actions fire,
	 * only for a viewable activity.
	 *
	 * @return void
	 */
	public function test_render_hooks_via_shortcode() {
		$this->create_site();
		wp_set_current_user( $this->subscriber_id );

		add_filter(
			'franer_render_markup',
			static function ( $markup ) {
				return '<div class="wrap-x">' . $markup . '</div>';
			}
		);

		$before = did_action( 'franer_before_render' );
		$after  = did_action( 'franer_after_render' );

		$output = do_shortcode( '[franer slug="mcode40"]' );

		$this->assertStringContainsString( 'wrap-x', $output );
		$this->assertSame( $before + 1, did_action( 'franer_before_render' ) );
		$this->assertSame( $after + 1, did_action( 'franer_after_render' ) );
	}

	/**
	 * The render hooks do not fire for a user who cannot view the activity.
	 *
	 * @return void
	 */
	public function test_render_hooks_skipped_when_not_viewable() {
		$this->create_site( array( 'slug' => 'restricted' ) );
		// Logged-out user cannot view.
		wp_set_current_user( 0 );

		$before = did_action( 'franer_before_render' );
		$output = do_shortcode( '[franer slug="restricted"]' );

		$this->assertSame( '', $output );
		$this->assertSame( $before, did_action( 'franer_before_render' ) );
	}
}
