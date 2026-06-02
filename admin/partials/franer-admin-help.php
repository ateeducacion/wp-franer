<?php
/**
 * Help admin page template.
 *
 * Expects the following variable from Franer_Help::render_help_page():
 *
 * @var string $prompt The reusable AI activity-generation prompt.
 *
 * @package    Franer
 * @subpackage Franer/admin/partials
 * @author     Área de Tecnología Educativa
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<div class="wrap franer-help-wrap">
	<h1>
		<img src="<?php echo esc_url( FRANER_PLUGIN_URL . 'admin/images/franer-logo.png' ); ?>"
			alt="<?php esc_attr_e( 'Franer logo', 'franer' ); ?>"
			class="franer-help-logo" width="48" height="48" style="vertical-align: middle; margin-right: 10px;" />
		<?php esc_html_e( 'Franer Help', 'franer' ); ?>
	</h1>

	<p>
		<?php esc_html_e( 'Franer is a secure environment to publish AI-generated forms. You paste a self-contained HTML activity, Franer renders it inside a sandboxed iframe, and it safely collects users\' JSON submissions on the server.', 'franer' ); ?>
	</p>

	<h2><?php esc_html_e( 'How it works', 'franer' ); ?></h2>
	<ol>
		<li><?php esc_html_e( 'Create an activity (Franer post type) and paste its HTML in the "HTML source" box.', 'franer' ); ?></li>
		<li><?php esc_html_e( 'Set the slug, visibility, allowed roles and submission options in "Site settings".', 'franer' ); ?></li>
		<li><?php esc_html_e( 'Share the public URL or embed it with the [franer slug="..."] shortcode.', 'franer' ); ?></li>
		<li><?php esc_html_e( 'Review and export collected submissions from the Submissions page.', 'franer' ); ?></li>
	</ol>

	<h2><?php esc_html_e( 'Security model', 'franer' ); ?></h2>
	<p>
		<?php esc_html_e( 'Activity HTML always runs inside an <iframe srcdoc> with sandbox="allow-scripts allow-forms" and no same-origin access. The iframe cannot read cookies, access the parent page, or make network requests. The parent page (not the iframe) performs the authenticated REST call that stores the submission.', 'franer' ); ?>
	</p>

	<h2><?php esc_html_e( 'JavaScript contract', 'franer' ); ?></h2>
	<p>
		<?php esc_html_e( 'Each activity must implement two global functions and respond to result messages from the host page:', 'franer' ); ?>
	</p>
	<ul>
		<li><code>window.FranerCollect()</code> — <?php esc_html_e( 'returns the structured submission object.', 'franer' ); ?></li>
		<li><code>window.FranerSubmit()</code> — <?php esc_html_e( 'validates and posts { type:"franer_submit", payload:FranerCollect() } to window.parent.', 'franer' ); ?></li>
		<li><?php esc_html_e( 'A "message" listener handling { type:"franer_submit_result", ok, result } from the host page.', 'franer' ); ?></li>
	</ul>

	<h2><?php esc_html_e( 'Reusable AI prompt', 'franer' ); ?></h2>
	<p>
		<?php esc_html_e( 'Copy this prompt into your AI assistant. Replace REPLACE_WITH_ACTIVITY_SLUG with the slug of your activity and describe the topic you want.', 'franer' ); ?>
	</p>
	<p>
		<button type="button" class="button button-primary" id="franer-copy-prompt"
			data-franer-copy-target="franer-prompt-text">
			<?php esc_html_e( 'Copy prompt', 'franer' ); ?>
		</button>
		<span id="franer-copy-prompt-status" class="franer-copy-status" role="status" aria-live="polite"></span>
	</p>
	<label for="franer-prompt-text" class="screen-reader-text"><?php esc_html_e( 'AI activity prompt', 'franer' ); ?></label>
	<textarea id="franer-prompt-text" class="large-text code" rows="22" readonly><?php echo esc_textarea( $prompt ); ?></textarea>
</div>
