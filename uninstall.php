<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin data: franer_site posts and their meta, the submissions
 * table, and plugin options.
 *
 * @link       https://github.com/ateeducacion/wp-franer
 *
 * @package    Franer
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

global $wpdb;

// Delete all franer_site posts and their meta.
$franer_posts = get_posts(
	array(
		'post_type'   => 'franer_site',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);

foreach ( $franer_posts as $franer_post_id ) {
	wp_delete_post( $franer_post_id, true );
}

// Drop the submissions table.
$franer_table_name = $wpdb->prefix . 'franer_submissions';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS {$franer_table_name}" );

// Delete plugin options.
delete_option( 'franer_version' );
delete_option( 'franer_flush_rewrites' );
delete_option( 'franer_db_version' );
delete_option( 'franer_demo_seeded' );
