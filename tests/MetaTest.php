<?php
/**
 * Tests for the generation-prompt and submission-view meta fields.
 *
 * Covers registration, the admin save path, the typed settings array, revision
 * coverage, code-content preservation, and the guarantee that the prompt is
 * never exposed in public rendering or submission exports.
 *
 * @package Franer
 */

/**
 * Verifies the _franer_generation_prompt / _franer_view_html meta behavior.
 */
class MetaTest extends WP_UnitTestCase {

	/**
	 * An administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Boot the submissions table and an administrator.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Franer_Activator::activate();
		// Ensure the CPT and its meta are registered for this test, independent of
		// any post-type reset performed by earlier tests in the suite.
		( new Franer_Post_Types() )->register();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * The new meta keys must be registered for the franer_site post type.
	 *
	 * @return void
	 */
	public function test_meta_keys_are_registered() {
		$this->assertTrue( registered_meta_key_exists( 'post', '_franer_generation_prompt', 'franer_site' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', '_franer_view_html', 'franer_site' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', '_franer_view_generation_prompt', 'franer_site' ) );
	}

	/**
	 * The new meta keys must be flagged for revisioning.
	 *
	 * @return void
	 */
	public function test_meta_keys_are_revisioned() {
		$keys = apply_filters( 'wp_post_revision_meta_keys', array(), 'franer_site' );

		$this->assertContains( '_franer_generation_prompt', $keys );
		$this->assertContains( '_franer_view_html', $keys );
		$this->assertContains( '_franer_view_generation_prompt', $keys );
	}

	/**
	 * Saving the metabox stores the prompt and view HTML, preserving code-like
	 * content, and the typed settings expose them.
	 *
	 * @return void
	 */
	public function test_save_meta_persists_prompt_and_view_html() {
		wp_set_current_user( $this->admin_id );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'franer_site',
				'post_status' => 'publish',
			)
		);

		$prompt    = "Generate <script>danger</script>\r\nwith {braces} and \"quotes\".";
		$view_html = "<!-- view -->\n<div>render here</div><script>// c\nvar a=1;</script>";

		$_POST = array(
			'franer_site_nonce'             => wp_create_nonce( 'save_franer_site' ),
			'franer_slug'                   => 'meta-demo',
			'franer_html'                   => '<p>activity</p>',
			'franer_generation_prompt'      => wp_slash( $prompt ),
			'franer_view_html'              => wp_slash( $view_html ),
			'franer_view_generation_prompt' => wp_slash( 'view prompt <b>x</b>' ),
		);

		$admin = new Franer_Admin();
		$admin->save_meta( $post_id, get_post( $post_id ) );

		// Code-like content is preserved verbatim (only newlines normalized).
		$stored = get_post_meta( $post_id, '_franer_generation_prompt', true );
		$this->assertStringContainsString( '<script>danger</script>', $stored );
		$this->assertStringContainsString( '{braces}', $stored );
		$this->assertStringContainsString( '"quotes"', $stored );
		$this->assertStringNotContainsString( "\r", $stored );

		$this->assertStringContainsString( '<!-- view -->', get_post_meta( $post_id, '_franer_view_html', true ) );
		$this->assertSame( 'view prompt <b>x</b>', get_post_meta( $post_id, '_franer_view_generation_prompt', true ) );

		// Typed settings expose the new fields.
		$settings = ( new Franer_Site_Repository() )->get_settings( $post_id );
		$this->assertStringContainsString( '<script>danger</script>', $settings['generation_prompt'] );
		$this->assertStringContainsString( '<!-- view -->', $settings['view_html'] );
		$this->assertSame( 'view prompt <b>x</b>', $settings['view_generation_prompt'] );

		$_POST = array();
	}

	/**
	 * The generation prompt must never appear in public activity rendering, and
	 * the iframe srcdoc must not receive it.
	 *
	 * @return void
	 */
	public function test_prompt_not_exposed_in_public_render() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'franer_site',
				'post_status'  => 'publish',
				'post_content' => '<p>activity</p>',
			)
		);
		update_post_meta( $post_id, '_franer_slug', 'prompt-secret' );
		update_post_meta( $post_id, '_franer_enabled', '1' );
		update_post_meta( $post_id, '_franer_allowed_roles', array( 'subscriber' ) );
		update_post_meta( $post_id, '_franer_generation_prompt', 'SUPER_SECRET_PROMPT_TEXT' );
		update_post_meta( $post_id, '_franer_view_html', '<div>VIEW_TEMPLATE_ONLY_ADMIN</div>' );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$output = do_shortcode( '[franer slug="prompt-secret"]' );

		$this->assertStringNotContainsString( 'SUPER_SECRET_PROMPT_TEXT', $output );
		$this->assertStringNotContainsString( 'VIEW_TEMPLATE_ONLY_ADMIN', $output );
	}

	/**
	 * The generation prompt must not be included in submission exports.
	 *
	 * @return void
	 */
	public function test_prompt_not_included_in_exports() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'franer_site' ) );
		update_post_meta( $post_id, '_franer_generation_prompt', 'EXPORT_SECRET_PROMPT' );

		$repo    = new Franer_Submissions_Repository();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$repo->save_submission( $post_id, $user_id, wp_json_encode( array( 'q1' => 'a' ) ), true, false );

		$export = $repo->export_site_submissions( $post_id );

		$this->assertNotEmpty( $export );
		$this->assertArrayNotHasKey( 'generation_prompt', $export[0] );
		$this->assertStringNotContainsString( 'EXPORT_SECRET_PROMPT', (string) wp_json_encode( $export ) );
	}
}
