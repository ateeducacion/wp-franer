<?php
/**
 * Tests for Franer_Demo_Data seeding.
 *
 * @package Franer
 */

/**
 * Verifies that seeding stores the activity HTML and never leaves an
 * author-less revision behind (revisions take their author from the current
 * user, and seeding may run with none).
 */
class DemoDataTest extends WP_UnitTestCase {

	/**
	 * Register the CPT so seeding can create franer_site posts.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		( new Franer_Post_Types() )->register();
	}

	/**
	 * Seeding with no current user must attribute the post to an administrator,
	 * store the HTML in post_content and create no (author-less) revision.
	 *
	 * @return void
	 */
	public function test_seed_stores_html_and_creates_no_authorless_revision() {
		// Ensure an administrator exists, then drop the current user as the init
		// seeding hook would run.
		self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );

		$post_id = Franer_Demo_Data::seed();
		$this->assertGreaterThan( 0, $post_id );

		$post = get_post( $post_id );

		// Attributed to an administrator, not left as user 0.
		$this->assertGreaterThan( 0, (int) $post->post_author );
		$this->assertTrue( user_can( (int) $post->post_author, 'manage_options' ) );

		// The activity HTML is stored in post_content.
		$this->assertNotSame( '', trim( (string) $post->post_content ) );

		// A single insert (not a second update) means no revision — and therefore
		// no author-less one — is created by seeding.
		$revisions = wp_get_post_revisions( $post_id );
		$this->assertCount( 0, $revisions, 'Seeding must not create revisions.' );
	}
}
