<?php
/**
 * Tests for Franer_Permissions.
 *
 * @package Franer
 */

/**
 * Verifies visibility, role and authentication checks.
 */
class PermissionsTest extends WP_UnitTestCase {

	/**
	 * A subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * An editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * An administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Create test users.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Build a settings array for testing.
	 *
	 * @param bool  $visible Whether the site is visible.
	 * @param array $roles   Allowed roles.
	 * @return array Settings array.
	 */
	private function settings( $visible, array $roles ) {
		return array(
			'is_visible'    => $visible,
			'allowed_roles' => $roles,
		);
	}

	/**
	 * can_manage should reflect the manage_options capability.
	 *
	 * @return void
	 */
	public function test_can_manage() {
		wp_set_current_user( $this->admin_id );
		$this->assertTrue( Franer_Permissions::can_manage() );

		wp_set_current_user( $this->subscriber_id );
		$this->assertFalse( Franer_Permissions::can_manage() );
	}

	/**
	 * A hidden site should never be viewable, even by an allowed role.
	 *
	 * @return void
	 */
	public function test_hidden_site_not_viewable() {
		$settings = $this->settings( false, array( 'subscriber' ) );

		$this->assertFalse(
			Franer_Permissions::user_can_view( $settings, $this->subscriber_id )
		);
	}

	/**
	 * A user without an allowed role should be blocked.
	 *
	 * @return void
	 */
	public function test_user_without_allowed_role_blocked() {
		$settings = $this->settings( true, array( 'editor' ) );

		$this->assertFalse(
			Franer_Permissions::user_can_view( $settings, $this->subscriber_id )
		);
	}

	/**
	 * A user holding an allowed role should be able to view.
	 *
	 * @return void
	 */
	public function test_user_with_allowed_role_can_view() {
		$settings = $this->settings( true, array( 'subscriber' ) );

		$this->assertTrue(
			Franer_Permissions::user_can_view( $settings, $this->subscriber_id )
		);
	}

	/**
	 * A logged-out user (ID 0) should be blocked.
	 *
	 * @return void
	 */
	public function test_logged_out_user_blocked() {
		wp_set_current_user( 0 );

		$settings = $this->settings( true, array( 'subscriber' ) );

		$this->assertFalse( Franer_Permissions::user_can_view( $settings, 0 ) );
	}

	/**
	 * Administrators are always allowed regardless of allowed roles.
	 *
	 * @return void
	 */
	public function test_admin_always_allowed() {
		$this->assertTrue(
			Franer_Permissions::user_has_allowed_role( array( 'editor' ), $this->admin_id )
		);

		$settings = $this->settings( true, array() );
		$this->assertTrue(
			Franer_Permissions::user_can_view( $settings, $this->admin_id )
		);
	}

	/**
	 * user_has_allowed_role should be true when the user holds any allowed role.
	 *
	 * @return void
	 */
	public function test_user_has_allowed_role() {
		$this->assertTrue(
			Franer_Permissions::user_has_allowed_role( array( 'subscriber', 'editor' ), $this->editor_id )
		);
		$this->assertFalse(
			Franer_Permissions::user_has_allowed_role( array( 'author' ), $this->subscriber_id )
		);
		$this->assertFalse(
			Franer_Permissions::user_has_allowed_role( array(), $this->subscriber_id )
		);
	}
}
