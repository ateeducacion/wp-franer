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
	 * Strategy (see strip_js_comments() for the inline-script handling):
	 * 1. Isolate <script> and <style> blocks so they are treated specially.
	 * 2. Outside those blocks, remove HTML comments (<!-- ... -->).
	 * 3. Inside <script> blocks, remove JS comments with a string/template-aware
	 *    state machine (URLs and comment-like text inside strings are preserved).
	 * 4. Leave <style> blocks (CSS comments) and external <script src> untouched.
	 *
	 * @param mixed $html The raw HTML to clean for rendering.
	 * @return string The HTML with HTML and inline-JS comments removed.
	 */
	public static function strip_activity_comments( $html ) {
		$html = is_scalar( $html ) ? (string) $html : '';

		if ( '' === $html ) {
			return '';
		}

		// Split on <script>...</script> and <style>...</style> so their contents
		// are never treated as plain HTML. DELIM_CAPTURE keeps the blocks as
		// alternating array entries. The /s flag lets . match newlines; /i makes
		// the tag names case-insensitive.
		$parts = preg_split(
			'#(<script\b[^>]*>.*?</script\s*>|<style\b[^>]*>.*?</style\s*>)#is',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		if ( ! is_array( $parts ) ) {
			// preg_split failed (e.g. PCRE backtrack limit on pathological input);
			// fall back to stripping only HTML comments from the whole string.
			return self::strip_html_comments( $html );
		}

		$out = '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			if ( preg_match( '#^<script\b#i', $part ) ) {
				$out .= self::strip_script_block_comments( $part );
			} elseif ( preg_match( '#^<style\b#i', $part ) ) {
				// CSS comments are intentionally preserved (only HTML and JS
				// comments are stripped), so the style block passes through as-is.
				$out .= $part;
			} else {
				$out .= self::strip_html_comments( $part );
			}
		}

		return $out;
	}

	/**
	 * Remove HTML comments (<!-- ... -->) from a markup fragment.
	 *
	 * @param string $html The fragment (already free of script/style blocks).
	 * @return string The fragment without HTML comments.
	 */
	private static function strip_html_comments( $html ) {
		// Non-greedy so adjacent comments are matched individually; /s so a
		// comment may span multiple lines. An unterminated "<!--" is left intact.
		$stripped = preg_replace( '/<!--.*?-->/s', '', $html );

		return null === $stripped ? $html : $stripped;
	}

	/**
	 * Strip JS comments from the inner content of a single <script> block.
	 *
	 * External scripts (no inline body, e.g. <script src="..."></script>) and the
	 * opening/closing tags are left untouched; only the inline JavaScript body is
	 * cleaned.
	 *
	 * @param string $block A complete <script ...>...</script> block.
	 * @return string The block with inline-JS comments removed.
	 */
	private static function strip_script_block_comments( $block ) {
		if ( ! preg_match( '#^(<script\b[^>]*>)(.*)(</script\s*>)$#is', $block, $m ) ) {
			return $block;
		}

		return $m[1] . self::strip_js_comments( $m[2] ) . $m[3];
	}

	/**
	 * Remove comments from a block of JavaScript with a defensive scanner.
	 *
	 * A naive regex would corrupt strings ("https://x"), template literals and
	 * regular expressions. This single-pass state machine instead tracks string,
	 * template-literal and regex contexts so that only real // and /* *​/ comments
	 * are removed. Supported and preserved:
	 *
	 * - single- and double-quoted strings (with escapes);
	 * - template literals, including ${ ... } interpolation (with nesting);
	 * - regular-expression literals (with character classes and escapes);
	 * - URLs and comment-like text inside any of the above.
	 *
	 * Regex-vs-division is decided from the previous significant character; the
	 * rare ambiguous cases degrade safely (a misread regex is preserved, never
	 * mis-stripped, unless it embeds // or /* *​/, which is documented as out of
	 * scope). Block comments are replaced with a single space to avoid token
	 * concatenation; line comments are dropped but their newline is kept.
	 *
	 * @param string $js The inline JavaScript source.
	 * @return string The JavaScript with comments removed.
	 */
	private static function strip_js_comments( $js ) {
		$len = strlen( $js );

		// The scanner state is threaded through the per-mode helpers by reference.
		// 'out' is the accumulated output, 'i' the read cursor, 'mode' the active
		// context, 'last_sig' the last significant character (for regex/division
		// disambiguation), 'brace_depth'/'tpl_stack' the ${ ... } bookkeeping and
		// 'in_class' whether the regex scanner is inside a [ ... ] character class.
		$state = array(
			'out'         => '',
			'i'           => 0,
			'mode'        => 'normal',
			'last_sig'    => '',
			'brace_depth' => 0,
			'tpl_stack'   => array(),
			'in_class'    => false,
		);

		while ( $state['i'] < $len ) {
			$c    = $js[ $state['i'] ];
			$next = ( $state['i'] + 1 < $len ) ? $js[ $state['i'] + 1 ] : '';

			switch ( $state['mode'] ) {
				case 'line':
					self::js_scan_line( $state, $c );
					break;
				case 'block':
					self::js_scan_block( $state, $c, $next );
					break;
				case 'sq':
				case 'dq':
					self::js_scan_string( $state, $c, $next );
					break;
				case 'tpl':
					self::js_scan_template( $state, $c, $next );
					break;
				case 'regex':
					self::js_scan_regex( $state, $c, $next );
					break;
				default:
					self::js_scan_normal( $state, $c, $next );
					break;
			}
		}

		return $state['out'];
	}

	/**
	 * Scan one character in "normal" (code) mode.
	 *
	 * Detects the start of comments, regex literals, strings, template literals
	 * and brace bookkeeping; any other character is copied verbatim.
	 *
	 * @param array  $state The scanner state (by reference).
	 * @param string $c     The current character.
	 * @param string $next  The next character ('' at end of input).
	 * @return void
	 */
	private static function js_scan_normal( &$state, $c, $next ) {
		if ( '/' === $c && self::js_normal_slash( $state, $next ) ) {
			return;
		}
		if ( self::js_normal_opener( $state, $c ) ) {
			return;
		}
		if ( self::js_normal_brace( $state, $c ) ) {
			return;
		}

		// Any other character is copied verbatim. Whitespace does not change the
		// "last significant" character used to disambiguate regex from division.
		$state['out'] .= $c;
		if ( '' !== trim( $c ) ) {
			$state['last_sig'] = $c;
		}
		$state['i']++;
	}

	/**
	 * Handle a "/" in normal mode (line/block comment or regex literal).
	 *
	 * @param array  $state The scanner state (by reference).
	 * @param string $next  The next character.
	 * @return bool True when the "/" started a comment or regex; false for division.
	 */
	private static function js_normal_slash( &$state, $next ) {
		if ( '/' === $next ) {
			$state['mode'] = 'line';
			$state['i']   += 2;
			return true;
		}
		if ( '*' === $next ) {
			$state['mode'] = 'block';
			$state['i']   += 2;
			return true;
		}
		if ( self::js_regex_can_start( $state['last_sig'] ) ) {
			$state['mode']     = 'regex';
			$state['in_class'] = false;
			$state['out']     .= '/';
			$state['i']++;
			return true;
		}

		// A "/" that is none of the above is the division operator; let the caller
		// copy it as an ordinary character.
		return false;
	}

	/**
	 * Handle a string/template-literal opener (', " or `) in normal mode.
	 *
	 * @param array  $state The scanner state (by reference).
	 * @param string $c     The current character.
	 * @return bool True when an opener was consumed; false otherwise.
	 */
	private static function js_normal_opener( &$state, $c ) {
		$modes = array(
			"'" => 'sq',
			'"' => 'dq',
			'`' => 'tpl',
		);

		if ( ! isset( $modes[ $c ] ) ) {
			return false;
		}

		$state['mode']     = $modes[ $c ];
		$state['out']     .= $c;
		$state['last_sig'] = $c;
		$state['i']++;
		return true;
	}

	/**
	 * Track "{" / "}" depth in normal mode, resuming a template on the closing }.
	 *
	 * @param array  $state The scanner state (by reference).
	 * @param string $c     The current character.
	 * @return bool True when a brace was consumed; false otherwise.
	 */
	private static function js_normal_brace( &$state, $c ) {
		if ( '{' === $c ) {
			$state['brace_depth']++;
		} elseif ( '}' === $c ) {
			if ( 0 === $state['brace_depth'] && ! empty( $state['tpl_stack'] ) ) {
				// Close a ${ ... } interpolation and resume the template literal.
				$state['brace_depth'] = (int) array_pop( $state['tpl_stack'] );
				$state['mode']        = 'tpl';
			} elseif ( $state['brace_depth'] > 0 ) {
				$state['brace_depth']--;
			}
		} else {
			return false;
		}

		$state['out']     .= $c;
		$state['last_sig'] = $c;
		$state['i']++;
		return true;
	}

	/**
	 * Scan one character inside a // line comment (dropped until newline).
	 *
	 * @param array  $state The scanner state (by reference).
	 * @param string $c     The current character.
	 * @return void
	 */
	private static function js_scan_line( &$state, $c ) {
		if ( "\n" === $c ) {
			$state['mode']     = 'normal';
			$state['out']     .= $c;
			$state['last_sig'] = "\n";
		}
		$state['i']++;
	}

	/**
	 * Scan one character inside a block comment (replaced with a single space).
	 *
	 * @param array  $state The scanner state (by reference).
	 * @param string $c     The current character.
	 * @param string $next  The next character.
	 * @return void
	 */
	private static function js_scan_block( &$state, $c, $next ) {
		if ( '*' === $c && '/' === $next ) {
			$state['mode'] = 'normal';
			$state['out'] .= ' ';
			$state['i']   += 2;
			return;
		}
		$state['i']++;
	}

	/**
	 * Scan one character inside a single- or double-quoted string (preserved).
	 *
	 * @param array  $state The scanner state (by reference).
	 * @param string $c     The current character.
	 * @param string $next  The next character.
	 * @return void
	 */
	private static function js_scan_string( &$state, $c, $next ) {
		$state['out'] .= $c;

		if ( '\\' === $c ) {
			$state['out'] .= $next;
			$state['i']   += 2;
			return;
		}

		$quote = ( 'sq' === $state['mode'] ) ? "'" : '"';
		if ( $c === $quote ) {
			$state['mode']     = 'normal';
			$state['last_sig'] = $c;
		}
		$state['i']++;
	}

	/**
	 * Scan one character inside a template literal (preserved, ${ } aware).
	 *
	 * @param array  $state The scanner state (by reference).
	 * @param string $c     The current character.
	 * @param string $next  The next character.
	 * @return void
	 */
	private static function js_scan_template( &$state, $c, $next ) {
		if ( '\\' === $c ) {
			$state['out'] .= $c . $next;
			$state['i']   += 2;
			return;
		}
		if ( '`' === $c ) {
			$state['out']     .= $c;
			$state['mode']     = 'normal';
			$state['last_sig'] = $c;
			$state['i']++;
			return;
		}
		if ( '$' === $c && '{' === $next ) {
			// Enter an interpolation: remember the outer brace depth and scan the
			// expression as normal code until its matching }.
			$state['tpl_stack'][] = $state['brace_depth'];
			$state['brace_depth'] = 0;
			$state['mode']        = 'normal';
			$state['out']        .= '${';
			$state['i']          += 2;
			return;
		}

		$state['out'] .= $c;
		$state['i']++;
	}

	/**
	 * Scan one character inside a regex literal (preserved, [ ] class aware).
	 *
	 * @param array  $state The scanner state (by reference).
	 * @param string $c     The current character.
	 * @param string $next  The next character.
	 * @return void
	 */
	private static function js_scan_regex( &$state, $c, $next ) {
		$state['out'] .= $c;

		if ( '\\' === $c ) {
			$state['out'] .= $next;
			$state['i']   += 2;
			return;
		}

		if ( '[' === $c ) {
			$state['in_class'] = true;
		} elseif ( ']' === $c ) {
			$state['in_class'] = false;
		} elseif ( '/' === $c && ! $state['in_class'] ) {
			$state['mode']     = 'normal';
			$state['last_sig'] = $c;
		}
		$state['i']++;
	}

	/**
	 * Heuristic: can a "/" at this point begin a regular-expression literal?
	 *
	 * A regex may start at the beginning of the script or after a character that
	 * cannot end an expression (operators, punctuation, an opening bracket, etc.).
	 * After a value-like token (identifier, number, ), ], }) a "/" is division.
	 *
	 * @param string $last_sig The previous significant character ('' at start).
	 * @return bool True when a regex literal may legitimately start here.
	 */
	private static function js_regex_can_start( $last_sig ) {
		if ( '' === $last_sig ) {
			return true;
		}

		// Characters after which a "/" introduces a regex rather than division.
		return false !== strpos( '(,=:[!&|?{;+-*%^~<>', $last_sig );
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
