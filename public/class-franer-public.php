<?php
/**
 * The public-facing functionality of the plugin.
 *
 * Resolves Franer sites by slug, enforces view permissions, renders the
 * sandboxed iframe shell and registers the [franer] shortcode.
 *
 * @package    Franer
 * @subpackage Franer/public
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Franer_Public
 *
 * Handles rewrite rules, query vars, the public shortcode and the
 * front-end rendering of Franer sites.
 */
class Franer_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @access private
	 * @var    string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @access private
	 * @var    string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and register its hooks.
	 *
	 * @param string $plugin_name The name of the plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		// Hooks are registered centrally through Franer_Loader in
		// Franer::define_public_hooks(), consistent with every other class.
	}

	/**
	 * Register the rewrite rule for pretty public URLs.
	 *
	 * Maps /franer/{slug}/ to the internal franer_slug query var.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^franer/([a-z0-9-]+)/?$',
			'index.php?franer_slug=$matches[1]',
			'top'
		);
	}

	/**
	 * Register the custom public query var.
	 *
	 * @param array $vars The existing WP query vars.
	 * @return array The modified query vars array.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'franer_slug';
		return $vars;
	}

	/**
	 * Register the [franer] shortcode.
	 */
	public function register_shortcodes() {
		add_shortcode( 'franer', array( $this, 'shortcode_callback' ) );
	}

	/**
	 * Shortcode callback for [franer slug="..."].
	 *
	 * Resolves the site, enforces view permissions and returns the rendered
	 * shell markup as a string for embedding in arbitrary content.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string The rendered markup, or an empty string if not viewable.
	 */
	public function shortcode_callback( $atts ) {
		$atts = shortcode_atts(
			array(
				'slug' => '',
			),
			$atts,
			'franer'
		);

		$slug = sanitize_title( $atts['slug'] );
		if ( '' === $slug ) {
			return '';
		}

		$repository = new Franer_Site_Repository();
		$site       = $repository->get_by_slug( $slug );
		if ( ! $site instanceof WP_Post ) {
			return '';
		}

		$settings = $repository->get_settings( $site->ID );

		// Do not leak existence: only logged-in users with an allowed role
		// on a visible site get any output.
		if ( ! Franer_Permissions::user_can_view( $settings, get_current_user_id() ) ) {
			return '';
		}

		$this->enqueue_assets( $settings );

		return $this->get_render_markup( $site, $settings );
	}

	/**
	 * Front controller for the pretty /franer/{slug}/ URL.
	 *
	 * Resolves the site and enforces permissions:
	 * - 404 if the site does not exist or is hidden.
	 * - Redirect to the login page if the visitor is logged out.
	 * - 403 (wp_die) if the logged-in user's role is not allowed.
	 * On success it renders the public partial and exits.
	 */
	public function maybe_render_site() {
		$slug = get_query_var( 'franer_slug' );

		if ( empty( $slug ) ) {
			return;
		}

		$slug = sanitize_title( $slug );

		$repository = new Franer_Site_Repository();
		$site       = $repository->get_by_slug( $slug );
		if ( ! $site instanceof WP_Post ) {
			$this->trigger_not_found();
			return;
		}

		$settings = $repository->get_settings( $site->ID );

		// A hidden or disabled site behaves as if it does not exist.
		if ( empty( $settings['is_visible'] ) || empty( $settings['enabled'] ) ) {
			$this->trigger_not_found();
			return;
		}

		// Logged-out visitors are sent to the login page (and back).
		if ( ! is_user_logged_in() ) {
			$this->redirect_to_login();
			exit;
		}

		// Logged-in but role not allowed: forbidden.
		if ( ! Franer_Permissions::user_can_view( $settings, get_current_user_id() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this activity.', 'franer' ),
				esc_html__( 'Forbidden', 'franer' ),
				array( 'response' => 403 )
			);
		}

		$this->render_site( $site, $settings );
		exit;
	}

	/**
	 * Render a full standalone page for a Franer site.
	 *
	 * Outputs a self-contained, theme-independent HTML document so the activity
	 * renders identically regardless of the active theme (classic or block) and
	 * without triggering get_header()/get_footer() deprecations on block themes.
	 * wp_head()/wp_footer() still run so enqueued styles and scripts load.
	 * Intended for the dedicated /franer/{slug}/ URL.
	 *
	 * @param WP_Post $site     The site post object.
	 * @param array   $settings The typed site settings.
	 */
	public function render_site( WP_Post $site, array $settings ) {
		$this->enqueue_assets( $settings );

		nocache_headers();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( get_the_title( $site ) ); ?></title>
		<?php wp_head(); ?>
</head>
<body <?php body_class( 'franer-activity-page' ); ?>>
		<?php
		if ( function_exists( 'wp_body_open' ) ) {
			wp_body_open();
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped within the partial.
		echo $this->get_render_markup( $site, $settings );

		wp_footer();
		?>
</body>
</html>
		<?php
	}

	/**
	 * Build the shared render markup from the public partial.
	 *
	 * Used by both the pretty URL renderer and the shortcode so the output
	 * stays identical. The partial escapes all values on output.
	 *
	 * @param WP_Post $site     The site post object.
	 * @param array   $settings The typed site settings.
	 * @return string The captured markup.
	 */
	protected function get_render_markup( WP_Post $site, array $settings ) {
		$partial = plugin_dir_path( __FILE__ ) . 'partials/franer-public-render.php';

		if ( ! file_exists( $partial ) ) {
			return '';
		}

		ob_start();
		// $site and $settings are available inside the partial scope.
		include $partial;
		return (string) ob_get_clean();
	}

	/**
	 * Enqueue the public stylesheet and parent-shell script.
	 *
	 * Only ever called while rendering a viewable site. Localizes the REST
	 * URLs, nonce, slug and translated UI messages for the shell.
	 *
	 * @param array $settings The typed site settings.
	 */
	protected function enqueue_assets( array $settings ) {
		$slug = isset( $settings['slug'] ) ? $settings['slug'] : '';

		wp_enqueue_style(
			'franer-public',
			plugin_dir_url( __FILE__ ) . 'css/franer-public.css',
			array(),
			FRANER_VERSION
		);

		wp_enqueue_script(
			'franer-shell',
			plugin_dir_url( __FILE__ ) . 'js/franer-shell.js',
			array(),
			FRANER_VERSION,
			true
		);

		$base = 'franer/v1/sites/' . rawurlencode( $slug );

		wp_localize_script(
			'franer-shell',
			'FranerShell',
			array(
				'restUrl'  => esc_url_raw( rest_url( $base . '/submissions' ) ),
				'myUrl'    => esc_url_raw( rest_url( $base . '/my-submission' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'slug'     => $slug,
				'messages' => array(
					'saved'   => __( 'Your response has been saved.', 'franer' ),
					'updated' => __( 'Your response has been updated.', 'franer' ),
					'error'   => __( 'There was a problem saving your response. Please try again.', 'franer' ),
					'network' => __( 'A network error occurred. Please check your connection and try again.', 'franer' ),
				),
			)
		);
	}

	/**
	 * Force the current request into a 404 state.
	 */
	protected function trigger_not_found() {
		global $wp_query;

		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Redirect the visitor to the login page, returning to the current URL.
	 */
	protected function redirect_to_login() {
		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$redirect_url = home_url( $request_uri );
		wp_safe_redirect( wp_login_url( $redirect_url ) );
	}
}
