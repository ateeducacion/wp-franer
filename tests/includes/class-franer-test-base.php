<?php
/**
 * Base test case for Franer plugin tests.
 *
 * Extends the WordPress factory with franer_site and franer_submission factories
 * (mirroring the wp-decker convention) and ensures the submissions table and the
 * custom post type are available in every test that needs them.
 *
 * @package Franer
 */

/**
 * Class Franer_Test_Base.
 *
 * Extend this instead of WP_UnitTestCase to get self::factory()->franer_site and
 * self::factory()->franer_submission.
 */
class Franer_Test_Base extends WP_UnitTestCase {

	/**
	 * Extend the core factory with Franer-specific factories.
	 *
	 * Registered lazily so the factory is only augmented when first requested.
	 *
	 * @return WP_UnitTest_Factory The extended factory object.
	 */
	public static function factory() {
		$factory = parent::factory();

		if ( ! isset( $factory->franer_site ) ) {
			$factory->franer_site = new WP_UnitTest_Factory_For_Franer_Site( $factory );
		}

		if ( ! isset( $factory->franer_submission ) ) {
			$factory->franer_submission = new WP_UnitTest_Factory_For_Franer_Submission( $factory );
		}

		return $factory;
	}

	/**
	 * Ensure the submissions table and the franer_site post type exist.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// Create the franer_submissions table (idempotent).
		Franer_Activator::activate();

		// Register the custom post type and its meta so meta-aware code paths work.
		( new Franer_Post_Types() )->register();
	}
}
