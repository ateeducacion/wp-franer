<?php
/**
 * Tests for the Franer activation routine.
 *
 * @package Franer
 */

/**
 * Verifies that activation creates the submissions table.
 */
class ActivationTest extends WP_UnitTestCase {

	/**
	 * The submissions table should exist after activation runs.
	 *
	 * @return void
	 */
	public function test_activation_creates_submissions_table() {
		global $wpdb;

		Franer_Activator::activate();

		$table = $wpdb->prefix . 'franer_submissions';

		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		$this->assertSame( $table, $found, 'The franer_submissions table should exist after activation.' );
	}

	/**
	 * Activation should store the database version option.
	 *
	 * @return void
	 */
	public function test_activation_stores_db_version() {
		Franer_Activator::activate();

		$this->assertSame( '1.0', get_option( 'franer_db_version' ) );
	}

	/**
	 * The submissions table should expose the expected columns.
	 *
	 * @return void
	 */
	public function test_submissions_table_has_expected_columns() {
		global $wpdb;

		Franer_Activator::activate();

		$table   = $wpdb->prefix . 'franer_submissions';
		$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB

		$expected = array(
			'id',
			'site_id',
			'user_id',
			'payload_json',
			'payload_hash',
			'ip_hash',
			'user_agent_hash',
			'created_at',
			'updated_at',
		);

		foreach ( $expected as $column ) {
			$this->assertContains( $column, $columns, "Column {$column} should exist." );
		}
	}
}
