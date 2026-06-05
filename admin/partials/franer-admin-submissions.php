<?php
/**
 * Submissions list admin page template.
 *
 * Expects the following variables from Franer_Admin_Submissions::render_submissions_page():
 *
 * @var array                              $site_posts      Array of franer_site WP_Post objects for the filter.
 * @var int                                $selected_site   Currently selected site ID (0 = none).
 * @var string                             $export_url      Nonced JSON export URL (empty when no site selected).
 * @var string                             $export_csv_url  Nonced CSV export URL (empty when no site selected).
 * @var Franer_Submissions_List_Table|null $list_table      Prepared list table (null when no site selected).
 * @var array|null                         $summary         Auto-summary (Franer_Submission_Schema::build_summary()) or null.
 * @var array                              $fields          Inferred field schema for the activity.
 * @var bool                               $has_custom_view Whether a custom _franer_view_html template exists.
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
			<span class="franer-filterbar__actions">
				<?php
				// Overview renderer for a custom submission-view template, if one exists.
				if ( $has_custom_view ) {
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
						<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
						<?php esc_html_e( 'View overview', 'franer' ); ?>
					</a>
					<?php
				}
				?>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>">
					<span class="dashicons dashicons-download" aria-hidden="true"></span>
					<?php esc_html_e( 'Export JSON', 'franer' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $export_csv_url ); ?>">
					<span class="dashicons dashicons-download" aria-hidden="true"></span>
					<?php esc_html_e( 'Export CSV', 'franer' ); ?>
				</a>
			</span>
		<?php endif; ?>
	</form>

	<?php if ( 0 === $selected_site || ! $list_table instanceof Franer_Submissions_List_Table ) : ?>
		<?php
		// Empty state with purpose: explain what appears and offer a direct action.
		require plugin_dir_path( __FILE__ ) . 'franer-admin-submissions-empty.php';
		?>
	<?php else : ?>
		<?php
		// Stats strip + auto-summary, shown FIRST (above the table). The summary is
		// generated from the answers themselves, so it needs no custom template.
		if ( is_array( $summary ) ) {
			$franer_summary    = $summary;
			$franer_show_panel = ! $has_custom_view;
			require plugin_dir_path( __FILE__ ) . 'franer-admin-submissions-summary.php';
		}
		?>

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

	<?php
	// Field schema for the detail drawer's readable (question → answer) view.
	$franer_fields_for_js = array();
	foreach ( (array) $fields as $franer_field_key => $franer_field ) {
		$franer_fields_for_js[ $franer_field_key ] = array(
			'label' => $franer_field['label'],
			'type'  => $franer_field['type'],
		);
	}
	?>
	<script type="application/json" id="franer-fields-schema"><?php echo wp_json_encode( $franer_fields_for_js ); ?></script>

	<?php // Standalone delete form, kept outside the list's GET form (no nested forms). ?>
	<form id="franer-delete-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="franer-hidden-form">
		<input type="hidden" name="action" value="franer_delete_submission" />
		<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $selected_site ); ?>" />
		<input type="hidden" name="submission_id" id="franer-delete-id" value="" />
		<input type="hidden" name="_wpnonce" id="franer-delete-nonce" value="" />
	</form>

	<!-- Submission detail drawer (readable view + editable JSON). -->
	<div id="franer-json-modal" class="franer-drawer" role="dialog" aria-modal="true"
		aria-labelledby="franer-drawer-title" hidden>
		<div class="franer-drawer__backdrop" data-franer-modal-close="1"></div>
		<aside class="franer-drawer__panel">
			<div class="franer-drawer__head">
				<div>
					<div class="franer-drawer__eyebrow" id="franer-drawer-eyebrow"></div>
					<h2 id="franer-drawer-title"><?php esc_html_e( 'Submission detail', 'franer' ); ?></h2>
				</div>
				<button type="button" class="button-link franer-drawer__x franer-modal__close" data-franer-modal-close="1"
					aria-label="<?php esc_attr_e( 'Close', 'franer' ); ?>">&times;</button>
			</div>

			<div class="franer-drawer__toolbar">
				<div class="franer-seg" role="tablist">
					<button type="button" class="franer-seg__btn is-on" data-franer-drawer-tab="summary"><?php esc_html_e( 'Summary', 'franer' ); ?></button>
					<button type="button" class="franer-seg__btn" data-franer-drawer-tab="json"><?php esc_html_e( 'JSON', 'franer' ); ?></button>
				</div>
			</div>

			<div class="franer-drawer__body">
				<div data-franer-drawer-panel="summary">
					<div class="franer-qalist" id="franer-drawer-readable"></div>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					data-franer-drawer-panel="json" hidden>
					<input type="hidden" name="action" value="franer_update_submission" />
					<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $selected_site ); ?>" />
					<input type="hidden" name="submission_id" id="franer-edit-id" value="" />
					<input type="hidden" name="_wpnonce" id="franer-edit-nonce" value="" />
					<label for="franer-modal-content" class="screen-reader-text"><?php esc_html_e( 'Submission JSON payload', 'franer' ); ?></label>
					<textarea name="payload" id="franer-modal-content" class="franer-drawer__json code" rows="18" spellcheck="false"></textarea>
					<p class="description"><?php esc_html_e( 'Edit the JSON payload (must be a valid JSON object), then save.', 'franer' ); ?></p>
					<p class="franer-drawer__json-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'franer' ); ?></button>
					</p>
				</form>
			</div>

			<div class="franer-drawer__foot">
				<div class="franer-drawer__nav">
					<button type="button" class="button button-small" data-franer-drawer-prev title="<?php esc_attr_e( 'Previous', 'franer' ); ?>">‹</button>
					<button type="button" class="button button-small" data-franer-drawer-next title="<?php esc_attr_e( 'Next', 'franer' ); ?>">›</button>
				</div>
				<button type="button" class="button franer-modal__close"><?php esc_html_e( 'Close', 'franer' ); ?></button>
			</div>
		</aside>
	</div>
</div>
