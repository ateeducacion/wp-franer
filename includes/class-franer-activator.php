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
	 * Current database schema version.
	 *
	 * Bumped whenever the submissions table changes so installs upgraded in place
	 * (not just freshly activated) pick up the new schema via maybe_upgrade().
	 *
	 * @var string
	 */
	const DB_VERSION = '1.1';

	/**
	 * Create/upgrade the submissions table and store the database version.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_table();
		update_option( 'franer_db_version', self::DB_VERSION );
	}

	/**
	 * Run the schema upgrade when the stored DB version is behind.
	 *
	 * The dbDelta call is idempotent and only issues the ALTER TABLE statements
	 * needed for missing columns/keys, so existing rows and data are preserved.
	 * Hooked on a normal request (see franer.php) so plugin updates that don't
	 * re-activate the plugin still migrate the table.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION === get_option( 'franer_db_version' ) ) {
			return;
		}

		self::install_table();
		update_option( 'franer_db_version', self::DB_VERSION );
	}

	/**
	 * Create or migrate the submissions table via dbDelta.
	 *
	 * @return void
	 */
	private static function install_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . 'franer_submissions';
		$charset_collate = $wpdb->get_charset_collate();

		// form_version is a hash of the activity HTML at submission time and
		// site_modified_at the Franer's modified date then, so an admin can tell
		// which version of the form a submission was answered against.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			site_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			payload_json LONGTEXT NOT NULL,
			payload_hash CHAR(64) NOT NULL,
			ip_hash CHAR(64) NULL,
			user_agent_hash CHAR(64) NULL,
			form_version CHAR(64) NULL,
			site_modified_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY site_id (site_id),
			KEY user_id (user_id),
			KEY created_at (created_at),
			KEY payload_hash (payload_hash),
			KEY form_version (form_version)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
