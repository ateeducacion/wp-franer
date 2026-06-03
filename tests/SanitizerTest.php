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
	 * is_json_object should accept only non-empty associative arrays.
	 *
	 * @return void
	 */
	public function test_is_json_object() {
		$this->assertTrue( Franer_Sanitizer::is_json_object( array( 'a' => 1 ) ) );
		$this->assertTrue( Franer_Sanitizer::is_json_object( array( 'a' => 1, 'b' => 2 ) ) );

		// Lists, empty arrays, scalars and null are not JSON objects.
		$this->assertFalse( Franer_Sanitizer::is_json_object( array( 'a', 'b' ) ) );
		$this->assertFalse( Franer_Sanitizer::is_json_object( array( 0 => 'a', 1 => 'b' ) ) );
		$this->assertFalse( Franer_Sanitizer::is_json_object( array() ) );
		$this->assertFalse( Franer_Sanitizer::is_json_object( 'string' ) );
		$this->assertFalse( Franer_Sanitizer::is_json_object( null ) );
	}

	/**
	 * validate_payload should reject a JSON array (list) payload.
	 *
	 * @return void
	 */
	public function test_validate_payload_rejects_list() {
		$result = Franer_Sanitizer::validate_payload( wp_json_encode( array( 'a', 'b' ) ), 1024 );

		$this->assertWPError( $result );
		$this->assertSame( 'franer_invalid_payload', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
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

	/**
	 * The generation prompt must preserve code-like content (angle brackets,
	 * braces, quotes) and only normalize line endings.
	 *
	 * @return void
	 */
	public function test_sanitize_generation_prompt_preserves_code() {
		$prompt = "Use <script>alert('x')</script>\r\nand {a:1} \"quotes\" 'single'\r/* block */";
		$result = Franer_Sanitizer::sanitize_generation_prompt( $prompt );

		$this->assertStringContainsString( "<script>alert('x')</script>", $result );
		$this->assertStringContainsString( '{a:1}', $result );
		$this->assertStringContainsString( '"quotes"', $result );
		$this->assertStringContainsString( '/* block */', $result );

		// CRLF and lone CR are normalized to LF; no carriage returns survive.
		$this->assertStringNotContainsString( "\r", $result );
		$this->assertStringContainsString( "</script>\nand", $result );
	}

	/**
	 * The generation prompt must be capped at the documented maximum size.
	 *
	 * @return void
	 */
	public function test_sanitize_generation_prompt_caps_size() {
		$max    = Franer_Sanitizer::GENERATION_PROMPT_MAX_BYTES;
		$prompt = str_repeat( 'a', $max + 5000 );
		$result = Franer_Sanitizer::sanitize_generation_prompt( $prompt );

		$this->assertLessThanOrEqual( $max, strlen( $result ) );
	}

	/**
	 * Non-scalar prompt input degrades to an empty string.
	 *
	 * @return void
	 */
	public function test_sanitize_generation_prompt_handles_non_scalar() {
		$this->assertSame( '', Franer_Sanitizer::sanitize_generation_prompt( array( 'x' ) ) );
		$this->assertSame( '', Franer_Sanitizer::sanitize_generation_prompt( null ) );
	}

	/**
	 * strip_activity_comments removes HTML comments outside script/style blocks.
	 *
	 * @return void
	 */
	public function test_strip_activity_comments_removes_html_comments() {
		$html    = "<!-- secret prompt -->\n<div>visible</div><!-- another -->";
		$cleaned = Franer_Sanitizer::strip_activity_comments( $html );

		$this->assertStringNotContainsString( '<!--', $cleaned );
		$this->assertStringNotContainsString( 'secret prompt', $cleaned );
		$this->assertStringContainsString( '<div>visible</div>', $cleaned );
	}

	/**
	 * strip_activity_comments removes JS line and block comments inside scripts.
	 *
	 * @return void
	 */
	public function test_strip_activity_comments_removes_js_comments() {
		$html    = "<script>\n// a line comment\nvar x = 1; /* a block comment */ var y = 2;\n</script>";
		$cleaned = Franer_Sanitizer::strip_activity_comments( $html );

		$this->assertStringNotContainsString( 'a line comment', $cleaned );
		$this->assertStringNotContainsString( 'a block comment', $cleaned );
		$this->assertStringContainsString( 'var x = 1;', $cleaned );
		$this->assertStringContainsString( 'var y = 2;', $cleaned );
	}

	/**
	 * strip_activity_comments must preserve comment-like text and URLs inside
	 * strings, template literals and regular expressions.
	 *
	 * @return void
	 */
	public function test_strip_activity_comments_preserves_strings_and_urls() {
		$html = "<script>\n"
			. "var url = \"https://example.com/path\";\n"
			. "var s = '// not a comment';\n"
			. "var b = \"/* not a comment */\";\n"
			. "var t = `tpl // not /* a */ comment \${1 + 1}`;\n"
			. "var re = /https:\\/\\//g;\n"
			. '</script>';

		$cleaned = Franer_Sanitizer::strip_activity_comments( $html );

		$this->assertStringContainsString( 'https://example.com/path', $cleaned );
		$this->assertStringContainsString( "'// not a comment'", $cleaned );
		$this->assertStringContainsString( '"/* not a comment */"', $cleaned );
		$this->assertStringContainsString( 'tpl // not /* a */ comment ${1 + 1}', $cleaned );
		$this->assertStringContainsString( '/https:\\/\\//g', $cleaned );
	}

	/**
	 * CSS comments inside <style> blocks are intentionally preserved (only HTML
	 * and JS comments are stripped).
	 *
	 * @return void
	 */
	public function test_strip_activity_comments_keeps_css_comments() {
		$html    = '<style>/* keep this css comment */ body { color: red; }</style>';
		$cleaned = Franer_Sanitizer::strip_activity_comments( $html );

		$this->assertStringContainsString( '/* keep this css comment */', $cleaned );
	}

	/**
	 * strip_activity_comments handles non-string and empty input gracefully.
	 *
	 * @return void
	 */
	public function test_strip_activity_comments_handles_empty_and_non_string() {
		$this->assertSame( '', Franer_Sanitizer::strip_activity_comments( '' ) );
		$this->assertSame( '', Franer_Sanitizer::strip_activity_comments( array() ) );
		$this->assertSame( '', Franer_Sanitizer::strip_activity_comments( null ) );
	}

	/**
	 * sanitize_view_html normalizes line endings without altering markup.
	 *
	 * @return void
	 */
	public function test_sanitize_view_html_normalizes_newlines() {
		$result = Franer_Sanitizer::sanitize_view_html( "<div>a</div>\r\n<span>b</span>\r" );

		$this->assertStringNotContainsString( "\r", $result );
		$this->assertStringContainsString( '<div>a</div>', $result );
		$this->assertStringContainsString( '<span>b</span>', $result );
	}

	/**
	 * add_activity_csp injects the CSP meta as the first child of <head>.
	 *
	 * @return void
	 */
	public function test_add_activity_csp_injects_into_head() {
		$html   = '<!doctype html><html><head><title>x</title></head><body>hi</body></html>';
		$result = Franer_Sanitizer::add_activity_csp( $html );

		$this->assertStringContainsString( 'http-equiv="Content-Security-Policy"', $result );
		$this->assertStringContainsString( "connect-src &#039;none&#039;", $result );
		$this->assertStringContainsString( "form-action &#039;none&#039;", $result );
		// Inserted before the existing <title>, i.e. as the first head child.
		$this->assertMatchesRegularExpression( '#<head>\s*<meta http-equiv="Content-Security-Policy"#', $result );
	}

	/**
	 * add_activity_csp creates a <head> when the document lacks one.
	 *
	 * @return void
	 */
	public function test_add_activity_csp_creates_head_when_missing() {
		$html   = '<html><body>only body</body></html>';
		$result = Franer_Sanitizer::add_activity_csp( $html );

		$this->assertStringContainsString( '<head><meta http-equiv="Content-Security-Policy"', $result );
		$this->assertStringContainsString( '<body>only body</body>', $result );
	}

	/**
	 * add_activity_csp prepends the meta for a bare fragment with no html/head.
	 *
	 * @return void
	 */
	public function test_add_activity_csp_prepends_for_fragment() {
		$result = Franer_Sanitizer::add_activity_csp( '<div>fragment</div>' );

		$this->assertStringStartsWith( '<meta http-equiv="Content-Security-Policy"', $result );
		$this->assertStringContainsString( '<div>fragment</div>', $result );
	}

	/**
	 * add_activity_csp leaves an author-declared CSP meta untouched.
	 *
	 * @return void
	 */
	public function test_add_activity_csp_respects_existing_policy() {
		$html   = '<html><head><meta http-equiv="Content-Security-Policy" content="default-src \'self\'"></head><body></body></html>';
		$result = Franer_Sanitizer::add_activity_csp( $html );

		// Only the author's policy remains; the default is not added.
		$this->assertSame( 1, substr_count( $result, 'http-equiv="Content-Security-Policy"' ) );
		$this->assertStringContainsString( "default-src 'self'", $result );
		$this->assertStringNotContainsString( "connect-src", $result );
	}

	/**
	 * The franer_activity_csp filter overrides the injected policy.
	 *
	 * @return void
	 */
	public function test_add_activity_csp_is_filterable() {
		$filter = static function () {
			return "default-src 'none'; img-src https:";
		};
		add_filter( 'franer_activity_csp', $filter );

		$result = Franer_Sanitizer::add_activity_csp( '<html><head></head><body></body></html>' );

		remove_filter( 'franer_activity_csp', $filter );

		$this->assertStringContainsString( 'img-src https:', $result );
		$this->assertStringNotContainsString( 'connect-src', $result );
	}

	/**
	 * An empty filter value skips injection entirely.
	 *
	 * @return void
	 */
	public function test_add_activity_csp_empty_filter_skips_injection() {
		$filter = static function () {
			return '';
		};
		add_filter( 'franer_activity_csp', $filter );

		$html   = '<html><head></head><body>x</body></html>';
		$result = Franer_Sanitizer::add_activity_csp( $html );

		remove_filter( 'franer_activity_csp', $filter );

		$this->assertSame( $html, $result );
	}

	/**
	 * add_activity_csp handles empty and non-string input gracefully.
	 *
	 * @return void
	 */
	public function test_add_activity_csp_handles_empty_and_non_string() {
		$this->assertSame( '', Franer_Sanitizer::add_activity_csp( '' ) );
		$this->assertSame( '', Franer_Sanitizer::add_activity_csp( array() ) );
		$this->assertSame( '', Franer_Sanitizer::add_activity_csp( null ) );
	}

	/**
	 * prepare_for_srcdoc both strips comments and injects the CSP.
	 *
	 * @return void
	 */
	public function test_prepare_for_srcdoc_strips_comments_and_adds_csp() {
		$html   = "<!doctype html><html><head><!-- secret prompt --></head><body><script>/* x */var a=1;</script></body></html>";
		$result = Franer_Sanitizer::prepare_for_srcdoc( $html );

		$this->assertStringNotContainsString( 'secret prompt', $result );
		$this->assertStringNotContainsString( '/* x */', $result );
		$this->assertStringContainsString( 'http-equiv="Content-Security-Policy"', $result );
		$this->assertStringContainsString( 'var a=1;', $result );
	}
}
