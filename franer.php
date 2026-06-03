<?php
/**
 * Franer is a secure environment to publish AI-generated forms.
 *
 * @link              https://github.com/ateeducacion/wp-franer
 * @package           Franer
 *
 * @wordpress-plugin
 * Plugin Name:       Franer
 * Plugin URI:        https://github.com/ateeducacion/wp-franer
 * Description:       Franer is a secure environment to publish AI-generated forms. It renders self-contained AI-generated HTML activities inside a sandboxed iframe and collects users' JSON submissions safely server-side.
 * Version:           0.0.0
 * Author:            Área de Tecnología Educativa
 * Author URI:        https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 * License:           GPL-3.0+
 * License URI:       https://www.gnu.org/licenses/gpl-3.0-standalone.html
 * Text Domain:       franer
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'FRANER_VERSION', '0.0.0' );
define( 'FRANER_PLUGIN_FILE', __FILE__ );
define( 'FRANER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FRANER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 *
 * @return void
 */
function franer_activate() {
	require_once FRANER_PLUGIN_DIR . 'includes/class-franer-activator.php';

	// Franer's pretty activity URLs (/franer/{slug}/) need a non-plain permalink
	// structure. Only enable one when the site is still on the plain default, so we
	// never overwrite an administrator's intentional choice (any non-plain structure
	// works with our rewrite rule, so a custom structure is left untouched). Record
	// that we set it so deactivation can restore the plain default.
	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( 'franer_set_permalink_structure', '1' );
	}

	Franer_Activator::activate();

	flush_rewrite_rules();

	update_option( 'franer_flush_rewrites', true );
	update_option( 'franer_version', FRANER_VERSION );
}

/**
 * The code that runs during plugin deactivation.
 *
 * @return void
 */
function franer_deactivate() {
	require_once FRANER_PLUGIN_DIR . 'includes/class-franer-deactivator.php';

	// Franer_Deactivator::deactivate() restores the permalink default (if Franer set
	// it) and flushes the rewrite rules.
	Franer_Deactivator::deactivate();
}

/**
 * Plugin update handler.
 *
 * @param WP_Upgrader $upgrader_object Upgrader object.
 * @param array       $options         Upgrade options.
 * @return void
 */
function franer_update_handler( $upgrader_object, $options ) {
	// Check if the update is for this specific plugin.
	if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
		$plugins_updated = $options['plugins'];

		$plugin_file = plugin_basename( __FILE__ );

		// Check if this plugin is in the list of updated plugins.
		if ( in_array( $plugin_file, $plugins_updated, true ) ) {
			flush_rewrite_rules();
		}
	}
}

register_activation_hook( __FILE__, 'franer_activate' );
register_deactivation_hook( __FILE__, 'franer_deactivate' );
add_action( 'upgrader_process_complete', 'franer_update_handler', 10, 2 );

/**
 * Maybe flush rewrite rules on init if needed.
 *
 * @return void
 */
function franer_maybe_flush_rewrite_rules() {
	$saved_version = get_option( 'franer_version' );

	// If plugin version changed, or a flag has been set (e.g. on activation), flush rules.
	if ( FRANER_VERSION !== $saved_version || get_option( 'franer_flush_rewrites' ) ) {
		flush_rewrite_rules();
		update_option( 'franer_version', FRANER_VERSION );
		delete_option( 'franer_flush_rewrites' );
	}
}
add_action( 'init', 'franer_maybe_flush_rewrite_rules', 999 );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once FRANER_PLUGIN_DIR . 'includes/class-franer.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @return void
 */
function franer_run() {
	$plugin = new Franer();
	$plugin->run();
}
franer_run();
