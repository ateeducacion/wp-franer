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
}
