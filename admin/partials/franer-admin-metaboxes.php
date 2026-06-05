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

if ( ! function_exists( 'franer_render_guide_strip' ) ) {
	/**
	 * Render the collapsible "Create a Franer in 3 steps" onboarding guide.
	 *
	 * Shown above the editor so non-technical staff understand the flow. Uses a
	 * native <details> element so it is collapsible without JavaScript.
	 *
	 * @return void
	 */
	function franer_render_guide_strip() {
		$steps = array(
			array(
				'icon' => 'dashicons-editor-code',
				'title' => __( 'Paste your activity', 'franer' ),
				'desc'  => __( 'A self-contained HTML document (an AI can generate it for you).', 'franer' ),
			),
			array(
				'icon' => 'dashicons-admin-settings',
				'title' => __( 'Set up access', 'franer' ),
				'desc'  => __( 'Who can see it, when, and how many times they may respond.', 'franer' ),
			),
			array(
				'icon' => 'dashicons-share',
				'title' => __( 'Share it', 'franer' ),
				'desc'  => __( 'Use the public URL or the shortcode, then collect the submissions.', 'franer' ),
			),
		);
		?>
		<details class="franer-guide" open>
			<summary class="franer-guide__summary">
				<span class="dashicons dashicons-superhero" aria-hidden="true"></span>
				<?php esc_html_e( 'Create a Franer in 3 steps', 'franer' ); ?>
			</summary>
			<ol class="franer-guide__steps">
				<?php foreach ( $steps as $index => $step ) : ?>
					<li>
						<span class="franer-guide__num"><span class="dashicons <?php echo esc_attr( $step['icon'] ); ?>" aria-hidden="true"></span></span>
						<span class="franer-guide__txt">
							<b><?php echo esc_html( ( $index + 1 ) . '. ' . $step['title'] ); ?></b>
							<i><?php echo esc_html( $step['desc'] ); ?></i>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		</details>
		<?php
	}
}

if ( ! function_exists( 'franer_render_access_metabox' ) ) {
	/**
	 * Render the Access and visibility metabox (slug, status, allowed roles).
	 *
	 * @param array  $settings    Typed settings from the repository.
	 * @param array  $all_roles   All available WordPress roles (raw role data).
	 * @param string $public_base The public base URL ("…/franer/") for the live preview.
	 * @return void
	 */
	function franer_render_access_metabox( array $settings, array $all_roles, $public_base ) {
		$slug = (string) $settings['slug'];
		?>
		<table class="form-table franer-form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="franer_slug"><?php esc_html_e( 'Slug', 'franer' ); ?></label>
					</th>
					<td>
						<input type="text" id="franer_slug" name="franer_slug" class="regular-text"
							value="<?php echo esc_attr( $slug ); ?>" pattern="[a-z0-9-]+"
							data-franer-url-base="<?php echo esc_attr( $public_base ); ?>" />
						<span class="franer-urlprev" data-franer-url-preview>
							<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
							<?php echo esc_html( $public_base ); ?><b data-franer-url-slug><?php echo esc_html( '' === $slug ? '…' : $slug ); ?></b>
						</span>
						<p class="description">
							<?php esc_html_e( 'Lowercase letters, numbers and hyphens only. Used in the public URL.', 'franer' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'franer' ); ?></th>
					<td>
						<label class="franer-toggle">
							<input type="checkbox" name="franer_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<span class="franer-toggle__track" aria-hidden="true"><span class="franer-toggle__thumb"></span></span>
							<span class="franer-toggle__text">
								<span><?php esc_html_e( 'Enabled', 'franer' ); ?></span>
								<span class="franer-toggle__note"><?php esc_html_e( 'When disabled, the activity is unavailable to everyone.', 'franer' ); ?></span>
							</span>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Allowed roles', 'franer' ); ?></th>
					<td>
						<fieldset class="franer-rolewrap">
							<legend class="screen-reader-text"><?php esc_html_e( 'Allowed roles', 'franer' ); ?></legend>
							<?php foreach ( $all_roles as $role_key => $role_data ) : ?>
								<label class="franer-rolechip">
									<input type="checkbox" name="franer_allowed_roles[]"
										value="<?php echo esc_attr( $role_key ); ?>"
										<?php checked( in_array( $role_key, $settings['allowed_roles'], true ) ); ?> />
									<span><?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'Only logged-in users with one of these roles may view and submit. Administrators are always allowed.', 'franer' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}
}

if ( ! function_exists( 'franer_render_submissions_settings_metabox' ) ) {
	/**
	 * Render the Submissions and availability metabox.
	 *
	 * @param array $settings Typed settings from the repository.
	 * @return void
	 */
	function franer_render_submissions_settings_metabox( array $settings ) {
		$toggles = array(
			array( 'franer_accepts_submissions', $settings['accepts_submissions'], __( 'Accept new submissions', 'franer' ) ),
			array( 'franer_allow_multiple_submissions', $settings['allow_multiple'], __( 'Allow multiple submissions per user', 'franer' ) ),
			array( 'franer_allow_overwrite', $settings['allow_overwrite'], __( 'Allow overwriting the previous submission', 'franer' ) ),
		);
		?>
		<table class="form-table franer-form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Reception', 'franer' ); ?></th>
					<td>
						<div class="franer-toggle-stack">
							<?php foreach ( $toggles as $toggle ) : ?>
								<label class="franer-toggle">
									<input type="checkbox" name="<?php echo esc_attr( $toggle[0] ); ?>" value="1" <?php checked( $toggle[1] ); ?> />
									<span class="franer-toggle__track" aria-hidden="true"><span class="franer-toggle__thumb"></span></span>
									<span class="franer-toggle__text"><span><?php echo esc_html( $toggle[2] ); ?></span></span>
								</label>
							<?php endforeach; ?>
						</div>
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
			</tbody>
		</table>
		<?php
	}
}

if ( ! function_exists( 'franer_render_prompt_details' ) ) {
	/**
	 * Render a collapsible (optional) generation-prompt textarea.
	 *
	 * Shared by the activity and submission-view editors. The value is escaped
	 * with esc_textarea() so embedded HTML/JS snippets are never executed.
	 *
	 * @param string $field_id   The textarea id/name.
	 * @param string $summary    The <summary> label.
	 * @param string $hint       The description shown under the textarea.
	 * @param string $value      The current prompt value.
	 * @return void
	 */
	function franer_render_prompt_details( $field_id, $summary, $hint, $value ) {
		?>
		<details class="franer-prompt-details">
			<summary><?php echo esc_html( $summary ); ?></summary>
			<p class="franer-copy-row">
				<button type="button" class="button franer-copy-btn" data-franer-copy-target="<?php echo esc_attr( $field_id ); ?>">
					<?php esc_html_e( 'Copy', 'franer' ); ?>
				</button>
			</p>
			<label for="<?php echo esc_attr( $field_id ); ?>" class="screen-reader-text"><?php echo esc_html( $summary ); ?></label>
			<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>"
				rows="10" class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
			<p class="description"><?php echo esc_html( $hint ); ?></p>
		</details>
		<?php
	}
}

if ( ! function_exists( 'franer_render_ai_prompt_copy' ) ) {
	/**
	 * Render a "Copy AI prompt" button with the prompt text held inline.
	 *
	 * Lets administrators copy the ready-to-use AI prompt without leaving the
	 * editor for the Help page. The prompt lives in a visually-hidden, readonly
	 * textarea so the existing copy-to-clipboard handler can read its value.
	 *
	 * @param string $field_id The id of the hidden prompt holder.
	 * @param string $prompt   The prompt text to copy.
	 * @return void
	 */
	function franer_render_ai_prompt_copy( $field_id, $prompt ) {
		if ( '' === trim( (string) $prompt ) ) {
			return;
		}
		?>
		<p class="franer-ai-prompt">
			<button type="button" class="button button-secondary franer-copy-btn"
				data-franer-copy-target="<?php echo esc_attr( $field_id ); ?>"
				data-franer-copy-status="<?php echo esc_attr( $field_id . '_status' ); ?>">
				<span class="dashicons dashicons-superhero" aria-hidden="true"></span>
				<?php esc_html_e( 'Copy AI prompt', 'franer' ); ?>
			</button>
			<span id="<?php echo esc_attr( $field_id . '_status' ); ?>" class="franer-copy-status" role="status" aria-live="polite"></span>
			<label for="<?php echo esc_attr( $field_id ); ?>" class="screen-reader-text"><?php esc_html_e( 'Ready-to-use AI prompt', 'franer' ); ?></label>
			<textarea id="<?php echo esc_attr( $field_id ); ?>" class="screen-reader-text" readonly tabindex="-1"><?php echo esc_textarea( $prompt ); ?></textarea>
		</p>
		<?php
	}
}

if ( ! function_exists( 'franer_render_security_note' ) ) {
	/**
	 * Render a compact security notice with a collapsible "Learn more" section.
	 *
	 * Replaces the old always-on yellow warning block with a discreet inline notice
	 * whose technical detail is tucked behind a native <details> disclosure.
	 *
	 * @param string $lead    The short, always-visible message.
	 * @param string $details The detailed message revealed on "Learn more".
	 * @return void
	 */
	function franer_render_security_note( $lead, $details ) {
		?>
		<div class="franer-secnote">
			<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
			<div class="franer-secnote__body">
				<span><strong><?php esc_html_e( 'Secure environment.', 'franer' ); ?></strong> <?php echo esc_html( $lead ); ?></span>
				<details class="franer-secnote__more">
					<summary><?php esc_html_e( 'Learn more', 'franer' ); ?></summary>
					<p><?php echo esc_html( $details ); ?></p>
				</details>
			</div>
		</div>
		<?php
	}
}

if ( ! function_exists( 'franer_render_html_metabox' ) ) {
	/**
	 * Render the HTML source metabox.
	 *
	 * Presents two tabs: the activity HTML (shown to end users) and the optional
	 * submission-view template (shown only to administrators when reviewing an
	 * attempt). Each tab also offers a collapsible field to store the prompt used
	 * to generate that HTML.
	 *
	 * @param array  $settings        Typed settings from the repository.
	 * @param string $activity_prompt Ready-to-use AI prompt for the activity HTML.
	 * @param string $view_prompt     Ready-to-use AI prompt for the submission view.
	 * @return void
	 */
	function franer_render_html_metabox( array $settings, $activity_prompt = '', $view_prompt = '' ) {
		$view_html              = isset( $settings['view_html'] ) ? $settings['view_html'] : '';
		$generation_prompt      = isset( $settings['generation_prompt'] ) ? $settings['generation_prompt'] : '';
		$view_generation_prompt = isset( $settings['view_generation_prompt'] ) ? $settings['view_generation_prompt'] : '';
		?>
		<div class="franer-tabs" data-franer-tabs>
			<div class="franer-tabs__list" role="tablist" aria-label="<?php esc_attr_e( 'HTML editors', 'franer' ); ?>">
				<button type="button" class="franer-tabs__tab" role="tab" id="franer-tab-activity"
					aria-controls="franer-panel-activity" aria-selected="true">
					<?php esc_html_e( 'Activity HTML', 'franer' ); ?>
				</button>
				<button type="button" class="franer-tabs__tab" role="tab" id="franer-tab-view"
					aria-controls="franer-panel-view" aria-selected="false" tabindex="-1">
					<?php esc_html_e( 'Submission View HTML', 'franer' ); ?>
					<span class="franer-tab-badge"><?php esc_html_e( 'Optional', 'franer' ); ?></span>
				</button>
			</div>

			<div class="franer-tabs__panel" role="tabpanel" id="franer-panel-activity" aria-labelledby="franer-tab-activity">
				<?php
				franer_render_security_note(
					__( 'This HTML runs isolated in a sandboxed iframe; it cannot read cookies or send data outside.', 'franer' ),
					__( 'This HTML is stored exactly as entered and is ONLY ever rendered inside a sandboxed iframe (sandbox="allow-scripts allow-forms", without same-origin access). It cannot read cookies/storage or the page DOM. It MAY load external libraries, fonts and images over https, but an injected Content-Security-Policy blocks fetch/XHR/forms so answers can only leave via postMessage. Because remote scripts run in front of your users, paste only activities you trust.', 'franer' )
				);
				?>
				<p>
					<label for="franer_html" class="screen-reader-text"><?php esc_html_e( 'Activity HTML source', 'franer' ); ?></label>
				</p>
				<div class="franer-drop" data-franer-drop>
					<textarea id="franer_html" name="franer_html" rows="20" class="large-text code franer-code-editor"><?php echo esc_textarea( $settings['html'] ); ?></textarea>
				</div>
				<p class="description">
					<?php esc_html_e( 'A complete, self-contained HTML document implementing window.FranerCollect() and window.FranerSubmit().', 'franer' ); ?>
					<?php esc_html_e( 'You can also drag and drop an .html file here to load its contents.', 'franer' ); ?>
				</p>
				<?php
				franer_render_ai_prompt_copy( 'franer_ai_prompt_activity', $activity_prompt );
				franer_render_prompt_details(
					'franer_generation_prompt',
					__( 'Prompt used to generate this activity', 'franer' ),
					__( 'Optional field. Store here the prompt used to generate this HTML. It can help with future modifications, adjustments or regeneration of the activity.', 'franer' ),
					$generation_prompt
				);
				?>
			</div>

			<div class="franer-tabs__panel" role="tabpanel" id="franer-panel-view" aria-labelledby="franer-tab-view" hidden>
				<?php
				franer_render_security_note(
					__( 'Optional template shown only to administrators; it also runs isolated in a sandboxed iframe.', 'franer' ),
					__( 'Like the activity HTML, this template is stored raw and only ever rendered inside a sandboxed iframe. It is shown only to administrators and receives every submission JSON through postMessage. It never receives REST nonces or admin URLs, and an injected Content-Security-Policy blocks fetch/XHR/forms so the aggregated answers cannot be exfiltrated. It may still load libraries, fonts and images over https — paste only templates you trust.', 'franer' )
				);
				?>
				<p>
					<label for="franer_view_html" class="screen-reader-text"><?php esc_html_e( 'Submission view HTML source', 'franer' ); ?></label>
				</p>
				<div class="franer-drop" data-franer-drop>
					<textarea id="franer_view_html" name="franer_view_html" rows="20" class="large-text code franer-code-editor"><?php echo esc_textarea( $view_html ); ?></textarea>
				</div>
				<p class="description">
					<?php esc_html_e( 'Optional HTML used to render an overview of all submissions for this Franer (totals, charts, …). It is only shown to administrators from the submissions screen and receives every submission JSON through postMessage.', 'franer' ); ?>
					<?php esc_html_e( 'You can also drag and drop an .html file here to load its contents.', 'franer' ); ?>
				</p>
				<?php
				franer_render_ai_prompt_copy( 'franer_ai_prompt_view', $view_prompt );
				franer_render_prompt_details(
					'franer_view_generation_prompt',
					__( 'Prompt used to generate this submission view', 'franer' ),
					__( 'Optional field. Store here the prompt used to generate the submission-view template. It can help with future modifications, adjustments or regeneration of the view.', 'franer' ),
					$view_generation_prompt
				);
				?>
			</div>
		</div>
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
		<p class="franer-sharelinks">
			<a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener noreferrer">
				<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
				<?php esc_html_e( 'Open public page', 'franer' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'fullscreen', '1', $public_url ) ); ?>" target="_blank" rel="noopener noreferrer">
				<span class="dashicons dashicons-fullscreen-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Open in fullscreen', 'franer' ); ?>
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
	 * @param int    $count          The submission count.
	 * @param string $list_url       URL to the filtered submissions list.
	 * @param string $export_url     Nonced JSON export URL.
	 * @param string $overview_url   URL to the rendered submissions overview.
	 * @param string $export_csv_url Nonced CSV export URL.
	 * @return void
	 */
	function franer_render_submissions_metabox( $count, $list_url, $export_url, $overview_url = '', $export_csv_url = '' ) {
		?>
		<div class="franer-enviosmini">
			<span class="franer-enviosmini__big"><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></span>
			<span class="franer-enviosmini__txt">
				<?php echo esc_html( _n( 'response collected', 'responses collected', (int) $count, 'franer' ) ); ?>
			</span>
		</div>
		<p>
			<a class="button" href="<?php echo esc_url( $list_url ); ?>">
				<?php esc_html_e( 'View submissions', 'franer' ); ?>
			</a>
		</p>
		<p class="franer-download-options">
			<strong class="franer-download-options__label"><?php esc_html_e( 'Download submissions:', 'franer' ); ?></strong>
			<a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>">
				<?php esc_html_e( 'Export JSON', 'franer' ); ?>
			</a>
			<?php if ( '' !== $export_csv_url ) : ?>
				<a class="button button-secondary" href="<?php echo esc_url( $export_csv_url ); ?>">
					<?php esc_html_e( 'Export CSV', 'franer' ); ?>
				</a>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'JSON keeps the full nested data; CSV gives one row per submission with each answer in its own column for spreadsheets.', 'franer' ); ?>
		</p>
		<?php if ( '' !== $overview_url ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url( $overview_url ); ?>">
					<?php esc_html_e( 'View overview', 'franer' ); ?>
				</a>
				<span class="description"><?php esc_html_e( 'Renders all submissions with the Submission View HTML template.', 'franer' ); ?></span>
			</p>
		<?php endif; ?>
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
