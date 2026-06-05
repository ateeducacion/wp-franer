<?php
/**
 * Submissions list table for the Franer admin screen.
 *
 * Wraps the per-activity submissions list in a standard WordPress WP_List_Table
 * so it gets the native sortable columns, search box and pagination. The data is
 * read through Franer_Submissions_Repository::query_site_submissions(), which
 * applies the search term, sort and paging at the SQL level.
 *
 * @package    Franer
 * @subpackage Franer/admin
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists the submissions of a single Franer activity with sort/search/pagination.
 */
class Franer_Submissions_List_Table extends WP_List_Table {

	/**
	 * Submissions repository.
	 *
	 * @var Franer_Submissions_Repository
	 */
	private $submissions;

	/**
	 * The activity (site) post ID being listed.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Rows shown per page.
	 *
	 * @var int
	 */
	private $per_page;

	/**
	 * Inferred field schema (Franer_Submission_Schema::infer_fields() result).
	 *
	 * Drives the readable answer-chip, rating and comment columns.
	 *
	 * @var array
	 */
	private $fields;

	/**
	 * The current activity's form fingerprint (hash of the activity HTML).
	 *
	 * Used to flag submissions answered against an older version of the form.
	 *
	 * @var string
	 */
	private $current_form_version;

	/**
	 * Constructor.
	 *
	 * @param Franer_Submissions_Repository $submissions          The submissions repository.
	 * @param int                           $site_id              The activity post ID to list.
	 * @param int                           $per_page             Rows per page. Default 50.
	 * @param array                         $fields               Inferred field schema. Default empty.
	 * @param string                        $current_form_version Current activity form hash. Default ''.
	 */
	public function __construct( $submissions, $site_id, $per_page = 50, $fields = array(), $current_form_version = '' ) {
		parent::__construct(
			array(
				'singular' => 'franer_submission',
				'plural'   => 'franer_submissions',
				'ajax'     => false,
			)
		);

		$this->submissions          = $submissions;
		$this->site_id              = (int) $site_id;
		$this->per_page             = max( 1, (int) $per_page );
		$this->fields               = is_array( $fields ) ? $fields : array();
		$this->current_form_version = (string) $current_form_version;
	}

	/**
	 * Whether a row was answered against an older version of the form.
	 *
	 * Only true when both fingerprints are known and differ; a missing stored
	 * version (older submissions, before this was tracked) is never flagged.
	 *
	 * @param array $item The current row.
	 * @return bool
	 */
	private function is_outdated( $item ) {
		$stored = isset( $item['form_version'] ) ? (string) $item['form_version'] : '';

		return '' !== $stored && '' !== $this->current_form_version && $stored !== $this->current_form_version;
	}

	/**
	 * The fields shown as preview chips (categorical/number, max 3).
	 *
	 * @return array Field descriptors.
	 */
	private function preview_fields() {
		return Franer_Submission_Schema::select_preview_fields( $this->fields, 3 );
	}

	/**
	 * The rating field descriptor, if the activity has one.
	 *
	 * @return array|null
	 */
	private function rating_field() {
		return Franer_Submission_Schema::first_field_of_type( $this->fields, 'rating' );
	}

	/**
	 * The free-text field descriptor, if the activity has one.
	 *
	 * @return array|null
	 */
	private function comment_field() {
		return Franer_Submission_Schema::first_field_of_type( $this->fields, 'text' );
	}

	/**
	 * Decode a row's stored JSON payload to an array.
	 *
	 * @param array $item The current row.
	 * @return array The decoded payload (empty on failure).
	 */
	private function row_payload( $item ) {
		$decoded = json_decode( isset( $item['payload'] ) ? (string) $item['payload'] : '', true );

		return is_array( $decoded ) ? Franer_Submission_Schema::flatten_payload( $decoded ) : array();
	}

	/**
	 * Capability gate for rendering the table.
	 *
	 * @return bool Whether the current user may manage submissions.
	 */
	public function ajax_user_can() {
		return Franer_Permissions::can_manage();
	}

	/**
	 * Define the table columns.
	 *
	 * @return array Column slug => header label.
	 */
	public function get_columns() {
		$columns = array(
			'id'   => __( 'ID', 'franer' ),
			'user' => __( 'User', 'franer' ),
		);

		if ( ! empty( $this->preview_fields() ) ) {
			$columns['answers'] = __( 'Answers', 'franer' );
		}
		$rating = $this->rating_field();
		if ( null !== $rating ) {
			$columns['rating'] = $rating['label'];
		}
		$comment = $this->comment_field();
		if ( null !== $comment ) {
			$columns['comment'] = $comment['label'];
		}

		$columns['created_at'] = __( 'Created', 'franer' );
		$columns['actions']    = __( 'Actions', 'franer' );

		return $columns;
	}

	/**
	 * Define which columns are sortable.
	 *
	 * @return array Column slug => array( orderby key, is-initially-sorted ).
	 */
	public function get_sortable_columns() {
		return array(
			'id'         => array( 'id', true ),
			'user'       => array( 'user', false ),
			'created_at' => array( 'created_at', false ),
			'updated_at' => array( 'updated_at', false ),
		);
	}

	/**
	 * The primary column slug.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'id';
	}

	/**
	 * Append a plugin-specific class so existing admin CSS keeps applying.
	 *
	 * @return array The list of CSS classes for the table element.
	 */
	protected function get_table_classes() {
		$classes   = parent::get_table_classes();
		$classes[] = 'franer-submissions-table';

		return $classes;
	}

	/**
	 * Message shown when the activity has no (matching) submissions.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No submissions found for this activity yet.', 'franer' );
	}

	/**
	 * Query, map and paginate the items for the current request.
	 *
	 * @return void
	 */
	public function prepare_items() {
		// Read-only sort/search/pager parameters; no state change, so no nonce.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$paged = $this->get_pagenum();

		$result = $this->submissions->query_site_submissions(
			$this->site_id,
			array(
				'search'   => $search,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $this->per_page,
				'offset'   => ( $paged - 1 ) * $this->per_page,
			)
		);

		$this->items = $this->map_rows( $result['rows'] );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $this->per_page,
				'total_pages' => (int) ceil( $result['total'] / $this->per_page ),
			)
		);

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		);
	}

	/**
	 * Resolve the activity title and submitter login for each raw row.
	 *
	 * @param array $raw_rows Rows from the submissions repository.
	 * @return array The display rows.
	 */
	private function map_rows( $raw_rows ) {
		$rows = array();
		foreach ( $raw_rows as $row ) {
			$user      = get_userdata( (int) $row['user_id'] );
			$site_post = get_post( (int) $row['site_id'] );
			$rows[]    = array(
				'id'           => (int) $row['id'],
				'site_id'      => (int) $row['site_id'],
				'site_title'   => $site_post ? $site_post->post_title : '',
				'user_id'      => (int) $row['user_id'],
				'user_login'   => $user ? $user->user_login : ( '#' . (int) $row['user_id'] ),
				'created_at'   => isset( $row['created_at'] ) ? $row['created_at'] : '',
				'updated_at'   => isset( $row['updated_at'] ) ? $row['updated_at'] : '',
				'form_version' => isset( $row['form_version'] ) ? (string) $row['form_version'] : '',
				'payload'      => isset( $row['payload_json'] ) ? $row['payload_json'] : '{}',
			);
		}

		return $rows;
	}

	/**
	 * Render a simple text column.
	 *
	 * @param array  $item        The current row.
	 * @param string $column_name The column slug.
	 * @return string The escaped cell HTML.
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				return esc_html( (string) $item['id'] );
			case 'activity':
				return esc_html( $item['site_title'] );
			case 'user':
				return esc_html( $item['user_login'] );
			case 'created_at':
				return esc_html( $item['created_at'] );
			case 'updated_at':
				return esc_html( $item['updated_at'] ? $item['updated_at'] : '—' );
			default:
				return '';
		}
	}

	/**
	 * Render the Created column, flagging submissions from an older form version.
	 *
	 * @param array $item The current row.
	 * @return string The cell HTML.
	 */
	public function column_created_at( $item ) {
		$out = esc_html( (string) $item['created_at'] );

		if ( $this->is_outdated( $item ) ) {
			$out .= ' <span class="franer-version-badge" title="' . esc_attr__( 'Answered against an earlier version of the form.', 'franer' ) . '">'
				. esc_html__( 'older version', 'franer' ) . '</span>';
		}

		return $out;
	}

	/**
	 * Render the readable answer-chip preview column.
	 *
	 * @param array $item The current row.
	 * @return string The cell HTML.
	 */
	public function column_answers( $item ) {
		$payload = $this->row_payload( $item );
		$chips   = '';
		foreach ( $this->preview_fields() as $field ) {
			$key   = $field['key'];
			$value = isset( $payload[ $key ] ) ? $payload[ $key ] : null;
			$chips .= sprintf(
				'<span class="franer-achip" title="%1$s">%2$s</span>',
				esc_attr( $field['label'] ),
				esc_html( Franer_Submission_Schema::preview_value( $field, $value ) )
			);
		}

		return '<span class="franer-apreview">' . $chips . '</span>';
	}

	/**
	 * Render the rating column as stars.
	 *
	 * @param array $item The current row.
	 * @return string The cell HTML.
	 */
	public function column_rating( $item ) {
		$field = $this->rating_field();
		if ( null === $field ) {
			return '';
		}
		$payload = $this->row_payload( $item );
		$value   = isset( $payload[ $field['key'] ] ) ? (float) $payload[ $field['key'] ] : 0.0;

		return self::stars_html( $value );
	}

	/**
	 * Render the free-text comment column (truncated).
	 *
	 * @param array $item The current row.
	 * @return string The cell HTML.
	 */
	public function column_comment( $item ) {
		$field = $this->comment_field();
		if ( null === $field ) {
			return '';
		}
		$payload = $this->row_payload( $item );
		$value   = isset( $payload[ $field['key'] ] ) ? (string) $payload[ $field['key'] ] : '';
		$value   = trim( $value );

		if ( '' === $value ) {
			return '<span class="franer-muted">—</span>';
		}

		return '<span class="franer-comment">“' . esc_html( wp_html_excerpt( $value, 80, '…' ) ) . '”</span>';
	}

	/**
	 * Build the star-rating markup for a 0..5 value.
	 *
	 * @param float $value The rating value.
	 * @return string The stars HTML.
	 */
	public static function stars_html( $value ) {
		$value = (float) $value;
		$out   = '<span class="franer-stars" title="' . esc_attr( number_format_i18n( $value, 1 ) . ' / 5' ) . '">';
		for ( $i = 1; $i <= 5; $i++ ) {
			$on   = $value >= ( $i - 0.25 ) ? ' is-on' : '';
			$out .= '<span class="franer-star' . $on . '" aria-hidden="true">★</span>';
		}
		$out .= '</span>';

		return $out;
	}

	/**
	 * Render the actions column (view/edit JSON + delete).
	 *
	 * @param array $item The current row.
	 * @return string The cell HTML.
	 */
	public function column_actions( $item ) {
		$decoded = json_decode( $item['payload'], true );
		$pretty  = ( null === $decoded )
			? $item['payload']
			: wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$id = (int) $item['id'];

		// Note: delete is wired to a single hidden form rendered outside the list's
		// own GET <form> (see the partial). A per-row <form> here would be an invalid
		// nested form and the browser would submit the search form instead.
		ob_start();
		?>
		<button type="button" class="franer-iconbtn franer-view-json"
			title="<?php esc_attr_e( 'View / edit', 'franer' ); ?>"
			data-franer-payload="<?php echo esc_attr( (string) $pretty ); ?>"
			data-franer-id="<?php echo esc_attr( (string) $id ); ?>"
			data-franer-outdated="<?php echo $this->is_outdated( $item ) ? '1' : '0'; ?>"
			data-franer-nonce="<?php echo esc_attr( wp_create_nonce( 'franer_update_submission_' . $id ) ); ?>">
			<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php esc_html_e( 'View / edit', 'franer' ); ?></span>
		</button>
		<button type="button" class="franer-iconbtn franer-iconbtn--danger franer-delete-btn"
			title="<?php esc_attr_e( 'Delete', 'franer' ); ?>"
			data-franer-id="<?php echo esc_attr( (string) $id ); ?>"
			data-franer-delete-nonce="<?php echo esc_attr( wp_create_nonce( 'franer_delete_submission_' . $id ) ); ?>">
			<span class="dashicons dashicons-trash" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php esc_html_e( 'Delete', 'franer' ); ?></span>
		</button>
		<?php
		return (string) ob_get_clean();
	}
}
