<?php
/**
 * Additional coverage for Franer_Public (registration, assets and the pretty-URL
 * front controller branches).
 *
 * Complements PublicTest (which focuses on shortcode rendering) by exercising the
 * rewrite/query/shortcode registration, asset enqueueing and the not-found /
 * login-redirect / forbidden paths of maybe_render_site().
 *
 * @package Franer
 */

if ( ! class_exists( 'Franer_Public_Test_Proxy' ) ) {
	/**
	 * Exposes the protected Franer_Public helpers for direct testing.
	 */
	class Franer_Public_Test_Proxy extends Franer_Public {

		/**
		 * Invoke the protected enqueue_assets().
		 *
		 * @param array $settings Typed settings.
		 * @return void
		 */
		public function expose_enqueue_assets( array $settings ) {
			$this->enqueue_assets( $settings );
		}

		/**
		 * Invoke the protected trigger_not_found().
		 *
		 * @return void
		 */
		public function expose_trigger_not_found() {
			$this->trigger_not_found();
		}

		/**
		 * Invoke the protected redirect_to_login().
		 *
		 * @return void
		 */
		public function expose_redirect_to_login() {
			$this->redirect_to_login();
		}
	}
}

if ( ! class_exists( 'Franer_Public_Redirect_Exception' ) ) {
	/**
	 * Marker exception used to capture a redirect target in tests.
	 */
	class Franer_Public_Redirect_Exception extends Exception {}
}

/**
 * Verifies Franer_Public registration, assets and front-controller branches.
 */
class PublicCoverageTest extends Franer_Test_Base {

	/**
	 * Public instance under test.
	 *
	 * @var Franer_Public_Test_Proxy
	 */
	private $public;

	/**
	 * Set up the instance.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->public = new Franer_Public_Test_Proxy( 'franer', FRANER_VERSION );
	}

	/**
	 * Reset request state between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * add_query_vars() registers the franer_slug var.
	 *
	 * @return void
	 */
	public function test_add_query_vars() {
		$this->assertContains( 'franer_slug', $this->public->add_query_vars( array() ) );
	}

	/**
	 * register_shortcodes() registers the [franer] shortcode.
	 *
	 * @return void
	 */
	public function test_register_shortcodes() {
		$this->public->register_shortcodes();
		$this->assertTrue( shortcode_exists( 'franer' ) );
	}

	/**
	 * add_rewrite_rules() registers the pretty /franer/{slug}/ rule.
	 *
	 * @return void
	 */
	public function test_add_rewrite_rules() {
		global $wp_rewrite;

		$this->public->add_rewrite_rules();

		$this->assertArrayHasKey( '^franer/([a-z0-9-]+)/?$', $wp_rewrite->extra_rules_top );
	}

	/**
	 * enqueue_assets() loads the public CSS/JS and localizes the shell data.
	 *
	 * @return void
	 */
	public function test_enqueue_assets_localizes_shell() {
		$this->public->expose_enqueue_assets( array( 'slug' => 'demo' ) );

		$this->assertTrue( wp_style_is( 'franer-public', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'franer-shell', 'enqueued' ) );

		$data = wp_scripts()->get_data( 'franer-shell', 'data' );
		$this->assertStringContainsString( 'FranerShell', (string) $data );
		$this->assertStringContainsString( 'demo', (string) $data );
	}

	/**
	 * frame_protection_headers() is filterable (integrators may tighten it).
	 *
	 * @return void
	 */
	public function test_frame_protection_headers_filterable() {
		$this->assertSame( 'SAMEORIGIN', $this->public->frame_protection_headers()['X-Frame-Options'] );

		add_filter(
			'franer_frame_protection_headers',
			static function () {
				return array( 'X-Frame-Options' => 'DENY' );
			}
		);

		$this->assertSame( 'DENY', $this->public->frame_protection_headers()['X-Frame-Options'] );
	}

	/**
	 * trigger_not_found() flips the main query into a 404.
	 *
	 * @return void
	 */
	public function test_trigger_not_found_sets_404() {
		global $wp_query;
		$wp_query->init();

		$this->public->expose_trigger_not_found();

		$this->assertTrue( $wp_query->is_404() );
	}

	/**
	 * redirect_to_login() targets the login URL with a redirect_to back to the request.
	 *
	 * @return void
	 */
	public function test_redirect_to_login_targets_wp_login() {
		$_SERVER['REQUEST_URI'] = '/franer/demo/';

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Franer_Public_Redirect_Exception( (string) $location );
			}
		);

		try {
			$this->public->expose_redirect_to_login();
			$this->fail( 'Expected a redirect.' );
		} catch ( Franer_Public_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'wp-login.php', $e->getMessage() );
		}
	}

	/**
	 * maybe_render_site() is a no-op when no slug is queried.
	 *
	 * @return void
	 */
	public function test_maybe_render_site_without_slug_is_noop() {
		set_query_var( 'franer_slug', '' );

		// Should simply return without error or output.
		ob_start();
		$this->public->maybe_render_site();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * maybe_render_site() 404s for an unknown slug.
	 *
	 * @return void
	 */
	public function test_maybe_render_site_unknown_slug_404s() {
		global $wp_query;
		$wp_query->init();
		set_query_var( 'franer_slug', 'does-not-exist' );

		$this->public->maybe_render_site();

		$this->assertTrue( $wp_query->is_404() );
	}

	/**
	 * maybe_render_site() 404s for a disabled site.
	 *
	 * @return void
	 */
	public function test_maybe_render_site_disabled_site_404s() {
		global $wp_query;
		$wp_query->init();

		$site_id = self::factory()->franer_site->create( array( 'slug' => 'disabled-demo' ) );
		update_post_meta( $site_id, '_franer_enabled', '0' );
		set_query_var( 'franer_slug', 'disabled-demo' );

		$this->public->maybe_render_site();

		$this->assertTrue( $wp_query->is_404() );
	}

	/**
	 * maybe_render_site() redirects logged-out visitors to the login page.
	 *
	 * @return void
	 */
	public function test_maybe_render_site_redirects_logged_out() {
		wp_set_current_user( 0 );

		self::factory()->franer_site->create(
			array(
				'slug'          => 'open-demo',
				'allowed_roles' => array( 'subscriber' ),
			)
		);
		set_query_var( 'franer_slug', 'open-demo' );
		$_SERVER['REQUEST_URI'] = '/franer/open-demo/';

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Franer_Public_Redirect_Exception( (string) $location );
			}
		);

		$this->expectException( Franer_Public_Redirect_Exception::class );
		$this->public->maybe_render_site();
	}

	/**
	 * maybe_render_site() forbids a logged-in user without an allowed role.
	 *
	 * @return void
	 */
	public function test_maybe_render_site_forbids_disallowed_role() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		self::factory()->franer_site->create(
			array(
				'slug'          => 'subs-only',
				'allowed_roles' => array( 'subscriber' ),
			)
		);
		set_query_var( 'franer_slug', 'subs-only' );

		$this->expectException( WPDieException::class );
		$this->public->maybe_render_site();
	}
}
