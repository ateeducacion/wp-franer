<?php
/**
 * Tests for Franer lifecycle and registration safety.
 *
 * Covers the activation permalink behavior, the submissions cascade on site
 * deletion, and the franer_site capability restrictions.
 *
 * @package Franer
 */

/**
 * Verifies activation/deactivation side effects and post-type access control.
 */
class LifecycleTest extends WP_UnitTestCase {

	/**
	 * Activation should enable pretty permalinks only on the plain default and
	 * deactivation should then restore the plain default.
	 *
	 * @return void
	 */
	public function test_activation_enables_pretty_permalinks_only_when_plain() {
		update_option( 'permalink_structure', '' );
		delete_option( 'franer_set_permalink_structure' );

		franer_activate();

		$this->assertSame( '/%postname%/', get_option( 'permalink_structure' ) );
		$this->assertSame( '1', (string) get_option( 'franer_set_permalink_structure' ) );

		franer_deactivate();

		$this->assertSame( '', get_option( 'permalink_structure' ) );
		$this->assertFalse( get_option( 'franer_set_permalink_structure' ) );
	}

	/**
	 * Activation must never overwrite an administrator's custom permalink
	 * structure, and deactivation must not touch a structure Franer did not set.
	 *
	 * @return void
	 */
	public function test_activation_preserves_custom_permalinks() {
		update_option( 'permalink_structure', '/%category%/%postname%/' );
		delete_option( 'franer_set_permalink_structure' );

		franer_activate();

		$this->assertSame( '/%category%/%postname%/', get_option( 'permalink_structure' ) );
		$this->assertFalse( get_option( 'franer_set_permalink_structure' ) );

		franer_deactivate();

		$this->assertSame( '/%category%/%postname%/', get_option( 'permalink_structure' ) );
	}

	/**
	 * Permanently deleting a franer_site should also delete its submissions.
	 *
	 * @return void
	 */
	public function test_deleting_site_purges_its_submissions() {
		Franer_Activator::activate();

		$repo    = new Franer_Submissions_Repository();
		$site_id = self::factory()->post->create( array( 'post_type' => 'franer_site' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$repo->save_submission( $site_id, $user_id, wp_json_encode( array( 'n' => 1 ) ), true, false );
		$repo->save_submission( $site_id, $user_id, wp_json_encode( array( 'n' => 2 ) ), true, false );
		$this->assertSame( 2, $repo->count_site_submissions( $site_id ) );

		wp_delete_post( $site_id, true );

		$this->assertSame( 0, $repo->count_site_submissions( $site_id ) );
	}

	/**
	 * Trashing a franer_site is reversible, so its submissions must survive it.
	 *
	 * @return void
	 */
	public function test_trashing_site_keeps_submissions() {
		Franer_Activator::activate();

		$repo    = new Franer_Submissions_Repository();
		$site_id = self::factory()->post->create(
			array(
				'post_type'   => 'franer_site',
				'post_status' => 'publish',
			)
		);
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$repo->save_submission( $site_id, $user_id, wp_json_encode( array( 'n' => 1 ) ), true, false );

		wp_trash_post( $site_id );

		$this->assertSame( 1, $repo->count_site_submissions( $site_id ) );
	}

	/**
	 * Editors must not be able to edit, publish or delete franer_site activities,
	 * while administrators can; regular posts stay editable by editors.
	 *
	 * @return void
	 */
	public function test_franer_site_caps_restricted_to_admins() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site   = self::factory()->post->create(
			array(
				'post_type'   => 'franer_site',
				'post_status' => 'publish',
			)
		);

		// Editors cannot manage activities through the core posts UI.
		$this->assertFalse( user_can( $editor, 'edit_post', $site ) );
		$this->assertFalse( user_can( $editor, 'delete_post', $site ) );
		$this->assertFalse( user_can( $editor, 'edit_franer_sites' ) );
		$this->assertFalse( user_can( $editor, 'publish_franer_sites' ) );
		$this->assertFalse( user_can( $editor, 'delete_others_franer_sites' ) );

		// Administrators can.
		$this->assertTrue( user_can( $admin, 'edit_post', $site ) );
		$this->assertTrue( user_can( $admin, 'delete_post', $site ) );
		$this->assertTrue( user_can( $admin, 'edit_franer_sites' ) );

		// The remap is scoped to franer_site: regular posts remain editable.
		$regular = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->assertTrue( user_can( $editor, 'edit_post', $regular ) );
	}

	/**
	 * A franer_site revision must inherit its parent's access: admins can view and
	 * restore it, editors cannot.
	 *
	 * @return void
	 */
	public function test_franer_site_revision_caps_follow_parent() {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$site   = self::factory()->post->create(
			array(
				'post_type'    => 'franer_site',
				'post_status'  => 'publish',
				'post_author'  => $admin,
				'post_content' => '<p>v1</p>',
			)
		);

		// Force a revision.
		wp_update_post( array( 'ID' => $site, 'post_content' => '<p>v2</p>' ) );
		$revisions = wp_get_post_revisions( $site );
		$this->assertNotEmpty( $revisions );
		$revision = (int) array_key_first( $revisions );

		// Admins can view (read) and restore (edit) the revision.
		$this->assertTrue( user_can( $admin, 'read_post', $revision ) );
		$this->assertTrue( user_can( $admin, 'edit_post', $revision ) );

		// Editors cannot.
		$this->assertFalse( user_can( $editor, 'edit_post', $revision ) );
	}
}
