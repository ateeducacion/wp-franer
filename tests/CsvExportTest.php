<?php
/**
 * Tests for the Franer CSV export controller helpers.
 *
 * Exercises the static table-building and RFC 4180 rendering logic directly, so
 * no HTTP request or stream/exit is involved.
 *
 * @package Franer
 */

/**
 * Verifies CSV column flattening, the column union, and RFC 4180 quoting.
 */
class CsvExportTest extends WP_UnitTestCase {

	/**
	 * Nested payload keys should be flattened with dot notation.
	 *
	 * @return void
	 */
	public function test_flatten_payload_uses_dot_notation_for_nested_keys() {
		$flat = Franer_Csv_Export_Controller::flatten_payload(
			array(
				'score'   => 8,
				'scores'  => array( 'math' => 9 ),
				'answers' => array( 'first', 'second' ),
				'passed'  => true,
				'failed'  => false,
				'note'    => null,
			)
		);

		$this->assertSame( '8', $flat['score'] );
		$this->assertSame( '9', $flat['scores.math'] );
		$this->assertSame( 'first', $flat['answers.0'] );
		$this->assertSame( 'second', $flat['answers.1'] );
		$this->assertSame( '1', $flat['passed'] );
		$this->assertSame( '0', $flat['failed'] );
		$this->assertSame( '', $flat['note'] );
	}

	/**
	 * The table should union payload keys across rows and fill missing cells.
	 *
	 * @return void
	 */
	public function test_build_table_unions_payload_keys_across_rows() {
		$rows = array(
			array(
				'id'      => 1,
				'payload' => array( 'a' => '1' ),
			),
			array(
				'id'      => 2,
				'payload' => array( 'b' => '2' ),
			),
		);

		$table = Franer_Csv_Export_Controller::build_table( $rows );

		$this->assertContains( 'payload.a', $table['header'] );
		$this->assertContains( 'payload.b', $table['header'] );

		$a_index = array_search( 'payload.a', $table['header'], true );
		$b_index = array_search( 'payload.b', $table['header'], true );

		// Row 1 has a but not b; row 2 has b but not a.
		$this->assertSame( '1', $table['data'][0][ $a_index ] );
		$this->assertSame( '', $table['data'][0][ $b_index ] );
		$this->assertSame( '', $table['data'][1][ $a_index ] );
		$this->assertSame( '2', $table['data'][1][ $b_index ] );
	}

	/**
	 * The fixed metadata columns should always lead, before the payload columns.
	 *
	 * @return void
	 */
	public function test_build_table_keeps_fixed_leading_columns() {
		$table = Franer_Csv_Export_Controller::build_table(
			array(
				array(
					'id'         => 7,
					'user_id'    => 42,
					'user_login' => 'ana',
					'user_email' => 'ana@example.com',
					'created_at' => '2026-06-01 10:00:00',
					'updated_at' => null,
					'payload'    => array( 'q1' => 'yes' ),
				),
			)
		);

		$expected_head = array( 'id', 'user_id', 'user_login', 'user_email', 'created_at', 'updated_at', 'payload.q1' );
		$this->assertSame( $expected_head, $table['header'] );

		// A null updated_at becomes an empty cell.
		$this->assertSame( array( '7', '42', 'ana', 'ana@example.com', '2026-06-01 10:00:00', '', 'yes' ), $table['data'][0] );
	}

	/**
	 * A round trip through str_getcsv should recover the original cell values.
	 *
	 * @return void
	 */
	public function test_table_to_csv_round_trips_with_semicolon() {
		$table = array(
			'header' => array( 'id', 'payload.q1' ),
			'data'   => array(
				array( '1', 'plain' ),
				array( '2', 'a;b' ),
			),
		);

		$csv   = Franer_Csv_Export_Controller::table_to_csv( $table, ';' );
		$lines = explode( "\r\n", rtrim( $csv, "\r\n" ) );

		$this->assertSame( array( 'id', 'payload.q1' ), str_getcsv( $lines[0], ';' ) );
		$this->assertSame( array( '1', 'plain' ), str_getcsv( $lines[1], ';' ) );
		// The semicolon inside the value must survive (it was quoted).
		$this->assertSame( array( '2', 'a;b' ), str_getcsv( $lines[2], ';' ) );
	}

	/**
	 * Fields with delimiters, quotes or newlines must be RFC 4180 quoted, and
	 * records joined with CRLF.
	 *
	 * @return void
	 */
	public function test_rfc4180_quoting() {
		$table = array(
			'header' => array( 'id', 'text' ),
			'data'   => array(
				array( '1', 'He said "hi"' ),
				array( '2', "line1\nline2" ),
				array( '3', 'a;b' ),
			),
		);

		$csv = Franer_Csv_Export_Controller::table_to_csv( $table, ';' );

		// Records are CRLF separated, including a trailing terminator.
		$this->assertStringContainsString( "id;text\r\n", $csv );

		// Inner double quotes are doubled and the field is wrapped in quotes.
		$this->assertStringContainsString( '"He said ""hi"""', $csv );

		// A field with a newline is quoted (keeping the embedded LF).
		$this->assertStringContainsString( "\"line1\nline2\"", $csv );

		// A field containing the delimiter is quoted.
		$this->assertStringContainsString( '"a;b"', $csv );
	}
}
