<?php
/**
 * The admin-specific functionality of the Franer plugin.
 *
 * Registers the admin menus, the franer_site editor metaboxes, the metabox
 * save handler, conditional asset enqueueing, and the Submissions list screen.
 *
 * @package    Franer
 * @subpackage Franer/admin
 * @author     Área de Tecnología Educativa
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Franer_Admin.
 *
 * @package    Franer
 * @subpackage Franer/admin
 */
class Franer_Admin {

	/**
	 * The plugin name / handle prefix.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * The plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Site repository instance.
	 *
	 * @var Franer_Site_Repository
	 */
	private $sites;

	/**
	 * Submissions repository instance.
	 *
	 * @var Franer_Submissions_Repository
	 */
	private $submissions;

	/**
	 * Transient-free notice storage for the current request.
	 *
	 * @var array
	 */
	private $notices = array();

	/**
	 * The Submissions admin screens controller.
	 *
	 * @var Franer_Admin_Submissions
	 */
	private $submissions_admin;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_name The plugin name / handle prefix.
	 * @param string $version     The plugin version.
	 */
	public function __construct( $plugin_name = 'franer', $version = FRANER_VERSION ) {
		$this->plugin_name       = $plugin_name;
		$this->version           = $version;
		$this->sites             = new Franer_Site_Repository();
		$this->submissions       = new Franer_Submissions_Repository();
		$this->submissions_admin = new Franer_Admin_Submissions( $version, $this->sites, $this->submissions );
	}

	/**
	 * Register the Submissions and Help submenu pages.
	 *
	 * The CPT itself appears automatically through show_in_menu; here we only
	 * add the extra pages beneath it.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'edit.php?post_type=franer_site',
			__( 'Franer Submissions', 'franer' ),
			__( 'Submissions', 'franer' ),
			'manage_options',
			'franer-submissions',
			array( $this->submissions_admin, 'render_submissions_page' )
		);

		$help = new Franer_Help();
		add_submenu_page(
			'edit.php?post_type=franer_site',
			__( 'Franer Help', 'franer' ),
			__( 'Help', 'franer' ),
			'manage_options',
			'franer-help',
			array( $help, 'render_help_page' )
		);

		// Admin-only overview renderer for a Franer's submissions, reached from the
		// submissions screen. Registered so its page route exists (capability
		// enforced in the callback), then hidden from the menu since it is opened
		// per-Franer with a site_id. The load hook seeds the page title so the
		// removed-from-menu page does not leave $title null (PHP 8 strip_tags()).
		$view_hook = add_submenu_page(
			'edit.php?post_type=franer_site',
			__( 'Submissions overview', 'franer' ),
			__( 'Submissions overview', 'franer' ),
			'manage_options',
			'franer-submission-view',
			array( $this->submissions_admin, 'render_submission_view_page' )
		);
		remove_submenu_page( 'edit.php?post_type=franer_site', 'franer-submission-view' );
		if ( $view_hook ) {
			add_action( 'load-' . $view_hook, array( $this->submissions_admin, 'set_submission_view_title' ) );
		}
	}

	/**
	 * Save the franer_site metadata.
	 *
	 * Validates the nonce and capabilities, then sanitizes and stores every
	 * field through Franer_Sanitizer. Rejects duplicate slugs.
	 *
	 * @param int     $post_id The post ID being saved.
	 * @param WP_Post $post    The post object being saved.
	 * @return void
	 */
	public function save_meta( $post_id, $post = null ) {
		if ( ! $this->should_save_meta( $post_id, $post ) ) {
			return;
		}

		$this->save_slug_meta( $post_id );
		// The activity HTML is written into post_content during the primary save by
		// inject_raw_html_into_content() (a wp_insert_post_data filter), so a single
		// revision per save captures it. See that method for why.
		$this->save_flag_meta( $post_id );
		$this->save_schedule_meta( $post_id );
		$this->save_prompt_meta( $post_id );
		$this->persist_notices();
	}

	/**
	 * Restrict the Author metabox dropdown to administrators on franer_site.
	 *
	 * The franer_site post type has its own capabilities, all remapped to
	 * manage_options (see Franer_Post_Types::map_franer_site_caps()). No role
	 * literally holds edit_franer_sites, so the core Author dropdown — which queries
	 * for users with that capability — comes up empty. Querying administrators (the
	 * only role allowed to own a Franer) instead makes the box show the current
	 * owner and lets an admin reassign it.
	 *
	 * @param array $query_args  The WP_User_Query arguments.
	 * @param array $parsed_args The wp_dropdown_users() arguments.
	 * @return array The adjusted query arguments.
	 */
	public function restrict_author_dropdown_to_admins( $query_args, $parsed_args ) {
		if ( ! isset( $parsed_args['name'] ) || 'post_author_override' !== $parsed_args['name'] ) {
			return $query_args;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! isset( $screen->post_type ) || 'franer_site' !== $screen->post_type ) {
			return $query_args;
		}

		unset( $query_args['who'] );
		$query_args['capability'] = array( 'manage_options' );

		return $query_args;
	}

	/**
	 * Write the raw activity HTML into post_content during the primary save.
	 *
	 * The franer_site editor keeps its HTML in a custom textarea (franer_html), not
	 * the native editor. Injecting it here — as part of the post's own insert — keeps
	 * the HTML in post_content (so it is revisioned and shown in the revision diff)
	 * while producing a SINGLE revision per save. The previous approach wrote it with
	 * a second wp_update_post() after save_post, which created a duplicate revision on
	 * every save.
	 *
	 * @param array $data    The sanitized post data about to be written.
	 * @param array $postarr The raw post array.
	 * @return array The (possibly modified) post data.
	 */
	public function inject_raw_html_into_content( $data, $postarr ) {
		if ( ! isset( $data['post_type'] ) || 'franer_site' !== $data['post_type'] ) {
			return $data;
		}

		if ( ! isset( $_POST['franer_site_nonce'] ) ) {
			return $data;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['franer_site_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'save_franer_site' ) ) {
			return $data;
		}

		if ( ! isset( $_POST['franer_html'] ) || ! current_user_can( 'manage_options' ) ) {
			return $data;
		}

		// Stored RAW by design (only ever rendered inside a sandboxed iframe). Left
		// slashed because wp_insert_post() unslashes $data right after this filter.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Raw HTML by design; kept slashed for core's post-filter wp_unslash().
		$data['post_content'] = $_POST['franer_html'];

		return $data;
	}

	/**
	 * Whether the current save_post request is a genuine franer_site metabox save.
	 *
	 * Verifies the nonce, skips autosaves/revisions, enforces the capability and
	 * confirms the post is a franer_site. Returns false (so the caller bails) on
	 * any failed check.
	 *
	 * @param int          $post_id The post ID being saved.
	 * @param WP_Post|null $post    The post object being saved (may be null).
	 * @return bool True when the metadata should be saved.
	 */
	private function should_save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['franer_site_nonce'] ) ) {
			return false;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['franer_site_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'save_franer_site' ) ) {
			return false;
		}

		// Skip autosaves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return false;
		}

		// Capability check.
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( null === $post ) {
			$post = get_post( $post_id );
		}

		return $post instanceof WP_Post && 'franer_site' === $post->post_type;
	}

	/**
	 * Sanitize and store the slug, rejecting empty or duplicate values.
	 *
	 * @param int $post_id The post ID being saved.
	 * @return void
	 */
	private function save_slug_meta( $post_id ) {
		$raw_slug       = isset( $_POST['franer_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['franer_slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in should_save_meta().
		$sanitized_slug = Franer_Sanitizer::sanitize_slug( (string) $raw_slug );

		if ( is_wp_error( $sanitized_slug ) ) {
			$this->add_notice( __( 'The slug is invalid. Use only lowercase letters, numbers and hyphens.', 'franer' ) );
		} elseif ( $this->sites->slug_exists( $sanitized_slug, $post_id ) ) {
			$this->add_notice( __( 'That slug is already in use by another activity. Please choose a different one.', 'franer' ) );
		} else {
			update_post_meta( $post_id, '_franer_slug', $sanitized_slug );
		}
	}

	/**
	 * Store the boolean flags, allowed roles and the maximum payload size.
	 *
	 * @param int $post_id The post ID being saved.
	 * @return void
	 */
	private function save_flag_meta( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in should_save_meta().
		// Booleans. Visibility is the post status (publish = visible), not a meta field.
		update_post_meta( $post_id, '_franer_accepts_submissions', Franer_Sanitizer::sanitize_bool( isset( $_POST['franer_accepts_submissions'] ) ) ? '1' : '' );
		update_post_meta( $post_id, '_franer_allow_multiple_submissions', Franer_Sanitizer::sanitize_bool( isset( $_POST['franer_allow_multiple_submissions'] ) ) ? '1' : '' );
		update_post_meta( $post_id, '_franer_allow_overwrite', Franer_Sanitizer::sanitize_bool( isset( $_POST['franer_allow_overwrite'] ) ) ? '1' : '' );

		// Allowed roles.
		$raw_roles = isset( $_POST['franer_allowed_roles'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['franer_allowed_roles'] ) ) : array();
		$roles     = Franer_Sanitizer::sanitize_roles( $raw_roles );
		update_post_meta( $post_id, '_franer_allowed_roles', $roles );

		// Max payload size (KB).
		$raw_size = isset( $_POST['franer_max_payload_size'] ) ? absint( wp_unslash( $_POST['franer_max_payload_size'] ) ) : 256;
		update_post_meta( $post_id, '_franer_max_payload_size', Franer_Sanitizer::sanitize_payload_size( $raw_size ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Store the enabled master switch and the optional availability window.
	 *
	 * @param int $post_id The post ID being saved.
	 * @return void
	 */
	private function save_schedule_meta( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in should_save_meta().
		// Enabled master switch (stored as '1'/'0' so an unset legacy value can default to enabled).
		update_post_meta( $post_id, '_franer_enabled', isset( $_POST['franer_enabled'] ) ? '1' : '0' );

		// Availability window (optional start/end date-times).
		$raw_start = isset( $_POST['franer_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['franer_start_date'] ) ) : '';
		update_post_meta( $post_id, '_franer_start_date', Franer_Sanitizer::sanitize_datetime( $raw_start ) );

		$raw_end = isset( $_POST['franer_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['franer_end_date'] ) ) : '';
		update_post_meta( $post_id, '_franer_end_date', Franer_Sanitizer::sanitize_datetime( $raw_end ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Store the optional generation prompts and submission-view template HTML.
	 *
	 * These are admin-only meta kept verbatim (normalized + size-capped, never
	 * KSES'd) because they may contain Markdown, HTML or code snippets.
	 *
	 * @param int $post_id The post ID being saved.
	 * @return void
	 */
	private function save_prompt_meta( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in should_save_meta().
		// Optional generation prompt. Stored as free-form text: it may contain
		// Markdown, HTML or code snippets, so it is normalized and size-capped (not
		// run through KSES/sanitize_text_field, which would strip angle brackets).
		if ( isset( $_POST['franer_generation_prompt'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Normalized and size-capped by sanitize_generation_prompt(); stored raw by design and only ever shown via esc_textarea(); nonce verified in should_save_meta().
			$raw_prompt = wp_unslash( $_POST['franer_generation_prompt'] );
			update_post_meta( $post_id, '_franer_generation_prompt', Franer_Sanitizer::sanitize_generation_prompt( $raw_prompt ) );
		}

		// Optional submission-view template HTML. Like the activity HTML it is raw,
		// admin-provided source rendered only inside a sandboxed iframe, so it is
		// stored verbatim (line endings normalized) and never sanitized destructively.
		if ( isset( $_POST['franer_view_html'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Raw HTML stored by design; only ever rendered inside a sandboxed iframe (comment-stripped) and escaped on output; nonce verified in should_save_meta().
			$raw_view_html = wp_unslash( $_POST['franer_view_html'] );
			update_post_meta( $post_id, '_franer_view_html', Franer_Sanitizer::sanitize_view_html( $raw_view_html ) );
		}

		// Optional prompt used to generate the submission-view template.
		if ( isset( $_POST['franer_view_generation_prompt'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Normalized and size-capped by sanitize_generation_prompt(); stored raw by design and only ever shown via esc_textarea(); nonce verified in should_save_meta().
			$raw_view_prompt = wp_unslash( $_POST['franer_view_generation_prompt'] );
			update_post_meta( $post_id, '_franer_view_generation_prompt', Franer_Sanitizer::sanitize_generation_prompt( $raw_view_prompt ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Persist any queued admin notices for display after the save redirect.
	 *
	 * @return void
	 */
	private function persist_notices() {
		if ( ! empty( $this->notices ) ) {
			set_transient( 'franer_admin_notices_' . get_current_user_id(), $this->notices, 60 );
		}
	}

	/**
	 * Queue an admin error notice.
	 *
	 * @param string $message The message to display.
	 * @return void
	 */
	private function add_notice( $message ) {
		$this->notices[] = $message;
	}

	/**
	 * Display queued admin notices (registered on 'admin_notices').
	 *
	 * @return void
	 */
	public function display_notices() {
		$key     = 'franer_admin_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $message ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	}

	/**
	 * Enqueue admin assets only on franer_site screens.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$is_cpt_screen = $screen && isset( $screen->post_type ) && 'franer_site' === $screen->post_type;
		$is_franer_page = isset( $_GET['page'] ) && in_array(
			sanitize_text_field( wp_unslash( $_GET['page'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
			array( 'franer-submissions', 'franer-help', 'franer-submission-view' ),
			true
		);

		if ( ! $is_cpt_screen && ! $is_franer_page ) {
			return;
		}

		wp_enqueue_style(
			'franer-admin',
			plugin_dir_url( __FILE__ ) . 'css/franer-admin.css',
			array(),
			$this->version,
			'all'
		);

		$deps = array( 'jquery' );

		// Initialize the WordPress code editor (CodeMirror) for the HTML textarea.
		$editor_settings = false;
		if ( $is_cpt_screen && function_exists( 'wp_enqueue_code_editor' ) ) {
			$editor_settings = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
			if ( false !== $editor_settings ) {
				$deps[] = 'wp-theme-plugin-editor';
				$deps[] = 'wp-codemirror';
			}
		}

		wp_enqueue_script(
			'franer-admin',
			plugin_dir_url( __FILE__ ) . 'js/franer-admin.js',
			$deps,
			$this->version,
			true
		);

		wp_localize_script(
			'franer-admin',
			'FranerAdmin',
			array(
				'editorSettings' => $editor_settings,
				'textareaId'     => 'franer_html',
				'messages'       => array(
					'copied'          => __( 'Copied to clipboard.', 'franer' ),
					'copyError'       => __( 'Could not copy. Please copy manually.', 'franer' ),
					'dropConfirm'     => __( 'Replace the current content with the dropped file?', 'franer' ),
					'dropInvalidType' => __( 'Please drop an .html file.', 'franer' ),
					'dropReadError'   => __( 'The file could not be read.', 'franer' ),
					'invalidJson'     => __( 'Could not read this submission.', 'franer' ),
					'noComment'       => __( 'No comment', 'franer' ),
					'outdated'        => __( 'Answered against an earlier version of the form.', 'franer' ),
					'deleteConfirm'   => __( 'Delete this submission? This cannot be undone.', 'franer' ),
				),
			)
		);
	}

	/**
	 * Delete a franer_site's submissions when the site is permanently deleted.
	 *
	 * Hooked on before_delete_post (which fires for permanent deletes, not for
	 * trashing, so submissions survive a reversible trash). Without this, deleting
	 * a site would orphan its submission rows forever and a recycled post ID could
	 * surface another activity's submissions.
	 *
	 * @param int          $post_id The post being deleted.
	 * @param WP_Post|null $post    The post object being deleted (WordPress 5.5+).
	 * @return void
	 */
	public function purge_site_submissions( $post_id, $post = null ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post instanceof WP_Post || 'franer_site' !== $post->post_type ) {
			return;
		}

		$this->submissions->delete_site_submissions( (int) $post_id );
	}

	/**
	 * Add Author and Submissions columns to the franer_site list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array The filtered columns.
	 */
	public function add_list_columns( $columns ) {
		// The "Author" column is provided natively by the CPT's 'author' support,
		// so we only add the Submissions count here (before the Date column).
		$new = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$new['franer_submissions'] = __( 'Submissions', 'franer' );
			}
			$new[ $key ] = $label;
		}

		if ( ! isset( $new['franer_submissions'] ) ) {
			$new['franer_submissions'] = __( 'Submissions', 'franer' );
		}

		return $new;
	}

	/**
	 * Render the custom franer_site list columns.
	 *
	 * @param string $column  The column key.
	 * @param int    $post_id The post ID for the row.
	 * @return void
	 */
	public function render_list_column( $column, $post_id ) {
		if ( 'franer_submissions' === $column ) {
			$count = $this->submissions->count_site_submissions( (int) $post_id );
			$url   = add_query_arg(
				array(
					'post_type' => 'franer_site',
					'page'      => 'franer-submissions',
					'site_id'   => (int) $post_id,
				),
				admin_url( 'edit.php' )
			);
			printf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				esc_html( number_format_i18n( $count ) )
			);
		}
	}

	/**
	 * Add a "Visit Franer" row action linking to the public activity URL.
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post    The post for the row.
	 * @return array The filtered row actions.
	 */
	public function add_row_actions( $actions, $post ) {
		if ( $post instanceof WP_Post && 'franer_site' === $post->post_type ) {
			$slug = get_post_meta( $post->ID, '_franer_slug', true );
			if ( '' !== (string) $slug ) {
				$actions['franer_visit'] = sprintf(
					'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					esc_url( $this->sites->get_public_url( $post ) ),
					esc_html__( 'Visit Franer', 'franer' )
				);
			}
		}

		return $actions;
	}

	/**
	 * Register the sortable franer_site list columns.
	 *
	 * @param array $columns Sortable columns map.
	 * @return array The filtered map.
	 */
	public function add_sortable_columns( $columns ) {
		$columns['author']             = 'author';
		$columns['franer_submissions'] = 'franer_submissions';

		return $columns;
	}

	/**
	 * Sort the franer_site list by submission count when requested.
	 *
	 * Joins an aggregated count of the submissions table so the custom
	 * "Submissions" column becomes sortable.
	 *
	 * @param array    $clauses The SQL clauses.
	 * @param WP_Query $query   The current query.
	 * @return array The filtered clauses.
	 */
	public function sort_by_submissions_clauses( $clauses, $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return $clauses;
		}
		if ( 'franer_site' !== $query->get( 'post_type' ) || 'franer_submissions' !== $query->get( 'orderby' ) ) {
			return $clauses;
		}

		global $wpdb;
		$table = Franer_Submissions_Repository::get_table_name();
		$order = ( 'asc' === strtolower( (string) $query->get( 'order' ) ) ) ? 'ASC' : 'DESC';

		// $table comes from get_table_name() (prefixed, not user input).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clauses['join']   .= " LEFT JOIN ( SELECT site_id, COUNT(*) AS franer_cnt FROM {$table} GROUP BY site_id ) franer_counts ON franer_counts.site_id = {$wpdb->posts}.ID";
		$clauses['orderby'] = 'COALESCE( franer_counts.franer_cnt, 0 ) ' . $order;
		$clauses['groupby'] = "{$wpdb->posts}.ID";

		return $clauses;
	}

	/**
	 * Render the franer_site list-table filters (status and author).
	 *
	 * @param string $post_type The current list post type.
	 * @return void
	 */
	public function add_list_filters( $post_type ) {
		if ( 'franer_site' !== $post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$current = isset( $_GET['franer_enabled'] ) ? sanitize_key( wp_unslash( $_GET['franer_enabled'] ) ) : '';
		?>
		<label for="franer-enabled-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'franer' ); ?></label>
		<select name="franer_enabled" id="franer-enabled-filter">
			<option value=""><?php esc_html_e( 'All statuses', 'franer' ); ?></option>
			<option value="enabled" <?php selected( $current, 'enabled' ); ?>><?php esc_html_e( 'Enabled', 'franer' ); ?></option>
			<option value="disabled" <?php selected( $current, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'franer' ); ?></option>
		</select>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$author = isset( $_GET['author'] ) ? absint( wp_unslash( $_GET['author'] ) ) : 0;
		wp_dropdown_users(
			array(
				'name'            => 'author',
				'show_option_all' => __( 'All authors', 'franer' ),
				'selected'        => $author,
				'capability'      => 'edit_posts',
			)
		);
	}

	/**
	 * Apply the franer_site list filters to the main admin query.
	 *
	 * @param WP_Query $query The current query.
	 * @return void
	 */
	public function filter_list_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'franer_site' !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$enabled = isset( $_GET['franer_enabled'] ) ? sanitize_key( wp_unslash( $_GET['franer_enabled'] ) ) : '';

		if ( 'disabled' === $enabled ) {
			$query->set(
				'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					array(
						'key'   => '_franer_enabled',
						'value' => '0',
					),
				)
			);
		} elseif ( 'enabled' === $enabled ) {
			// Enabled = the flag is not '0' (unset legacy meta also counts as enabled).
			$query->set(
				'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'relation' => 'OR',
					array(
						'key'     => '_franer_enabled',
						'value'   => '0',
						'compare' => '!=',
					),
					array(
						'key'     => '_franer_enabled',
						'compare' => 'NOT EXISTS',
					),
				)
			);
		}
	}
}
