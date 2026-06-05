<?php
/**
 * Custom factory for the franer_site custom post type.
 *
 * Mirrors the WordPress core factory pattern (extends WP_UnitTest_Factory_For_Post)
 * so tests can create fully-configured Franer activities in one call instead of
 * repeating the post + meta + raw-HTML setup by hand.
 *
 * @package Franer
 */

/**
 * Class WP_UnitTest_Factory_For_Franer_Site.
 *
 * Usage: $site_id = self::factory()->franer_site->create( array( 'slug' => 'demo' ) );
 */
class WP_UnitTest_Factory_For_Franer_Site extends WP_UnitTest_Factory_For_Post {

	/**
	 * Constructor.
	 *
	 * @param object|null $factory The global factory instance.
	 */
	public function __construct( $factory = null ) {
		parent::__construct( $factory );

		// Extend the post defaults with franer_site specifics. The keys that are not
		// real wp_insert_post fields (slug, html, the booleans) are consumed in
		// create_object() and stored as post meta.
		$this->default_generation_definitions = array_merge(
			$this->default_generation_definitions,
			array(
				'post_type'           => 'franer_site',
				'post_status'         => 'publish',
				'post_title'          => new WP_UnitTest_Generator_Sequence( 'Franer activity %s' ),
				'slug'                => new WP_UnitTest_Generator_Sequence( 'activity-%s' ),
				'html'                => '',
				'enabled'             => true,
				'accepts_submissions' => true,
				'allow_multiple'      => false,
				'allow_overwrite'     => false,
				// Note: array-valued defaults (e.g. allowed_roles) cannot live in the
				// generation definitions — WP_UnitTest_Factory::generate_args() only
				// accepts scalars or generators — so they are defaulted in create_object().
			)
		);
	}

	/**
	 * Create a franer_site post with its Franer meta.
	 *
	 * @param array $args Merged generation arguments.
	 * @return int|WP_Error The new post ID, or WP_Error on failure.
	 */
	public function create_object( $args ) {
		// Pull the franer-specific fields out before delegating the post creation
		// to the parent factory (which only understands wp_insert_post fields).
		$slug                = isset( $args['slug'] ) ? (string) $args['slug'] : '';
		$html                = isset( $args['html'] ) ? (string) $args['html'] : '';
		$enabled             = ! empty( $args['enabled'] );
		$accepts_submissions = ! empty( $args['accepts_submissions'] );
		$allow_multiple      = ! empty( $args['allow_multiple'] );
		$allow_overwrite     = ! empty( $args['allow_overwrite'] );
		$allowed_roles       = isset( $args['allowed_roles'] ) && is_array( $args['allowed_roles'] )
			? array_values( $args['allowed_roles'] )
			: array( 'subscriber' );

		unset(
			$args['slug'],
			$args['html'],
			$args['enabled'],
			$args['accepts_submissions'],
			$args['allow_multiple'],
			$args['allow_overwrite'],
			$args['allowed_roles']
		);

		$post_id = parent::create_object( $args );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_franer_slug', $slug );
		update_post_meta( $post_id, '_franer_enabled', $enabled ? '1' : '0' );
		update_post_meta( $post_id, '_franer_accepts_submissions', $accepts_submissions ? '1' : '0' );
		update_post_meta( $post_id, '_franer_allow_multiple_submissions', $allow_multiple ? '1' : '0' );
		update_post_meta( $post_id, '_franer_allow_overwrite', $allow_overwrite ? '1' : '0' );
		update_post_meta( $post_id, '_franer_allowed_roles', $allowed_roles );

		// Store the activity HTML exactly like the real save path (KSES bypassed),
		// so <script>/<style> blocks survive into post_content.
		if ( '' !== $html ) {
			Franer_Site_Repository::set_raw_html( $post_id, $html );
		}

		return $post_id;
	}

	/**
	 * Retrieve a franer_site post by ID.
	 *
	 * @param int $object_id The post ID.
	 * @return WP_Post|false The post when it is a franer_site, false otherwise.
	 */
	public function get_object_by_id( $object_id ) {
		$post = get_post( $object_id );

		if ( $post instanceof WP_Post && 'franer_site' === $post->post_type ) {
			return $post;
		}

		return false;
	}
}
