<?php
/**
 * Fired during plugin activation.
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
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation,
 * including the creation of the submissions database table.
 *
 * @package    Franer
 * @subpackage Franer/includes
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Franer_Activator {

	/**
	 * Create the submissions table and store the database version.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . 'franer_submissions';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			site_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			payload_json LONGTEXT NOT NULL,
			payload_hash CHAR(64) NOT NULL,
			ip_hash CHAR(64) NULL,
			user_agent_hash CHAR(64) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY site_id (site_id),
			KEY user_id (user_id),
			KEY created_at (created_at),
			KEY payload_hash (payload_hash)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'franer_db_version', '1.0' );
	}
}
