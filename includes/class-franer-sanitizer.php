<?php
/**
 * Input sanitization and validation helpers for Franer.
 *
 * @package Franer
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Provides static sanitization and validation helpers.
 */
class Franer_Sanitizer {

	/**
	 * Sanitize and validate a slug.
	 *
	 * Allows only lowercase letters, digits and hyphens. Rejects spaces,
	 * uppercase, underscores and any other character. Empty input is invalid.
	 *
	 * @param string $slug The raw slug to sanitize.
	 * @return string|WP_Error Sanitized slug, or WP_Error on failure.
	 */
	public static function sanitize_slug( $slug ) {
		$slug = is_string( $slug ) ? trim( $slug ) : '';

		if ( '' === $slug ) {
			return new WP_Error(
				'franer_invalid_slug',
				__( 'The slug cannot be empty.', 'franer' )
			);
		}

		if ( ! self::is_valid_slug( $slug ) ) {
			return new WP_Error(
				'franer_invalid_slug',
				__( 'The slug may only contain lowercase letters, numbers and hyphens.', 'franer' )
			);
		}

		return $slug;
	}

	/**
	 * Check whether a slug is valid.
	 *
	 * @param string $slug The slug to check.
	 * @return bool True when the slug matches the allowed pattern.
	 */
	public static function is_valid_slug( $slug ) {
		if ( ! is_string( $slug ) || '' === $slug ) {
			return false;
		}

		return (bool) preg_match( '/^[a-z0-9-]+$/', $slug );
	}

	/**
	 * Sanitize a list of role slugs.
	 *
	 * Keeps only roles that exist in the current WordPress installation.
	 *
	 * @param array $roles List of role slugs.
	 * @return array Filtered, re-indexed list of valid role slugs.
	 */
	public static function sanitize_roles( $roles ) {
		if ( ! is_array( $roles ) ) {
			return array();
		}

		$valid_roles = array_keys( wp_roles()->roles );

		$roles = array_map(
			static function ( $role ) {
				return is_scalar( $role ) ? sanitize_key( $role ) : '';
			},
			$roles
		);

		return array_values( array_intersect( $roles, $valid_roles ) );
	}

	/**
	 * Sanitize a boolean-ish value.
	 *
	 * @param mixed $value The value to interpret as a boolean.
	 * @return bool The boolean interpretation.
	 */
	public static function sanitize_bool( $value ) {
		return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Sanitize and clamp the maximum payload size (in KB).
	 *
	 * Clamps to the [1, 5120] range, defaulting to 256 when not numeric.
	 *
	 * @param mixed $kb The candidate payload size in kilobytes.
	 * @return int The clamped payload size in kilobytes.
	 */
	public static function sanitize_payload_size( $kb ) {
		if ( ! is_numeric( $kb ) ) {
			$kb = 256;
		}

		$kb = (int) $kb;

		if ( 1 > $kb ) {
			$kb = 1;
		}

		if ( 5120 < $kb ) {
			$kb = 5120;
		}

		return $kb;
	}

	/**
	 * Validate and decode a JSON payload.
	 *
	 * @param string $raw_json  The raw JSON string submitted.
	 * @param int    $max_bytes The maximum allowed payload size in bytes.
	 * @return array|WP_Error Decoded associative array, or WP_Error on failure.
	 */
	public static function validate_payload( $raw_json, $max_bytes ) {
		$raw_json = is_string( $raw_json ) ? $raw_json : '';

		if ( strlen( $raw_json ) > (int) $max_bytes ) {
			return new WP_Error(
				'franer_payload_too_large',
				__( 'The submitted payload is too large.', 'franer' ),
				array( 'status' => 413 )
			);
		}

		$decoded = json_decode( $raw_json, true );

		if ( ! is_array( $decoded ) || array() === $decoded ) {
			return new WP_Error(
				'franer_invalid_payload',
				__( 'The submitted payload is invalid or empty.', 'franer' ),
				array( 'status' => 400 )
			);
		}

		return $decoded;
	}
}
