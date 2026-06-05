<?php
/**
 * Tests for Franer_Admin_Metaboxes.
 *
 * Exercises metabox registration and every render callback. Renders are captured
 * with an output buffer (the callbacks echo through the shared partial), so we
 * assert they produce markup without inspecting exact HTML.
 *
 * @package Franer
 */

/**
 * Verifies metabox registration on the franer_site editor and the render output.
 */
class AdminMetaboxesTest extends Franer_Test_Base {

	/**
	 * Metaboxes instance under test.
	 *
	 * @var Franer_Admin_Metaboxes
	 */
	private $metaboxes;

	/**
	 * A franer_site post object.
	 *
	 * @var WP_Post
	 */
	private $site;

	/**
	 * Create the instance and a site fixture.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->metaboxes = new Franer_Admin_Metaboxes();
		$site_id         = self::factory()->franer_site->create(
			array(
				'slug' => 'mb-demo',
				'html' => '<!doctype html><html><body>hi</body></html>',
			)
		);
		$this->site = get_post( $site_id );
	}

	/**
	 * add_meta_boxes() should register the Franer editor metaboxes.
	 *
	 * @return void
	 */
	public function test_add_meta_boxes_registers_boxes() {
		global $wp_meta_boxes;

		set_current_screen( 'franer_site' );
		$wp_meta_boxes = array();

		$this->metaboxes->add_meta_boxes();

		$ids = array();
		foreach ( (array) ( $wp_meta_boxes['franer_site'] ?? array() ) as $context => $priorities ) {
			foreach ( (array) $priorities as $boxes ) {
				$ids = array_merge( $ids, array_keys( (array) $boxes ) );
			}
		}

		$this->assertContains( 'franer_site_html', $ids );
		$this->assertContains( 'franer_site_access', $ids );
		$this->assertContains( 'franer_site_submissions_settings', $ids );
		$this->assertContains( 'franer_site_public_url', $ids );
		$this->assertContains( 'franer_site_submissions', $ids );
		$this->assertContains( 'franer_site_help', $ids );
	}

	/**
	 * render_guide_strip() should bail out for non-franer posts and render otherwise.
	 *
	 * @return void
	 */
	public function test_render_guide_strip_bails_for_other_post_types() {
		$other = self::factory()->post->create_and_get( array( 'post_type' => 'post' ) );

		ob_start();
		$this->metaboxes->render_guide_strip( $other );
		$this->assertSame( '', ob_get_clean() );

		ob_start();
		$this->metaboxes->render_guide_strip( $this->site );
		$this->assertNotEmpty( ob_get_clean() );
	}

	/**
	 * The access metabox should render and emit the shared save nonce field.
	 *
	 * @return void
	 */
	public function test_render_access_metabox_outputs_nonce() {
		ob_start();
		$this->metaboxes->render_access_metabox( $this->site );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'franer_site_nonce', $html );
	}

	/**
	 * Every remaining render callback should produce non-empty markup.
	 *
	 * @return void
	 */
	public function test_render_callbacks_produce_markup() {
		$callbacks = array(
			'render_html_metabox',
			'render_submissions_settings_metabox',
			'render_public_url_metabox',
			'render_submissions_metabox',
			'render_help_metabox',
		);

		foreach ( $callbacks as $callback ) {
			ob_start();
			$this->metaboxes->{$callback}( $this->site );
			$html = ob_get_clean();

			$this->assertNotEmpty( $html, "{$callback} should render markup." );
		}
	}

	/**
	 * The submissions summary metabox should reflect the stored count.
	 *
	 * @return void
	 */
	public function test_submissions_metabox_reflects_count() {
		self::factory()->franer_submission->create( array( 'site_id' => $this->site->ID ) );
		self::factory()->franer_submission->create( array( 'site_id' => $this->site->ID ) );

		ob_start();
		$this->metaboxes->render_submissions_metabox( $this->site );
		$html = ob_get_clean();

		$this->assertStringContainsString( '2', $html );
	}
}
