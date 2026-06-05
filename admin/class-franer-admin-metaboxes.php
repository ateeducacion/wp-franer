<?php
/**
 * Editor metaboxes for the franer_site post type.
 *
 * Owns the registration and render callbacks for the Franer editor metaboxes
 * (the onboarding guide, Activity HTML, Access, Submissions settings, Share,
 * per-site Submissions and Help). Split out of Franer_Admin so each class stays
 * focused and below the project complexity thresholds; Franer_Admin still wires
 * the hooks (see Franer's define_admin_hooks()).
 *
 * @package    Franer
 * @subpackage Franer/admin
 * @author     Área de Tecnología Educativa
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Registers and renders the franer_site editor metaboxes.
 */
class Franer_Admin_Metaboxes {

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
	 * @param Franer_Site_Repository|null        $sites       Optional site repository.
	 * @param Franer_Submissions_Repository|null $submissions Optional submissions repository.
	 */
	public function __construct( $sites = null, $submissions = null ) {
		$this->sites       = $sites instanceof Franer_Site_Repository ? $sites : new Franer_Site_Repository();
		$this->submissions = $submissions instanceof Franer_Submissions_Repository ? $submissions : new Franer_Submissions_Repository();
	}

	/**
	 * Register the metaboxes on the franer_site editor.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		// The CPT supports 'author', so core registers the Author box in the main
		// column. Move it to the side and let restrict_author_dropdown_to_admins()
		// populate it, so the activity owner is shown and can be reassigned.
		if ( post_type_supports( 'franer_site', 'author' ) ) {
			remove_meta_box( 'authordiv', 'franer_site', 'normal' );
			add_meta_box(
				'authordiv',
				__( 'Author', 'franer' ),
				'post_author_meta_box',
				'franer_site',
				'side',
				'high'
			);
		}

		// The activity HTML is the heart of a Franer, so it leads the main column.
		add_meta_box(
			'franer_site_html',
			__( 'Activity', 'franer' ),
			array( $this, 'render_html_metabox' ),
			'franer_site',
			'normal',
			'high'
		);

		add_meta_box(
			'franer_site_access',
			__( 'Access and visibility', 'franer' ),
			array( $this, 'render_access_metabox' ),
			'franer_site',
			'normal',
			'high'
		);

		add_meta_box(
			'franer_site_submissions_settings',
			__( 'Submissions and availability', 'franer' ),
			array( $this, 'render_submissions_settings_metabox' ),
			'franer_site',
			'normal',
			'default'
		);

		add_meta_box(
			'franer_site_public_url',
			__( 'Share', 'franer' ),
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
	 * Render the onboarding guide strip above the editor (franer_site only).
	 *
	 * Hooked on edit_form_top, which fires for every post type, so it must bail
	 * out for anything that is not a Franer.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public function render_guide_strip( $post ) {
		if ( ! $post instanceof WP_Post || 'franer_site' !== $post->post_type ) {
			return;
		}
		require_once plugin_dir_path( __FILE__ ) . 'partials/franer-admin-metaboxes.php';
		franer_render_guide_strip();
	}

	/**
	 * Render the Access and visibility metabox (slug, status, roles).
	 *
	 * Also emits the shared save nonce for the whole editor.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public function render_access_metabox( $post ) {
		$settings    = $this->sites->get_settings( $post->ID );
		$all_roles   = wp_roles()->roles;
		$public_base = home_url( '/franer/' );
		wp_nonce_field( 'save_franer_site', 'franer_site_nonce' );
		require plugin_dir_path( __FILE__ ) . 'partials/franer-admin-metaboxes.php';
		franer_render_access_metabox( $settings, $all_roles, $public_base );
	}

	/**
	 * Render the Submissions and availability metabox.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public function render_submissions_settings_metabox( $post ) {
		$settings = $this->sites->get_settings( $post->ID );
		require plugin_dir_path( __FILE__ ) . 'partials/franer-admin-metaboxes.php';
		franer_render_submissions_settings_metabox( $settings );
	}

	/**
	 * Render the HTML source metabox.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public function render_html_metabox( $post ) {
		$settings        = $this->sites->get_settings( $post->ID );
		$activity_prompt = Franer_Help::get_default_activity_prompt();
		$view_prompt     = Franer_Help::get_default_view_prompt();
		require_once plugin_dir_path( __FILE__ ) . 'partials/franer-admin-metaboxes.php';
		franer_render_html_metabox( $settings, $activity_prompt, $view_prompt );
	}

	/**
	 * Render the Public URL (Share) metabox.
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
		$count    = $this->submissions->count_site_submissions( $post->ID );
		$list_url = add_query_arg(
			array(
				'post_type' => 'franer_site',
				'page'      => 'franer-submissions',
				'site_id'   => $post->ID,
			),
			admin_url( 'edit.php' )
		);

		$export_url     = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'franer_export',
					'site_id' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'franer_export_' . $post->ID
		);
		$export_csv_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'franer_export_csv',
					'site_id' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'franer_export_csv_' . $post->ID
		);
		$overview_url   = add_query_arg(
			array(
				'post_type' => 'franer_site',
				'page'      => 'franer-submission-view',
				'site_id'   => $post->ID,
			),
			admin_url( 'edit.php' )
		);
		require_once plugin_dir_path( __FILE__ ) . 'partials/franer-admin-metaboxes.php';
		franer_render_submissions_metabox( $count, $list_url, $export_url, $overview_url, $export_csv_url );
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
}
