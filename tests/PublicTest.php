<?php
/**
 * Tests for Franer_Public.
 *
 * @package Franer
 */

/**
 * Verifies the public-facing render helpers and security headers.
 */
class PublicTest extends WP_UnitTestCase {

	/**
	 * The standalone activity page must advertise same-origin-only framing so the
	 * authenticated submit shell cannot be framed by third-party origins.
	 *
	 * @return void
	 */
	public function test_frame_protection_headers() {
		$public  = new Franer_Public( 'franer', FRANER_VERSION );
		$headers = $public->frame_protection_headers();

		$this->assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'] );
		$this->assertSame( "frame-ancestors 'self'", $headers['Content-Security-Policy'] );
	}

	/**
	 * Integrators may tighten the anti-framing headers via the filter.
	 *
	 * @return void
	 */
	public function test_frame_protection_headers_are_filterable() {
		add_filter(
			'franer_frame_protection_headers',
			static function ( $headers ) {
				$headers['X-Frame-Options'] = 'DENY';
				return $headers;
			}
		);

		$public  = new Franer_Public( 'franer', FRANER_VERSION );
		$headers = $public->frame_protection_headers();

		$this->assertSame( 'DENY', $headers['X-Frame-Options'] );
	}

	/**
	 * Create a published, viewable Franer site with the given activity HTML.
	 *
	 * @param string $html The raw activity HTML (stored in post_content).
	 * @return int The created post ID.
	 */
	private function create_viewable_site( $html ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'franer_site',
				'post_status' => 'publish',
				'post_title'  => 'Comment Activity',
			)
		);

		// Store the activity HTML exactly like the real save path (KSES bypassed),
		// so <script>/<style> blocks survive into post_content.
		Franer_Site_Repository::set_raw_html( $post_id, $html );

		update_post_meta( $post_id, '_franer_slug', 'comments-demo' );
		update_post_meta( $post_id, '_franer_enabled', '1' );
		update_post_meta( $post_id, '_franer_allowed_roles', array( 'subscriber' ) );
		update_post_meta( $post_id, '_franer_accepts_submissions', '1' );

		return $post_id;
	}

	/**
	 * Render the activity for a subscriber via the shortcode and return the markup.
	 *
	 * @param int $post_id The Franer site post ID (unused but kept for clarity).
	 * @return string The rendered shell markup.
	 */
	private function render_as_subscriber() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		return do_shortcode( '[franer slug="comments-demo"]' );
	}

	/**
	 * An open, never-submitted activity renders the form (the iframe), not a panel.
	 *
	 * @return void
	 */
	public function test_render_open_form_shows_iframe() {
		$this->create_viewable_site( '<!doctype html><html><body><div>q</div></body></html>' );

		$output = $this->render_as_subscriber();

		$this->assertStringContainsString( 'data-franer-mode="form"', $output );
		$this->assertStringContainsString( 'franer-shell__frame-wrap', $output );
		$this->assertStringNotContainsString( 'franer-shell__panel--closed', $output );
	}

	/**
	 * An activity past its end date renders the closed panel and no iframe.
	 *
	 * @return void
	 */
	public function test_render_closed_activity_shows_closed_panel() {
		$post_id = $this->create_viewable_site( '<!doctype html><html><body><div>q</div></body></html>' );
		update_post_meta( $post_id, '_franer_end_date', '2000-01-01 00:00:00' );

		$output = $this->render_as_subscriber();

		$this->assertStringContainsString( 'data-franer-mode="closed"', $output );
		$this->assertStringContainsString( 'franer-shell__panel--closed', $output );
		$this->assertStringNotContainsString( 'franer-shell__frame-wrap', $output );
	}

	/**
	 * An activity not accepting submissions is treated as closed.
	 *
	 * @return void
	 */
	public function test_render_not_accepting_submissions_shows_closed_panel() {
		$post_id = $this->create_viewable_site( '<!doctype html><html><body><div>q</div></body></html>' );
		update_post_meta( $post_id, '_franer_accepts_submissions', '0' );

		$output = $this->render_as_subscriber();

		$this->assertStringContainsString( 'data-franer-mode="closed"', $output );
		$this->assertStringContainsString( 'franer-shell__panel--closed', $output );
	}

	/**
	 * An activity with a future start date renders the not-yet panel.
	 *
	 * @return void
	 */
	public function test_render_not_yet_open_shows_notyet_panel() {
		$post_id = $this->create_viewable_site( '<!doctype html><html><body><div>q</div></body></html>' );
		update_post_meta( $post_id, '_franer_start_date', '2999-01-01 00:00:00' );

		$output = $this->render_as_subscriber();

		$this->assertStringContainsString( 'data-franer-mode="not_yet"', $output );
		$this->assertStringContainsString( 'franer-shell__panel--notyet', $output );
		$this->assertStringNotContainsString( 'franer-shell__frame-wrap', $output );
	}

	/**
	 * A single-submission, non-editable activity already submitted shows the
	 * "already submitted" panel and no iframe.
	 *
	 * @return void
	 */
	public function test_render_already_submitted_locked_shows_already_panel() {
		$post_id = $this->create_viewable_site( '<!doctype html><html><body><div>q</div></body></html>' );
		update_post_meta( $post_id, '_franer_allow_multiple_submissions', '0' );
		update_post_meta( $post_id, '_franer_allow_overwrite', '0' );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		( new Franer_Submissions_Repository() )->save_submission( $post_id, $subscriber, wp_json_encode( array( 'a' => 1 ) ), false, false );

		$output = do_shortcode( '[franer slug="comments-demo"]' );

		$this->assertStringContainsString( 'data-franer-mode="already"', $output );
		$this->assertStringContainsString( 'franer-shell__panel--already', $output );
		$this->assertStringNotContainsString( 'franer-shell__frame-wrap', $output );
	}

	/**
	 * A single-submission, editable activity already submitted renders the
	 * confirmation panel (with the iframe present but hidden) and an Edit button.
	 *
	 * @return void
	 */
	public function test_render_already_submitted_editable_shows_confirm_panel() {
		$post_id = $this->create_viewable_site( '<!doctype html><html><body><div>q</div></body></html>' );
		update_post_meta( $post_id, '_franer_allow_multiple_submissions', '0' );
		update_post_meta( $post_id, '_franer_allow_overwrite', '1' );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		( new Franer_Submissions_Repository() )->save_submission( $post_id, $subscriber, wp_json_encode( array( 'a' => 1 ) ), false, true );

		$output = do_shortcode( '[franer slug="comments-demo"]' );

		$this->assertStringContainsString( 'data-franer-mode="confirm"', $output );
		$this->assertStringContainsString( 'franer-shell__panel--confirm', $output );
		$this->assertStringContainsString( 'data-franer-action="edit"', $output );
		// The iframe is rendered (hidden) so Edit can reveal it.
		$this->assertStringContainsString( 'franer-shell__frame-wrap', $output );
	}

	/**
	 * The confirmation message is customizable via the franer_confirmation_message
	 * filter.
	 *
	 * @return void
	 */
	public function test_confirmation_message_is_filterable() {
		$this->create_viewable_site( '<!doctype html><html><body><div>q</div></body></html>' );

		add_filter(
			'franer_confirmation_message',
			static function () {
				return 'Custom thank-you copy';
			}
		);

		$output = $this->render_as_subscriber();

		$this->assertStringContainsString( 'Custom thank-you copy', $output );
	}

	/**
	 * Public rendering must strip HTML and inline-JS comments from the iframe
	 * srcdoc, while the stored source (and the admin editor's settings view) keeps
	 * its comments intact.
	 *
	 * @return void
	 */
	public function test_public_render_strips_comments_but_source_is_preserved() {
		$html = "<!-- GENERATED BY PROMPT: do not leak -->\n"
			. "<style>/* css stays */ body{color:#000}</style>\n"
			. "<div>Question</div>\n"
			. "<script>\n// js line comment\nvar keep = \"https://example.com\"; /* js block */\n</script>";

		$post_id = $this->create_viewable_site( $html );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$output = do_shortcode( '[franer slug="comments-demo"]' );

		// The rendered srcdoc must not carry HTML or JS comments...
		$this->assertStringNotContainsString( 'GENERATED BY PROMPT', $output );
		$this->assertStringNotContainsString( 'js line comment', $output );
		$this->assertStringNotContainsString( 'js block', $output );
		// ...but CSS comments and string contents survive.
		$this->assertStringContainsString( 'css stays', $output );
		$this->assertStringContainsString( 'https://example.com', $output );

		// The stored source (used by the admin editor) keeps every comment.
		$settings = ( new Franer_Site_Repository() )->get_settings( $post_id );
		$this->assertStringContainsString( 'GENERATED BY PROMPT', $settings['html'] );
		$this->assertStringContainsString( '// js line comment', $settings['html'] );
		$this->assertStringContainsString( '/* js block */', $settings['html'] );
	}

	/**
	 * The rendered iframe srcdoc must carry the guardrail Content-Security-Policy
	 * (the source is double-escaped inside the srcdoc attribute, so the plain
	 * directive keywords survive while the quotes are entity-encoded).
	 *
	 * @return void
	 */
	public function test_public_render_injects_csp_into_srcdoc() {
		$this->create_viewable_site( '<!doctype html><html><head><title>t</title></head><body><div>q</div></body></html>' );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$output = do_shortcode( '[franer slug="comments-demo"]' );

		$this->assertStringContainsString( 'Content-Security-Policy', $output );
		$this->assertStringContainsString( 'connect-src', $output );
		$this->assertStringContainsString( 'form-action', $output );
	}

	/**
	 * Regression: an activity whose inline script contains literal HTML entities
	 * (e.g. an escapeHtml map) must reach the iframe intact. The srcdoc attribute
	 * must double-encode entities so the browser's single decode pass restores the
	 * original script; otherwise "&amp;"/"&quot;" decode too early and break the JS.
	 *
	 * @return void
	 */
	public function test_public_render_double_encodes_entities_in_srcdoc() {
		$html = '<!doctype html><html><head><title>t</title></head><body><div>q</div>'
			. '<script>var map = { "&": "&amp;", "<": "&lt;", "\\"": "&quot;" };</script>'
			. '</body></html>';

		$this->create_viewable_site( $html );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$output = do_shortcode( '[franer slug="comments-demo"]' );

		// Isolate the srcdoc attribute value and decode it once, exactly like a
		// browser does before parsing the iframe document.
		$this->assertSame( 1, preg_match( '/srcdoc="([^"]*)"/s', $output, $m ) );
		$decoded = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );

		// The inline script's entity strings survive verbatim (not decoded early).
		$this->assertStringContainsString( '"&": "&amp;"', $decoded );
		$this->assertStringContainsString( '"&quot;"', $decoded );
		$this->assertStringNotContainsString( '"\\"": """', $decoded );
	}
}
