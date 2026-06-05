<?php
/**
 * PHPUnit bootstrap file for the Franer plugin.
 *
 * Uses the Yoast WPIntegration test utilities to boot a WordPress testing
 * environment and manually loads the Franer plugin.
 *
 * @package Franer
 */

use Yoast\WPTestUtils\WPIntegration;

require_once dirname( __DIR__ ) . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

$_tests_dir = WPIntegration\get_path_to_wp_test_dir();
if ( false === $_tests_dir ) {
	echo PHP_EOL . 'ERROR: The WordPress native unit test bootstrap file could not be found. '
		. 'Please set either the WP_TESTS_DIR or the WP_DEVELOP_DIR environment variable, '
		. 'either in your OS or in a custom phpunit.xml file.' . PHP_EOL;
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . 'includes/functions.php';

/**
 * Manually load the plugin being tested.
 *
 * @return void
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/franer.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Ensure the submissions table exists for the integration tests.
tests_add_filter(
	'setup_theme',
	static function () {
		require_once dirname( __DIR__ ) . '/includes/class-franer-activator.php';
		Franer_Activator::activate();
	}
);

// Start up the WP testing environment.
WPIntegration\bootstrap_it();

// Register the custom factories and the shared base test case. These depend on
// the WordPress test factory classes, so they are loaded after bootstrap_it().
require_once __DIR__ . '/includes/class-wp-unittest-factory-for-franer-site.php';
require_once __DIR__ . '/includes/class-wp-unittest-factory-for-franer-submission.php';
require_once __DIR__ . '/includes/class-franer-test-base.php';
