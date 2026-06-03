<?php
/**
 * Tests for Franer_Submissions_Repository.
 *
 * @package Franer
 */

/**
 * Verifies saving, fetching, duplicate handling, overwrite and export.
 */
class SubmissionsRepositoryTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var Franer_Submissions_Repository
	 */
	private $repo;

	/**
	 * A site post ID.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * A user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up the table, repository and fixtures.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Franer_Activator::activate();

		$this->repo    = new Franer_Submissions_Repository();
		$this->site_id = self::factory()->post->create( array( 'post_type' => 'franer_site' ) );
		$this->user_id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'tester',
				'user_email' => 'tester@example.test',
			)
		);
	}

	/**
	 * get_table_name should include the table prefix.
	 *
	 * @return void
	 */
	public function test_get_table_name() {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'franer_submissions', Franer_Submissions_Repository::get_table_name() );
	}

	/**
	 * Saving then fetching should round-trip the payload.
	 *
	 * @return void
	 */
	public function test_save_then_fetch() {
		$payload = wp_json_encode( array( 'q1' => 'a' ) );

		$result = $this->repo->save_submission( $this->site_id, $this->user_id, $payload, false, false );

		$this->assertIsArray( $result );
		$this->assertSame( 'saved', $result['status'] );
		$this->assertGreaterThan( 0, $result['submission_id'] );

		$row = $this->repo->get_submission( $result['submission_id'] );
		$this->assertNotNull( $row );
		$this->assertSame( $payload, $row['payload_json'] );
		$this->assertSame( hash( 'sha256', $payload ), $row['payload_hash'] );
		$this->assertNull( $row['updated_at'] );
	}

	/**
	 * delete_submission should remove a single row.
	 *
	 * @return void
	 */
	public function test_delete_submission() {
		$result = $this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'q1' => 'a' ) ), false, false );
		$id     = (int) $result['submission_id'];

		$this->assertSame( 1, $this->repo->delete_submission( $id ) );
		$this->assertNull( $this->repo->get_submission( $id ) );
	}

	/**
	 * update_submission should replace the payload, rehash and set updated_at.
	 *
	 * @return void
	 */
	public function test_update_submission() {
		$result = $this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'q1' => 'a' ) ), false, false );
		$id     = (int) $result['submission_id'];

		$new_payload = wp_json_encode( array( 'q1' => 'edited' ) );
		$this->assertSame( 1, $this->repo->update_submission( $id, $new_payload ) );

		$row = $this->repo->get_submission( $id );
		$this->assertSame( $new_payload, $row['payload_json'] );
		$this->assertSame( hash( 'sha256', $new_payload ), $row['payload_hash'] );
		$this->assertNotNull( $row['updated_at'] );
	}

	/**
	 * get_latest_user_submission should return the most recent row.
	 *
	 * @return void
	 */
	public function test_get_latest_user_submission() {
		$this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'n' => 1 ) ), true, false );
		$second = $this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'n' => 2 ) ), true, false );

		$latest = $this->repo->get_latest_user_submission( $this->site_id, $this->user_id );

		$this->assertSame( (int) $second['submission_id'], (int) $latest['id'] );
		$this->assertSame( array( 'n' => 2 ), json_decode( $latest['payload_json'], true ) );
	}

	/**
	 * A duplicate with multiple disallowed and overwrite disallowed should 409.
	 *
	 * @return void
	 */
	public function test_duplicate_without_multiple_returns_conflict() {
		$this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'n' => 1 ) ), false, false );

		$dup = $this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'n' => 2 ) ), false, false );

		$this->assertWPError( $dup );
		$this->assertSame( 'franer_duplicate', $dup->get_error_code() );
		$data = $dup->get_error_data();
		$this->assertSame( 409, $data['status'] );
	}

	/**
	 * Overwrite should update the existing row and report 'updated'.
	 *
	 * @return void
	 */
	public function test_overwrite_updates_existing() {
		$first = $this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'n' => 1 ) ), false, false );

		$updated = $this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'n' => 2 ) ), false, true );

		$this->assertIsArray( $updated );
		$this->assertSame( 'updated', $updated['status'] );
		$this->assertSame( (int) $first['submission_id'], (int) $updated['submission_id'] );

		$row = $this->repo->get_submission( $updated['submission_id'] );
		$this->assertSame( array( 'n' => 2 ), json_decode( $row['payload_json'], true ) );
		$this->assertNotNull( $row['updated_at'] );

		$this->assertSame( 1, $this->repo->count_site_submissions( $this->site_id ) );
	}

	/**
	 * Multiple submissions should each insert a new row.
	 *
	 * @return void
	 */
	public function test_multiple_submissions_insert_rows() {
		$this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'n' => 1 ) ), true, false );
		$this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'n' => 2 ) ), true, false );

		$this->assertSame( 2, $this->repo->count_site_submissions( $this->site_id ) );
	}

	/**
	 * delete_site_submissions should remove all rows for the site.
	 *
	 * @return void
	 */
	public function test_delete_site_submissions() {
		$this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'n' => 1 ) ), true, false );
		$this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'n' => 2 ) ), true, false );

		$deleted = $this->repo->delete_site_submissions( $this->site_id );

		$this->assertSame( 2, $deleted );
		$this->assertSame( 0, $this->repo->count_site_submissions( $this->site_id ) );
	}

	/**
	 * When the per-user lock cannot be acquired, a single submission must fail with
	 * a 503 rather than fall back to the unguarded (racy) read-then-write.
	 *
	 * @return void
	 */
	public function test_lock_failure_returns_503_and_writes_nothing() {
		// A repository whose lock acquisition always fails, simulating contention.
		$repo = new class() extends Franer_Submissions_Repository {
			/**
			 * Always fail to acquire the lock.
			 *
			 * @param int $site_id The site post ID.
			 * @param int $user_id The user ID.
			 * @return bool Always false.
			 */
			protected function acquire_user_lock( $site_id, $user_id ) {
				return false;
			}
		};

		$result = $repo->save_submission(
			$this->site_id,
			$this->user_id,
			wp_json_encode( array( 'q1' => 'a' ) ),
			false,
			false
		);

		$this->assertWPError( $result );
		$this->assertSame( 'franer_lock_failed', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );

		// The failed save must not have written a row.
		$this->assertSame( 0, $this->repo->count_site_submissions( $this->site_id ) );
	}

	/**
	 * Multiple-submission saves bypass the lock entirely, so a lock failure does not
	 * affect them.
	 *
	 * @return void
	 */
	public function test_lock_failure_does_not_block_multiple_submissions() {
		$repo = new class() extends Franer_Submissions_Repository {
			/**
			 * Always fail to acquire the lock.
			 *
			 * @param int $site_id The site post ID.
			 * @param int $user_id The user ID.
			 * @return bool Always false.
			 */
			protected function acquire_user_lock( $site_id, $user_id ) {
				return false;
			}
		};

		$result = $repo->save_submission(
			$this->site_id,
			$this->user_id,
			wp_json_encode( array( 'q1' => 'a' ) ),
			true,
			false
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'saved', $result['status'] );
		$this->assertSame( 1, $this->repo->count_site_submissions( $this->site_id ) );
	}

	/**
	 * Export should return rows with decoded payload and user data.
	 *
	 * @return void
	 */
	public function test_export_shape() {
		$this->repo->save_submission( $this->site_id, $this->user_id, wp_json_encode( array( 'colour' => 'blue' ) ), true, false );

		$export = $this->repo->export_site_submissions( $this->site_id );

		$this->assertIsArray( $export );
		$this->assertCount( 1, $export );

		$entry = $export[0];
		$this->assertSame( $this->site_id, $entry['site_id'] );
		$this->assertSame( $this->user_id, $entry['user_id'] );
		$this->assertSame( 'tester', $entry['user_login'] );
		$this->assertSame( 'tester@example.test', $entry['user_email'] );
		$this->assertSame( array( 'colour' => 'blue' ), $entry['payload'] );
	}
}
