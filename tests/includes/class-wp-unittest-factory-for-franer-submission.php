<?php
/**
 * Custom factory for Franer submissions (a custom table, not a CPT).
 *
 * Delegates to Franer_Submissions_Repository::save_submission() so tests get
 * realistic rows without touching $wpdb directly.
 *
 * @package Franer
 */

/**
 * Class WP_UnitTest_Factory_For_Franer_Submission.
 *
 * Usage: $id = self::factory()->franer_submission->create( array( 'site_id' => $s, 'payload' => array( 'q1' => 'a' ) ) );
 */
class WP_UnitTest_Factory_For_Franer_Submission extends WP_UnitTest_Factory_For_Thing {

	/**
	 * Constructor.
	 *
	 * @param object|null $factory The global factory instance.
	 */
	public function __construct( $factory = null ) {
		parent::__construct( $factory );

		// Only scalar defaults belong here (generate_args() rejects array values);
		// the array payload is defaulted in create_object().
		$this->default_generation_definitions = array(
			'site_id'         => 0,
			'user_id'         => 0,
			'allow_multiple'  => true,
			'allow_overwrite' => false,
			'form_version'    => '',
		);
	}

	/**
	 * Insert a submission row via the repository.
	 *
	 * Missing site_id/user_id are created with the sibling factories so a bare
	 * create() call still yields a valid, related row.
	 *
	 * @param array $args Merged generation arguments.
	 * @return int|WP_Error The new submission ID, or WP_Error on failure.
	 */
	public function create_object( $args ) {
		if ( empty( $args['site_id'] ) ) {
			$args['site_id'] = $this->factory->franer_site->create();
		}

		if ( empty( $args['user_id'] ) ) {
			$args['user_id'] = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		}

		$payload = isset( $args['payload'] ) ? $args['payload'] : array( 'q1' => 'a' );

		$payload_json = is_string( $payload ) ? $payload : wp_json_encode( $payload );

		$repository = new Franer_Submissions_Repository();
		$result     = $repository->save_submission(
			(int) $args['site_id'],
			(int) $args['user_id'],
			$payload_json,
			(bool) $args['allow_multiple'],
			(bool) $args['allow_overwrite'],
			(string) $args['form_version']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['submission_id'] ) ? (int) $result['submission_id'] : 0;
	}

	/**
	 * Submissions are immutable fixtures here; updating is not supported.
	 *
	 * @param int   $object The submission ID.
	 * @param array $fields Ignored.
	 * @return int The unchanged submission ID.
	 */
	public function update_object( $object, $fields ) {
		return $object;
	}

	/**
	 * Return the submission ID (there is no public single-row getter).
	 *
	 * @param int $object_id The submission ID.
	 * @return int The same ID.
	 */
	public function get_object_by_id( $object_id ) {
		return (int) $object_id;
	}
}
