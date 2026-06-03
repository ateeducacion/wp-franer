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
	 * Restore the plain permalink default (if Franer set it) and flush rewrite rules.
	 *
	 * If Franer enabled pretty permalinks on activation (because the site was on the
	 * plain default) and the structure is still exactly the one we set, restore the
	 * plain default so deactivation leaves no unexpected site-wide side effects. An
	 * administrator who has since chosen a different structure is left untouched.
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( '1' === (string) get_option( 'franer_set_permalink_structure' ) ) {
			if ( '/%postname%/' === (string) get_option( 'permalink_structure' ) ) {
				update_option( 'permalink_structure', '' );
			}
			delete_option( 'franer_set_permalink_structure' );
		}

		flush_rewrite_rules();
	}
}
