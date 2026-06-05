<?php
/**
 * Tests for the core Franer plugin class.
 *
 * The plugin is instantiated once during bootstrap (before coverage starts), so
 * these tests build a fresh instance to exercise the constructor wiring, the
 * accessors and run().
 *
 * @package Franer
 */

/**
 * Verifies dependency loading, accessors and hook registration.
 */
class CoreTest extends WP_UnitTestCase {

	/**
	 * The constructor should set the plugin identity and build the loader.
	 *
	 * @return void
	 */
	public function test_construct_sets_identity_and_loader() {
		$plugin = new Franer();

		$this->assertSame( 'franer', $plugin->get_plugin_name() );
		$this->assertSame( FRANER_VERSION, $plugin->get_version() );
		$this->assertInstanceOf( Franer_Loader::class, $plugin->get_loader() );
	}

	/**
	 * Constructing the plugin should require all of its dependency classes.
	 *
	 * @return void
	 */
	public function test_construct_loads_dependencies() {
		new Franer();

		foreach ( array( 'Franer_I18n', 'Franer_Post_Types', 'Franer_Rest_Controller', 'Franer_Admin', 'Franer_Public' ) as $class ) {
			$this->assertTrue( class_exists( $class ), "{$class} should be loaded by the core class." );
		}
	}

	/**
	 * run() should hand off to the loader without error and keep the same loader.
	 *
	 * @return void
	 */
	public function test_run_executes_via_loader() {
		$plugin = new Franer();
		$loader = $plugin->get_loader();

		$plugin->run();

		$this->assertSame( $loader, $plugin->get_loader() );
	}
}
