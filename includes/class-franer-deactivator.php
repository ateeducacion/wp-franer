<?php
/**
 * Fired during plugin deactivation.
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
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @package    Franer
 * @subpackage Franer/includes
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Franer_Deactivator {

	/**
	 * Flush rewrite rules on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
