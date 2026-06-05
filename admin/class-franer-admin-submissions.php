<?php
/**
 * Admin-only Submissions screens for Franer.
 *
 * Owns the standalone Submissions list page, the per-Franer submissions-overview
 * page and the admin-post handlers that edit or delete a single submission. Split
 * out of Franer_Admin so each screen stays focused (and below the project
 * complexity thresholds); the menu entries are still registered by
 * Franer_Admin::add_menu(), which delegates their page callbacks here.
 *
 * @package Franer
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Renders and handles the Franer submissions admin screens.
 */
class Franer_Admin_Submissions {

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
	 * The plugin version (used to version enqueued scripts).
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
	 * Constructor.
	 *
	 * @param string                             $version     The plugin version.
	 * @param Franer_Site_Repository|null        $sites       Optional site repository.
	 * @param Franer_Submissions_Repository|null $submissions Optional submissions repository.
	 */
	public function __construct( $version = FRANER_VERSION, $sites = null, $submissions = null ) {
		$this->version     = $version;
		$this->sites       = $sites instanceof Franer_Site_Repository ? $sites : new Franer_Site_Repository();
		$this->submissions = $submissions instanceof Franer_Submissions_Repository ? $submissions : new Franer_Submissions_Repository();
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
				'post_type'        => 'franer_site',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'fields'           => 'all',
				'suppress_filters' => false,
			)
		);

		// Submission counts per activity, used by the empty-state chips.
		$activity_counts = array();
		foreach ( $site_posts as $franer_site_post ) {
			$activity_counts[ $franer_site_post->ID ] = $this->submissions->count_site_submissions( $franer_site_post->ID );
		}

		$export_url      = '';
		$export_csv_url  = '';
		$list_table      = null;
		$summary         = null;
		$fields          = array();
		$has_custom_view = false;
		if ( $selected_site > 0 ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-franer-submissions-list-table.php';

			// Infer the field schema from a bounded sample so the table, the
			// auto-summary and the detail drawer can read any free-form payload.
			$summary_rows    = $this->build_summary_rows( $selected_site );
			$fields          = Franer_Submission_Schema::infer_fields( wp_list_pluck( $summary_rows, 'payload' ) );
			$summary         = Franer_Submission_Schema::build_summary( $summary_rows );
			$settings        = $this->sites->get_settings( $selected_site );
			$has_custom_view = isset( $settings['view_html'] ) && '' !== trim( (string) $settings['view_html'] );

			$list_table = new Franer_Submissions_List_Table( $this->submissions, $selected_site, 50, $fields );
			$list_table->prepare_items();

			$export_url     = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'franer_export',
						'site_id' => $selected_site,
					),
					admin_url( 'admin-post.php' )
				),
				'franer_export_' . $selected_site
			);
			$export_csv_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'franer_export_csv',
						'site_id' => $selected_site,
					),
					admin_url( 'admin-post.php' )
				),
				'franer_export_csv_' . $selected_site
			);
		}

		require plugin_dir_path( __FILE__ ) . 'partials/franer-admin-submissions.php';
	}

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

		$total             = $this->submissions->count_site_submissions( $site_id );
		$data['count']     = $total;
		$data['truncated'] = $total > self::VIEW_MAX_SUBMISSIONS;

		// Newest submissions first, capped so the localized JSON stays bounded.
		$submissions = $this->decode_submissions_for_view(
			$this->submissions->get_site_submissions( $site_id, self::VIEW_MAX_SUBMISSIONS, 0 )
		);

		$data['has_template'] = true;
		// Render-time transforms (comment stripping + guardrail CSP); the overview
		// receives every submission, so blocking exfiltration here matters most.
		$data['view_html']    = Franer_Sanitizer::prepare_for_srcdoc( $raw_view );

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
	 * Decode submission rows into the bounded list delivered to the view iframe.
	 *
	 * Each payload is decoded safely; an unreadable payload becomes an empty object
	 * so the template can degrade gracefully. Only non-PII fields are included.
	 *
	 * @param array $rows Rows from the submissions repository.
	 * @return array The decoded submissions for the postMessage context.
	 */
	private function decode_submissions_for_view( $rows ) {
		$submissions = array();
		foreach ( $rows as $row ) {
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

		return $submissions;
	}

	/**
	 * Build the decoded rows used to infer the schema and the auto-summary.
	 *
	 * Reads a bounded, newest-first sample of the activity's submissions, decodes
	 * each payload and resolves the submitter login, shaped for
	 * Franer_Submission_Schema (no PII beyond the public login name).
	 *
	 * @param int $site_id The activity (site) post ID.
	 * @return array List of { id, user, created_at, updated_at, payload }.
	 */
	private function build_summary_rows( $site_id ) {
		$raw  = $this->submissions->get_site_submissions( (int) $site_id, self::VIEW_MAX_SUBMISSIONS, 0 );
		$rows = array();
		foreach ( $raw as $row ) {
			$decoded = json_decode( isset( $row['payload_json'] ) ? (string) $row['payload_json'] : '', true );
			if ( ! is_array( $decoded ) ) {
				$decoded = array();
			}
			$user   = get_userdata( (int) $row['user_id'] );
			$rows[] = array(
				'id'         => (int) $row['id'],
				'user'       => $user ? $user->user_login : ( '#' . (int) $row['user_id'] ),
				'created_at' => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
				'updated_at' => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
				'payload'    => $decoded,
			);
		}

		return $rows;
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
			$this->submissions->delete_submission( $submission_id );
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

		$this->submissions->update_submission( $submission_id, (string) $payload_json );

		$this->redirect_to_submissions( $site_id, 'updated' );
	}
}
