<?php
/**
 * Permission and capability helpers for Franer.
 *
 * @package Franer
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Provides static permission checks.
 */
class Franer_Permissions {

	/**
	 * Whether the current user can manage Franer.
	 *
	 * @return bool True when the user has the manage_options capability.
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Whether a user holds any of the allowed roles.
	 *
	 * Administrators (users with manage_options) are always allowed.
	 *
	 * @param array $allowed_roles List of allowed role slugs.
	 * @param int   $user_id       The user ID to check.
	 * @return bool True when the user is allowed.
	 */
	public static function user_has_allowed_role( $allowed_roles, $user_id ) {
		$user_id = (int) $user_id;

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		if ( empty( $allowed_roles ) || ! is_array( $allowed_roles ) ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}

		return count( array_intersect( (array) $user->roles, $allowed_roles ) ) > 0;
	}

	/**
	 * Whether a user can view a Franer site.
	 *
	 * Requires the user to be logged in, the site to be visible and the user
	 * to hold an allowed role.
	 *
	 * @param array $settings The site settings array.
	 * @param int   $user_id  The user ID to check. Defaults to current user.
	 * @return bool True when the user can view the site.
	 */
	public static function user_can_view( $settings, $user_id = 0 ) {
		$user_id = (int) $user_id;

		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( 0 === $user_id ) {
			return false;
		}

		if ( empty( $settings['is_visible'] ) ) {
			return false;
		}

		$allowed_roles = isset( $settings['allowed_roles'] ) ? (array) $settings['allowed_roles'] : array();

		return self::user_has_allowed_role( $allowed_roles, $user_id );
	}
}
