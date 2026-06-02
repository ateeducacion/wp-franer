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
			'capability_type'     => 'post',
			'hierarchical'        => false,
			'supports'            => array( 'title' ),
			'menu_icon'           => 'dashicons-welcome-widgets-menus',
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
			'_franer_html'                       => 'string',
			'_franer_is_visible'                 => 'boolean',
			'_franer_accepts_submissions'        => 'boolean',
			'_franer_allowed_roles'              => 'string',
			'_franer_allow_multiple_submissions' => 'boolean',
			'_franer_allow_overwrite'            => 'boolean',
			'_franer_max_payload_size'           => 'integer',
			'_franer_schema_version'             => 'string',
			'_franer_enabled'                    => 'boolean',
			'_franer_start_date'                 => 'string',
			'_franer_end_date'                   => 'string',
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
