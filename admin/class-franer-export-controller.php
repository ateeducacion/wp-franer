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

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming a JSON file download; escaping would corrupt it.
		echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		exit;
	}
}
