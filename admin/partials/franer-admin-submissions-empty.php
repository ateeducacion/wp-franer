<?php
/**
 * Empty state for the submissions screen (no activity selected).
 *
 * Explains what will appear and offers a direct action: picking an activity.
 *
 * @var array $site_posts      franer_site WP_Post objects.
 * @var array $activity_counts site_id => submission count.
 *
 * @package    Franer
 * @subpackage Franer/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<div class="franer-emptystate">
	<div class="franer-emptystate__icon"><span class="dashicons dashicons-list-view" aria-hidden="true"></span></div>
	<h2><?php esc_html_e( 'Choose an activity to see its submissions', 'franer' ); ?></h2>
	<p><?php esc_html_e( 'Once you pick a Franer you will see the collected responses here, an automatic summary and the export options.', 'franer' ); ?></p>
	<?php if ( ! empty( $site_posts ) ) : ?>
		<div class="franer-emptystate__chips">
			<?php
			foreach ( $site_posts as $franer_site_post ) :
				$franer_chip_url = add_query_arg(
					array(
						'post_type' => 'franer_site',
						'page'      => 'franer-submissions',
						'site_id'   => (int) $franer_site_post->ID,
					),
					admin_url( 'edit.php' )
				);
				$franer_chip_count = isset( $activity_counts[ $franer_site_post->ID ] ) ? (int) $activity_counts[ $franer_site_post->ID ] : 0;
				?>
				<a class="franer-actchip" href="<?php echo esc_url( $franer_chip_url ); ?>">
					<span class="dashicons dashicons-superhero" aria-hidden="true"></span>
					<?php echo esc_html( $franer_site_post->post_title ); ?>
					<span class="franer-actchip__n"><?php echo esc_html( number_format_i18n( $franer_chip_count ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
