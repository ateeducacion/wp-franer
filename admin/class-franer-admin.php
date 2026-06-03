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
	 * Constructor.
	 *
	 * @param string $plugin_name The plugin name / handle prefix.
	 * @param string $version     The plugin version.
	 */
	public function __construct( $plugin_name = 'franer', $version = FRANER_VERSION ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->sites       = new Franer_Site_Repository();
		$this->submissions = new Franer_Submissions_Repository();
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
			array( $this, 'render_submissions_page' )
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
			array( $this, 'render_submission_view_page' )
		);
		remove_submenu_page( 'edit.php?post_type=franer_site', 'franer-submission-view' );
		if ( $view_hook ) {
			add_action( 'load-' . $view_hook, array( $this, 'set_submission_view_title' ) );
		}
	}

	/**
	 * Seed the page title for the hidden submissions-overview page.
	 *
	 * The page is removed from the submenu, so WordPress cannot resolve its title
	 * from the menu and would pass null to strip_tags() in admin-header.php. Setting
	 * the global title before the header renders avoids that PHP 8 deprecation (and
	 * the "headers already sent" cascade it triggers when WP_DEBUG_DISPLAY is on).
	 *
	 * @return void
	 */
	public function set_submission_view_title() {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentionally seeds the page title for a menu-hidden admin page so admin-header.php does not strip_tags(null).
		$GLOBALS['title'] = __( 'Submissions overview', 'franer' );
	}

	/**
	 * Register the five metaboxes on the franer_site editor.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'franer_site_settings',
			__( 'Site settings', 'franer' ),
			array( $this, 'render_settings_metabox' ),
			'franer_site',
			'normal',
			'high'
		);

		add_meta_box(
			'franer_site_html',
			__( 'HTML source', 'franer' ),
			array( $this, 'render_html_metabox' ),
			'franer_site',
			'normal',
			'high'
		);

		add_meta_box(
			'franer_site_public_url',
			__( 'Public URL', 'franer' ),
			array( $this, 'render_public_url_metabox' ),
			'franer_site',
			'side',
			'default'
		);

		add_meta_box(
			'franer_site_submissions',
			__( 'Submissions', 'franer' ),
			array( $this, 'render_submissions_metabox' ),
			'franer_site',
			'side',
			'default'
		);

		add_meta_box(
			'franer_site_help',
			__( 'Help', 'franer' ),
			array( $this, 'render_help_metabox' ),
			'franer_site',
			'side',
			'low'
		);
	}

	/**
	 * Render the Site settings metabox.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public function render_settings_metabox( $post ) {
		$settings  = $this->sites->get_settings( $post->ID );
		$all_roles = wp_roles()->roles;
		wp_nonce_field( 'save_franer_site', 'franer_site_nonce' );
		require plugin_dir_path( __FILE__ ) . 'partials/franer-admin-metaboxes.php';
		franer_render_settings_metabox( $settings, $all_roles );
	}

	/**
	 * Render the HTML source metabox.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public function render_html_metabox( $post ) {
		$settings = $this->sites->get_settings( $post->ID );
		require_once plugin_dir_path( __FILE__ ) . 'partials/franer-admin-metaboxes.php';
		franer_render_html_metabox( $settings );
	}

	/**
	 * Render the Public URL metabox.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public function render_public_url_metabox( $post ) {
		$settings   = $this->sites->get_settings( $post->ID );
		$public_url = $this->sites->get_public_url( $post );
		require_once plugin_dir_path( __FILE__ ) . 'partials/franer-admin-metaboxes.php';
		franer_render_public_url_metabox( $settings, $public_url );
	}

	/**
	 * Render the per-site Submissions summary metabox.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public function render_submissions_metabox( $post ) {
		$count        = $this->submissions->count_site_submissions( $post->ID );
		$list_url     = add_query_arg(
			array(
				'post_type' => 'franer_site',
				'page'      => 'franer-submissions',
				'site_id'   => $post->ID,
			),
			admin_url( 'edit.php' )
		);
		$export_url   = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'franer_export',
					'site_id' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'franer_export_' . $post->ID
		);
		$overview_url = add_query_arg(
			array(
				'post_type' => 'franer_site',
				'page'      => 'franer-submission-view',
				'site_id'   => $post->ID,
			),
			admin_url( 'edit.php' )
		);
		require_once plugin_dir_path( __FILE__ ) . 'partials/franer-admin-metaboxes.php';
		franer_render_submissions_metabox( $count, $list_url, $export_url, $overview_url );
	}

	/**
	 * Render the Help metabox.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public function render_help_metabox( $post ) {
		$help_url = add_query_arg(
			array(
				'post_type' => 'franer_site',
				'page'      => 'franer-help',
			),
			admin_url( 'edit.php' )
		);
		require_once plugin_dir_path( __FILE__ ) . 'partials/franer-admin-metaboxes.php';
		franer_render_help_metabox( $help_url );
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
		// Verify the nonce.
		if ( ! isset( $_POST['franer_site_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['franer_site_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'save_franer_site' ) ) {
			return;
		}

		// Skip autosaves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Capability check.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( null === $post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post instanceof WP_Post || 'franer_site' !== $post->post_type ) {
			return;
		}

		// Slug: sanitize and reject empty or duplicate values.
		$raw_slug       = isset( $_POST['franer_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['franer_slug'] ) ) : '';
		$sanitized_slug = Franer_Sanitizer::sanitize_slug( (string) $raw_slug );

		if ( is_wp_error( $sanitized_slug ) ) {
			$this->add_notice( __( 'The slug is invalid. Use only lowercase letters, numbers and hyphens.', 'franer' ) );
		} elseif ( $this->sites->slug_exists( $sanitized_slug, $post_id ) ) {
			$this->add_notice( __( 'That slug is already in use by another activity. Please choose a different one.', 'franer' ) );
		} else {
			update_post_meta( $post_id, '_franer_slug', $sanitized_slug );
		}

		// HTML source: stored RAW in post_content (so it is revisioned and shown in
		// the revision diff). It only ever renders inside a sandboxed iframe.
		if ( isset( $_POST['franer_html'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HTML is stored raw by design and only rendered inside a sandboxed iframe.
			$html = wp_unslash( $_POST['franer_html'] );

			// Avoid recursion: writing post_content fires save_post again.
			remove_action( 'save_post_franer_site', array( $this, 'save_meta' ), 10 );
			Franer_Site_Repository::set_raw_html( $post_id, $html );
			add_action( 'save_post_franer_site', array( $this, 'save_meta' ), 10, 2 );
		}

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

		// Enabled master switch (stored as '1'/'0' so an unset legacy value can default to enabled).
		update_post_meta( $post_id, '_franer_enabled', isset( $_POST['franer_enabled'] ) ? '1' : '0' );

		// Availability window (optional start/end date-times).
		$raw_start = isset( $_POST['franer_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['franer_start_date'] ) ) : '';
		update_post_meta( $post_id, '_franer_start_date', Franer_Sanitizer::sanitize_datetime( $raw_start ) );

		$raw_end = isset( $_POST['franer_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['franer_end_date'] ) ) : '';
		update_post_meta( $post_id, '_franer_end_date', Franer_Sanitizer::sanitize_datetime( $raw_end ) );

		// Optional generation prompt. Stored as free-form text: it may contain
		// Markdown, HTML or code snippets, so it is normalized and size-capped (not
		// run through KSES/sanitize_text_field, which would strip angle brackets).
		if ( isset( $_POST['franer_generation_prompt'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized and size-capped by sanitize_generation_prompt(); stored raw by design and only ever shown via esc_textarea().
			$raw_prompt = wp_unslash( $_POST['franer_generation_prompt'] );
			update_post_meta( $post_id, '_franer_generation_prompt', Franer_Sanitizer::sanitize_generation_prompt( $raw_prompt ) );
		}

		// Optional submission-view template HTML. Like the activity HTML it is raw,
		// admin-provided source rendered only inside a sandboxed iframe, so it is
		// stored verbatim (line endings normalized) and never sanitized destructively.
		if ( isset( $_POST['franer_view_html'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw HTML stored by design; only ever rendered inside a sandboxed iframe (comment-stripped) and escaped on output.
			$raw_view_html = wp_unslash( $_POST['franer_view_html'] );
			update_post_meta( $post_id, '_franer_view_html', Franer_Sanitizer::sanitize_view_html( $raw_view_html ) );
		}

		// Optional prompt used to generate the submission-view template.
		if ( isset( $_POST['franer_view_generation_prompt'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized and size-capped by sanitize_generation_prompt(); stored raw by design and only ever shown via esc_textarea().
			$raw_view_prompt = wp_unslash( $_POST['franer_view_generation_prompt'] );
			update_post_meta( $post_id, '_franer_view_generation_prompt', Franer_Sanitizer::sanitize_generation_prompt( $raw_view_prompt ) );
		}

		// Persist any notices for display on redirect.
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
					'copied'    => __( 'Copied to clipboard.', 'franer' ),
					'copyError' => __( 'Could not copy. Please copy manually.', 'franer' ),
				),
			)
		);
	}

	/**
	 * Render the standalone Submissions admin page.
	 *
	 * Lists submissions (optionally filtered by site) with a JSON detail modal
	 * and an export link.
	 *
	 * @return void
	 */
	public function render_submissions_page() {
		if ( ! Franer_Permissions::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'franer' ) );
		}

		$selected_site = isset( $_GET['site_id'] ) ? absint( wp_unslash( $_GET['site_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$paged         = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pager.

		// Build the list of available sites for the filter dropdown.
		$site_posts = get_posts(
			array(
				'post_type'      => 'franer_site',
				'post_status'    => 'any',
				'numberposts'    => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'all',
				'suppress_filters' => false,
			)
		);

		$rows        = array();
		$export_url  = '';
		$per_page    = 50;
		$total       = 0;
		$total_pages = 0;
		if ( $selected_site > 0 ) {
			$total       = $this->submissions->count_site_submissions( $selected_site );
			$total_pages = (int) ceil( $total / $per_page );
			// Clamp the requested page to the available range.
			if ( $total_pages > 0 && $paged > $total_pages ) {
				$paged = $total_pages;
			}
			$offset   = ( $paged - 1 ) * $per_page;
			$raw_rows = $this->submissions->get_site_submissions( $selected_site, $per_page, $offset );
			foreach ( $raw_rows as $row ) {
				$user      = get_userdata( (int) $row['user_id'] );
				$site_post = get_post( (int) $row['site_id'] );
				$rows[]    = array(
					'id'         => (int) $row['id'],
					'site_id'    => (int) $row['site_id'],
					'site_title' => $site_post ? $site_post->post_title : '',
					'user_id'    => (int) $row['user_id'],
					'user_login' => $user ? $user->user_login : ( '#' . (int) $row['user_id'] ),
					'created_at' => isset( $row['created_at'] ) ? $row['created_at'] : '',
					'updated_at' => isset( $row['updated_at'] ) ? $row['updated_at'] : '',
					'payload'    => isset( $row['payload_json'] ) ? $row['payload_json'] : '{}',
				);
			}
			$export_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'franer_export',
						'site_id' => $selected_site,
					),
					admin_url( 'admin-post.php' )
				),
				'franer_export_' . $selected_site
			);
		}

		require plugin_dir_path( __FILE__ ) . 'partials/franer-admin-submissions.php';
	}

	/**
	 * Maximum number of submissions delivered to a submission-view template.
	 *
	 * Bounds the JSON localized into the overview page. When a Franer has more
	 * submissions, the newest ones are sent and the template is told it was
	 * truncated, so it can say so instead of silently under-reporting.
	 *
	 * @var int
	 */
	const VIEW_MAX_SUBMISSIONS = 2000;

	/**
	 * Render the admin-only submissions-overview page for one Franer.
	 *
	 * Resolves the Franer site and its optional `_franer_view_html` template and
	 * renders that template inside a sandboxed iframe. ALL of the Franer's
	 * submissions (decoded, capped at VIEW_MAX_SUBMISSIONS) are delivered to the
	 * iframe by the parent page via postMessage (see
	 * admin/js/franer-submission-view.js), so the template can build an aggregate
	 * report (totals, charts, …). The data is never interpolated into the iframe
	 * markup, and the iframe never receives a REST nonce or any privileged URL.
	 * When no template is configured, a notice is shown instead.
	 *
	 * @return void
	 */
	public function render_submission_view_page() {
		if ( ! Franer_Permissions::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'franer' ) );
		}

		$site_id = isset( $_GET['site_id'] ) ? absint( wp_unslash( $_GET['site_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin view keyed by site ID; capability checked above.

		$data = $this->prepare_submission_view( $site_id );

		// Extract the prepared values into the template scope.
		$back_url     = $data['back_url'];
		$error        = $data['error'];
		$has_template = $data['has_template'];
		$view_html    = $data['view_html'];
		$site_title   = $data['site_title'];
		$count        = $data['count'];
		$truncated    = $data['truncated'];
		$pretty_json  = $data['pretty_json'];
		$context      = $data['context'];

		if ( $has_template ) {
			// Deliver the decoded submissions to the sandboxed iframe via postMessage
			// after it loads. No nonce or privileged URL is ever exposed to the frame.
			wp_register_script(
				'franer-submission-view',
				plugin_dir_url( __FILE__ ) . 'js/franer-submission-view.js',
				array(),
				$this->version,
				true
			);
			wp_localize_script(
				'franer-submission-view',
				'FranerSubmissionView',
				array(
					'frameId' => 'franer-view-frame',
					'payload' => $context,
				)
			);
			wp_enqueue_script( 'franer-submission-view' );
		}

		require plugin_dir_path( __FILE__ ) . 'partials/franer-admin-submission-view.php';
	}

	/**
	 * Build the data and JSON context for a Franer's submissions overview.
	 *
	 * Separated from render_submission_view_page() so the resolution logic (invalid
	 * site, missing template, decoded submissions) can be unit-tested without
	 * output. The returned 'context' is what the parent page posts to the sandboxed
	 * iframe; it contains only the site metadata and the decoded submissions —
	 * never a nonce, a privileged URL, or stored PII such as the IP/UA hashes or the
	 * user email.
	 *
	 * @param int $site_id The Franer site ID whose submissions to render.
	 * @return array {
	 *     @type string $back_url     URL back to the submissions list (filtered by site).
	 *     @type string $error        Error/notice message ('' on success).
	 *     @type bool   $has_template Whether a view template is available.
	 *     @type string $view_html    Comment-stripped view template HTML.
	 *     @type string $site_title   The Franer site title.
	 *     @type int    $count        Total submissions for the site.
	 *     @type bool   $truncated    Whether the delivered list was capped.
	 *     @type string $pretty_json  Pretty-printed delivered context JSON.
	 *     @type array  $context      The postMessage context (site/count/submissions).
	 * }
	 */
	public function prepare_submission_view( $site_id ) {
		$site_id = (int) $site_id;
		$post    = $site_id > 0 ? get_post( $site_id ) : null;

		$data = array(
			'back_url'     => add_query_arg(
				array(
					'post_type' => 'franer_site',
					'page'      => 'franer-submissions',
					'site_id'   => $site_id,
				),
				admin_url( 'edit.php' )
			),
			'error'        => '',
			'has_template' => false,
			'view_html'    => '',
			'site_title'   => '',
			'count'        => 0,
			'truncated'    => false,
			'pretty_json'  => '',
			'context'      => array(),
		);

		if ( ! $post instanceof WP_Post || 'franer_site' !== $post->post_type ) {
			$data['error'] = __( 'The requested Franer could not be found.', 'franer' );
			return $data;
		}

		$settings           = $this->sites->get_settings( $site_id );
		$data['site_title'] = isset( $settings['title'] ) ? $settings['title'] : '';

		$raw_view = isset( $settings['view_html'] ) ? (string) $settings['view_html'] : '';

		if ( '' === trim( $raw_view ) ) {
			$data['error'] = __( 'No submission view template has been configured for this Franer.', 'franer' );
			return $data;
		}

		$total            = $this->submissions->count_site_submissions( $site_id );
		$data['count']    = $total;
		$data['truncated'] = $total > self::VIEW_MAX_SUBMISSIONS;

		// Newest submissions first, capped so the localized JSON stays bounded.
		$rows        = $this->submissions->get_site_submissions( $site_id, self::VIEW_MAX_SUBMISSIONS, 0 );
		$submissions = array();
		foreach ( $rows as $row ) {
			// Decode each payload safely; an unreadable payload becomes an empty
			// object so the template can degrade gracefully.
			$decoded = json_decode( isset( $row['payload_json'] ) ? (string) $row['payload_json'] : '', true );
			if ( ! is_array( $decoded ) ) {
				$decoded = array();
			}

			$updated_at    = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '';
			$submissions[] = array(
				'id'         => (int) $row['id'],
				'user_id'    => (int) $row['user_id'],
				'created_at' => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
				'updated_at' => ( '' === $updated_at ) ? null : $updated_at,
				'payload'    => $decoded,
			);
		}

		$data['has_template'] = true;
		$data['view_html']    = Franer_Sanitizer::strip_activity_comments( $raw_view );

		$data['context'] = array(
			'site'        => array(
				'id'    => $site_id,
				'slug'  => isset( $settings['slug'] ) ? $settings['slug'] : '',
				'title' => $data['site_title'],
			),
			'count'       => $total,
			'truncated'   => $data['truncated'],
			'submissions' => $submissions,
		);

		$data['pretty_json'] = (string) wp_json_encode(
			$data['context'],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return $data;
	}

	/**
	 * Redirect back to the submissions screen with a status message.
	 *
	 * @param int    $site_id The site filter to preserve.
	 * @param string $message The franer_msg status key.
	 * @return void
	 */
	private function redirect_to_submissions( $site_id, $message ) {
		$url = add_query_arg(
			array(
				'post_type'  => 'franer_site',
				'page'       => 'franer-submissions',
				'site_id'    => (int) $site_id,
				'franer_msg' => $message,
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handle deletion of a single submission (admin-post action).
	 *
	 * @return void
	 */
	public function handle_delete_submission() {
		if ( ! Franer_Permissions::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage submissions.', 'franer' ) );
		}

		$submission_id = isset( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		$site_id       = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;

		check_admin_referer( 'franer_delete_submission_' . $submission_id );

		if ( $submission_id > 0 ) {
			$repository = new Franer_Submissions_Repository();
			$repository->delete_submission( $submission_id );
		}

		$this->redirect_to_submissions( $site_id, 'deleted' );
	}

	/**
	 * Handle editing the JSON payload of a single submission (admin-post action).
	 *
	 * @return void
	 */
	public function handle_update_submission() {
		if ( ! Franer_Permissions::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage submissions.', 'franer' ) );
		}

		$submission_id = isset( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		$site_id       = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;

		check_admin_referer( 'franer_update_submission_' . $submission_id );

		// Raw JSON: sanitizing as text would corrupt it; it is validated by decoding.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw     = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$decoded = json_decode( is_string( $raw ) ? $raw : '', true );

		if ( $submission_id <= 0 || ! Franer_Sanitizer::is_json_object( $decoded ) ) {
			$this->redirect_to_submissions( $site_id, 'invalid' );
		}

		$payload_json = wp_json_encode( $decoded );

		$repository = new Franer_Submissions_Repository();
		$repository->update_submission( $submission_id, (string) $payload_json );

		$this->redirect_to_submissions( $site_id, 'updated' );
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
