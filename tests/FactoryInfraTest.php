<?php
/**
 * Smoke tests for the Franer test factories and base test case.
 *
 * @package Franer
 */

/**
 * Verifies that the franer_site and franer_submission factories produce valid rows.
 */
class FactoryInfraTest extends Franer_Test_Base {

	/**
	 * The site factory should create a published franer_site with typed meta.
	 *
	 * @return void
	 */
	public function test_site_factory_creates_configured_activity() {
		$site_id = self::factory()->franer_site->create(
			array(
				'slug' => 'infra-demo',
				'html' => '<!doctype html><html><body>hi</body></html>',
			)
		);

		$this->assertIsInt( $site_id );

		$settings = ( new Franer_Site_Repository() )->get_settings( $site_id );

		$this->assertSame( 'franer_site', get_post_type( $site_id ) );
		$this->assertSame( 'infra-demo', $settings['slug'] );
		$this->assertTrue( $settings['is_visible'] );
		$this->assertTrue( $settings['accepts_submissions'] );
		$this->assertContains( 'subscriber', $settings['allowed_roles'] );
		$this->assertStringContainsString( '<body>hi</body>', $settings['html'] );
	}

	/**
	 * create_and_get() should return the franer_site WP_Post.
	 *
	 * @return void
	 */
	public function test_site_factory_create_and_get_returns_post() {
		$post = self::factory()->franer_site->create_and_get();

		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'franer_site', $post->post_type );
	}

	/**
	 * The submission factory should insert a row tied to a site and user.
	 *
	 * @return void
	 */
	public function test_submission_factory_inserts_row() {
		$site_id = self::factory()->franer_site->create();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$submission_id = self::factory()->franer_submission->create(
			array(
				'site_id' => $site_id,
				'user_id' => $user_id,
				'payload' => array( 'q1' => 'answer' ),
			)
		);

		$this->assertIsInt( $submission_id );
		$this->assertGreaterThan( 0, $submission_id );

		$repo  = new Franer_Submissions_Repository();
		$count = $repo->count_site_submissions( $site_id );
		$this->assertSame( 1, (int) $count );

		$latest = $repo->get_latest_user_submission( $site_id, $user_id );
		$this->assertSame( array( 'q1' => 'answer' ), json_decode( $latest['payload_json'], true ) );
	}

	/**
	 * A bare submission create() should auto-create its site and user.
	 *
	 * @return void
	 */
	public function test_submission_factory_autocreates_dependencies() {
		$submission_id = self::factory()->franer_submission->create();

		$this->assertIsInt( $submission_id );
		$this->assertGreaterThan( 0, $submission_id );
	}
}
