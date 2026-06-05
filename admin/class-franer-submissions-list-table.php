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
	 * Constructor.
	 *
	 * @param Franer_Submissions_Repository $submissions The submissions repository.
	 * @param int                           $site_id     The activity post ID to list.
	 * @param int                           $per_page    Rows per page. Default 50.
	 */
	public function __construct( $submissions, $site_id, $per_page = 50 ) {
		parent::__construct(
			array(
				'singular' => 'franer_submission',
				'plural'   => 'franer_submissions',
				'ajax'     => false,
			)
		);

		$this->submissions = $submissions;
		$this->site_id     = (int) $site_id;
		$this->per_page    = max( 1, (int) $per_page );
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
		return array(
			'id'         => __( 'ID', 'franer' ),
			'activity'   => __( 'Activity', 'franer' ),
			'user'       => __( 'User', 'franer' ),
			'created_at' => __( 'Created', 'franer' ),
			'updated_at' => __( 'Updated', 'franer' ),
			'actions'    => __( 'Actions', 'franer' ),
		);
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
				'id'         => (int) $row['id'],
				'site_id'    => (int) $row['site_id'],
				'site_title' => $site_post ? $site_post->post_title : '',
				'user_id'    => (int) $row['user_id'],
				'user_login' => $user ? $user->user_login : ( '#' . (int) $row['user_id'] ),
				'created_at' => isset( $row['created_at'] ) ? $row['created_at'] : '',
				'updated_at' => isset( $row['updated_at'] ) ? $row['updated_at'] : '',
				'payload'    => isset( $row['payload_json'] ) ? $row['payload_json'] : '{}',
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

		ob_start();
		?>
		<button type="button" class="button button-small franer-view-json"
			data-franer-payload="<?php echo esc_attr( (string) $pretty ); ?>"
			data-franer-id="<?php echo esc_attr( (string) $item['id'] ); ?>"
			data-franer-nonce="<?php echo esc_attr( wp_create_nonce( 'franer_update_submission_' . (int) $item['id'] ) ); ?>">
			<?php esc_html_e( 'View / edit', 'franer' ); ?>
		</button>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			class="franer-inline-form"
			onsubmit="return confirm( '<?php echo esc_js( __( 'Delete this submission? This cannot be undone.', 'franer' ) ); ?>' );">
			<input type="hidden" name="action" value="franer_delete_submission" />
			<input type="hidden" name="submission_id" value="<?php echo esc_attr( (string) $item['id'] ); ?>" />
			<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $this->site_id ); ?>" />
			<?php wp_nonce_field( 'franer_delete_submission_' . (int) $item['id'] ); ?>
			<button type="submit" class="button button-small button-link-delete">
				<?php esc_html_e( 'Delete', 'franer' ); ?>
			</button>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}
