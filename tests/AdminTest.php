<?php
/**
 * Tests for Franer_Admin (list columns, filters, save handlers, assets, notices).
 *
 * The metabox save path is partially covered by MetaTest; this file targets the
 * list-table integration, query filters, notices and asset enqueueing.
 *
 * @package Franer
 */

/**
 * Verifies the admin-area glue around the franer_site list and editor.
 */
class AdminTest extends Franer_Test_Base {

	/**
	 * Admin instance under test.
	 *
	 * @var Franer_Admin
	 */
	private $admin;

	/**
	 * An administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Create the instance and an administrator.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->admin    = new Franer_Admin();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Reset request/global state between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST = array();
		$_GET  = array();
		parent::tear_down();
	}

	/**
	 * add_list_columns() inserts a Submissions column before Date.
	 *
	 * @return void
	 */
	public function test_add_list_columns_inserts_submissions_before_date() {
		$columns = $this->admin->add_list_columns(
			array(
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$keys = array_keys( $columns );
		$this->assertContains( 'franer_submissions', $keys );
		$this->assertLessThan( array_search( 'date', $keys, true ), array_search( 'franer_submissions', $keys, true ) );
	}

	/**
	 * Without a Date column the Submissions column is appended.
	 *
	 * @return void
	 */
	public function test_add_list_columns_appends_when_no_date() {
		$columns = $this->admin->add_list_columns( array( 'title' => 'Title' ) );
		$this->assertArrayHasKey( 'franer_submissions', $columns );
	}

	/**
	 * render_list_column() prints a linked submission count.
	 *
	 * @return void
	 */
	public function test_render_list_column_outputs_count_link() {
		$site_id = self::factory()->franer_site->create();
		self::factory()->franer_submission->create( array( 'site_id' => $site_id ) );

		ob_start();
		$this->admin->render_list_column( 'franer_submissions', $site_id );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'page=franer-submissions', $html );
		$this->assertStringContainsString( '>1<', $html );

		// An unknown column prints nothing.
		ob_start();
		$this->admin->render_list_column( 'other', $site_id );
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * add_sortable_columns() marks author and submissions sortable.
	 *
	 * @return void
	 */
	public function test_add_sortable_columns() {
		$columns = $this->admin->add_sortable_columns( array() );
		$this->assertSame( 'author', $columns['author'] );
		$this->assertSame( 'franer_submissions', $columns['franer_submissions'] );
	}

	/**
	 * add_row_actions() adds a Visit link for franer_site posts with a slug.
	 *
	 * @return void
	 */
	public function test_add_row_actions_for_franer_site() {
		$site = get_post( self::factory()->franer_site->create( array( 'slug' => 'visit-me' ) ) );

		$actions = $this->admin->add_row_actions( array(), $site );
		$this->assertArrayHasKey( 'franer_visit', $actions );
		$this->assertStringContainsString( '/franer/visit-me/', $actions['franer_visit'] );

		// Other post types are untouched.
		$other = get_post( self::factory()->post->create() );
		$this->assertSame( array(), $this->admin->add_row_actions( array(), $other ) );
	}

	/**
	 * restrict_author_dropdown_to_admins() swaps the capability on the editor screen.
	 *
	 * @return void
	 */
	public function test_restrict_author_dropdown_to_admins() {
		set_current_screen( 'franer_site' );

		$args = $this->admin->restrict_author_dropdown_to_admins(
			array( 'who' => 'authors' ),
			array( 'name' => 'post_author_override' )
		);

		$this->assertArrayNotHasKey( 'who', $args );
		$this->assertSame( array( 'manage_options' ), $args['capability'] );

		// A different dropdown name is left untouched.
		$unchanged = $this->admin->restrict_author_dropdown_to_admins(
			array( 'who' => 'authors' ),
			array( 'name' => 'something_else' )
		);
		$this->assertSame( 'authors', $unchanged['who'] );
	}

	/**
	 * inject_raw_html_into_content() writes franer_html into post_content when the
	 * nonce, capability and post type are valid.
	 *
	 * @return void
	 */
	public function test_inject_raw_html_into_content() {
		$_POST['franer_site_nonce'] = wp_create_nonce( 'save_franer_site' );
		$_POST['franer_html']       = '<p>raw <script>x</script></p>';

		$data = $this->admin->inject_raw_html_into_content(
			array( 'post_type' => 'franer_site', 'post_content' => 'old' ),
			array()
		);
		$this->assertStringContainsString( '<script>x</script>', $data['post_content'] );

		// Wrong post type is a no-op.
		$untouched = $this->admin->inject_raw_html_into_content(
			array( 'post_type' => 'post', 'post_content' => 'keep' ),
			array()
		);
		$this->assertSame( 'keep', $untouched['post_content'] );
	}

	/**
	 * save_meta() stores flags, schedule and slug, and queues a notice for a
	 * duplicate slug.
	 *
	 * @return void
	 */
	public function test_save_meta_stores_fields_and_flags_duplicate_slug() {
		// An existing activity already owns the slug "taken".
		self::factory()->franer_site->create( array( 'slug' => 'taken' ) );

		$post_id = self::factory()->franer_site->create();

		$_POST = array(
			'franer_site_nonce'                 => wp_create_nonce( 'save_franer_site' ),
			'franer_slug'                       => 'taken',
			'franer_accepts_submissions'        => '1',
			'franer_allow_multiple_submissions' => '1',
			'franer_enabled'                    => '1',
			'franer_start_date'                 => '2026-01-01 00:00',
			'franer_allowed_roles'              => array( 'subscriber', 'editor' ),
			'franer_max_payload_size'           => '128',
		);

		$this->admin->save_meta( $post_id, get_post( $post_id ) );

		$this->assertSame( '1', get_post_meta( $post_id, '_franer_accepts_submissions', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_franer_allow_multiple_submissions', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_franer_enabled', true ) );
		$this->assertContains( 'subscriber', get_post_meta( $post_id, '_franer_allowed_roles', true ) );

		// The duplicate slug was rejected (not stored) and a notice was queued.
		$this->assertNotSame( 'taken', get_post_meta( $post_id, '_franer_slug', true ) );
		$notices = get_transient( 'franer_admin_notices_' . $this->admin_id );
		$this->assertNotEmpty( $notices );
	}

	/**
	 * save_meta() bails entirely without a valid nonce.
	 *
	 * @return void
	 */
	public function test_save_meta_requires_nonce() {
		$post_id = self::factory()->franer_site->create();
		$_POST   = array( 'franer_slug' => 'no-nonce' );

		$this->admin->save_meta( $post_id, get_post( $post_id ) );

		$this->assertNotSame( 'no-nonce', get_post_meta( $post_id, '_franer_slug', true ) );
	}

	/**
	 * display_notices() prints and clears queued notices.
	 *
	 * @return void
	 */
	public function test_display_notices_prints_and_clears() {
		set_transient( 'franer_admin_notices_' . $this->admin_id, array( 'Something went wrong' ), 60 );

		ob_start();
		$this->admin->display_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Something went wrong', $html );
		$this->assertFalse( get_transient( 'franer_admin_notices_' . $this->admin_id ) );

		// A second call has nothing to print.
		ob_start();
		$this->admin->display_notices();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * enqueue_assets() loads the admin stylesheet on franer_site screens only.
	 *
	 * @return void
	 */
	public function test_enqueue_assets_on_cpt_screen() {
		set_current_screen( 'franer_site' );

		$this->admin->enqueue_assets( 'post.php' );

		$this->assertTrue( wp_style_is( 'franer-admin', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'franer-admin', 'enqueued' ) );
	}

	/**
	 * enqueue_assets() does nothing on unrelated screens.
	 *
	 * @return void
	 */
	public function test_enqueue_assets_skips_unrelated_screen() {
		set_current_screen( 'edit-post' );
		wp_dequeue_style( 'franer-admin' );

		$this->admin->enqueue_assets( 'edit.php' );

		$this->assertFalse( wp_style_is( 'franer-admin', 'enqueued' ) );
	}

	/**
	 * add_list_filters() renders the status select and author dropdown for the CPT.
	 *
	 * @return void
	 */
	public function test_add_list_filters_renders_for_cpt() {
		ob_start();
		$this->admin->add_list_filters( 'franer_site' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="franer_enabled"', $html );

		// Non-franer post types render nothing.
		ob_start();
		$this->admin->add_list_filters( 'post' );
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * filter_list_query() sets a meta query for the disabled filter on the main query.
	 *
	 * @return void
	 */
	public function test_filter_list_query_applies_disabled_filter() {
		set_current_screen( 'edit-franer_site' );

		$query                     = new WP_Query();
		$query->query_vars['post_type'] = 'franer_site';
		$_GET['franer_enabled']    = 'disabled';

		$previous_main             = $GLOBALS['wp_the_query'] ?? null;
		$GLOBALS['wp_the_query']   = $query; // Make $query->is_main_query() true.

		try {
			$this->admin->filter_list_query( $query );
			$meta_query = $query->get( 'meta_query' );
			$this->assertNotEmpty( $meta_query );
			$this->assertSame( '_franer_enabled', $meta_query[0]['key'] );
		} finally {
			$GLOBALS['wp_the_query'] = $previous_main;
		}
	}

	/**
	 * sort_by_submissions_clauses() injects the count join when ordering by it.
	 *
	 * @return void
	 */
	public function test_sort_by_submissions_clauses_joins_count() {
		set_current_screen( 'edit-franer_site' );

		$query                         = new WP_Query();
		$query->query_vars['post_type'] = 'franer_site';
		$query->query_vars['orderby']  = 'franer_submissions';
		$query->query_vars['order']    = 'asc';

		$previous_main           = $GLOBALS['wp_the_query'] ?? null;
		$GLOBALS['wp_the_query'] = $query;

		try {
			$clauses = $this->admin->sort_by_submissions_clauses(
				array( 'join' => '', 'orderby' => '', 'groupby' => '' ),
				$query
			);
			$this->assertStringContainsString( 'franer_counts', $clauses['join'] );
			$this->assertStringContainsString( 'ASC', $clauses['orderby'] );
		} finally {
			$GLOBALS['wp_the_query'] = $previous_main;
		}
	}

	/**
	 * purge_site_submissions() deletes a deleted activity's submissions.
	 *
	 * @return void
	 */
	public function test_purge_site_submissions() {
		$site_id = self::factory()->franer_site->create();
		self::factory()->franer_submission->create( array( 'site_id' => $site_id ) );

		$repo = new Franer_Submissions_Repository();
		$this->assertSame( 1, (int) $repo->count_site_submissions( $site_id ) );

		$this->admin->purge_site_submissions( $site_id, get_post( $site_id ) );

		$this->assertSame( 0, (int) $repo->count_site_submissions( $site_id ) );
	}

	/**
	 * add_menu() registers the Submissions and Help submenu pages.
	 *
	 * @return void
	 */
	public function test_add_menu_registers_submenus() {
		global $submenu;
		$submenu = array();

		$this->admin->add_menu();

		$parent = 'edit.php?post_type=franer_site';
		$this->assertArrayHasKey( $parent, $submenu );

		$slugs = wp_list_pluck( $submenu[ $parent ], 2 );
		$this->assertContains( 'franer-submissions', $slugs );
		$this->assertContains( 'franer-help', $slugs );
	}
}
