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
		global $wpdb;

		$site_id      = (int) $site_id;
		$user_id      = (int) $user_id;
		$payload_json = (string) $payload_json;
		$table        = self::get_table_name();
		$now          = current_time( 'mysql' );
		$payload_hash = $this->payload_hash( $payload_json );

		$existing = $this->get_latest_user_submission( $site_id, $user_id );

		if ( $existing && ! $allow_multiple ) {
			if ( $allow_overwrite ) {
				$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'payload_json' => $payload_json,
						'payload_hash' => $payload_hash,
						'updated_at'   => $now,
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

			return new WP_Error(
				'franer_duplicate',
				__( 'Duplicate submission not allowed', 'franer' ),
				array( 'status' => 409 )
			);
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'site_id'         => $site_id,
				'user_id'         => $user_id,
				'payload_json'    => $payload_json,
				'payload_hash'    => $payload_hash,
				'ip_hash'         => $this->hashed_server_value( 'REMOTE_ADDR' ),
				'user_agent_hash' => $this->hashed_server_value( 'HTTP_USER_AGENT' ),
				'created_at'      => $now,
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
