<?php
/**
 * Tests for Franer_Sanitizer.
 *
 * @package Franer
 */

/**
 * Verifies slug, role, payload-size and payload validation behavior.
 */
class SanitizerTest extends WP_UnitTestCase {

	/**
	 * Valid slugs should pass through unchanged.
	 *
	 * @return void
	 */
	public function test_sanitize_slug_accepts_valid_slugs() {
		$this->assertSame( 'mcode40', Franer_Sanitizer::sanitize_slug( 'mcode40' ) );
		$this->assertSame( 'my-site-1', Franer_Sanitizer::sanitize_slug( 'my-site-1' ) );
	}

	/**
	 * Invalid slugs should yield a WP_Error.
	 *
	 * @return void
	 */
	public function test_sanitize_slug_rejects_invalid_slugs() {
		foreach ( array( 'Bad', 'a b', 'a_b', 'a!', 'UPPER', '' ) as $bad ) {
			$result = Franer_Sanitizer::sanitize_slug( $bad );
			$this->assertWPError( $result, "Slug '{$bad}' should be rejected." );
			$this->assertSame( 'franer_invalid_slug', $result->get_error_code() );
		}
	}

	/**
	 * is_valid_slug should agree with the documented pattern.
	 *
	 * @return void
	 */
	public function test_is_valid_slug() {
		$this->assertTrue( Franer_Sanitizer::is_valid_slug( 'mcode40' ) );
		$this->assertTrue( Franer_Sanitizer::is_valid_slug( 'my-site-1' ) );
		$this->assertFalse( Franer_Sanitizer::is_valid_slug( 'Bad' ) );
		$this->assertFalse( Franer_Sanitizer::is_valid_slug( 'a b' ) );
		$this->assertFalse( Franer_Sanitizer::is_valid_slug( 'a_b' ) );
		$this->assertFalse( Franer_Sanitizer::is_valid_slug( '' ) );
	}

	/**
	 * sanitize_roles should keep only existing roles.
	 *
	 * @return void
	 */
	public function test_sanitize_roles_filters_unknown_roles() {
		$result = Franer_Sanitizer::sanitize_roles( array( 'subscriber', 'editor', 'not_a_role' ) );

		$this->assertContains( 'subscriber', $result );
		$this->assertContains( 'editor', $result );
		$this->assertNotContains( 'not_a_role', $result );
	}

	/**
	 * sanitize_roles should return an empty array for non-arrays.
	 *
	 * @return void
	 */
	public function test_sanitize_roles_handles_non_array() {
		$this->assertSame( array(), Franer_Sanitizer::sanitize_roles( 'subscriber' ) );
	}

	/**
	 * sanitize_payload_size should clamp to the [1, 5120] range.
	 *
	 * @return void
	 */
	public function test_sanitize_payload_size_clamps() {
		$this->assertSame( 1, Franer_Sanitizer::sanitize_payload_size( 0 ) );
		$this->assertSame( 1, Franer_Sanitizer::sanitize_payload_size( -10 ) );
		$this->assertSame( 5120, Franer_Sanitizer::sanitize_payload_size( 999999 ) );
		$this->assertSame( 256, Franer_Sanitizer::sanitize_payload_size( 'not-a-number' ) );
		$this->assertSame( 512, Franer_Sanitizer::sanitize_payload_size( 512 ) );
	}

	/**
	 * sanitize_bool should interpret common truthy and falsy values.
	 *
	 * @return void
	 */
	public function test_sanitize_bool() {
		$this->assertTrue( Franer_Sanitizer::sanitize_bool( '1' ) );
		$this->assertTrue( Franer_Sanitizer::sanitize_bool( true ) );
		$this->assertTrue( Franer_Sanitizer::sanitize_bool( 'true' ) );
		$this->assertFalse( Franer_Sanitizer::sanitize_bool( '0' ) );
		$this->assertFalse( Franer_Sanitizer::sanitize_bool( '' ) );
		$this->assertFalse( Franer_Sanitizer::sanitize_bool( false ) );
	}

	/**
	 * validate_payload should decode a valid object payload.
	 *
	 * @return void
	 */
	public function test_validate_payload_accepts_object() {
		$json   = wp_json_encode( array( 'answer' => 42 ) );
		$result = Franer_Sanitizer::validate_payload( $json, 1024 );

		$this->assertIsArray( $result );
		$this->assertSame( 42, $result['answer'] );
	}

	/**
	 * validate_payload should reject empty or non-object payloads.
	 *
	 * @return void
	 */
	public function test_validate_payload_rejects_invalid() {
		$empty = Franer_Sanitizer::validate_payload( '{}', 1024 );
		$this->assertWPError( $empty );
		$this->assertSame( 'franer_invalid_payload', $empty->get_error_code() );

		$bad = Franer_Sanitizer::validate_payload( 'not json', 1024 );
		$this->assertWPError( $bad );
		$this->assertSame( 'franer_invalid_payload', $bad->get_error_code() );
	}

	/**
	 * validate_payload should reject oversize payloads with a 413 error.
	 *
	 * @return void
	 */
	public function test_validate_payload_rejects_oversize() {
		$json   = wp_json_encode( array( 'blob' => str_repeat( 'x', 100 ) ) );
		$result = Franer_Sanitizer::validate_payload( $json, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'franer_payload_too_large', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 413, $data['status'] );
	}
}
