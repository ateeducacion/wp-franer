<?php
/**
 * Stats strip + auto-generated submissions summary, shown above the table.
 *
 * The summary is built from the answers themselves (no custom template needed).
 * When a custom _franer_view_html template exists, only the stats strip renders
 * and the panel defers to the "View overview" override.
 *
 * @var array $franer_summary    Franer_Submission_Schema::build_summary() result.
 * @var bool  $franer_show_panel Whether to render the full auto-summary panel.
 *
 * @package    Franer
 * @subpackage Franer/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! function_exists( 'franer_render_summary_bar' ) ) {
	/**
	 * Render one labelled distribution bar.
	 *
	 * @param string $label The row label.
	 * @param int    $count The row count.
	 * @param int    $total The total to compute the percentage against.
	 * @return void
	 */
	function franer_render_summary_bar( $label, $count, $total ) {
		$pct = $total > 0 ? (int) round( $count / $total * 100 ) : 0;
		?>
		<div class="franer-bar">
			<div class="franer-bar__top">
				<span class="franer-bar__label"><?php echo esc_html( $label ); ?></span>
				<span class="franer-bar__val"><?php echo esc_html( number_format_i18n( $count ) . ' · ' . $pct . '%' ); ?></span>
			</div>
			<div class="franer-bar__track"><span class="franer-bar__fill" style="width:<?php echo esc_attr( $pct ); ?>%"></span></div>
		</div>
		<?php
	}
}
?>
<div class="franer-statstrip">
	<?php foreach ( $franer_summary['stats'] as $franer_stat ) : ?>
		<div class="franer-stat">
			<span class="franer-stat__big"><?php echo esc_html( $franer_stat['big'] ); ?></span>
			<span class="franer-stat__label"><?php echo esc_html( $franer_stat['label'] ); ?></span>
		</div>
	<?php endforeach; ?>
</div>

<?php if ( $franer_show_panel && ( ! empty( $franer_summary['distributions'] ) || null !== $franer_summary['rating'] || ! empty( $franer_summary['comments'] ) ) ) : ?>
	<div class="postbox franer-summary-box">
		<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Automatic summary', 'franer' ); ?></h2></div>
		<div class="inside">
			<p class="description franer-summary-intro">
				<?php esc_html_e( 'Generated automatically from the responses. You do not need to create an HTML template.', 'franer' ); ?>
			</p>
			<div class="franer-summary__grid">
				<?php foreach ( $franer_summary['distributions'] as $franer_dist ) : ?>
					<div class="franer-sumcard">
						<h3><?php echo esc_html( $franer_dist['label'] ); ?></h3>
						<?php
						foreach ( $franer_dist['distribution'] as $franer_value => $franer_count ) {
							franer_render_summary_bar( (string) $franer_value, (int) $franer_count, (int) $franer_dist['count'] );
						}
						?>
					</div>
				<?php endforeach; ?>

				<?php if ( null !== $franer_summary['rating'] ) : ?>
					<?php $franer_rating = $franer_summary['rating']; ?>
					<div class="franer-sumcard">
						<h3><?php echo esc_html( $franer_rating['label'] ); ?></h3>
						<div class="franer-ratingbig">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- stars_html() returns escaped markup.
							echo Franer_Submissions_List_Table::stars_html( $franer_rating['avg'] );
							?>
							<b><?php echo esc_html( number_format_i18n( $franer_rating['avg'], 1 ) ); ?></b>
							<i>
								<?php
								/* translators: %s: number of ratings. */
								echo esc_html( sprintf( _n( 'average of %s rating', 'average of %s ratings', (int) $franer_rating['count'], 'franer' ), number_format_i18n( (int) $franer_rating['count'] ) ) );
								?>
							</i>
						</div>
						<?php
						for ( $franer_step = 5; $franer_step >= 1; $franer_step-- ) {
							$franer_step_count = isset( $franer_rating['hist'][ $franer_step ] ) ? (int) $franer_rating['hist'][ $franer_step ] : 0;
							/* translators: %d: number of stars. */
							franer_render_summary_bar( sprintf( _n( '%d star', '%d stars', $franer_step, 'franer' ), $franer_step ), $franer_step_count, (int) $franer_rating['count'] );
						}
						?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $franer_summary['comments'] ) ) : ?>
					<div class="franer-sumcard franer-sumcard--wide">
						<h3>
							<?php esc_html_e( 'Comments', 'franer' ); ?>
							<span class="franer-muted">(<?php echo esc_html( number_format_i18n( count( $franer_summary['comments'] ) ) ); ?>)</span>
						</h3>
						<ul class="franer-commentlist">
							<?php foreach ( $franer_summary['comments'] as $franer_comment ) : ?>
								<li>
									<b><?php echo esc_html( $franer_comment['user'] ); ?></b>
									<i class="franer-muted"><?php echo esc_html( $franer_comment['created'] ); ?></i>
									<p>“<?php echo esc_html( $franer_comment['text'] ); ?>”</p>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
<?php endif; ?>
