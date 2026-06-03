<?php
/**
 * Tests for the admin-only submissions-overview route.
 *
 * Covers capability enforcement, invalid-site handling, the missing-template
 * notice, and the aggregate postMessage context built for the iframe.
 *
 * @package Franer
 */

/**
 * Verifies Franer_Admin_Submissions::render_submission_view_page() / prepare_submission_view().
 */
class SubmissionViewTest extends WP_UnitTestCase {

	/**
	 * Submissions repository.
	 *
	 * @var Franer_Submissions_Repository
	 */
	private $repo;

	/**
	 * Boot the submissions table.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Franer_Activator::activate();
		$this->repo = new Franer_Submissions_Repository();
	}

	/**
	 * Create a Franer site, optionally with a submissions-overview template.
	 *
	 * @param string $view_html Optional view template HTML.
	 * @return int The created post ID.
	 */
	private function create_site( $view_html = '' ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'franer_site',
				'post_status' => 'publish',
				'post_title'  => 'View Activity',
			)
		);
		update_post_meta( $post_id, '_franer_slug', 'view-demo' );
		if ( '' !== $view_html ) {
			update_post_meta( $post_id, '_franer_view_html', $view_html );
		}

		return $post_id;
	}

	/**
	 * The route must reject non-administrators with wp_die().
	 *
	 * @return void
	 */
	public function test_view_requires_manage_options() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_GET['site_id'] = 1;

		$admin = new Franer_Admin_Submissions();
		$this->expectException( 'WPDieException' );
		$admin->render_submission_view_page();
	}

	/**
	 * Invalid or unknown site IDs yield a "not found" error and no template.
	 *
	 * @return void
	 */
	public function test_invalid_site_id_is_rejected() {
		$admin = new Franer_Admin_Submissions();

		$zero = $admin->prepare_submission_view( 0 );
		$this->assertFalse( $zero['has_template'] );
		$this->assertNotSame( '', $zero['error'] );

		$missing = $admin->prepare_submission_view( 999999 );
		$this->assertFalse( $missing['has_template'] );
		$this->assertNotSame( '', $missing['error'] );
	}

	/**
	 * A non-franer_site post ID is rejected.
	 *
	 * @return void
	 */
	public function test_non_franer_post_is_rejected() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$admin = new Franer_Admin_Submissions();
		$data  = $admin->prepare_submission_view( $post_id );

		$this->assertFalse( $data['has_template'] );
		$this->assertNotSame( '', $data['error'] );
	}

	/**
	 * A Franer with no view template shows the configured notice.
	 *
	 * @return void
	 */
	public function test_notice_when_no_template_configured() {
		$site_id = $this->create_site();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->repo->save_submission( $site_id, $user_id, wp_json_encode( array( 'q1' => 'a' ) ), true, false );

		$admin = new Franer_Admin_Submissions();
		$data  = $admin->prepare_submission_view( $site_id );

		$this->assertFalse( $data['has_template'] );
		// Compare against the translated string so the assertion is locale-agnostic
		// (the tests environment may run with a non-English locale).
		$this->assertSame(
			__( 'No submission view template has been configured for this Franer.', 'franer' ),
			$data['error']
		);
	}

	/**
	 * When a template exists, the context carries every decoded submission (and no
	 * nonce/privileged URL/PII), and the view HTML is comment-stripped.
	 *
	 * @return void
	 */
	public function test_context_contains_all_submissions_and_stripped_html() {
		$view_html = "<!-- view secret -->\n<div>Overview</div><script>// note\nvar a = 1;</script>";
		$site_id   = $this->create_site( $view_html );

		// Two users, three submissions in total (multiple submissions allowed).
		$user_a = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user_b = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->repo->save_submission( $site_id, $user_a, wp_json_encode( array( 'data' => array( 'q1' => 'x' ) ) ), true, false );
		$this->repo->save_submission( $site_id, $user_a, wp_json_encode( array( 'data' => array( 'q1' => 'y' ) ) ), true, false );
		$this->repo->save_submission( $site_id, $user_b, wp_json_encode( array( 'data' => array( 'q1' => 'z' ) ) ), true, false );

		$admin = new Franer_Admin_Submissions();
		$data  = $admin->prepare_submission_view( $site_id );

		$this->assertTrue( $data['has_template'] );
		$this->assertSame( '', $data['error'] );
		$this->assertSame( 3, $data['count'] );
		$this->assertFalse( $data['truncated'] );

		// Context shape matches the documented postMessage contract.
		$this->assertSame( 'view-demo', $data['context']['site']['slug'] );
		$this->assertSame( 3, $data['context']['count'] );
		$this->assertCount( 3, $data['context']['submissions'] );

		// Each submission carries a decoded payload (not a raw JSON string).
		$first = $data['context']['submissions'][0];
		$this->assertIsArray( $first['payload'] );
		$this->assertArrayHasKey( 'data', $first['payload'] );

		// No nonce, privileged URL or stored PII leaks into the iframe context.
		$encoded = strtolower( (string) wp_json_encode( $data['context'] ) );
		$this->assertStringNotContainsString( 'nonce', $encoded );
		$this->assertStringNotContainsString( 'admin.php', $encoded );
		$this->assertStringNotContainsString( 'ip_hash', $encoded );
		$this->assertStringNotContainsString( 'user_agent', $encoded );
		$this->assertStringNotContainsString( 'user_email', $encoded );

		// The rendered template HTML is comment-stripped.
		$this->assertStringNotContainsString( 'view secret', $data['view_html'] );
		$this->assertStringNotContainsString( '// note', $data['view_html'] );
		$this->assertStringContainsString( '<div>Overview</div>', $data['view_html'] );
		$this->assertStringContainsString( 'var a = 1;', $data['view_html'] );

		// ...and the guardrail Content-Security-Policy is injected for the iframe.
		$this->assertStringContainsString( 'http-equiv="Content-Security-Policy"', $data['view_html'] );
		$this->assertStringContainsString( 'connect-src', $data['view_html'] );
	}

	/**
	 * The overview template renders without error, including the truncation notice
	 * (which references Franer_Admin_Submissions::VIEW_MAX_SUBMISSIONS).
	 *
	 * @return void
	 */
	public function test_overview_template_renders_truncation_notice() {
		$back_url     = 'https://example.test/wp-admin/edit.php';
		$error        = '';
		$has_template = true;
		$view_html    = '<div>Overview</div>';
		$site_title   = 'View Activity';
		$count        = Franer_Admin_Submissions::VIEW_MAX_SUBMISSIONS + 5;
		$truncated    = true;
		$pretty_json  = '{}';
		$context      = array();

		ob_start();
		require dirname( __DIR__ ) . '/admin/partials/franer-admin-submission-view.php';
		$html = (string) ob_get_clean();

		// The page rendered, the iframe is present and the truncation notice shows
		// the configured cap (proving the constant reference resolves).
		$this->assertStringContainsString( 'franer-view-frame', $html );
		$this->assertStringContainsString(
			number_format_i18n( Franer_Admin_Submissions::VIEW_MAX_SUBMISSIONS ),
			$html
		);
	}
}
