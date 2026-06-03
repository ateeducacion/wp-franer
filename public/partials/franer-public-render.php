<?php
/**
 * Public render partial for a Franer site.
 *
 * Renders a minimal page shell: a header with the site title, a sandboxed
 * iframe holding the raw AI-generated HTML, and a status region used by the
 * parent shell to report submission results.
 *
 * The HTML source is intentionally NOT sanitized: it is only ever placed
 * inside an iframe with the "srcdoc" attribute and a restrictive sandbox
 * (allow-scripts allow-forms, NO allow-same-origin), so it cannot touch the
 * parent document, cookies or storage. At render time only two non-destructive
 * transforms are applied (see Franer_Sanitizer::prepare_for_srcdoc()):
 * maintenance comments are stripped and a guardrail Content-Security-Policy is
 * injected, which lets the activity load external libraries/fonts/images over
 * https while blocking data exfiltration (connect-src/form-action).
 *
 * Expected variables in scope:
 *
 * @var WP_Post $site     The site post object.
 * @var array   $settings The typed site settings.
 *
 * @package    Franer
 * @subpackage Franer/public/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$franer_title = isset( $settings['title'] ) ? $settings['title'] : '';
// Render-time only: maintenance comments are stripped and a guardrail CSP is
// injected before the markup reaches the sandboxed iframe, so comments (and any
// embedded generation prompt) never leak to users and a remote script cannot
// exfiltrate answers. The stored source is unchanged — only this rendered copy.
$franer_html  = Franer_Sanitizer::prepare_for_srcdoc( isset( $settings['html'] ) ? $settings['html'] : '' );
$franer_slug  = isset( $settings['slug'] ) ? $settings['slug'] : '';

/* translators: %s: Franer activity title. */
$franer_iframe_title = sprintf( __( 'Activity: %s', 'franer' ), $franer_title );
?>
<div class="franer-shell" data-franer-slug="<?php echo esc_attr( $franer_slug ); ?>">
	<header class="franer-shell__header">
		<h1 class="franer-shell__title"><?php echo esc_html( $franer_title ); ?></h1>
	</header>

	<div class="franer-shell__frame-wrap">
		<iframe
			class="franer-shell__frame"
			srcdoc="<?php echo esc_attr( $franer_html ); ?>"
			sandbox="allow-scripts allow-forms"
			referrerpolicy="no-referrer"
			loading="lazy"
			title="<?php echo esc_attr( $franer_iframe_title ); ?>"></iframe>
	</div>

	<div
		class="franer-shell__status"
		role="status"
		aria-live="polite"
		hidden><?php echo esc_html__( 'Ready.', 'franer' ); ?></div>
</div>
