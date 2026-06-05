<?php
/**
 * Tests for the full Franer_Demo_Data seeding flow and sample submissions.
 *
 * Complements DemoDataTest (which focuses on the single-activity revision
 * behaviour) by exercising seed_all(), the idempotency gate and the sample
 * submission generators end to end.
 *
 * @package Franer
 */

/**
 * Verifies bundled demo seeding and the sample-submission generators.
 */
class DemoDataSeedingTest extends Franer_Test_Base {

	/**
	 * Ensure an administrator exists so seeded posts get an author.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * seed_all() should create both demo activities and set the seeded flag.
	 *
	 * @return void
	 */
	public function test_seed_all_creates_both_activities() {
		$mcode_id = Franer_Demo_Data::seed_all();

		$this->assertGreaterThan( 0, $mcode_id );

		$repository = new Franer_Site_Repository();
		$this->assertInstanceOf( WP_Post::class, $repository->get_by_slug( Franer_Demo_Data::DEMO_SLUG ) );
		$this->assertInstanceOf( WP_Post::class, $repository->get_by_slug( Franer_Demo_Data::CANARIO_SLUG ) );

		$this->assertSame( '1', get_option( Franer_Demo_Data::SEEDED_OPTION ) );
	}

	/**
	 * seed_all() should be idempotent: re-running returns the same activity, not a copy.
	 *
	 * @return void
	 */
	public function test_seed_all_is_idempotent() {
		$first  = Franer_Demo_Data::seed_all();
		$second = Franer_Demo_Data::seed_all();

		$this->assertSame( $first, $second );

		$query = new WP_Query(
			array(
				'post_type'      => 'franer_site',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		// Exactly the two bundled activities, no duplicates.
		$this->assertCount( 2, $query->posts );
	}

	/**
	 * maybe_seed() respects the option gate.
	 *
	 * @return void
	 */
	public function test_maybe_seed_respects_flag() {
		update_option( Franer_Demo_Data::SEEDED_OPTION, '1' );

		Franer_Demo_Data::maybe_seed();

		$repository = new Franer_Site_Repository();
		$this->assertNull( $repository->get_by_slug( Franer_Demo_Data::DEMO_SLUG ) );

		// Clearing the flag lets it seed.
		delete_option( Franer_Demo_Data::SEEDED_OPTION );
		Franer_Demo_Data::maybe_seed();
		$this->assertInstanceOf( WP_Post::class, $repository->get_by_slug( Franer_Demo_Data::DEMO_SLUG ) );
	}

	/**
	 * seed_sample_submissions() should populate both activities with rows, once.
	 *
	 * @return void
	 */
	public function test_seed_sample_submissions_populates_activities() {
		// Several users so the generator can cycle submitters.
		self::factory()->user->create_many( 8, array( 'role' => 'subscriber' ) );

		Franer_Demo_Data::seed_all();
		Franer_Demo_Data::seed_sample_submissions();

		$repository  = new Franer_Site_Repository();
		$submissions = new Franer_Submissions_Repository();

		$canario_id = $repository->get_by_slug( Franer_Demo_Data::CANARIO_SLUG )->ID;
		$mcode_id   = $repository->get_by_slug( Franer_Demo_Data::DEMO_SLUG )->ID;

		$canario_count = (int) $submissions->count_site_submissions( $canario_id );
		$mcode_count   = (int) $submissions->count_site_submissions( $mcode_id );

		$this->assertGreaterThan( 0, $canario_count );
		$this->assertGreaterThan( 0, $mcode_count );

		// Idempotent: a second pass adds nothing.
		Franer_Demo_Data::seed_sample_submissions();
		$this->assertSame( $canario_count, (int) $submissions->count_site_submissions( $canario_id ) );
		$this->assertSame( $mcode_count, (int) $submissions->count_site_submissions( $mcode_id ) );
	}

	/**
	 * seed_sample_submissions() bails cleanly when there are no users to attribute to.
	 *
	 * @return void
	 */
	public function test_seed_sample_submissions_without_users_is_noop() {
		// Remove every user, then seed activities only.
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $uid ) {
			wp_delete_user( (int) $uid );
		}

		Franer_Demo_Data::seed_all();

		// Should not error even though there are no submitters.
		Franer_Demo_Data::seed_sample_submissions();

		$repository  = new Franer_Site_Repository();
		$submissions = new Franer_Submissions_Repository();
		$canario     = $repository->get_by_slug( Franer_Demo_Data::CANARIO_SLUG );

		$this->assertSame( 0, (int) $submissions->count_site_submissions( $canario->ID ) );
	}
}
