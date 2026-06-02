<?php
/**
 * Render templates for the franer_site editor metaboxes.
 *
 * Each function renders one metabox. They are defined here (guarded against
 * redeclaration) and called from Franer_Admin.
 *
 * @package    Franer
 * @subpackage Franer/admin/partials
 * @author     Área de Tecnología Educativa
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! function_exists( 'franer_render_settings_metabox' ) ) {
	/**
	 * Render the Site settings metabox.
	 *
	 * @param array $settings  Typed settings from the repository.
	 * @param array $all_roles All available WordPress roles (raw role data).
	 * @return void
	 */
	function franer_render_settings_metabox( array $settings, array $all_roles ) {
		?>
		<table class="form-table franer-form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="franer_slug"><?php esc_html_e( 'Slug', 'franer' ); ?></label>
					</th>
					<td>
						<input type="text" id="franer_slug" name="franer_slug" class="regular-text"
							value="<?php echo esc_attr( $settings['slug'] ); ?>"
							pattern="[a-z0-9-]+" />
						<p class="description">
							<?php esc_html_e( 'Lowercase letters, numbers and hyphens only. Used in the public URL.', 'franer' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'franer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="franer_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( 'Enabled', 'franer' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When disabled, the activity is unavailable to everyone.', 'franer' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Visibility', 'franer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="franer_is_visible" value="1" <?php checked( $settings['is_visible'] ); ?> />
							<?php esc_html_e( 'Visible to allowed users', 'franer' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Availability window', 'franer' ); ?></th>
					<td>
						<label for="franer_start_date"><?php esc_html_e( 'Start', 'franer' ); ?></label>
						<input type="datetime-local" id="franer_start_date" name="franer_start_date"
							value="<?php echo esc_attr( '' === $settings['start_date'] ? '' : str_replace( ' ', 'T', substr( $settings['start_date'], 0, 16 ) ) ); ?>" />
						&nbsp;
						<label for="franer_end_date"><?php esc_html_e( 'End', 'franer' ); ?></label>
						<input type="datetime-local" id="franer_end_date" name="franer_end_date"
							value="<?php echo esc_attr( '' === $settings['end_date'] ? '' : str_replace( ' ', 'T', substr( $settings['end_date'], 0, 16 ) ) ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Optional. Submissions are only accepted within this window (site time). Leave empty for no limit.', 'franer' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Submissions', 'franer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="franer_accepts_submissions" value="1" <?php checked( $settings['accepts_submissions'] ); ?> />
							<?php esc_html_e( 'Accept new submissions', 'franer' ); ?>
						</label>
						<br />
						<label>
							<input type="checkbox" name="franer_allow_multiple_submissions" value="1" <?php checked( $settings['allow_multiple'] ); ?> />
							<?php esc_html_e( 'Allow multiple submissions per user', 'franer' ); ?>
						</label>
						<br />
						<label>
							<input type="checkbox" name="franer_allow_overwrite" value="1" <?php checked( $settings['allow_overwrite'] ); ?> />
							<?php esc_html_e( 'Allow overwriting the previous submission', 'franer' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Allowed roles', 'franer' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Allowed roles', 'franer' ); ?></legend>
							<?php foreach ( $all_roles as $role_key => $role_data ) : ?>
								<label style="display:inline-block;margin-right:1em;">
									<input type="checkbox" name="franer_allowed_roles[]"
										value="<?php echo esc_attr( $role_key ); ?>"
										<?php checked( in_array( $role_key, $settings['allowed_roles'], true ) ); ?> />
									<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'Only logged-in users with one of these roles may view and submit. Administrators are always allowed.', 'franer' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="franer_max_payload_size"><?php esc_html_e( 'Max payload size (KB)', 'franer' ); ?></label>
					</th>
					<td>
						<input type="number" id="franer_max_payload_size" name="franer_max_payload_size"
							min="1" max="5120" step="1"
							value="<?php echo esc_attr( (string) $settings['max_payload_size'] ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Maximum accepted submission size, between 1 and 5120 KB.', 'franer' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="franer_schema_version"><?php esc_html_e( 'Schema version', 'franer' ); ?></label>
					</th>
					<td>
						<input type="text" id="franer_schema_version" name="franer_schema_version" class="small-text"
							value="<?php echo esc_attr( $settings['schema_version'] ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Expected payload schema version (currently 1.0).', 'franer' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}
}

if ( ! function_exists( 'franer_render_html_metabox' ) ) {
	/**
	 * Render the HTML source metabox.
	 *
	 * @param array $settings Typed settings from the repository.
	 * @return void
	 */
	function franer_render_html_metabox( array $settings ) {
		?>
		<div class="franer-notice-box">
			<p>
				<strong><?php esc_html_e( 'Security note:', 'franer' ); ?></strong>
				<?php esc_html_e( 'This HTML is stored exactly as entered and is ONLY ever rendered inside a sandboxed iframe (sandbox="allow-scripts allow-forms", without same-origin access). It cannot read cookies, the page DOM, or make network requests. Paste only trusted, self-contained activities.', 'franer' ); ?>
			</p>
		</div>
		<p>
			<label for="franer_html" class="screen-reader-text"><?php esc_html_e( 'Activity HTML source', 'franer' ); ?></label>
		</p>
		<textarea id="franer_html" name="franer_html" rows="20" class="large-text code franer-code-editor"><?php echo esc_textarea( $settings['html'] ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'A complete, self-contained HTML document implementing window.FranerCollect() and window.FranerSubmit(). See the Help page for a ready-to-use AI prompt.', 'franer' ); ?>
		</p>
		<?php
	}
}

if ( ! function_exists( 'franer_render_public_url_metabox' ) ) {
	/**
	 * Render the Public URL metabox.
	 *
	 * @param array  $settings   Typed settings from the repository.
	 * @param string $public_url The resolved public URL.
	 * @return void
	 */
	function franer_render_public_url_metabox( array $settings, $public_url ) {
		$shortcode = '[franer slug="' . $settings['slug'] . '"]';
		?>
		<p>
			<label for="franer_public_url"><strong><?php esc_html_e( 'Public URL', 'franer' ); ?></strong></label>
		</p>
		<p class="franer-copy-row">
			<input type="text" id="franer_public_url" class="widefat" readonly
				value="<?php echo esc_url( $public_url ); ?>" />
			<button type="button" class="button franer-copy-btn" data-franer-copy-target="franer_public_url">
				<?php esc_html_e( 'Copy', 'franer' ); ?>
			</button>
		</p>
		<p>
			<a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Open public page', 'franer' ); ?>
			</a>
		</p>
		<hr />
		<p>
			<label for="franer_shortcode"><strong><?php esc_html_e( 'Shortcode', 'franer' ); ?></strong></label>
		</p>
		<p class="franer-copy-row">
			<input type="text" id="franer_shortcode" class="widefat" readonly
				value="<?php echo esc_attr( $shortcode ); ?>" />
			<button type="button" class="button franer-copy-btn" data-franer-copy-target="franer_shortcode">
				<?php esc_html_e( 'Copy', 'franer' ); ?>
			</button>
		</p>
		<?php
	}
}

if ( ! function_exists( 'franer_render_submissions_metabox' ) ) {
	/**
	 * Render the per-site Submissions summary metabox.
	 *
	 * @param int    $count      The submission count.
	 * @param string $list_url   URL to the filtered submissions list.
	 * @param string $export_url Nonced export URL.
	 * @return void
	 */
	function franer_render_submissions_metabox( $count, $list_url, $export_url ) {
		?>
		<p>
			<?php
			printf(
				/* translators: %s: number of submissions. */
				esc_html( _n( '%s submission collected.', '%s submissions collected.', (int) $count, 'franer' ) ),
				'<strong>' . esc_html( number_format_i18n( (int) $count ) ) . '</strong>'
			);
			?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( $list_url ); ?>">
				<?php esc_html_e( 'View submissions', 'franer' ); ?>
			</a>
			<a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>">
				<?php esc_html_e( 'Export JSON', 'franer' ); ?>
			</a>
		</p>
		<?php
	}
}

if ( ! function_exists( 'franer_render_help_metabox' ) ) {
	/**
	 * Render the Help metabox.
	 *
	 * @param string $help_url URL to the full Help page.
	 * @return void
	 */
	function franer_render_help_metabox( $help_url ) {
		?>
		<p>
			<?php esc_html_e( 'Need help creating an activity? The Help page includes a ready-to-use AI prompt and the full JavaScript contract.', 'franer' ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( $help_url ); ?>">
				<?php esc_html_e( 'Open Help', 'franer' ); ?>
			</a>
		</p>
		<?php
	}
}
