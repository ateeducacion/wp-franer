<?php
/**
 * Submissions list admin page template.
 *
 * Expects the following variables from Franer_Admin::render_submissions_page():
 *
 * @var array  $site_posts    Array of franer_site WP_Post objects for the filter.
 * @var int    $selected_site Currently selected site ID (0 = none).
 * @var array  $rows          Normalized submission rows for the selected site.
 * @var string $export_url    Nonced export URL (empty when no site selected).
 *
 * @package    Franer
 * @subpackage Franer/admin/partials
 * @author     Área de Tecnología Educativa
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<div class="wrap franer-submissions-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Franer Submissions', 'franer' ); ?></h1>

	<form method="get" class="franer-submissions-filter">
		<input type="hidden" name="post_type" value="franer_site" />
		<input type="hidden" name="page" value="franer-submissions" />
		<label for="franer-site-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by activity', 'franer' ); ?></label>
		<select id="franer-site-filter" name="site_id">
			<option value="0"><?php esc_html_e( '— Select an activity —', 'franer' ); ?></option>
			<?php foreach ( $site_posts as $franer_site_post ) : ?>
				<option value="<?php echo esc_attr( (string) $franer_site_post->ID ); ?>" <?php selected( $selected_site, $franer_site_post->ID ); ?>>
					<?php echo esc_html( $franer_site_post->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'franer' ); ?></button>
		<?php if ( $selected_site > 0 && '' !== $export_url ) : ?>
			<a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>">
				<?php esc_html_e( 'Export JSON', 'franer' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( 0 === $selected_site ) : ?>
		<p><?php esc_html_e( 'Choose an activity to view its submissions.', 'franer' ); ?></p>
	<?php elseif ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'No submissions found for this activity yet.', 'franer' ); ?></p>
	<?php else : ?>
		<table class="widefat striped franer-submissions-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'ID', 'franer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Activity', 'franer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'User', 'franer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Created', 'franer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Updated', 'franer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Payload', 'franer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $rows as $franer_row ) :
					$franer_decoded = json_decode( $franer_row['payload'], true );
					$franer_pretty  = ( null === $franer_decoded )
						? $franer_row['payload']
						: wp_json_encode( $franer_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					?>
					<tr>
						<td><?php echo esc_html( (string) $franer_row['id'] ); ?></td>
						<td><?php echo esc_html( $franer_row['site_title'] ); ?></td>
						<td><?php echo esc_html( $franer_row['user_login'] ); ?></td>
						<td><?php echo esc_html( $franer_row['created_at'] ); ?></td>
						<td><?php echo esc_html( $franer_row['updated_at'] ? $franer_row['updated_at'] : '—' ); ?></td>
						<td>
							<button type="button" class="button button-small franer-view-json"
								data-franer-payload="<?php echo esc_attr( $franer_pretty ); ?>">
								<?php esc_html_e( 'View JSON', 'franer' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<!-- JSON detail modal. -->
	<div id="franer-json-modal" class="franer-modal" role="dialog" aria-modal="true"
		aria-labelledby="franer-modal-title" hidden>
		<div class="franer-modal__backdrop" data-franer-modal-close="1"></div>
		<div class="franer-modal__dialog">
			<div class="franer-modal__header">
				<h2 id="franer-modal-title"><?php esc_html_e( 'Submission payload', 'franer' ); ?></h2>
				<button type="button" class="button-link franer-modal__close" data-franer-modal-close="1"
					aria-label="<?php esc_attr_e( 'Close', 'franer' ); ?>">&times;</button>
			</div>
			<pre id="franer-modal-content" class="franer-modal__content" tabindex="0"></pre>
		</div>
	</div>
</div>
