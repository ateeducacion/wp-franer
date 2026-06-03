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

		/**
		 * Filters the Franer shortcode attributes before resolving the activity.
		 *
		 * Presentation/integration use only. Security-sensitive: it must not be
		 * used to bypass visibility, permission or role checks (those run after).
		 *
		 * @since 1.0.0
		 *
		 * @param array $atts Shortcode attributes after defaults are merged.
		 * @return array Filtered attributes (non-array returns are reset to empty).
		 */
		$atts = apply_filters( 'franer_shortcode_atts', $atts );

		if ( ! is_array( $atts ) ) {
			$atts = array();
		}

		$slug = isset( $atts['slug'] ) ? sanitize_title( $atts['slug'] ) : '';
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

		/**
		 * Fires before a Franer activity is rendered.
		 *
		 * Runs only after the site is resolved and view permissions pass.
		 * Security-sensitive: it must not be used to bypass access control.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_Post $site     The Franer site post.
		 * @param array   $settings Typed Franer site settings.
		 * @param string  $context  Render context: 'shortcode' or 'pretty_url'.
		 */
		do_action( 'franer_before_render', $site, $settings, 'shortcode' );

		return $this->get_render_markup( $site, $settings, 'shortcode' );
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

		// Presentation/kiosk layout toggle. Display-only: it changes nothing on the
		// server, so no nonce is required for this read.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fullscreen = isset( $_GET['fullscreen'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['fullscreen'] ) );

		$this->render_site( $site, $settings, $fullscreen );
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
	 * @param WP_Post $site       The site post object.
	 * @param array   $settings   The typed site settings.
	 * @param bool    $fullscreen Whether to render the kiosk/presentation layout.
	 */
	public function render_site( WP_Post $site, array $settings, $fullscreen = false ) {
		// Kiosk mode is meant to fill the viewport, so suppress the admin bar.
		if ( $fullscreen ) {
			add_filter( 'show_admin_bar', '__return_false' );
		}

		$this->enqueue_assets( $settings );

		/** This action is documented in public/class-franer-public.php */
		do_action( 'franer_before_render', $site, $settings, 'pretty_url' );

		nocache_headers();
		$this->send_frame_protection_headers();

		$body_classes = array( 'franer-activity-page' );
		if ( $fullscreen ) {
			$body_classes[] = 'franer-activity-page--full';
		}
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( get_the_title( $site ) ); ?></title>
		<?php wp_head(); ?>
</head>
<body <?php body_class( $body_classes ); ?>>
		<?php
		if ( function_exists( 'wp_body_open' ) ) {
			wp_body_open();
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped within the partial.
		echo $this->get_render_markup( $site, $settings, 'pretty_url' );

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
	 * @param string  $context  Render context: 'shortcode' or 'pretty_url'.
	 * @return string The captured markup.
	 */
	protected function get_render_markup( WP_Post $site, array $settings, $context = 'pretty_url' ) {
		$partial = plugin_dir_path( __FILE__ ) . 'partials/franer-public-render.php';

		if ( ! file_exists( $partial ) ) {
			return '';
		}

		ob_start();
		// $site and $settings are available inside the partial scope.
		include $partial;
		$markup = (string) ob_get_clean();

		/**
		 * Filters the final Franer render markup.
		 *
		 * Shared by the shortcode and the pretty URL renderer. Intended for
		 * wrapping or adding presentation markup around the existing shell.
		 * Security-sensitive: callbacks must not remove the sandboxed iframe
		 * security attributes or expose the untrusted activity HTML outside it.
		 *
		 * @since 1.0.0
		 *
		 * @param string  $markup   Rendered Franer markup.
		 * @param WP_Post $site     The Franer site post.
		 * @param array   $settings Typed Franer site settings.
		 * @param string  $context  Render context: 'shortcode' or 'pretty_url'.
		 * @return string Filtered render markup.
		 */
		$markup = apply_filters( 'franer_render_markup', $markup, $site, $settings, $context );

		if ( ! is_string( $markup ) ) {
			$markup = '';
		}

		/**
		 * Fires after a Franer activity render markup has been built.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_Post $site     The Franer site post.
		 * @param array   $settings Typed Franer site settings.
		 * @param string  $context  Render context: 'shortcode' or 'pretty_url'.
		 * @param string  $markup   Final render markup.
		 */
		do_action( 'franer_after_render', $site, $settings, $context, $markup );

		return $markup;
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

		// Depends on franer-shell so the localized FranerShell global is available.
		wp_enqueue_script(
			'franer-fullscreen',
			plugin_dir_url( __FILE__ ) . 'js/franer-fullscreen.js',
			array( 'franer-shell' ),
			FRANER_VERSION,
			true
		);

		$base = 'franer/v1/sites/' . rawurlencode( $slug );

		$default_shell_data = array(
			'restUrl'  => esc_url_raw( rest_url( $base . '/submissions' ) ),
			'myUrl'    => esc_url_raw( rest_url( $base . '/my-submission' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'slug'     => $slug,
			'messages' => array(
				'saved'          => __( 'Your response has been saved.', 'franer' ),
				'updated'        => __( 'Your response has been updated.', 'franer' ),
				'error'          => __( 'There was a problem saving your response. Please try again.', 'franer' ),
				'network'        => __( 'A network error occurred. Please check your connection and try again.', 'franer' ),
				'fullscreen'     => __( 'Fullscreen', 'franer' ),
				'exitFullscreen' => __( 'Exit fullscreen', 'franer' ),
			),
		);

		/**
		 * Filters the public Franer shell data localized to JavaScript.
		 *
		 * Intended for adding presentation messages or integration metadata.
		 * Security-sensitive: the required restUrl, myUrl, nonce and slug values
		 * must remain valid. Missing required keys are restored from the defaults.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $shell_data Localized shell data.
		 * @param array  $settings   Typed Franer site settings.
		 * @param string $slug       Franer site slug.
		 * @return array Filtered shell data.
		 */
		$shell_data = apply_filters( 'franer_public_shell_data', $default_shell_data, $settings, $slug );

		if ( ! is_array( $shell_data ) ) {
			$shell_data = array();
		}

		// Required REST keys are always restored so callbacks cannot break security.
		$shell_data = wp_parse_args( $shell_data, $default_shell_data );

		wp_localize_script( 'franer-shell', 'FranerShell', $shell_data );
	}

	/**
	 * The anti-framing headers sent with the standalone activity page.
	 *
	 * The activity page carries the live REST nonce and runs the parent shell that
	 * brokers authenticated submissions, so it must not be framed by third-party
	 * origins (clickjacking / cross-origin framing of the submit shell). These
	 * headers restrict framing to the same origin. Exposed (and filterable) so the
	 * value can be inspected in tests and tightened by integrators.
	 *
	 * @return array Map of header name => value.
	 */
	public function frame_protection_headers() {
		$headers = array(
			'X-Frame-Options'         => 'SAMEORIGIN',
			'Content-Security-Policy' => "frame-ancestors 'self'",
		);

		/**
		 * Filters the anti-framing headers for the standalone Franer activity page.
		 *
		 * Security-sensitive: callbacks should only tighten framing (e.g. switch to
		 * 'DENY'), never remove the protection that guards the authenticated shell.
		 *
		 * @since 1.0.0
		 *
		 * @param array $headers Map of header name => value.
		 */
		$headers = apply_filters( 'franer_frame_protection_headers', $headers );

		return is_array( $headers ) ? $headers : array();
	}

	/**
	 * Send the anti-framing headers for the standalone activity page.
	 *
	 * @return void
	 */
	protected function send_frame_protection_headers() {
		if ( headers_sent() ) {
			return;
		}

		foreach ( $this->frame_protection_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
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
