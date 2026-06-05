<?php
/**
 * Tests for the Franer_Export_Controller JSON export controller.
 *
 * Exercises the capability/validation guard clauses (which terminate via
 * wp_die(), surfaced as a WPDieException in the test environment) and the
 * private row-normalization helper. The streamed success path calls exit() and
 * is intentionally left to the E2E suite.
 *
 * @package Franer
 */

/**
 * Verifies access control, input validation and row normalization.
 */
class ExportControllerTest extends WP_UnitTestCase {

	/**
	 * An administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Create an administrator for the authorized paths.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Reset the request superglobal between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_REQUEST['site_id'], $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * A user without manage_options should be denied.
	 *
	 * @return void
	 */
	public function test_handle_denies_without_capability() {
		wp_set_current_user( 0 );

		$this->expectException( WPDieException::class );
		( new Franer_Export_Controller() )->handle();
	}

	/**
	 * A missing or non-positive site identifier should be rejected.
	 *
	 * @return void
	 */
	public function test_handle_rejects_invalid_site_id() {
		wp_set_current_user( $this->admin_id );
		unset( $_REQUEST['site_id'] );

		$this->expectException( WPDieException::class );
		( new Franer_Export_Controller() )->handle();
	}

	/**
	 * A valid request for a non-existent site should be rejected after the nonce
	 * check passes.
	 *
	 * @return void
	 */
	public function test_handle_rejects_missing_site() {
		wp_set_current_user( $this->admin_id );
		$site_id              = 999999;
		$_REQUEST['site_id']  = $site_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'franer_export_' . $site_id );

		$this->expectException( WPDieException::class );
		( new Franer_Export_Controller() )->handle();
	}

	/**
	 * Invoke the private format_row() helper.
	 *
	 * @param array $row Raw submission row.
	 * @return array Normalized row.
	 */
	private function format_row( array $row ) {
		$method = new ReflectionMethod( Franer_Export_Controller::class, 'format_row' );
		$method->setAccessible( true );
		return $method->invoke( new Franer_Export_Controller(), $row );
	}

	/**
	 * format_row() should coerce identifiers to ints and preserve payloads.
	 *
	 * @return void
	 */
	public function test_format_row_normalizes_present_fields() {
		$out = $this->format_row(
			array(
				'id'         => '5',
				'user_id'    => '7',
				'user_login' => 'ana',
				'user_email' => 'ana@example.com',
				'created_at' => '2026-06-01 10:00:00',
				'updated_at' => '2026-06-02 11:00:00',
				'payload'    => array( 'a' => 1 ),
			)
		);

		$this->assertSame( 5, $out['id'] );
		$this->assertSame( 7, $out['user_id'] );
		$this->assertSame( 'ana', $out['user_login'] );
		$this->assertSame( 'ana@example.com', $out['user_email'] );
		$this->assertSame( '2026-06-02 11:00:00', $out['updated_at'] );
		$this->assertSame( array( 'a' => 1 ), $out['payload'] );
	}

	/**
	 * format_row() should fall back to safe defaults for missing fields.
	 *
	 * @return void
	 */
	public function test_format_row_defaults_missing_fields() {
		$out = $this->format_row( array() );

		$this->assertSame( 0, $out['id'] );
		$this->assertSame( 0, $out['user_id'] );
		$this->assertSame( '', $out['user_login'] );
		$this->assertSame( '', $out['user_email'] );
		$this->assertSame( '', $out['created_at'] );
		$this->assertNull( $out['updated_at'] );
		$this->assertNull( $out['payload'] );
	}
}
