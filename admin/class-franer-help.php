<?php
/**
 * Help / documentation screen for the Franer plugin.
 *
 * Renders the in-admin help page and exposes the reusable AI prompt used to
 * generate Franer-compatible self-contained HTML activities.
 *
 * @package    Franer
 * @subpackage Franer/admin
 * @author     Área de Tecnología Educativa
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Franer_Help.
 *
 * @package    Franer
 * @subpackage Franer/admin
 */
class Franer_Help {

	/**
	 * Render the Help admin page using the help partial.
	 *
	 * @return void
	 */
	public function render_help_page() {
		if ( ! Franer_Permissions::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'franer' ) );
		}

		$prompt = self::get_default_activity_prompt();

		require plugin_dir_path( __FILE__ ) . 'partials/franer-admin-help.php';
	}

	/**
	 * Get the reusable AI prompt for generating Franer activities.
	 *
	 * Returns a translatable string that instructs an AI model to produce a
	 * single self-contained HTML document that complies with the Franer
	 * JavaScript and postMessage contract.
	 *
	 * @return string The full prompt text.
	 */
	public static function get_default_activity_prompt() {
		// This prompt is copied verbatim into an external AI tool, so it is intentionally
		// NOT translated: altering its wording would break the generated activity contract.
		return "You are generating an interactive learning activity for the Franer WordPress plugin.\n" .
			"\n" .
			"OUTPUT FORMAT\n" .
			"- Produce ONE single, complete, self-contained HTML document (<!DOCTYPE html> ... </html>).\n" .
			"- Inline ALL CSS in a <style> tag and ALL JavaScript in <script> tags within that same document.\n" .
			"- Do NOT load any external resource: no external scripts, stylesheets, fonts, images by URL, CDNs, iframes, web fonts or analytics.\n" .
			"- Do NOT use fetch(), XMLHttpRequest, WebSocket, localStorage, sessionStorage, cookies or any network/storage API.\n" .
			"- The document will run inside a SANDBOXED iframe (sandbox=\"allow-scripts allow-forms\", NO allow-same-origin). Assume an opaque, untrusted origin.\n" .
			"\n" .
			"COMMUNICATION CONTRACT\n" .
			"- The activity communicates with the host page ONLY via window.parent.postMessage.\n" .
			"- Implement a global function window.FranerCollect() that returns an object with EXACTLY this shape:\n" .
			"    {\n" .
			"      schema_version: \"1.0\",\n" .
			"      activity_id: \"REPLACE_WITH_ACTIVITY_SLUG\",\n" .
			"      activity_title: \"<a short human-readable title>\",\n" .
			"      submitted_at: new Date().toISOString(),\n" .
			"      data: {\n" .
			"        answers: { /* raw user answers keyed by field id */ },\n" .
			"        calculated_values: { /* any computed scores or derived values */ },\n" .
			"        report: { text: \"\", html: \"\" } /* an optional generated report */\n" .
			"      }\n" .
			"    }\n" .
			"- Implement a global function window.FranerSubmit() that:\n" .
			"    1. validates the required fields and, if invalid, shows a clear, accessible inline message and returns WITHOUT posting;\n" .
			"    2. when valid, calls window.parent.postMessage({ type: \"franer_submit\", payload: window.FranerCollect() }, \"*\").\n" .
			"- Add a window 'message' event listener that handles results from the host page: messages where event.data.type === \"franer_submit_result\".\n" .
			"    - On success (event.data.ok === true) show a confirmation using event.data.result (e.g. submission_id, status).\n" .
			"    - On error (event.data.ok === false) show event.data.result.message to the user.\n" .
			"- Provide a single, clearly visible submit <button> whose click handler calls window.FranerSubmit().\n" .
			"\n" .
			"ACCESSIBILITY & VALIDATION\n" .
			"- Use semantic HTML, associated <label> elements, and appropriate ARIA attributes (e.g. aria-required, role=\"alert\" / aria-live for messages).\n" .
			"- Ensure keyboard navigation works and that error and status messages are announced to assistive technologies.\n" .
			"- Validate all required fields before submitting and never submit incomplete data.\n" .
			"\n" .
			"Replace REPLACE_WITH_ACTIVITY_SLUG with the activity slug you are told to use, and design the questions/logic for the requested topic.\n" .
			"\n" .
			'Return only the complete HTML document.';
	}
}
