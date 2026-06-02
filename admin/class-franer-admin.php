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
		require_once plugin_dir_path( __FILE__ ) . 'partials/franer-admin-metaboxes.php';
		franer_render_submissions_metabox( $count, $list_url, $export_url );
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

		// HTML source: stored RAW (never sanitized). It only ever renders inside a sandboxed iframe.
		if ( isset( $_POST['franer_html'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HTML is stored raw by design and only rendered inside a sandboxed iframe.
			$html = wp_unslash( $_POST['franer_html'] );
			update_post_meta( $post_id, '_franer_html', $html );
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
			array( 'franer-submissions', 'franer-help' ),
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

		$rows       = array();
		$export_url = '';
		if ( $selected_site > 0 ) {
			$raw_rows = $this->submissions->get_site_submissions( $selected_site, 200, 0 );
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
}
