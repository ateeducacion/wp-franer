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
 * parent document, cookies or storage.
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
// Render-time only: HTML and inline-JavaScript comments are stripped before the
// markup reaches the sandboxed iframe, so maintenance comments (and any embedded
// generation prompt) never leak to end users. The stored source keeps its
// comments — only this rendered copy is cleaned.
$franer_html  = Franer_Sanitizer::strip_activity_comments( isset( $settings['html'] ) ? $settings['html'] : '' );
$franer_slug  = isset( $settings['slug'] ) ? $settings['slug'] : '';

/* translators: %s: Franer activity title. */
$franer_iframe_title = sprintf( __( 'Activity: %s', 'franer' ), $franer_title );
?>
<div class="franer-shell" data-franer-slug="<?php echo esc_attr( $franer_slug ); ?>">
	<header class="franer-shell__header">
		<h1 class="franer-shell__title"><?php echo esc_html( $franer_title ); ?></h1>
	</header>

	<div class="franer-shell__frame-wrap">
		<button
			type="button"
			class="franer-shell__fullscreen"
			aria-pressed="false"
			hidden>
			<span class="franer-shell__fullscreen-icon" aria-hidden="true">&#9974;</span>
			<span class="franer-shell__fullscreen-text"><?php echo esc_html__( 'Fullscreen', 'franer' ); ?></span>
		</button>
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
