<?php
/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
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
 * Define the internationalization functionality.
 *
 * @package    Franer
 * @subpackage Franer/includes
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Franer_I18n {

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @return void
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'franer',
			false,
			dirname( plugin_basename( FRANER_PLUGIN_FILE ) ) . '/languages/'
		);
	}
}
