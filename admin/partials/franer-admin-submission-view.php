<?php
/**
 * Admin-only "Submissions overview" page template.
 *
 * Renders ALL of a Franer's submissions through the site's optional
 * `_franer_view_html` template inside a sandboxed iframe. The decoded
 * submissions are delivered to the iframe by the parent page via postMessage
 * (franer-submission-view.js); they are never interpolated into the iframe
 * markup. The iframe carries no REST nonce and no privileged URL.
 *
 * Expects the following variables from Franer_Admin_Submissions::render_submission_view_page():
 *
 * @var string $back_url     URL back to the submissions list.
 * @var string $error        Error/notice message (empty when a template renders).
 * @var bool   $has_template Whether a view template is available to render.
 * @var string $view_html    Comment-stripped view template HTML (iframe srcdoc).
 * @var string $site_title   The Franer site title.
 * @var int    $count        Total submissions for the site.
 * @var bool   $truncated    Whether the delivered list was capped.
 * @var string $pretty_json  Pretty-printed delivered context JSON.
 * @var array  $context      The submission context delivered via postMessage.
 *
 * @package    Franer
 * @subpackage Franer/admin/partials
 * @author     Área de Tecnología Educativa
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<div class="wrap franer-submission-view-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Submissions overview', 'franer' ); ?></h1>

	<p>
		<a class="button" href="<?php echo esc_url( $back_url ); ?>">
			&laquo; <?php esc_html_e( 'Back to submissions', 'franer' ); ?>
		</a>
		<button type="button" class="button" onclick="window.print();">
			<?php esc_html_e( 'Print', 'franer' ); ?>
		</button>
	</p>

	<?php if ( '' !== $error ) : ?>
		<div class="notice notice-warning">
			<p><?php echo esc_html( $error ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $has_template ) : ?>
		<table class="widefat striped" style="max-width: 640px; margin-bottom: 16px;">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Activity', 'franer' ); ?></th>
					<td><?php echo esc_html( $site_title ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Total submissions', 'franer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<?php if ( $truncated ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: maximum number of submissions delivered to the view. */
						esc_html__( 'This activity has more submissions than can be rendered at once. The view received the %s most recent submissions.', 'franer' ),
						esc_html( number_format_i18n( Franer_Admin_Submissions::VIEW_MAX_SUBMISSIONS ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<details class="franer-raw-json">
			<summary><?php esc_html_e( 'View JSON', 'franer' ); ?></summary>
			<label for="franer-view-json" class="screen-reader-text"><?php esc_html_e( 'Submissions JSON payload', 'franer' ); ?></label>
			<textarea id="franer-view-json" class="large-text code" rows="12" readonly spellcheck="false"><?php echo esc_textarea( $pretty_json ); ?></textarea>
		</details>

		<h2 class="screen-reader-text"><?php esc_html_e( 'Rendered overview', 'franer' ); ?></h2>
		<iframe
			id="franer-view-frame"
			class="franer-view-frame"
			<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape_for_srcdoc() double-encodes the whole document with ENT_QUOTES for the srcdoc attribute; esc_attr() would under-encode existing entities. ?>
			srcdoc="<?php echo Franer_Sanitizer::escape_for_srcdoc( $view_html ); ?>"
			sandbox="allow-scripts allow-modals"
			referrerpolicy="no-referrer"
			title="<?php esc_attr_e( 'Rendered submissions overview', 'franer' ); ?>"></iframe>
	<?php endif; ?>
</div>
