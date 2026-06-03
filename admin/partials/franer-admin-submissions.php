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
 * @var int    $total         Total submissions for the selected site.
 * @var int    $per_page      Rows shown per page.
 * @var int    $paged         Current page number (1-based).
 * @var int    $total_pages   Total number of pages.
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

	<?php
	// Read-only status message from a delete/edit redirect (no nonce needed to display it).
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$franer_msg = isset( $_GET['franer_msg'] ) ? sanitize_key( wp_unslash( $_GET['franer_msg'] ) ) : '';
	if ( 'deleted' === $franer_msg ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Submission deleted.', 'franer' ) . '</p></div>';
	} elseif ( 'updated' === $franer_msg ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Submission updated.', 'franer' ) . '</p></div>';
	} elseif ( 'invalid' === $franer_msg ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The submitted JSON is not a valid object. No changes were saved.', 'franer' ) . '</p></div>';
	}
	?>

	<?php // Always target edit.php so the filter works regardless of how this page was reached. ?>
	<form method="get" class="franer-submissions-filter" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
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
			<a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>">
				<?php esc_html_e( 'Export all submissions (JSON)', 'franer' ); ?>
			</a>
			<?php
			// Overview renderer for this Franer's submissions (only useful when a
			// submission-view template is configured, but always offered: the page
			// itself shows a clear notice when no template exists).
			$franer_overview_url = add_query_arg(
				array(
					'post_type' => 'franer_site',
					'page'      => 'franer-submission-view',
					'site_id'   => (int) $selected_site,
				),
				admin_url( 'edit.php' )
			);
			?>
			<a class="button" href="<?php echo esc_url( $franer_overview_url ); ?>">
				<?php esc_html_e( 'View overview', 'franer' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( 0 === $selected_site ) : ?>
		<p><?php esc_html_e( 'Choose an activity to view its submissions.', 'franer' ); ?></p>
	<?php elseif ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'No submissions found for this activity yet.', 'franer' ); ?></p>
	<?php else : ?>
		<div class="tablenav top">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: number of submissions. */
						esc_html( _n( '%s submission', '%s submissions', (int) $total, 'franer' ) ),
						esc_html( number_format_i18n( (int) $total ) )
					);
					?>
				</span>
				<?php
				if ( (int) $total_pages > 1 ) {
					$franer_pager_base = add_query_arg(
						array(
							'post_type' => 'franer_site',
							'page'      => 'franer-submissions',
							'site_id'   => (int) $selected_site,
							'paged'     => '%#%',
						),
						admin_url( 'edit.php' )
					);
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => $franer_pager_base,
								'format'    => '',
								'prev_text' => __( '&laquo;', 'franer' ),
								'next_text' => __( '&raquo;', 'franer' ),
								'total'     => (int) $total_pages,
								'current'   => (int) $paged,
							)
						)
					);
				}
				?>
			</div>
		</div>
		<table class="widefat striped franer-submissions-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'ID', 'franer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Activity', 'franer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'User', 'franer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Created', 'franer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Updated', 'franer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'franer' ); ?></th>
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
								data-franer-payload="<?php echo esc_attr( $franer_pretty ); ?>"
								data-franer-id="<?php echo esc_attr( (string) $franer_row['id'] ); ?>"
								data-franer-nonce="<?php echo esc_attr( wp_create_nonce( 'franer_update_submission_' . (int) $franer_row['id'] ) ); ?>">
								<?php esc_html_e( 'View / edit', 'franer' ); ?>
							</button>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
								class="franer-inline-form"
								onsubmit="return confirm( '<?php echo esc_js( __( 'Delete this submission? This cannot be undone.', 'franer' ) ); ?>' );">
								<input type="hidden" name="action" value="franer_delete_submission" />
								<input type="hidden" name="submission_id" value="<?php echo esc_attr( (string) $franer_row['id'] ); ?>" />
								<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $selected_site ); ?>" />
								<?php wp_nonce_field( 'franer_delete_submission_' . (int) $franer_row['id'] ); ?>
								<button type="submit" class="button button-small button-link-delete">
									<?php esc_html_e( 'Delete', 'franer' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<!-- JSON view / edit modal. -->
	<div id="franer-json-modal" class="franer-modal" role="dialog" aria-modal="true"
		aria-labelledby="franer-modal-title" hidden>
		<div class="franer-modal__backdrop" data-franer-modal-close="1"></div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="franer-modal__dialog">
			<div class="franer-modal__header">
				<h2 id="franer-modal-title"><?php esc_html_e( 'Edit submission payload', 'franer' ); ?></h2>
				<button type="button" class="button-link franer-modal__close" data-franer-modal-close="1"
					aria-label="<?php esc_attr_e( 'Close', 'franer' ); ?>">&times;</button>
			</div>
			<input type="hidden" name="action" value="franer_update_submission" />
			<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $selected_site ); ?>" />
			<input type="hidden" name="submission_id" id="franer-edit-id" value="" />
			<input type="hidden" name="_wpnonce" id="franer-edit-nonce" value="" />
			<label for="franer-modal-content" class="screen-reader-text"><?php esc_html_e( 'Submission JSON payload', 'franer' ); ?></label>
			<textarea name="payload" id="franer-modal-content" class="franer-modal__content code" rows="18" spellcheck="false"></textarea>
			<p class="description"><?php esc_html_e( 'Edit the JSON payload (must be a valid JSON object), then save.', 'franer' ); ?></p>
			<p class="franer-modal__actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'franer' ); ?></button>
				<button type="button" class="button franer-modal__close"><?php esc_html_e( 'Cancel', 'franer' ); ?></button>
			</p>
		</form>
	</div>
</div>
