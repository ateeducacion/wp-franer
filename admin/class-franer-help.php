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
		// Concise, copy-paste Franer spec. Prose lines are translatable; the code
		// lines (the contract) are kept literal so they are never altered.
		$lines = array(
			__( 'Add this to your request. Generate ONE complete, self-contained HTML document (HTML, CSS and JS inline).', 'franer' ),
			__( 'No external resources, no network calls (fetch/XHR/WebSocket), no cookies or storage.', 'franer' ),
			__( 'It runs inside a sandboxed iframe and talks to the page ONLY via window.parent.postMessage.', 'franer' ),
			'',
			__( 'Replace REPLACE_WITH_SLUG with the activity slug and implement these globals:', 'franer' ),
			'window.FranerCollect = function () {',
			'  return { schema_version: "1.0", activity_id: "REPLACE_WITH_SLUG", data: { /* answers */ } };',
			'};',
			'window.FranerSubmit = function () {',
			'  /* validate required fields, then: */',
			'  window.parent.postMessage({ type: "franer_submit", payload: window.FranerCollect() }, "*");',
			'};',
			'window.addEventListener("message", function (e) {',
			'  if (e.data && e.data.type === "franer_submit_result") { /* e.data.ok, e.data.result.message */ }',
			'});',
			'',
			__( 'Add a clearly visible button that calls FranerSubmit(). Return only the HTML document.', 'franer' ),
		);

		return implode( "\n", $lines );
	}
}
