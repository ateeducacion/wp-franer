<?php
/**
 * Submissions list admin page template.
 *
 * Expects the following variables from Franer_Admin_Submissions::render_submissions_page():
 *
 * @var array                            $site_posts     Array of franer_site WP_Post objects for the filter.
 * @var int                              $selected_site  Currently selected site ID (0 = none).
 * @var string                           $export_url     Nonced JSON export URL (empty when no site selected).
 * @var string                           $export_csv_url Nonced CSV export URL (empty when no site selected).
 * @var Franer_Submissions_List_Table|null $list_table   Prepared list table (null when no site selected).
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
		<?php
		// Overview renderer for this Franer's submissions (only useful when a
		// submission-view template is configured, but always offered: the page
		// itself shows a clear notice when no template exists).
		if ( $selected_site > 0 && '' !== $export_url ) {
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
			<?php
		}
		?>
	</form>

	<?php if ( $selected_site > 0 && '' !== $export_url ) : ?>
		<div class="franer-download-options">
			<strong class="franer-download-options__label"><?php esc_html_e( 'Download submissions:', 'franer' ); ?></strong>
			<a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>">
				<?php esc_html_e( 'Export JSON', 'franer' ); ?>
			</a>
			<a class="button button-secondary" href="<?php echo esc_url( $export_csv_url ); ?>">
				<?php esc_html_e( 'Export CSV', 'franer' ); ?>
			</a>
			<p class="description franer-download-options__hint">
				<?php esc_html_e( 'JSON keeps the full nested data and is best for re-import. CSV has one row per submission with each answer in its own column and opens directly in a spreadsheet (Excel, LibreOffice, Google Sheets).', 'franer' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( 0 === $selected_site || ! $list_table instanceof Franer_Submissions_List_Table ) : ?>
		<p><?php esc_html_e( 'Choose an activity to view its submissions.', 'franer' ); ?></p>
	<?php else : ?>
		<?php // GET form so the search box keeps the page/post_type/site context. ?>
		<form method="get" class="franer-submissions-list-form">
			<input type="hidden" name="post_type" value="franer_site" />
			<input type="hidden" name="page" value="franer-submissions" />
			<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $selected_site ); ?>" />
			<?php
			$list_table->search_box( __( 'Search submissions', 'franer' ), 'franer-submission' );
			$list_table->display();
			?>
		</form>
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
