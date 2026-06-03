<?php
/**
 * Repository for Franer site custom posts.
 *
 * @package Franer
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Reads and types Franer site data from the franer_site CPT and its meta.
 */
class Franer_Site_Repository {

	/**
	 * Persist the activity HTML as raw post_content.
	 *
	 * The markup is stored verbatim (KSES is bypassed) because it is arbitrary,
	 * administrator-provided HTML that is only ever rendered inside a sandboxed
	 * iframe; the franer_site post type is non-public, non-REST and excluded from
	 * search, so its post_content is never exposed directly. Storing it in
	 * post_content makes it appear in the native revision diff.
	 *
	 * @param int    $post_id The franer_site post ID.
	 * @param string $html    The raw activity HTML.
	 * @return void
	 */
	public static function set_raw_html( $post_id, $html ) {
		$restore_kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		kses_remove_filters();
		wp_update_post(
			array(
				'ID'           => (int) $post_id,
				'post_content' => (string) $html,
			)
		);
		if ( $restore_kses ) {
			kses_init_filters();
		}
	}

	/**
	 * Retrieve a Franer site post by its slug.
	 *
	 * Matches the _franer_slug meta key.
	 *
	 * @param string $slug The slug to look up.
	 * @return WP_Post|null The matching post, or null when not found.
	 */
	public function get_by_slug( $slug ) {
		$slug = (string) $slug;

		if ( '' === $slug ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'franer_site',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_franer_slug',
						'value' => $slug,
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return null;
		}

		return $query->posts[0];
	}

	/**
	 * Get the fully typed settings array for a site.
	 *
	 * @param int $site_id The site post ID.
	 * @return array Typed settings for the site.
	 */
	public function get_settings( $site_id ) {
		$site_id = (int) $site_id;
		$post    = get_post( $site_id );

		$slug                = (string) get_post_meta( $site_id, '_franer_slug', true );
		// The activity HTML is stored in post_content so it is revisioned and
		// shown in the WordPress revision diff.
		$html                = $post ? (string) $post->post_content : '';
		$accepts_submissions = get_post_meta( $site_id, '_franer_accepts_submissions', true );
		$allowed_roles       = get_post_meta( $site_id, '_franer_allowed_roles', true );
		$allow_multiple      = get_post_meta( $site_id, '_franer_allow_multiple_submissions', true );
		$allow_overwrite     = get_post_meta( $site_id, '_franer_allow_overwrite', true );
		$max_payload_size    = get_post_meta( $site_id, '_franer_max_payload_size', true );
		$enabled             = get_post_meta( $site_id, '_franer_enabled', true );
		$start_date          = (string) get_post_meta( $site_id, '_franer_start_date', true );
		$end_date            = (string) get_post_meta( $site_id, '_franer_end_date', true );
		// Admin-only fields. They are never rendered publicly, sent to the activity
		// iframe, or included in submission exports; the public render partial uses
		// only 'html', 'title' and 'slug'.
		$generation_prompt      = (string) get_post_meta( $site_id, '_franer_generation_prompt', true );
		$view_html              = (string) get_post_meta( $site_id, '_franer_view_html', true );
		$view_generation_prompt = (string) get_post_meta( $site_id, '_franer_view_generation_prompt', true );

		return array(
			'id'                  => $site_id,
			'slug'                => $slug,
			'title'               => $post ? $post->post_title : '',
			'html'                => $html,
			// Visibility is the post status itself: a published Franer is visible.
			'is_visible'          => ( $post && 'publish' === $post->post_status ),
			'accepts_submissions' => Franer_Sanitizer::sanitize_bool( $accepts_submissions ),
			'allowed_roles'       => is_array( $allowed_roles ) ? array_values( $allowed_roles ) : array(),
			'allow_multiple'      => Franer_Sanitizer::sanitize_bool( $allow_multiple ),
			'allow_overwrite'     => Franer_Sanitizer::sanitize_bool( $allow_overwrite ),
			'max_payload_size'    => '' === $max_payload_size ? 256 : (int) $max_payload_size,
			// Schema version is a fixed protocol constant, not a per-site setting.
			'schema_version'      => '1.0',
			// Unset enabled meta (legacy/demo sites) defaults to enabled.
			'enabled'             => ( '0' !== (string) $enabled ),
			'start_date'          => $start_date,
			'end_date'            => $end_date,
			// Admin-only; see the reads above.
			'generation_prompt'      => $generation_prompt,
			'view_html'              => $view_html,
			'view_generation_prompt' => $view_generation_prompt,
		);
	}

	/**
	 * Build the public URL for a site.
	 *
	 * @param WP_Post|int|array $site A site post, post ID, or settings array.
	 * @return string The public URL.
	 */
	public function get_public_url( $site ) {
		$slug = '';

		if ( is_array( $site ) ) {
			$slug = isset( $site['slug'] ) ? (string) $site['slug'] : '';
		} elseif ( $site instanceof WP_Post ) {
			$slug = (string) get_post_meta( $site->ID, '_franer_slug', true );
		} elseif ( is_numeric( $site ) ) {
			$slug = (string) get_post_meta( (int) $site, '_franer_slug', true );
		}

		return home_url( '/franer/' . $slug . '/' );
	}

	/**
	 * Check whether a slug already exists.
	 *
	 * @param string $slug       The slug to check.
	 * @param int    $exclude_id Optional post ID to exclude from the check.
	 * @return bool True when another site already uses the slug.
	 */
	public function slug_exists( $slug, $exclude_id = 0 ) {
		$slug       = (string) $slug;
		$exclude_id = (int) $exclude_id;

		if ( '' === $slug ) {
			return false;
		}

		$args = array(
			'post_type'              => 'franer_site',
			'post_status'            => 'any',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_franer_slug',
					'value' => $slug,
				),
			),
		);

		if ( $exclude_id > 0 ) {
			$args['post__not_in'] = array( $exclude_id );
		}

		$query = new WP_Query( $args );

		return ! empty( $query->posts );
	}
}
