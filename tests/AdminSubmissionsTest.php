<?php
/**
 * Tests for Franer_Admin_Submissions (list/overview pages and post handlers).
 *
 * The two admin-post handlers end in wp_safe_redirect() + exit. To exercise the
 * success path without terminating PHPUnit, a 'wp_redirect' filter throws a
 * marker exception carrying the redirect URL (the standard WordPress test trick),
 * which we catch and inspect.
 *
 * @package Franer
 */

if ( ! class_exists( 'Franer_Redirect_Exception' ) ) {
	/**
	 * Marker exception used to capture a wp_safe_redirect() target in tests.
	 */
	class Franer_Redirect_Exception extends Exception {}
}

/**
 * Verifies the submissions admin screens and the edit/delete handlers.
 */
class AdminSubmissionsTest extends Franer_Test_Base {

	/**
	 * Controller under test.
	 *
	 * @var Franer_Admin_Submissions
	 */
	private $controller;

	/**
	 * An administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up the controller, an administrator and a redirect interceptor.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->controller = new Franer_Admin_Submissions();
		$this->admin_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Capture wp_safe_redirect()/wp_redirect() targets instead of exiting.
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Franer_Redirect_Exception( (string) $location );
			}
		);
	}

	/**
	 * Reset request state between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Create a site that has a configured submissions-overview template.
	 *
	 * @return int The site ID.
	 */
	private function site_with_view() {
		$site_id = self::factory()->franer_site->create();
		update_post_meta( $site_id, '_franer_view_html', '<!doctype html><html><body><div id="r"></div></body></html>' );

		return $site_id;
	}

	/**
	 * set_submission_view_title() seeds the global page title.
	 *
	 * @return void
	 */
	public function test_set_submission_view_title() {
		$GLOBALS['title'] = '';
		$this->controller->set_submission_view_title();
		$this->assertNotEmpty( $GLOBALS['title'] );
	}

	/**
	 * The list page renders the unfiltered overview for an administrator.
	 *
	 * @return void
	 */
	public function test_render_submissions_page_unfiltered() {
		self::factory()->franer_site->create();

		ob_start();
		$this->controller->render_submissions_page();
		$this->assertNotEmpty( ob_get_clean() );
	}

	/**
	 * The list page renders the per-site table (and its summary) when site_id is set.
	 *
	 * @return void
	 */
	public function test_render_submissions_page_filtered_by_site() {
		set_current_screen( 'franer_site_page_franer-submissions' );

		$site_id = self::factory()->franer_site->create();
		self::factory()->franer_submission->create( array( 'site_id' => $site_id ) );

		$_GET['site_id'] = $site_id;

		ob_start();
		$this->controller->render_submissions_page();
		$this->assertNotEmpty( ob_get_clean() );
	}

	/**
	 * The list page is gated by the manage capability.
	 *
	 * @return void
	 */
	public function test_render_submissions_page_denies_without_capability() {
		wp_set_current_user( 0 );
		$this->expectException( WPDieException::class );
		$this->controller->render_submissions_page();
	}

	/**
	 * The overview page renders and enqueues its script when a template exists.
	 *
	 * @return void
	 */
	public function test_render_submission_view_page_with_template() {
		$site_id         = $this->site_with_view();
		$_GET['site_id'] = $site_id;

		ob_start();
		$this->controller->render_submission_view_page();
		$html = ob_get_clean();

		$this->assertNotEmpty( $html );
		$this->assertTrue( wp_script_is( 'franer-submission-view', 'enqueued' ) );
	}

	/**
	 * The overview page is gated by the manage capability.
	 *
	 * @return void
	 */
	public function test_render_submission_view_page_denies_without_capability() {
		wp_set_current_user( 0 );
		$this->expectException( WPDieException::class );
		$this->controller->render_submission_view_page();
	}

	/**
	 * delete handler removes the row and redirects with a "deleted" status.
	 *
	 * @return void
	 */
	public function test_handle_delete_submission_success() {
		$site_id       = self::factory()->franer_site->create();
		$submission_id = self::factory()->franer_submission->create( array( 'site_id' => $site_id ) );

		$_POST['submission_id'] = $submission_id;
		$_POST['site_id']       = $site_id;
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'franer_delete_submission_' . $submission_id );

		try {
			$this->controller->handle_delete_submission();
			$this->fail( 'Expected a redirect.' );
		} catch ( Franer_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'franer_msg=deleted', $e->getMessage() );
		}

		$repo = new Franer_Submissions_Repository();
		$this->assertSame( 0, (int) $repo->count_site_submissions( $site_id ) );
	}

	/**
	 * delete handler is gated by the manage capability.
	 *
	 * @return void
	 */
	public function test_handle_delete_submission_denies_without_capability() {
		wp_set_current_user( 0 );
		$this->expectException( WPDieException::class );
		$this->controller->handle_delete_submission();
	}

	/**
	 * update handler replaces the payload and redirects with an "updated" status.
	 *
	 * @return void
	 */
	public function test_handle_update_submission_success() {
		$site_id       = self::factory()->franer_site->create();
		$submission_id = self::factory()->franer_submission->create(
			array(
				'site_id' => $site_id,
				'payload' => array( 'q1' => 'old' ),
			)
		);

		$_POST['submission_id'] = $submission_id;
		$_POST['site_id']       = $site_id;
		$_POST['payload']       = wp_slash( wp_json_encode( array( 'q1' => 'new' ) ) );
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'franer_update_submission_' . $submission_id );

		try {
			$this->controller->handle_update_submission();
			$this->fail( 'Expected a redirect.' );
		} catch ( Franer_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'franer_msg=updated', $e->getMessage() );
		}
	}

	/**
	 * update handler redirects with "invalid" when the payload is not a JSON object.
	 *
	 * @return void
	 */
	public function test_handle_update_submission_invalid_payload() {
		$site_id       = self::factory()->franer_site->create();
		$submission_id = self::factory()->franer_submission->create( array( 'site_id' => $site_id ) );

		$_POST['submission_id'] = $submission_id;
		$_POST['site_id']       = $site_id;
		$_POST['payload']       = 'not-json';
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'franer_update_submission_' . $submission_id );

		$this->expectException( Franer_Redirect_Exception::class );
		$this->expectExceptionMessage( 'franer_msg=invalid' );
		$this->controller->handle_update_submission();
	}
}
