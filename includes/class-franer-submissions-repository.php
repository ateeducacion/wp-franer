<?php
/**
 * Repository for Franer submissions.
 *
 * @package Franer
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Reads and writes submission rows in the franer_submissions table.
 */
class Franer_Submissions_Repository {

	/**
	 * Get the fully-qualified submissions table name.
	 *
	 * @return string The table name including the WordPress prefix.
	 */
	public static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'franer_submissions';
	}

	/**
	 * Whether exported rows should include the user email.
	 *
	 * Isolated so the behavior can be flipped in one place.
	 *
	 * @return bool True to include the email in exports.
	 */
	private function include_email() {
		return true;
	}

	/**
	 * Compute the SHA-256 hash for a payload string.
	 *
	 * @param string $payload_json The JSON payload string.
	 * @return string The hexadecimal hash.
	 */
	private function payload_hash( $payload_json ) {
		return hash( 'sha256', (string) $payload_json );
	}

	/**
	 * Compute the hashed value of a $_SERVER variable.
	 *
	 * @param string $key The $_SERVER key to read.
	 * @return string|null The hashed value, or null when not present.
	 */
	private function hashed_server_value( $key ) {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return null;
		}

		$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );

		if ( '' === $value ) {
			return null;
		}

		return wp_hash( $value );
	}

	/**
	 * Save (insert, update, or reject) a submission.
	 *
	 * @param int    $site_id         The site post ID.
	 * @param int    $user_id         The submitting user ID.
	 * @param string $payload_json    The JSON payload string.
	 * @param bool   $allow_multiple  Whether multiple submissions are allowed.
	 * @param bool   $allow_overwrite Whether overwriting the latest is allowed.
	 * @return array|WP_Error Result array, or WP_Error on duplicate/failure.
	 */
	public function save_submission( $site_id, $user_id, $payload_json, $allow_multiple, $allow_overwrite ) {
		$site_id      = (int) $site_id;
		$user_id      = (int) $user_id;
		$payload_json = (string) $payload_json;

		// Multiple-submission sites always insert a fresh row, so concurrent saves
		// never race against each other and no lock is needed.
		if ( $allow_multiple ) {
			return $this->insert_submission( $site_id, $user_id, $payload_json );
		}

		// Single-submission sites decide between insert / overwrite / reject based on
		// an existing row. That read-then-write is a TOCTOU race: two concurrent
		// requests could both observe "no row" and both insert (bypassing duplicate
		// handling) or clobber each other on overwrite. Serialize per (site, user)
		// with a MySQL advisory lock so the check and the write are atomic across
		// concurrent requests, without a schema change that would break multiple
		// submissions.
		//
		// GET_LOCK is MySQL-only. On databases without advisory locks (e.g. SQLite,
		// used by WordPress Playground) acquisition just returns false; we then fall
		// back to a best-effort save rather than failing the request — that is the
		// pre-existing behavior and no worse than not holding a lock. Under real
		// MySQL contention the second request blocks until the first releases
		// (milliseconds), so the lock does its job in practice.
		$locked = $this->acquire_user_lock( $site_id, $user_id );

		try {
			return $this->save_single_submission( $site_id, $user_id, $payload_json, $allow_overwrite );
		} finally {
			if ( $locked ) {
				$this->release_user_lock( $site_id, $user_id );
			}
		}
	}

	/**
	 * Insert/overwrite a single submission for a one-per-user site.
	 *
	 * Must be called while holding the per-user lock (see save_submission()).
	 *
	 * @param int    $site_id         The site post ID.
	 * @param int    $user_id         The submitting user ID.
	 * @param string $payload_json    The JSON payload string.
	 * @param bool   $allow_overwrite Whether overwriting the latest is allowed.
	 * @return array|WP_Error Result array, or WP_Error on duplicate/failure.
	 */
	private function save_single_submission( $site_id, $user_id, $payload_json, $allow_overwrite ) {
		global $wpdb;

		$existing = $this->get_latest_user_submission( $site_id, $user_id );

		if ( $existing ) {
			if ( ! $allow_overwrite ) {
				return new WP_Error(
					'franer_duplicate',
					__( 'Duplicate submission not allowed', 'franer' ),
					array( 'status' => 409 )
				);
			}

			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::get_table_name(),
				array(
					'payload_json' => $payload_json,
					'payload_hash' => $this->payload_hash( $payload_json ),
					'updated_at'   => current_time( 'mysql' ),
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				return new WP_Error(
					'franer_db_error',
					__( 'Could not update the submission.', 'franer' ),
					array( 'status' => 500 )
				);
			}

			return array(
				'submission_id' => (int) $existing['id'],
				'status'        => 'updated',
			);
		}

		return $this->insert_submission( $site_id, $user_id, $payload_json );
	}

	/**
	 * Insert a new submission row.
	 *
	 * @param int    $site_id      The site post ID.
	 * @param int    $user_id      The submitting user ID.
	 * @param string $payload_json The JSON payload string.
	 * @return array|WP_Error Result array, or WP_Error on failure.
	 */
	private function insert_submission( $site_id, $user_id, $payload_json ) {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::get_table_name(),
			array(
				'site_id'         => $site_id,
				'user_id'         => $user_id,
				'payload_json'    => $payload_json,
				'payload_hash'    => $this->payload_hash( $payload_json ),
				'ip_hash'         => $this->hashed_server_value( 'REMOTE_ADDR' ),
				'user_agent_hash' => $this->hashed_server_value( 'HTTP_USER_AGENT' ),
				'created_at'      => current_time( 'mysql' ),
				'updated_at'      => null,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'franer_db_error',
				__( 'Could not save the submission.', 'franer' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'submission_id' => (int) $wpdb->insert_id,
			'status'        => 'saved',
		);
	}

	/**
	 * Build the MySQL advisory-lock name for a (site, user) pair.
	 *
	 * Hashed so it stays within MySQL's 64-character lock-name limit and is unique
	 * per database, site and user.
	 *
	 * @param int $site_id The site post ID.
	 * @param int $user_id The user ID.
	 * @return string The lock name.
	 */
	private function user_lock_name( $site_id, $user_id ) {
		global $wpdb;

		return 'franer_sub_' . md5( $wpdb->dbname . '|' . (int) $site_id . '|' . (int) $user_id );
	}

	/**
	 * Acquire the per-user advisory lock, waiting briefly if another request holds it.
	 *
	 * GET_LOCK is MySQL-only and returns 1 on success, 0 on timeout and NULL on
	 * error. On databases without advisory locks (e.g. SQLite, used by WordPress
	 * Playground) the query fails harmlessly; the error is suppressed and this
	 * returns false so save_submission() falls back to a best-effort save. Protected
	 * so tests can simulate the lock being unavailable.
	 *
	 * @param int $site_id The site post ID.
	 * @param int $user_id The user ID.
	 * @return bool True when the lock was acquired.
	 */
	protected function acquire_user_lock( $site_id, $user_id ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$got      = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->user_lock_name( $site_id, $user_id ), 10 )
		);
		$wpdb->suppress_errors( $suppress );

		return '1' === (string) $got;
	}

	/**
	 * Release the per-user advisory lock (no-op on databases without GET_LOCK).
	 *
	 * @param int $site_id The site post ID.
	 * @param int $user_id The user ID.
	 * @return void
	 */
	protected function release_user_lock( $site_id, $user_id ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->user_lock_name( $site_id, $user_id ) )
		);
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Get a single submission by ID.
	 *
	 * @param int $submission_id The submission ID.
	 * @return array|null The submission row, or null when not found.
	 */
	public function get_submission( $submission_id ) {
		global $wpdb;

		$submission_id = (int) $submission_id;
		$table         = self::get_table_name();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$submission_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Get the latest submission for a user on a site.
	 *
	 * @param int $site_id The site post ID.
	 * @param int $user_id The user ID.
	 * @return array|null The submission row, or null when none exists.
	 */
	public function get_latest_user_submission( $site_id, $user_id ) {
		global $wpdb;

		$site_id = (int) $site_id;
		$user_id = (int) $user_id;
		$table   = self::get_table_name();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE site_id = %d AND user_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$site_id,
				$user_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Get a page of submissions for a site.
	 *
	 * @param int $site_id The site post ID.
	 * @param int $limit   Maximum rows to return.
	 * @param int $offset  Row offset.
	 * @return array List of submission rows.
	 */
	public function get_site_submissions( $site_id, $limit = 50, $offset = 0 ) {
		global $wpdb;

		$site_id = (int) $site_id;
		$limit   = (int) $limit;
		$offset  = (int) $offset;
		$table   = self::get_table_name();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE site_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$site_id,
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count submissions for a site.
	 *
	 * @param int $site_id The site post ID.
	 * @return int The number of submissions.
	 */
	public function count_site_submissions( $site_id ) {
		global $wpdb;

		$site_id = (int) $site_id;
		$table   = self::get_table_name();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE site_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$site_id
			)
		);
	}

	/**
	 * Delete all submissions for a site.
	 *
	 * @param int $site_id The site post ID.
	 * @return int The number of rows deleted.
	 */
	public function delete_site_submissions( $site_id ) {
		global $wpdb;

		$site_id = (int) $site_id;
		$table   = self::get_table_name();

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE site_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$site_id
			)
		);

		return (int) $deleted;
	}

	/**
	 * Delete a single submission by ID.
	 *
	 * @param int $submission_id The submission ID.
	 * @return int Number of rows deleted (0 or 1).
	 */
	public function delete_submission( $submission_id ) {
		global $wpdb;

		$table   = self::get_table_name();
		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array( 'id' => (int) $submission_id ),
			array( '%d' )
		);

		return (int) $deleted;
	}

	/**
	 * Update the stored payload of a single submission.
	 *
	 * Recomputes the payload hash and sets updated_at. The caller is responsible
	 * for validating that $payload_json is a valid JSON object before saving.
	 *
	 * @param int    $submission_id The submission ID.
	 * @param string $payload_json  The new JSON payload string.
	 * @return int Number of rows updated (0 or 1).
	 */
	public function update_submission( $submission_id, $payload_json ) {
		global $wpdb;

		$table   = self::get_table_name();
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'payload_json' => $payload_json,
				'payload_hash' => hash( 'sha256', $payload_json ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => (int) $submission_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return (int) $updated;
	}

	/**
	 * Export all submissions for a site with decoded payloads and user data.
	 *
	 * @param int $site_id The site post ID.
	 * @return array List of export rows with decoded payload and user info.
	 */
	public function export_site_submissions( $site_id ) {
		$site_id      = (int) $site_id;
		$rows         = $this->get_site_submissions( $site_id, PHP_INT_MAX, 0 );
		$include_mail = $this->include_email();
		$export       = array();

		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row['user_id'] );

			$entry = array(
				'id'         => (int) $row['id'],
				'site_id'    => (int) $row['site_id'],
				'user_id'    => (int) $row['user_id'],
				'user_login' => $user ? $user->user_login : '',
				'payload'    => json_decode( (string) $row['payload_json'], true ),
				'created_at' => $row['created_at'],
				'updated_at' => $row['updated_at'],
			);

			if ( $include_mail ) {
				$entry['user_email'] = $user ? $user->user_email : '';
			}

			$export[] = $entry;
		}

		/**
		 * Filters the Franer export rows for a site.
		 *
		 * Receives decoded payloads and user metadata prepared for export. Use it
		 * to add calculated fields, remove fields, anonymize data, or map payloads
		 * to an institutional format. Admin export capability and nonce checks are
		 * enforced by the export controller before this method runs.
		 *
		 * @since 1.0.0
		 *
		 * @param array $export  Export rows.
		 * @param int   $site_id Franer site ID.
		 * @return array Filtered export rows (non-array returns are reset to empty).
		 */
		$export = apply_filters( 'franer_export_rows', $export, (int) $site_id );

		if ( ! is_array( $export ) ) {
			$export = array();
		}

		return $export;
	}
}
