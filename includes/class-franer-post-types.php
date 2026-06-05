<?php
/**
 * Register the custom post type and its meta keys.
 *
 * @link       https://github.com/ateeducacion/wp-franer
 *
 * @package    Franer
 * @subpackage Franer/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Register the franer_site custom post type and its associated meta.
 *
 * @package    Franer
 * @subpackage Franer/includes
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Franer_Post_Types {

	/**
	 * Register the custom post type and its meta keys.
	 *
	 * Hooked on 'init'.
	 *
	 * @return void
	 */
	public function register() {
		$this->register_post_type();
		$this->register_meta();

		// Keep the activity HTML (and settings) in the post revisions so previous
		// versions of a Franer can be viewed and restored (WordPress 6.4+).
		add_filter( 'wp_post_revision_meta_keys', array( $this, 'add_revisioned_meta_keys' ), 10, 2 );

		// Lock down every create/edit/publish/delete operation on franer_site posts
		// to administrators, consistent with the rest of the plugin.
		add_filter( 'map_meta_cap', array( $this, 'map_franer_site_caps' ), 10, 4 );
	}

	/**
	 * Require manage_options for every franer_site capability.
	 *
	 * The franer_site post type uses its own capability_type, so all of its
	 * primitive and meta capabilities are unique. This filter maps every one of
	 * them to manage_options, so only administrators can list, create, edit,
	 * publish, view (private) or delete activities — matching the meta save,
	 * submissions and export screens, which all require manage_options. Other
	 * post types (including core posts) are returned unchanged.
	 *
	 * @param array  $caps    The mapped primitive capabilities.
	 * @param string $cap     The capability being checked.
	 * @param int    $user_id The user ID.
	 * @param array  $args    Additional arguments ($args[0] is the post ID for meta caps).
	 * @return array The (possibly remapped) primitive capabilities.
	 */
	public function map_franer_site_caps( $caps, $cap, $user_id, $args ) {
		// Meta caps carry the post ID; only intervene for franer_site posts.
		if ( in_array( $cap, array( 'edit_post', 'delete_post', 'read_post', 'publish_post' ), true ) ) {
			$post = isset( $args[0] ) ? get_post( $args[0] ) : null;

			// A revision inherits its parent's access: resolve it so viewing and
			// restoring a franer_site revision is gated by manage_options too.
			if ( $post instanceof WP_Post && 'revision' === $post->post_type && $post->post_parent ) {
				$post = get_post( $post->post_parent );
			}

			if ( $post instanceof WP_Post && 'franer_site' === $post->post_type ) {
				return array( 'manage_options' );
			}

			return $caps;
		}

		// Primitive capabilities generated from the franer_site capability_type.
		$franer_primitives = array(
			'edit_franer_sites',
			'edit_others_franer_sites',
			'edit_private_franer_sites',
			'edit_published_franer_sites',
			'publish_franer_sites',
			'read_private_franer_sites',
			'delete_franer_sites',
			'delete_private_franer_sites',
			'delete_published_franer_sites',
			'delete_others_franer_sites',
			'create_franer_sites',
		);

		if ( in_array( $cap, $franer_primitives, true ) ) {
			return array( 'manage_options' );
		}

		return $caps;
	}

	/**
	 * Register Franer meta keys to be stored with post revisions.
	 *
	 * @param array  $keys      Meta keys already flagged for revisioning.
	 * @param string $post_type The post type being revisioned.
	 * @return array The filtered list of revisioned meta keys.
	 */
	public function add_revisioned_meta_keys( $keys, $post_type ) {
		if ( 'franer_site' !== $post_type ) {
			return $keys;
		}

		// Note: the activity HTML lives in post_content (natively revisioned and
		// shown in the revision diff), so it is not listed here.
		$keys[] = '_franer_slug';
		$keys[] = '_franer_accepts_submissions';
		$keys[] = '_franer_allowed_roles';
		$keys[] = '_franer_allow_multiple_submissions';
		$keys[] = '_franer_allow_overwrite';
		$keys[] = '_franer_max_payload_size';
		$keys[] = '_franer_enabled';
		$keys[] = '_franer_start_date';
		$keys[] = '_franer_end_date';
		// Optional generation prompt, submission-view template, and the prompt
		// used to generate that view template (admin-only, never public).
		$keys[] = '_franer_generation_prompt';
		$keys[] = '_franer_view_html';
		$keys[] = '_franer_view_generation_prompt';

		return $keys;
	}

	/**
	 * Register the franer_site custom post type.
	 *
	 * @access private
	 * @return void
	 */
	private function register_post_type() {

		$labels = array(
			'name'               => _x( 'Franer', 'Post type general name', 'franer' ),
			'singular_name'      => _x( 'Franer', 'Post type singular name', 'franer' ),
			'menu_name'          => _x( 'Franer', 'Admin Menu text', 'franer' ),
			'name_admin_bar'     => _x( 'Franer', 'Add New on Toolbar', 'franer' ),
			'add_new'            => __( 'Add New', 'franer' ),
			'add_new_item'       => __( 'Add New Franer', 'franer' ),
			'new_item'           => __( 'New Franer', 'franer' ),
			'edit_item'          => __( 'Edit Franer', 'franer' ),
			'view_item'          => __( 'View Franer', 'franer' ),
			'all_items'          => __( 'All Franers', 'franer' ),
			'search_items'       => __( 'Search Franers', 'franer' ),
			'not_found'          => __( 'No Franers found.', 'franer' ),
			'not_found_in_trash' => __( 'No Franers found in Trash.', 'franer' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => false,
			// A dedicated capability_type (not 'post') gives franer_site its own
			// capabilities, which map_franer_site_caps() restricts to administrators.
			// Without this, any role with edit_others_posts/delete_others_posts
			// (e.g. Editor) could unpublish or delete activities.
			'capability_type'     => array( 'franer_site', 'franer_sites' ),
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'supports'            => array( 'title', 'author', 'revisions' ),
			// A flexing "superhero" dashicon matches the Franer strongman mascot and
			// recolors cleanly with the admin color scheme (unlike a custom image).
			'menu_icon'           => 'dashicons-superhero',
			'has_archive'         => false,
			'rewrite'             => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
		);

		register_post_type( 'franer_site', $args );
	}

	/**
	 * Register the post meta keys for the franer_site post type.
	 *
	 * @access private
	 * @return void
	 */
	private function register_meta() {

		$auth_callback = static function () {
			return current_user_can( 'manage_options' );
		};

		$meta_keys = array(
			'_franer_slug'                       => 'string',
			'_franer_accepts_submissions'        => 'boolean',
			'_franer_allowed_roles'              => 'string',
			'_franer_allow_multiple_submissions' => 'boolean',
			'_franer_allow_overwrite'            => 'boolean',
			'_franer_max_payload_size'           => 'integer',
			'_franer_enabled'                    => 'boolean',
			'_franer_start_date'                 => 'string',
			'_franer_end_date'                   => 'string',
			// Optional, admin-only, never exposed via REST (show_in_rest is false
			// for every key below). The generation prompt and submission-view
			// template can contain long free-form text and raw HTML respectively.
			'_franer_generation_prompt'          => 'string',
			'_franer_view_html'                  => 'string',
			'_franer_view_generation_prompt'     => 'string',
		);

		foreach ( $meta_keys as $meta_key => $type ) {
			register_post_meta(
				'franer_site',
				$meta_key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => $auth_callback,
				)
			);
		}
	}
}
