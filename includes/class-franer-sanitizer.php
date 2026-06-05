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
	 * Maximum stored size, in bytes, for a generation prompt (512 KB).
	 *
	 * @var int
	 */
	const GENERATION_PROMPT_MAX_BYTES = 524288;

	/**
	 * Default Content-Security-Policy injected into the sandboxed iframe.
	 *
	 * Applied as a <meta http-equiv="Content-Security-Policy"> at render time so it
	 * acts as a guardrail on top of the iframe sandbox. The intent is to let
	 * activities load external libraries, fonts and images over https (so authors
	 * can use Bootstrap, charting libraries, web fonts, remote images, …) while
	 * blocking the channels a remote script would normally use to exfiltrate the
	 * user's answers: 'connect-src none' kills fetch/XHR/WebSocket/sendBeacon and
	 * 'form-action none' blocks form posts. The activity sends its data only via
	 * window.parent.postMessage, which is not governed by CSP, so the Franer
	 * contract keeps working.
	 *
	 * Note: 'unsafe-inline' is unavoidable because activities ship their own inline
	 * CSS/JS, and 'img-src https:' leaves an image-beacon channel open, so this is
	 * defense-in-depth, NOT an airtight guarantee — administrators must still paste
	 * only trusted activities. The value is filterable via 'franer_activity_csp'.
	 *
	 * frame-ancestors and sandbox are intentionally omitted: they are not supported
	 * in a <meta> CSP and are already enforced by the parent page header and the
	 * iframe's own sandbox attribute respectively.
	 *
	 * @var string
	 */
	const DEFAULT_ACTIVITY_CSP = "default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval' https:; style-src 'unsafe-inline' https:; img-src https: data: blob:; font-src https: data:; media-src https: data: blob:; connect-src 'none'; form-action 'none'; base-uri 'none'";

	/**
	 * Normalize and size-cap a generation prompt for storage.
	 *
	 * The prompt is administrator-provided plain text that may legitimately
	 * contain Markdown, HTML or JavaScript snippets, so it is deliberately NOT
	 * passed through KSES or sanitize_text_field(): doing so would strip angle
	 * brackets and corrupt code examples. Instead it is only normalized (line
	 * endings unified to "\n") and capped to a reasonable maximum size so it can
	 * never be used to bloat the database. The value must still always be escaped
	 * with esc_textarea() / esc_html() on output.
	 *
	 * @param mixed $prompt The raw prompt value (already wp_unslash()'d).
	 * @return string The normalized, size-capped prompt text.
	 */
	public static function sanitize_generation_prompt( $prompt ) {
		$prompt = is_scalar( $prompt ) ? (string) $prompt : '';

		// Unify line endings (CRLF and lone CR) to LF without touching content.
		$prompt = str_replace( array( "\r\n", "\r" ), "\n", $prompt );

		// Enforce a maximum size, cutting on a UTF-8 character boundary so the
		// stored text never ends with a broken multibyte sequence.
		if ( strlen( $prompt ) > self::GENERATION_PROMPT_MAX_BYTES ) {
			if ( function_exists( 'mb_strcut' ) ) {
				$prompt = mb_strcut( $prompt, 0, self::GENERATION_PROMPT_MAX_BYTES, 'UTF-8' );
			} else {
				$prompt = substr( $prompt, 0, self::GENERATION_PROMPT_MAX_BYTES );
			}
		}

		return $prompt;
	}

	/**
	 * Normalize raw admin-provided HTML (line endings only).
	 *
	 * Used for the optional submission-view template, which—like the activity
	 * HTML—is stored verbatim and only ever rendered inside a sandboxed iframe.
	 * It must not be sanitized destructively (it may contain CSS and JavaScript),
	 * so this only unifies line endings.
	 *
	 * @param mixed $html The raw HTML value (already wp_unslash()'d).
	 * @return string The normalized HTML.
	 */
	public static function sanitize_view_html( $html ) {
		$html = is_scalar( $html ) ? (string) $html : '';

		return str_replace( array( "\r\n", "\r" ), "\n", $html );
	}

	/**
	 * Remove HTML and inline-JavaScript comments from activity/view HTML.
	 *
	 * Used ONLY when preparing the srcdoc for a sandboxed iframe (public activity
	 * rendering and the admin submission-view renderer). The stored source is
	 * never modified: administrators keep their comments in the editor and in the
	 * post revisions; only the rendered copy is stripped, so maintenance comments
	 * (and any embedded generation prompt) never leak to end users.
	 *
	 * The heavy lifting (the string/template/regex-aware JavaScript scanner) lives
	 * in Franer_Comment_Stripper; this remains the stable public entry point.
	 *
	 * @param mixed $html The raw HTML to clean for rendering.
	 * @return string The HTML with HTML and inline-JS comments removed.
	 */
	public static function strip_activity_comments( $html ) {
		return Franer_Comment_Stripper::strip_activity_comments( $html );
	}

	/**
	 * Prepare raw activity/view HTML for use as an iframe "srcdoc".
	 *
	 * Single entry point used by every render path so the same transforms always
	 * apply: maintenance comments are stripped (so they never leak to users) and a
	 * guardrail Content-Security-Policy is injected. The stored source is never
	 * modified — only this rendered copy.
	 *
	 * @param mixed $html The raw stored HTML.
	 * @return string The HTML ready to be placed in a sandboxed iframe srcdoc.
	 */
	public static function prepare_for_srcdoc( $html ) {
		return self::add_activity_csp( self::strip_activity_comments( $html ) );
	}

	/**
	 * Encode a full HTML document for use as an iframe "srcdoc" attribute value.
	 *
	 * The srcdoc attribute holds an entire HTML document verbatim, and the browser
	 * decodes the attribute's character references exactly once before parsing it.
	 * esc_attr() must NOT be used here: WordPress's esc_attr() does not re-encode
	 * existing entities ($double_encode = false), so a document that legitimately
	 * contains entity text — e.g. an inline `escapeHtml` map like
	 * `{ "&": "&amp;", "\"": "&quot;" }` — would have those entities decoded by the
	 * browser ("&amp;" -> "&", "&quot;" -> "\""), corrupting the inline script and
	 * killing the whole activity (tabs included). Double-encoding ensures one round
	 * of attribute decoding reproduces the stored document byte-for-byte.
	 *
	 * @param mixed $html The prepared HTML document (see prepare_for_srcdoc()).
	 * @return string The document encoded for safe placement in a srcdoc attribute.
	 */
	public static function escape_for_srcdoc( $html ) {
		// $double_encode = true so existing entities survive the browser's single
		// attribute-decode pass intact.
		return _wp_specialchars( (string) $html, ENT_QUOTES, false, true );
	}

	/**
	 * Inject the guardrail Content-Security-Policy into an HTML document.
	 *
	 * The CSP is added as a <meta http-equiv="Content-Security-Policy"> element so
	 * it applies inside the sandboxed iframe (srcdoc). It is inserted as the first
	 * child of <head> (a <head> is created right after <html> when missing, or the
	 * meta is prepended when the input is a bare fragment) so it is parsed before
	 * any external resource is requested.
	 *
	 * If the document already declares its own CSP meta tag it is left untouched,
	 * so an author who ships a deliberately stricter/looser policy is respected.
	 *
	 * @param mixed $html The HTML (already comment-stripped) to protect.
	 * @return string The HTML with the CSP meta tag injected.
	 */
	public static function add_activity_csp( $html ) {
		$html = is_scalar( $html ) ? (string) $html : '';

		if ( '' === $html ) {
			return '';
		}

		/**
		 * Filters the Content-Security-Policy applied to sandboxed activity iframes.
		 *
		 * Security-sensitive: the default lets activities load external libraries,
		 * fonts and images over https while blocking data exfiltration via
		 * connect-src/form-action. Callbacks may pin specific CDN hosts instead of
		 * the broad "https:" source, but should keep "connect-src 'none'" (and
		 * "form-action 'none'") to preserve the no-exfiltration guarantee.
		 *
		 * @since 1.1.0
		 *
		 * @param string $csp The Content-Security-Policy directive string.
		 */
		$csp = apply_filters( 'franer_activity_csp', self::DEFAULT_ACTIVITY_CSP );
		$csp = is_scalar( $csp ) ? trim( (string) $csp ) : '';

		if ( '' === $csp ) {
			return $html;
		}

		// Respect a CSP the author already declared in the document head.
		if ( preg_match( '#<meta\b[^>]*http-equiv\s*=\s*(["\']?)content-security-policy\1#i', $html ) ) {
			return $html;
		}

		$meta = '<meta http-equiv="Content-Security-Policy" content="' . esc_attr( $csp ) . '">';

		// Insert as the first child of <head> when present.
		$with_head = preg_replace(
			'#(<head\b[^>]*>)#i',
			'$1' . $meta,
			$html,
			1,
			$head_count
		);
		if ( null !== $with_head && $head_count > 0 ) {
			return $with_head;
		}

		// No <head>: create one right after <html ...> when that tag exists.
		$with_html = preg_replace(
			'#(<html\b[^>]*>)#i',
			'$1<head>' . $meta . '</head>',
			$html,
			1,
			$html_count
		);
		if ( null !== $with_html && $html_count > 0 ) {
			return $with_html;
		}

		// Bare fragment (or pathological input): prepend the meta tag.
		return $meta . $html;
	}

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
	 * Sanitize a date-time value into a comparable 'Y-m-d H:i:s' string.
	 *
	 * Accepts the HTML datetime-local format ('Y-m-d\TH:i') or 'Y-m-d H:i:s'.
	 * Stored as local wall-clock time so it can be compared, as a string, with
	 * current_time( 'mysql' ) without timezone conversions. Invalid or empty
	 * values return an empty string (meaning "no limit").
	 *
	 * @param mixed $value The raw date-time value.
	 * @return string A 'Y-m-d H:i:s' string, or '' when empty/invalid.
	 */
	public static function sanitize_datetime( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		// datetime-local inputs use a "T" separator.
		$value = str_replace( 'T', ' ', $value );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value ) ) {
			return '';
		}

		if ( 16 === strlen( $value ) ) {
			$value .= ':00';
		}

		return $value;
	}

	/**
	 * Whether a decoded value is a non-empty JSON object (associative array).
	 *
	 * Submissions are defined as JSON objects with named fields. PHP decodes both
	 * JSON objects and JSON arrays to PHP arrays, so this also rejects JSON arrays
	 * (lists) and empty objects, which would otherwise slip through an is_array()
	 * check and be stored as malformed list payloads.
	 *
	 * @param mixed $value The decoded value to inspect.
	 * @return bool True when the value is a non-empty associative array.
	 */
	public static function is_json_object( $value ) {
		if ( ! is_array( $value ) || array() === $value ) {
			return false;
		}

		// A list has sequential integer keys 0..n-1; an object does not.
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
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

		// Must be a non-empty JSON object: lists and empty payloads are rejected.
		if ( ! self::is_json_object( $decoded ) ) {
			return new WP_Error(
				'franer_invalid_payload',
				__( 'The submitted payload is invalid or empty.', 'franer' ),
				array( 'status' => 400 )
			);
		}

		return $decoded;
	}
}
