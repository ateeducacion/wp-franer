<?php
/**
 * Tests for the Franer_Loader hook collector.
 *
 * @package Franer
 */

/**
 * Verifies that queued actions and filters are registered with WordPress on run().
 */
class LoaderTest extends WP_UnitTestCase {

	/**
	 * Queued hooks should not be registered until run() is called, and then
	 * registered with the requested priority.
	 *
	 * @return void
	 */
	public function test_run_registers_queued_actions_and_filters() {
		$loader    = new Franer_Loader();
		$component = new class() {
			/**
			 * Dummy callback.
			 *
			 * @return void
			 */
			public function cb() {}
		};

		$loader->add_action( 'franer_loader_test_action', $component, 'cb', 20, 2 );
		$loader->add_filter( 'franer_loader_test_filter', $component, 'cb' );

		// Nothing is registered with WordPress until run() executes.
		$this->assertFalse( has_action( 'franer_loader_test_action', array( $component, 'cb' ) ) );
		$this->assertFalse( has_filter( 'franer_loader_test_filter', array( $component, 'cb' ) ) );

		$loader->run();

		// The action keeps its custom priority; the filter falls back to the default 10.
		$this->assertSame( 20, has_action( 'franer_loader_test_action', array( $component, 'cb' ) ) );
		$this->assertSame( 10, has_filter( 'franer_loader_test_filter', array( $component, 'cb' ) ) );
	}
}
