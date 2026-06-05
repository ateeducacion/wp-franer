<?php
/**
 * Tests for Franer_Submissions_List_Table.
 *
 * @package Franer
 */

/**
 * Verifies columns, rendering and the prepare_items() query mapping.
 */
class SubmissionsListTableTest extends Franer_Test_Base {

	/**
	 * Submissions repository.
	 *
	 * @var Franer_Submissions_Repository
	 */
	private $repo;

	/**
	 * The activity post ID.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Set up a current screen, repository and site.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// The list table is loaded on demand by Franer_Admin_Submissions in
		// production, so it is not part of the plugin's eager dependencies.
		require_once FRANER_PLUGIN_DIR . 'admin/class-franer-submissions-list-table.php';

		// WP_List_Table::__construct() reads the current screen.
		set_current_screen( 'edit-franer_site' );

		$this->repo    = new Franer_Submissions_Repository();
		$this->site_id = self::factory()->franer_site->create();
	}

	/**
	 * Build a list table with optional inferred fields.
	 *
	 * @param array $fields Inferred field descriptors.
	 * @return Franer_Submissions_List_Table
	 */
	private function make_table( array $fields = array() ) {
		return new Franer_Submissions_List_Table( $this->repo, $this->site_id, 50, $fields, 'v1' );
	}

	/**
	 * Field descriptors covering the answer-chip, rating and comment columns.
	 *
	 * @return array
	 */
	private function rich_fields() {
		return array(
			'color' => array( 'key' => 'color', 'label' => 'Color', 'type' => 'category' ),
			'score' => array( 'key' => 'score', 'label' => 'Score', 'type' => 'rating' ),
			'note'  => array( 'key' => 'note', 'label' => 'Note', 'type' => 'text' ),
		);
	}

	/**
	 * A display row for the column renderers.
	 *
	 * @param array  $payload Decoded payload.
	 * @param string $version Stored form version.
	 * @return array
	 */
	private function row( array $payload, $version = 'v1' ) {
		return array(
			'id'           => 7,
			'site_id'      => $this->site_id,
			'site_title'   => 'Activity',
			'user_id'      => 3,
			'user_login'   => 'ana',
			'created_at'   => '2026-06-01 10:00:00',
			'updated_at'   => '',
			'form_version' => $version,
			'payload'      => wp_json_encode( $payload ),
		);
	}

	/**
	 * Without inferred fields, only the base columns are present.
	 *
	 * @return void
	 */
	public function test_get_columns_base_set() {
		$columns = $this->make_table()->get_columns();

		$this->assertSame(
			array( 'id', 'user', 'created_at', 'actions' ),
			array_keys( $columns )
		);
	}

	/**
	 * With inferred fields, the answers/rating/comment columns appear.
	 *
	 * @return void
	 */
	public function test_get_columns_includes_inferred_columns() {
		$columns = $this->make_table( $this->rich_fields() )->get_columns();

		$this->assertArrayHasKey( 'answers', $columns );
		$this->assertArrayHasKey( 'rating', $columns );
		$this->assertArrayHasKey( 'comment', $columns );
		$this->assertSame( 'Score', $columns['rating'] );
	}

	/**
	 * The sortable map should expose id/user/created_at/updated_at.
	 *
	 * @return void
	 */
	public function test_get_sortable_columns() {
		$sortable = $this->make_table()->get_sortable_columns();

		$this->assertSame( array( 'id', true ), $sortable['id'] );
		$this->assertArrayHasKey( 'created_at', $sortable );
		$this->assertArrayHasKey( 'updated_at', $sortable );
	}

	/**
	 * no_items() prints a friendly empty-state message.
	 *
	 * @return void
	 */
	public function test_no_items_message() {
		ob_start();
		$this->make_table()->no_items();
		$this->assertStringContainsString(
			esc_html__( 'No submissions found for this activity yet.', 'franer' ),
			ob_get_clean()
		);
	}

	/**
	 * column_default() escapes and maps each base column.
	 *
	 * @return void
	 */
	public function test_column_default_maps_columns() {
		$table = $this->make_table();
		$item  = $this->row( array( 'q1' => 'a' ) );

		$this->assertSame( '7', $table->column_default( $item, 'id' ) );
		$this->assertSame( 'ana', $table->column_default( $item, 'user' ) );
		$this->assertSame( '2026-06-01 10:00:00', $table->column_default( $item, 'created_at' ) );
		$this->assertSame( '', $table->column_default( $item, 'unknown' ) );
	}

	/**
	 * column_created_at() flags rows answered against an older form version.
	 *
	 * @return void
	 */
	public function test_column_created_at_flags_outdated() {
		$table = $this->make_table();

		$badge = esc_html__( 'older version', 'franer' );

		$current = $table->column_created_at( $this->row( array(), 'v1' ) );
		$this->assertStringNotContainsString( $badge, $current );

		$outdated = $table->column_created_at( $this->row( array(), 'v0' ) );
		$this->assertStringContainsString( $badge, $outdated );
	}

	/**
	 * The inferred columns render chips, stars and a comment excerpt.
	 *
	 * @return void
	 */
	public function test_inferred_columns_render() {
		$table = $this->make_table( $this->rich_fields() );
		$item  = $this->row(
			array(
				'color' => 'red',
				'score' => 4,
				'note'  => 'A long free-text comment that should appear.',
			)
		);

		$this->assertStringContainsString( 'franer-achip', $table->column_answers( $item ) );
		$this->assertStringContainsString( 'franer-stars', $table->column_rating( $item ) );
		$this->assertStringContainsString( 'franer-comment', $table->column_comment( $item ) );
	}

	/**
	 * An empty comment renders the muted placeholder.
	 *
	 * @return void
	 */
	public function test_comment_column_empty_value() {
		$table = $this->make_table( $this->rich_fields() );
		$out   = $table->column_comment( $this->row( array( 'note' => '' ) ) );

		$this->assertStringContainsString( 'franer-muted', $out );
	}

	/**
	 * stars_html() renders five star spans and marks the filled ones.
	 *
	 * @return void
	 */
	public function test_stars_html() {
		$html = Franer_Submissions_List_Table::stars_html( 3.0 );

		$this->assertSame( 5, substr_count( $html, 'franer-star' ) - substr_count( $html, 'franer-stars' ) );
		$this->assertStringContainsString( 'is-on', $html );
	}

	/**
	 * column_actions() renders the view/edit and delete buttons with nonces.
	 *
	 * @return void
	 */
	public function test_column_actions_renders_buttons() {
		$html = $this->make_table()->column_actions( $this->row( array( 'q1' => 'a' ) ) );

		$this->assertStringContainsString( 'franer-view-json', $html );
		$this->assertStringContainsString( 'franer-delete-btn', $html );
		$this->assertStringContainsString( 'data-franer-nonce', $html );
	}

	/**
	 * prepare_items() queries the repository, maps rows and sets pagination.
	 *
	 * @return void
	 */
	public function test_prepare_items_populates_from_repository() {
		self::factory()->franer_submission->create( array( 'site_id' => $this->site_id ) );
		self::factory()->franer_submission->create( array( 'site_id' => $this->site_id ) );

		$_GET['orderby'] = 'id';
		$_GET['order']   = 'asc';

		$table = $this->make_table();
		$table->prepare_items();

		$this->assertCount( 2, $table->items );
		$this->assertArrayHasKey( 'user_login', $table->items[0] );

		unset( $_GET['orderby'], $_GET['order'] );
	}
}
