<?php
/**
 * Export controller for the Franer plugin.
 *
 * Handles the admin-post action that streams a site's submissions as a JSON
 * download for administrators.
 *
 * @package    Franer
 * @subpackage Franer/admin
 * @author     Área de Tecnología Educativa
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Franer_Export_Controller.
 *
 * @package    Franer
 * @subpackage Franer/admin
 */
class Franer_Export_Controller {

	/**
	 * Handle admin_post_franer_export.
	 *
	 * Streams a JSON file containing all submissions for the requested site.
	 *
	 * @return void Outputs the download and terminates the request.
	 */
	public function handle() {
		// Capability check: only administrators may export.
		if ( ! Franer_Permissions::can_manage() ) {
			wp_die(
				esc_html__( 'You do not have permission to export submissions.', 'franer' ),
				esc_html__( 'Forbidden', 'franer' ),
				array( 'response' => 403 )
			);
		}

		// Validate the site identifier.
		$site_id = isset( $_REQUEST['site_id'] ) ? absint( wp_unslash( $_REQUEST['site_id'] ) ) : 0;

		if ( $site_id <= 0 ) {
			wp_die( esc_html__( 'Invalid site identifier.', 'franer' ) );
		}

		// Verify the per-site nonce.
		check_admin_referer( 'franer_export_' . $site_id );

		$repository = new Franer_Site_Repository();
		$site_post  = get_post( $site_id );

		if ( ! $site_post instanceof WP_Post || 'franer_site' !== $site_post->post_type ) {
			wp_die( esc_html__( 'The requested activity does not exist.', 'franer' ) );
		}

		$settings = $repository->get_settings( $site_id );

		/**
		 * Fires before a Franer site export is prepared.
		 *
		 * Runs only after the admin capability and nonce checks have passed.
		 *
		 * @since 1.0.0
		 *
		 * @param int     $site_id   Franer site ID.
		 * @param WP_Post $site_post Franer site post.
		 * @param array   $settings  Typed Franer site settings.
		 */
		do_action( 'franer_before_export', $site_id, $site_post, $settings );

		$submissions_repo = new Franer_Submissions_Repository();
		$rows             = $submissions_repo->export_site_submissions( $site_id );

		$export = array(
			'plugin'         => 'franer',
			'schema_version' => '1.0',
			'exported_at'    => current_time( 'c' ),
			'site'           => array(
				'id'    => (int) $settings['id'],
				'slug'  => $settings['slug'],
				'title' => $settings['title'],
			),
			'count'          => count( $rows ),
			'submissions'    => array_map( array( $this, 'format_row' ), $rows ),
		);

		/**
		 * Filters the full Franer export payload before download.
		 *
		 * May add metadata, transform submission rows, remove fields, or adapt
		 * the export to an external system. Must return an array; non-array
		 * returns are ignored so the default structure is preserved.
		 *
		 * @since 1.0.0
		 *
		 * @param array   $export    Full export payload.
		 * @param int     $site_id   Franer site ID.
		 * @param WP_Post $site_post Franer site post.
		 * @param array   $settings  Typed Franer site settings.
		 * @return array Filtered export payload.
		 */
		$filtered_export = apply_filters( 'franer_export_payload', $export, $site_id, $site_post, $settings );

		if ( is_array( $filtered_export ) ) {
			$export = $filtered_export;
		}

		/**
		 * Fires after the Franer export payload has been generated and filtered.
		 *
		 * Runs before the JSON download is streamed.
		 *
		 * @since 1.0.0
		 *
		 * @param array   $export    Full export payload.
		 * @param int     $site_id   Franer site ID.
		 * @param WP_Post $site_post Franer site post.
		 * @param array   $settings  Typed Franer site settings.
		 */
		do_action( 'franer_after_export_payload_generated', $export, $site_id, $site_post, $settings );

		$this->stream_download( $export, $settings['slug'] );
	}

	/**
	 * Normalize a submission row to the export shape.
	 *
	 * @param array $row A row as returned by export_site_submissions().
	 * @return array The normalized submission entry.
	 */
	private function format_row( array $row ) {
		return array(
			'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'user_id'    => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'user_login' => isset( $row['user_login'] ) ? $row['user_login'] : '',
			'user_email' => isset( $row['user_email'] ) ? $row['user_email'] : '',
			'created_at' => isset( $row['created_at'] ) ? $row['created_at'] : '',
			'updated_at' => isset( $row['updated_at'] ) ? $row['updated_at'] : null,
			'payload'    => isset( $row['payload'] ) ? $row['payload'] : null,
		);
	}

	/**
	 * Stream the export payload as a JSON file download and terminate.
	 *
	 * @param array  $export The export data structure.
	 * @param string $slug   The site slug, used in the file name.
	 * @return void
	 */
	private function stream_download( array $export, $slug ) {
		$filename = 'franer-' . ( '' !== $slug ? $slug : 'export' ) . '-' . gmdate( 'Ymd-His' ) . '.json';

		/**
		 * Filters the Franer export download filename.
		 *
		 * The returned filename is sanitized with sanitize_file_name() and is
		 * always forced to end in ".json".
		 *
		 * @since 1.0.0
		 *
		 * @param string $filename Default export filename.
		 * @param string $slug     Franer site slug.
		 * @param array  $export   Full export payload.
		 * @return string Filtered filename.
		 */
		$filename = apply_filters( 'franer_export_filename', $filename, $slug, $export );

		if ( ! is_string( $filename ) || '' === $filename ) {
			$filename = 'franer-export-' . gmdate( 'Ymd-His' ) . '.json';
		}

		$filename = sanitize_file_name( $filename );

		if ( '.json' !== substr( $filename, -5 ) ) {
			$filename .= '.json';
		}

		$json_flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

		/**
		 * Filters the JSON encoding flags used for Franer exports.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $json_flags JSON encoding flags.
		 * @param array  $export     Full export payload.
		 * @param string $slug       Franer site slug.
		 * @return int Filtered JSON flags.
		 */
		$json_flags = (int) apply_filters( 'franer_export_json_flags', $json_flags, $export, $slug );

		/**
		 * Fires before a Franer export JSON file is streamed to the browser.
		 *
		 * Headers have not been sent yet. For logging or audit trails only; do
		 * not echo output from callbacks.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $export   Full export payload.
		 * @param string $slug     Franer site slug.
		 * @param string $filename Final sanitized download filename.
		 */
		do_action( 'franer_before_export_download', $export, $slug, $filename );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming a JSON file download; escaping would corrupt it.
		echo wp_json_encode( $export, $json_flags );

		exit;
	}
}
