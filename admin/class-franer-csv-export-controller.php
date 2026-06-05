<?php
/**
 * CSV export controller for the Franer plugin.
 *
 * Handles the admin-post action that streams a site's submissions as a CSV
 * download (one row per submission, payload flattened into columns) for
 * administrators. Complements Franer_Export_Controller, which streams JSON.
 *
 * @package    Franer
 * @subpackage Franer/admin
 * @author     Área de Tecnología Educativa
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Franer_Csv_Export_Controller.
 *
 * @package    Franer
 * @subpackage Franer/admin
 */
class Franer_Csv_Export_Controller {

	/**
	 * Default field delimiter.
	 *
	 * Semicolon is used so the file opens directly (double-click) in Excel under
	 * Spanish/EU locales, where comma is the decimal separator.
	 *
	 * @var string
	 */
	const DEFAULT_DELIMITER = ';';

	/**
	 * Fixed leading columns, always emitted before the payload columns.
	 *
	 * @var string[]
	 */
	const FIXED_COLUMNS = array( 'id', 'user_id', 'user_login', 'user_email', 'created_at', 'updated_at' );

	/**
	 * Handle admin_post_franer_export_csv.
	 *
	 * Streams a CSV file containing all submissions for the requested site.
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
		check_admin_referer( 'franer_export_csv_' . $site_id );

		$repository = new Franer_Site_Repository();
		$site_post  = get_post( $site_id );

		if ( ! $site_post instanceof WP_Post || 'franer_site' !== $site_post->post_type ) {
			wp_die( esc_html__( 'The requested activity does not exist.', 'franer' ) );
		}

		$settings = $repository->get_settings( $site_id );

		/** This action is documented in admin/class-franer-export-controller.php */
		do_action( 'franer_before_export', $site_id, $site_post, $settings );

		$submissions_repo = new Franer_Submissions_Repository();
		$rows             = $submissions_repo->export_site_submissions( $site_id );

		$table = self::build_table( $rows );

		/**
		 * Filters the Franer CSV export table before download.
		 *
		 * The table is an array with a `header` (column names) and `data` (a list of
		 * row arrays aligned to the header). Use it to add, reorder or remove columns.
		 *
		 * @since 1.1.0
		 *
		 * @param array   $table     { @type string[] $header, @type array[] $data }.
		 * @param int     $site_id   Franer site ID.
		 * @param WP_Post $site_post Franer site post.
		 * @param array   $settings  Typed Franer site settings.
		 * @return array Filtered table.
		 */
		$filtered_table = apply_filters( 'franer_export_csv_table', $table, $site_id, $site_post, $settings );

		if ( is_array( $filtered_table ) && isset( $filtered_table['header'], $filtered_table['data'] ) ) {
			$table = $filtered_table;
		}

		$this->stream_download( $table, $settings['slug'] );
	}

	/**
	 * Recursively flatten a decoded payload to a `path => scalar string` map.
	 *
	 * Nested objects/arrays use dot notation (`scores.math`, `answers.0`). Booleans
	 * become `1`/`0`, null becomes an empty string, and an empty array yields a
	 * single empty cell so its column is still represented.
	 *
	 * @param mixed  $payload The decoded payload (array, scalar, or null).
	 * @param string $prefix  The dotted key prefix for the current recursion level.
	 * @return array<string,string> Flattened key/value pairs.
	 */
	public static function flatten_payload( $payload, $prefix = '' ) {
		$flat = array();

		if ( ! is_array( $payload ) ) {
			if ( '' !== $prefix ) {
				$flat[ $prefix ] = self::scalarize( $payload );
			}
			return $flat;
		}

		if ( array() === $payload ) {
			if ( '' !== $prefix ) {
				$flat[ $prefix ] = '';
			}
			return $flat;
		}

		foreach ( $payload as $key => $value ) {
			$path = ( '' === $prefix ) ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				$flat = array_merge( $flat, self::flatten_payload( $value, $path ) );
			} else {
				$flat[ $path ] = self::scalarize( $value );
			}
		}

		return $flat;
	}

	/**
	 * Normalize a scalar payload value to a CSV cell string.
	 *
	 * @param mixed $value The scalar value.
	 * @return string The string representation.
	 */
	private static function scalarize( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( null === $value ) {
			return '';
		}
		return (string) $value;
	}

	/**
	 * Build the CSV table (header + data) from export rows.
	 *
	 * Leading columns are the fixed submission metadata; the remaining columns are
	 * the union of every flattened payload key across all rows, in first-seen order
	 * and prefixed with `payload.`. Cells absent from a given row are empty strings.
	 *
	 * @param array $rows Rows as returned by export_site_submissions().
	 * @return array{header:string[],data:array<int,string[]>} The CSV table.
	 */
	public static function build_table( array $rows ) {
		$payload_keys = array();
		$prepared     = array();

		foreach ( $rows as $row ) {
			$flat = self::flatten_payload( isset( $row['payload'] ) ? $row['payload'] : null );
			foreach ( $flat as $key => $unused ) {
				$payload_keys[ $key ] = true;
			}
			$prepared[] = array(
				'row'  => $row,
				'flat' => $flat,
			);
		}

		$payload_cols = array_keys( $payload_keys );

		$header = self::FIXED_COLUMNS;
		foreach ( $payload_cols as $key ) {
			$header[] = 'payload.' . $key;
		}

		$data = array();
		foreach ( $prepared as $item ) {
			$row    = $item['row'];
			$flat   = $item['flat'];
			$line   = array();
			foreach ( self::FIXED_COLUMNS as $column ) {
				$value = isset( $row[ $column ] ) ? $row[ $column ] : '';
				$line[] = ( null === $value ) ? '' : (string) $value;
			}
			foreach ( $payload_cols as $key ) {
				$line[] = isset( $flat[ $key ] ) ? $flat[ $key ] : '';
			}
			$data[] = $line;
		}

		return array(
			'header' => $header,
			'data'   => $data,
		);
	}

	/**
	 * Render a CSV table to an RFC 4180-compliant string.
	 *
	 * Fields are quoted when they contain the delimiter, a double quote, or a
	 * line break; embedded double quotes are doubled. Records are separated by
	 * CRLF, as RFC 4180 requires.
	 *
	 * @param array  $table     { @type string[] $header, @type array[] $data }.
	 * @param string $delimiter The field delimiter.
	 * @return string The CSV text (without the leading BOM).
	 */
	public static function table_to_csv( array $table, $delimiter = self::DEFAULT_DELIMITER ) {
		$header = isset( $table['header'] ) && is_array( $table['header'] ) ? $table['header'] : array();
		$data   = isset( $table['data'] ) && is_array( $table['data'] ) ? $table['data'] : array();

		$records = array();
		$records[] = self::record_to_line( $header, $delimiter );
		foreach ( $data as $fields ) {
			$records[] = self::record_to_line( (array) $fields, $delimiter );
		}

		return implode( "\r\n", $records ) . "\r\n";
	}

	/**
	 * Join one record's fields into an RFC 4180 line.
	 *
	 * @param array  $fields    The field values.
	 * @param string $delimiter The field delimiter.
	 * @return string The encoded line.
	 */
	private static function record_to_line( array $fields, $delimiter ) {
		$cells = array();
		foreach ( $fields as $field ) {
			$cells[] = self::quote_field( (string) $field, $delimiter );
		}
		return implode( $delimiter, $cells );
	}

	/**
	 * Quote a single field per RFC 4180 when required.
	 *
	 * @param string $field     The field value.
	 * @param string $delimiter The field delimiter.
	 * @return string The (optionally) quoted field.
	 */
	private static function quote_field( $field, $delimiter ) {
		$needs_quote = ( '' !== $delimiter && false !== strpos( $field, $delimiter ) )
			|| false !== strpos( $field, '"' )
			|| false !== strpos( $field, "\n" )
			|| false !== strpos( $field, "\r" );

		if ( ! $needs_quote ) {
			return $field;
		}

		return '"' . str_replace( '"', '""', $field ) . '"';
	}

	/**
	 * Stream the CSV table as a file download and terminate.
	 *
	 * @param array  $table The CSV table (header + data).
	 * @param string $slug  The site slug, used in the file name.
	 * @return void
	 */
	private function stream_download( array $table, $slug ) {
		$filename = 'franer-' . ( '' !== $slug ? $slug : 'export' ) . '-' . gmdate( 'Ymd-His' ) . '.csv';

		/**
		 * Filters the Franer CSV export download filename.
		 *
		 * The returned filename is sanitized with sanitize_file_name() and is always
		 * forced to end in ".csv".
		 *
		 * @since 1.1.0
		 *
		 * @param string $filename Default export filename.
		 * @param string $slug     Franer site slug.
		 * @param array  $table    The CSV table.
		 * @return string Filtered filename.
		 */
		$filename = apply_filters( 'franer_export_csv_filename', $filename, $slug, $table );

		if ( ! is_string( $filename ) || '' === $filename ) {
			$filename = 'franer-export-' . gmdate( 'Ymd-His' ) . '.csv';
		}

		$filename = sanitize_file_name( $filename );

		if ( '.csv' !== substr( $filename, -4 ) ) {
			$filename .= '.csv';
		}

		$delimiter = self::DEFAULT_DELIMITER;

		/**
		 * Filters the field delimiter used for Franer CSV exports.
		 *
		 * @since 1.1.0
		 *
		 * @param string $delimiter Default delimiter (semicolon).
		 * @param array  $table     The CSV table.
		 * @param string $slug      Franer site slug.
		 * @return string Filtered delimiter (a single character).
		 */
		$delimiter = (string) apply_filters( 'franer_export_csv_delimiter', $delimiter, $table, $slug );

		if ( '' === $delimiter ) {
			$delimiter = self::DEFAULT_DELIMITER;
		}

		$csv = self::table_to_csv( $table, $delimiter );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// UTF-8 BOM so spreadsheet apps (Excel) detect the encoding and render accents.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming a CSV file download; escaping would corrupt it.
		echo "\xEF\xBB\xBF" . $csv;

		exit;
	}
}
