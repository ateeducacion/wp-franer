<?php
/**
 * Tests for Franer_Submission_Schema.
 *
 * @package Franer
 */

/**
 * Verifies label humanization, field-type inference and summary aggregation.
 */
class SubmissionSchemaTest extends WP_UnitTestCase {

	/**
	 * Keys are turned into readable, capitalized labels.
	 *
	 * @return void
	 */
	public function test_humanize_label() {
		$this->assertSame( 'Rating', Franer_Submission_Schema::humanize_label( 'rating' ) );
		$this->assertSame( 'Main dish', Franer_Submission_Schema::humanize_label( 'main_dish' ) );
		$this->assertSame( 'User comment', Franer_Submission_Schema::humanize_label( 'user-comment' ) );
		$this->assertSame( '', Franer_Submission_Schema::humanize_label( '' ) );
	}

	/**
	 * A 1..5 integer field is detected as a rating with avg and histogram.
	 *
	 * @return void
	 */
	public function test_infer_rating_field() {
		$payloads = array(
			array( 'rating' => 5 ),
			array( 'rating' => 4 ),
			array( 'rating' => 4 ),
			array( 'rating' => 3 ),
		);

		$fields = Franer_Submission_Schema::infer_fields( $payloads );

		$this->assertSame( 'rating', $fields['rating']['type'] );
		$this->assertSame( 4.0, $fields['rating']['avg'] );
		$this->assertSame( 1, $fields['rating']['hist'][5] );
		$this->assertSame( 2, $fields['rating']['hist'][4] );
		$this->assertSame( 1, $fields['rating']['hist'][3] );
		$this->assertSame( 0, $fields['rating']['hist'][1] );
	}

	/**
	 * Numbers outside 1..5 are a number field, not a rating.
	 *
	 * @return void
	 */
	public function test_infer_number_field() {
		$payloads = array(
			array( 'score' => 92 ),
			array( 'score' => 75 ),
		);

		$fields = Franer_Submission_Schema::infer_fields( $payloads );

		$this->assertSame( 'number', $fields['score']['type'] );
		$this->assertEqualsWithDelta( 83.5, $fields['score']['avg'], 0.001 );
	}

	/**
	 * Few short distinct strings make a category with a frequency distribution.
	 *
	 * @return void
	 */
	public function test_infer_category_field() {
		$payloads = array(
			array( 'mojo' => 'rojo' ),
			array( 'mojo' => 'verde' ),
			array( 'mojo' => 'rojo' ),
		);

		$fields = Franer_Submission_Schema::infer_fields( $payloads );

		$this->assertSame( 'category', $fields['mojo']['type'] );
		$this->assertSame( 2, $fields['mojo']['distribution']['rojo'] );
		$this->assertSame( 1, $fields['mojo']['distribution']['verde'] );
		// Most frequent value comes first.
		$this->assertSame( 'rojo', array_key_first( $fields['mojo']['distribution'] ) );
	}

	/**
	 * Long / high-cardinality strings are classified as free text.
	 *
	 * @return void
	 */
	public function test_infer_text_field() {
		$payloads = array(
			array( 'comment' => 'Me encantó la actividad, muy completa y entretenida de verdad.' ),
			array( 'comment' => 'El barraquito sin duda fue lo mejor de toda la propuesta.' ),
		);

		$fields = Franer_Submission_Schema::infer_fields( $payloads );

		$this->assertSame( 'text', $fields['comment']['type'] );
	}

	/**
	 * Sequential list values keep a field as 'other' (only maps are flattened).
	 *
	 * @return void
	 */
	public function test_infer_other_field_for_list_values() {
		$payloads = array(
			array( 'tags' => array( 'a', 'b', 'c' ) ),
		);

		$fields = Franer_Submission_Schema::infer_fields( $payloads );

		$this->assertSame( 'other', $fields['tags']['type'] );
	}

	/**
	 * Short but all-distinct values (comments) are text, not a category.
	 *
	 * @return void
	 */
	public function test_infer_short_unique_values_are_text() {
		$payloads = array(
			array( 'comentario' => 'Todo perfecto.' ),
			array( 'comentario' => 'El barraquito sin duda.' ),
			array( 'comentario' => 'Las papas no me convencen.' ),
		);

		$fields = Franer_Submission_Schema::infer_fields( $payloads );

		$this->assertSame( 'text', $fields['comentario']['type'] );
	}

	/**
	 * Single-word repeated answers stay categorical.
	 *
	 * @return void
	 */
	public function test_infer_single_word_answers_are_category() {
		$payloads = array(
			array( 'papas' => 'si' ),
			array( 'papas' => 'no' ),
			array( 'papas' => 'si' ),
		);

		$fields = Franer_Submission_Schema::infer_fields( $payloads );

		$this->assertSame( 'category', $fields['papas']['type'] );
	}

	/**
	 * Nested answers (e.g. {"answers":{...}}) are flattened to dotted fields.
	 *
	 * @return void
	 */
	public function test_infer_flattens_nested_answers() {
		$payloads = array(
			array(
				'answers'    => array( 'mojo' => 'rojo' ),
				'rating'     => 5,
				'comentario' => '',
			),
			array(
				'answers'    => array( 'mojo' => 'verde' ),
				'rating'     => 4,
				'comentario' => '',
			),
		);

		$fields = Franer_Submission_Schema::infer_fields( $payloads );

		$this->assertArrayHasKey( 'answers.mojo', $fields );
		$this->assertSame( 'category', $fields['answers.mojo']['type'] );
		// The label uses only the last dotted segment.
		$this->assertSame( 'Mojo', $fields['answers.mojo']['label'] );
		$this->assertSame( 'rating', $fields['rating']['type'] );
	}

	/**
	 * build_summary aggregates totals, the rating card, comments and stats.
	 *
	 * @return void
	 */
	public function test_build_summary() {
		$rows = array(
			array(
				'user'       => 'lucia',
				'created_at' => '2026-06-05 13:13:00',
				'payload'    => array(
					'mojo'    => 'rojo',
					'rating'  => 5,
					'comment' => 'Una actividad fantástica para conocer la cultura canaria.',
				),
			),
			array(
				'user'       => 'pedro',
				'created_at' => '2026-06-05 12:11:00',
				'payload'    => array(
					'mojo'    => 'verde',
					'rating'  => 3,
					'comment' => '',
				),
			),
		);

		$summary = Franer_Submission_Schema::build_summary( $rows );

		$this->assertSame( 2, $summary['total'] );
		$this->assertSame( '2026-06-05 13:13:00', $summary['last_created'] );
		$this->assertNotNull( $summary['rating'] );
		$this->assertEqualsWithDelta( 4.0, $summary['rating']['avg'], 0.001 );
		$this->assertCount( 1, $summary['comments'] );
		$this->assertSame( 'lucia', $summary['comments'][0]['user'] );
		// The mojo category shows up among the distributions.
		$keys = wp_list_pluck( $summary['distributions'], 'key' );
		$this->assertContains( 'mojo', $keys );
		// Stats strip carries at least total + last response.
		$stat_keys = wp_list_pluck( $summary['stats'], 'key' );
		$this->assertContains( 'total', $stat_keys );
		$this->assertContains( 'rating', $stat_keys );
		$this->assertContains( 'comments', $stat_keys );
		$this->assertContains( 'last', $stat_keys );
	}

	/**
	 * Preview field selection prefers categories and excludes rating/text.
	 *
	 * @return void
	 */
	public function test_select_preview_fields() {
		$payloads = array(
			array(
				'mojo'    => 'rojo',
				'bebida'  => 'barraquito',
				'rating'  => 5,
				'comment' => 'Texto bastante largo que debería clasificarse como comentario libre.',
			),
		);

		$fields  = Franer_Submission_Schema::infer_fields( $payloads );
		$preview = Franer_Submission_Schema::select_preview_fields( $fields, 3 );
		$keys    = wp_list_pluck( $preview, 'key' );

		$this->assertContains( 'mojo', $keys );
		$this->assertContains( 'bebida', $keys );
		$this->assertNotContains( 'rating', $keys );
		$this->assertNotContains( 'comment', $keys );
	}
}
