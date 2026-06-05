<?php
/**
 * The file that defines the core plugin class.
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://github.com/ateeducacion/wp-franer
 *
 * @package    Franer
 * @subpackage Franer/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @package    Franer
 * @subpackage Franer/includes
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Franer {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @access protected
	 * @var    Franer_Loader $loader Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @access protected
	 * @var    string $plugin_name The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @access protected
	 * @var    string $version The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 */
	public function __construct() {
		$this->version     = defined( 'FRANER_VERSION' ) ? FRANER_VERSION : '0.0.0';
		$this->plugin_name = 'franer';

		$this->load_dependencies();
		$this->define_i18n_hooks();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_rest_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the files that make up the plugin and create an instance of the
	 * loader which will be used to register the hooks with WordPress.
	 *
	 * @access private
	 * @return void
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the core plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-franer-loader.php';

		/**
		 * The class responsible for defining internationalization functionality.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-franer-i18n.php';

		/**
		 * The classes responsible for activation and deactivation.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-franer-activator.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-franer-deactivator.php';

		/**
		 * The class responsible for registering the custom post type and its meta.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-franer-post-types.php';

		/**
		 * Helper classes for sanitization and permissions.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-franer-sanitizer.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-franer-permissions.php';

		/**
		 * Repository classes for sites and submissions.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-franer-site-repository.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-franer-submissions-repository.php';

		/**
		 * The class responsible for the REST API endpoints.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-franer-rest-controller.php';

		/**
		 * The class responsible for creating demo data.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-franer-demo-data.php';

		/**
		 * The classes responsible for the admin area.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-franer-admin.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-franer-admin-submissions.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-franer-help.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-franer-export-controller.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-franer-csv-export-controller.php';

		/**
		 * The class responsible for the public-facing side of the site.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'public/class-franer-public.php';

		$this->loader = new Franer_Loader();
	}

	/**
	 * Register all of the hooks related to internationalization.
	 *
	 * @access private
	 * @return void
	 */
	private function define_i18n_hooks() {
		$plugin_i18n = new Franer_I18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
	}

	/**
	 * Register all of the hooks related to the admin area functionality of the plugin.
	 *
	 * @access private
	 * @return void
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Franer_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_menu' );
		$this->loader->add_action( 'add_meta_boxes', $plugin_admin, 'add_meta_boxes' );
		// Show administrators in the Author metabox dropdown (the CPT's custom caps
		// otherwise leave it empty) and write the activity HTML during the primary
		// save so each save records a single revision.
		$this->loader->add_filter( 'wp_dropdown_users_args', $plugin_admin, 'restrict_author_dropdown_to_admins', 10, 2 );
		$this->loader->add_filter( 'wp_insert_post_data', $plugin_admin, 'inject_raw_html_into_content', 10, 2 );
		$this->loader->add_action( 'save_post_franer_site', $plugin_admin, 'save_meta', 10, 2 );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_assets' );
		$this->loader->add_action( 'admin_notices', $plugin_admin, 'display_notices' );

		// The Help submenu and its page are registered inside Franer_Admin::add_menu().

		$plugin_export = new Franer_Export_Controller();
		$this->loader->add_action( 'admin_post_franer_export', $plugin_export, 'handle' );

		$plugin_export_csv = new Franer_Csv_Export_Controller();
		$this->loader->add_action( 'admin_post_franer_export_csv', $plugin_export_csv, 'handle' );

		$plugin_submissions = new Franer_Admin_Submissions( $this->get_version() );
		$this->loader->add_action( 'admin_post_franer_delete_submission', $plugin_submissions, 'handle_delete_submission' );
		$this->loader->add_action( 'admin_post_franer_update_submission', $plugin_submissions, 'handle_update_submission' );

		// Permanently deleting a franer_site must also remove its submissions, so no
		// orphaned rows (or PII) survive and a recycled post ID can never inherit
		// another activity's submissions. Registered unconditionally (not gated on
		// is_admin) so WP-CLI and programmatic deletes are covered too.
		$this->loader->add_action( 'before_delete_post', $plugin_admin, 'purge_site_submissions', 10, 2 );

		$this->loader->add_filter( 'manage_franer_site_posts_columns', $plugin_admin, 'add_list_columns' );
		$this->loader->add_action( 'manage_franer_site_posts_custom_column', $plugin_admin, 'render_list_column', 10, 2 );
		$this->loader->add_filter( 'manage_edit-franer_site_sortable_columns', $plugin_admin, 'add_sortable_columns' );
		$this->loader->add_filter( 'posts_clauses', $plugin_admin, 'sort_by_submissions_clauses', 10, 2 );
		$this->loader->add_action( 'restrict_manage_posts', $plugin_admin, 'add_list_filters' );
		$this->loader->add_action( 'pre_get_posts', $plugin_admin, 'filter_list_query' );
		$this->loader->add_filter( 'post_row_actions', $plugin_admin, 'add_row_actions', 10, 2 );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality of the plugin.
	 *
	 * @access private
	 * @return void
	 */
	private function define_public_hooks() {

		$plugin_post_types = new Franer_Post_Types();
		$this->loader->add_action( 'init', $plugin_post_types, 'register' );

		// Seed the bundled demo activity once (idempotent, gated by an option).
		$this->loader->add_action( 'init', 'Franer_Demo_Data', 'maybe_seed', 99 );

		$plugin_public = new Franer_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'init', $plugin_public, 'add_rewrite_rules' );
		$this->loader->add_action( 'init', $plugin_public, 'register_shortcodes' );
		$this->loader->add_filter( 'query_vars', $plugin_public, 'add_query_vars' );
		$this->loader->add_action( 'template_redirect', $plugin_public, 'maybe_render_site' );
		// Assets are enqueued contextually inside render_site()/shortcode_callback()
		// (only when a viewable site is actually rendered), so there is no global
		// wp_enqueue_scripts hook here.
	}

	/**
	 * Register all of the hooks related to the REST API.
	 *
	 * @access private
	 * @return void
	 */
	private function define_rest_hooks() {

		$plugin_rest = new Franer_Rest_Controller( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'rest_api_init', $plugin_rest, 'register_routes' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @return void
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @return string The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return string The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Reference to the loader instance.
	 *
	 * @return Franer_Loader The loader instance.
	 */
	public function get_loader() {
		return $this->loader;
	}
}
