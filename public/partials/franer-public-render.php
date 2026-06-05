<?php
/**
 * Public render partial for a Franer site.
 *
 * Renders a minimal page shell whose visible region depends on the submission
 * state, computed server-side so it is consistent for every activity:
 *
 * - form     : the sandboxed iframe holding the raw AI-generated HTML.
 * - confirm  : a host "form submitted" page (also the editable re-entry state),
 *              optionally offering "Edit responses" / "Submit another response".
 * - closed   : the activity is closed / disabled / not accepting submissions.
 * - not_yet  : the activity has not opened yet.
 * - already  : a non-editable, single-submission activity already submitted.
 *
 * The parent shell (public/js/franer-shell.js) toggles between the form and the
 * confirmation panel at runtime; the closed/not_yet/already states are load-time
 * only. All panels are part of this single .franer-shell instance.
 *
 * The HTML source is intentionally NOT sanitized: it is only ever placed
 * inside an iframe with the "srcdoc" attribute and a restrictive sandbox
 * (allow-scripts allow-forms, NO allow-same-origin), so it cannot touch the
 * parent document, cookies or storage. At render time only two non-destructive
 * transforms are applied (see Franer_Sanitizer::prepare_for_srcdoc()):
 * maintenance comments are stripped and a guardrail Content-Security-Policy is
 * injected, which lets the activity load external libraries/fonts/images over
 * https while blocking data exfiltration (connect-src/form-action).
 *
 * Expected variables in scope:
 *
 * @var WP_Post $site     The site post object.
 * @var array   $settings The typed site settings.
 *
 * @package    Franer
 * @subpackage Franer/public/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$franer_title = isset( $settings['title'] ) ? $settings['title'] : '';
// Render-time only: maintenance comments are stripped and a guardrail CSP is
// injected before the markup reaches the sandboxed iframe, so comments (and any
// embedded generation prompt) never leak to users and a remote script cannot
// exfiltrate answers. The stored source is unchanged — only this rendered copy.
$franer_html  = Franer_Sanitizer::prepare_for_srcdoc( isset( $settings['html'] ) ? $settings['html'] : '' );
$franer_slug  = isset( $settings['slug'] ) ? $settings['slug'] : '';

/* translators: %s: Franer activity title. */
$franer_iframe_title = sprintf( __( 'Activity: %s', 'franer' ), $franer_title );

/*
 * Submission state, computed server-side. The host owns these states so the
 * "form submitted" page and the closed / already-submitted screens behave the
 * same regardless of the activity HTML.
 */
$franer_schedule_state  = Franer_Permissions::schedule_state( $settings );
$franer_accepts         = ! empty( $settings['accepts_submissions'] );
$franer_allow_multiple  = ! empty( $settings['allow_multiple'] );
$franer_allow_overwrite = ! empty( $settings['allow_overwrite'] );
$franer_site_id         = isset( $settings['id'] ) ? (int) $settings['id'] : (int) $site->ID;

$franer_user_id        = get_current_user_id();
$franer_has_submission = false;
if ( $franer_user_id > 0 ) {
	$franer_existing       = ( new Franer_Submissions_Repository() )->get_latest_user_submission( $franer_site_id, $franer_user_id );
	$franer_has_submission = ! empty( $franer_existing );
}

// Whether the user may edit (overwrite) a single existing submission.
$franer_can_edit = $franer_allow_overwrite && ! $franer_allow_multiple;

// Derive the initial display mode.
if ( 'ended' === $franer_schedule_state || 'disabled' === $franer_schedule_state || ! $franer_accepts ) {
	$franer_mode = 'closed';
} elseif ( 'not_yet' === $franer_schedule_state ) {
	$franer_mode = 'not_yet';
} elseif ( $franer_has_submission && ! $franer_allow_multiple && $franer_allow_overwrite ) {
	$franer_mode = 'confirm';
} elseif ( $franer_has_submission && ! $franer_allow_multiple && ! $franer_allow_overwrite ) {
	$franer_mode = 'already';
} else {
	$franer_mode = 'form';
}

// The iframe (and the confirmation panel that can reveal it) is only rendered
// for the interactive states; a closed/not-yet/already form never loads the
// activity at all.
$franer_render_form = in_array( $franer_mode, array( 'form', 'confirm' ), true );

/**
 * Filters the host "form submitted" confirmation message.
 *
 * Shown on the confirmation page after a successful submit and when re-entering
 * an editable activity that was already submitted. Returning an empty string
 * falls back to the default copy.
 *
 * @since 1.0.0
 *
 * @param string $message  Confirmation message HTML (paragraph contents).
 * @param array  $settings Typed Franer site settings.
 */
$franer_confirmation_message = apply_filters(
	'franer_confirmation_message',
	esc_html__( 'Thank you. Your response has been saved.', 'franer' ),
	$settings
);
if ( '' === trim( (string) $franer_confirmation_message ) ) {
	$franer_confirmation_message = esc_html__( 'Thank you. Your response has been saved.', 'franer' );
}

/*
 * Data protection (GDPR/LOPD) notice shown below the activity in every view
 * mode — shortcode, pretty URL and fullscreen all share this partial. It is
 * filterable so a deployment can localize, replace or remove it; returning an
 * empty string hides it entirely.
 */
$franer_notice = '<p>En cumplimiento del Reglamento General de Protección de Datos, le informamos de que los <strong>datos</strong> serán incorporados al tratamiento denominado &ldquo;<a href="https://www.gobiernodecanarias.org/cmsgob2/export/sites/protecciondedatos/galerias/2024/19.06.2024-Res-421-EFPAFD-Espacios-virtuales.pdf" target="_blank" rel="noreferrer noopener">Espacios virtuales de aprendizaje y colaboración</a>&rdquo;, que es responsabilidad de la <em>Consejería de Educación, Formación Profesional, Actividad Física y Deportes</em> del Gobierno de Canarias.</p> <p>Los datos que se solicitan a continuación serán utilizados para la gestión, publicación y difusión de convocatorias para la participación en programas, proyectos y ejes de la Consejería de Educación. Únicamente se conservarán, a los efectos anteriormente descritos, los datos de las personas usuarias que finalmente cumplimenten el formulario, así como profesorado coordinador y participante en las convocatorias.</p>';
$franer_notice = apply_filters( 'franer_data_protection_notice', $franer_notice, $settings );
?>
<div
	class="franer-shell"
	data-franer-slug="<?php echo esc_attr( $franer_slug ); ?>"
	data-franer-mode="<?php echo esc_attr( $franer_mode ); ?>"
	data-franer-allow-multiple="<?php echo $franer_allow_multiple ? '1' : '0'; ?>"
	data-franer-allow-overwrite="<?php echo $franer_can_edit ? '1' : '0'; ?>">
	<header class="franer-shell__header">
		<h1 class="franer-shell__title"><?php echo esc_html( $franer_title ); ?></h1>
	</header>

	<?php if ( $franer_render_form ) : ?>
	<div class="franer-shell__frame-wrap"<?php echo ( 'form' === $franer_mode ) ? '' : ' hidden'; ?>>
		<button
			type="button"
			class="franer-shell__fullscreen"
			aria-pressed="false"
			hidden>
			<span class="franer-shell__fullscreen-icon" aria-hidden="true">&#9974;</span>
			<span class="franer-shell__fullscreen-text"><?php echo esc_html__( 'Fullscreen', 'franer' ); ?></span>
		</button>
		<iframe
			class="franer-shell__frame"
			<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape_for_srcdoc() double-encodes the whole document with ENT_QUOTES for the srcdoc attribute; esc_attr() would under-encode existing entities. ?>
			srcdoc="<?php echo Franer_Sanitizer::escape_for_srcdoc( $franer_html ); ?>"
			sandbox="allow-scripts allow-forms"
			referrerpolicy="no-referrer"
			loading="lazy"
			title="<?php echo esc_attr( $franer_iframe_title ); ?>"></iframe>
	</div>

	<div
		class="franer-shell__status"
		role="status"
		aria-live="polite"
		hidden><?php echo esc_html__( 'Ready.', 'franer' ); ?></div>

	<div
		class="franer-shell__panel franer-shell__panel--confirm"
		role="status"
		aria-live="polite"
		<?php echo ( 'confirm' === $franer_mode ) ? '' : ' hidden'; ?>>
		<h2 class="franer-shell__panel-title"><?php echo esc_html__( 'Form submitted', 'franer' ); ?></h2>
		<?php // The message is escaped above (esc_html__ default or sanitized via the filter). ?>
		<p class="franer-shell__panel-text"><?php echo wp_kses_post( $franer_confirmation_message ); ?></p>
		<?php if ( $franer_can_edit ) : ?>
		<button type="button" class="franer-shell__panel-button" data-franer-action="edit">
			<?php echo esc_html__( 'Edit responses', 'franer' ); ?>
		</button>
		<?php endif; ?>
		<?php if ( $franer_allow_multiple ) : ?>
		<button type="button" class="franer-shell__panel-button" data-franer-action="again">
			<?php echo esc_html__( 'Submit another response', 'franer' ); ?>
		</button>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ( 'closed' === $franer_mode ) : ?>
	<div class="franer-shell__panel franer-shell__panel--closed" role="status">
		<h2 class="franer-shell__panel-title"><?php echo esc_html__( 'This form is closed', 'franer' ); ?></h2>
		<p class="franer-shell__panel-text"><?php echo esc_html__( 'This activity is no longer accepting submissions.', 'franer' ); ?></p>
	</div>
	<?php endif; ?>

	<?php if ( 'not_yet' === $franer_mode ) : ?>
	<div class="franer-shell__panel franer-shell__panel--notyet" role="status">
		<h2 class="franer-shell__panel-title"><?php echo esc_html__( 'This form is not open yet', 'franer' ); ?></h2>
		<p class="franer-shell__panel-text"><?php echo esc_html__( 'This activity is not open for submissions yet. Please come back later.', 'franer' ); ?></p>
	</div>
	<?php endif; ?>

	<?php if ( 'already' === $franer_mode ) : ?>
	<div class="franer-shell__panel franer-shell__panel--already" role="status">
		<h2 class="franer-shell__panel-title"><?php echo esc_html__( 'You have already submitted this form', 'franer' ); ?></h2>
		<p class="franer-shell__panel-text"><?php echo esc_html__( 'Your response has been recorded. This activity only accepts one submission per person.', 'franer' ); ?></p>
	</div>
	<?php endif; ?>

	<?php if ( '' !== trim( (string) $franer_notice ) ) : ?>
	<div class="franer-shell__notice"><?php echo wp_kses_post( $franer_notice ); ?></div>
	<?php endif; ?>
</div>
