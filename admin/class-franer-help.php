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

		$prompt      = self::get_default_activity_prompt();
		$view_prompt = self::get_default_view_prompt();

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
			__( 'Add this to your request. Generate ONE complete HTML document with all HTML, CSS and JS inline.', 'franer' ),
			__( 'It runs inside a sandboxed iframe (sandbox="allow-scripts allow-forms", NO same-origin) and talks to the page ONLY via window.parent.postMessage.', 'franer' ),
			'',
			__( 'RUNTIME RULES (the sandbox blocks these — using them breaks the activity):', 'franer' ),
			__( '- No localStorage, sessionStorage, IndexedDB or cookies (they throw); keep all state in a plain JS variable in memory.', 'franer' ),
			__( '- No alert(), confirm(), prompt() or window.print() (they are suppressed); build confirmations and notices as in-page HTML/CSS.', 'franer' ),
			__( '- No window.open(), no file downloads. For a printable view, toggle a CSS class and use @media print so the user prints with the browser.', 'franer' ),
			__( '- Never let the script throw while loading: wire the critical UI (tab navigation, listeners) first and guard any optional feature with feature-detection or try/catch.', 'franer' ),
			'',
			__( 'EXTERNAL RESOURCES: loading libraries, web fonts and images from https (Bootstrap, charting libraries, Google Fonts, remote images, …) IS allowed. Prefer a self-contained document when you can, for privacy, reliability and offline use.', 'franer' ),
			__( 'DATA NEVER LEAVES THE ACTIVITY except through window.parent.postMessage. Do not use fetch/XHR/WebSocket/sendBeacon or form actions to send answers anywhere — a Content-Security-Policy blocks them.', 'franer' ),
			'',
			__( 'TABS/PAGES (if requested) must use plain JS, not a framework: buttons with class "tab-btn" and data-tab="ID", sections with class "tab" (".tab{display:none}", ".tab.active{display:block}"), toggling the "active" class on click. Add role="tablist"/"tab"/"tabpanel" and keyboard support.', 'franer' ),
			__( 'Make it a quality resource: responsive, accessible (labels, contrast, visible focus), CSS variables for the palette, @media print rules.', 'franer' ),
			'',
			__( 'FIELD VALIDATION: use native HTML5 validation, never alert(). Pick the right input type (email, number, url, tel, date, time) and add required where needed. Constrain values: pattern (with a title that explains the format) for text, min/max/step for number and date, minlength/maxlength for text. In FranerSubmit, call the form\'s reportValidity() (Constraint Validation API) and stop if it returns false; show any extra messages as in-page HTML.', 'franer' ),
			'',
			__( 'CONFIRMATION: the Franer host shows the "form submitted" page and the closed / already-submitted screens after a successful submit. Do NOT build your own thank-you screen — just validate, submit, and (optionally) clear inline errors.', 'franer' ),
			'',
			__( 'Replace REPLACE_WITH_SLUG with the activity slug and implement these globals:', 'franer' ),
			'window.FranerCollect = function () {',
			'  return { schema_version: "1.0", activity_id: "REPLACE_WITH_SLUG", data: { /* answers */ } };',
			'};',
			'window.FranerSubmit = function () {',
			'  /* if (!document.querySelector("form").reportValidity()) return; then: */',
			'  window.parent.postMessage({ type: "franer_submit", payload: window.FranerCollect() }, "*");',
			'};',
			'window.addEventListener("message", function (e) {',
			'  if (e.data && e.data.type === "franer_submit_result") { /* e.data.ok, e.data.result.message */ }',
			'});',
			'',
			__( 'OPTIONAL — editable forms: if the activity allows editing a submission, implement window.FranerPrefill(data) to repopulate your fields. The host calls it (via a { type:"franer_prefill", payload:data } message) with the previously submitted answers when the user chooses "Edit responses".', 'franer' ),
			'window.FranerPrefill = function (data) { /* set field values from data (same keys as FranerCollect().data) */ };',
			'',
			__( 'Add a clearly visible button that calls FranerSubmit(). Return only the HTML document.', 'franer' ),
			'',
			__( 'Add clear comments to the HTML, CSS and JavaScript to make future maintenance easier.', 'franer' ),
			__( 'Include the prompt that generated this activity as an HTML comment near the top of the document when possible. Do not include secrets, private data, credentials, API keys or personal data in that comment.', 'franer' ),
			__( 'Franer will strip HTML and JavaScript comments before rendering the activity to users, so comments are for administrator maintenance only.', 'franer' ),
		);

		return implode( "\n", $lines );
	}

	/**
	 * Get the reusable AI prompt for generating a submissions-overview template.
	 *
	 * Returns a translatable string instructing an AI model to produce a single
	 * self-contained, admin-only HTML document that renders an overview of ALL of
	 * a Franer's submissions (totals, charts, tables). The generated document is
	 * NOT a form: it receives every submission through window.postMessage and
	 * builds an aggregate, printable report.
	 *
	 * @return string The full submissions-overview prompt text.
	 */
	public static function get_default_view_prompt() {
		// Prose lines are translatable; the contract/code lines are literal so they
		// are never altered by translation.
		$lines = array(
			__( 'You are generating a self-contained HTML template for Franer, a WordPress plugin that stores JSON submissions from AI-generated activities.', 'franer' ),
			__( 'This HTML document is not the activity form. It is an administrator-only template that renders an OVERVIEW of all submissions for one activity: totals, charts and summary tables.', 'franer' ),
			'',
			__( 'Generate ONE complete HTML document with all HTML, CSS and JavaScript inline.', 'franer' ),
			__( 'You MAY load a charting library, fonts or images from https (for example Chart.js or D3 from a CDN), or draw charts yourself with inline SVG or <canvas>. Prefer a self-contained document when practical, for privacy and reliability. Do not use cookies or localStorage for personal data.', 'franer' ),
			__( 'It must not exfiltrate data: no fetch(), XMLHttpRequest, navigator.sendBeacon(), WebSockets, no form action URL, and it must not read or modify the parent window DOM. A Content-Security-Policy blocks those channels; the data arrives only through postMessage.', 'franer' ),
			__( 'It receives data only through window.postMessage(). The parent admin page sends this message:', 'franer' ),
			'',
			'{ "type": "franer_view_payload", "payload": {',
			'  "site": { "id": 10, "slug": "activity-slug", "title": "Activity title" },',
			'  "count": 123,',
			'  "truncated": false,',
			'  "submissions": [',
			'    { "id": 1, "user_id": 44, "created_at": "2026-06-02 10:15:00", "updated_at": null,',
			'      "payload": { "schema_version": "1.0", "activity_id": "activity-slug", "data": {} } }',
			'  ] } }',
			'',
			__( 'Implement this global and listener:', 'franer' ),
			'window.FranerRenderSubmissions = function (context) {',
			'  /* context.submissions is the array; context.count is the total. */',
			'};',
			'window.addEventListener("message", function (event) {',
			'  if (!event.data || event.data.type !== "franer_view_payload") { return; }',
			'  if (typeof window.FranerRenderSubmissions === "function") {',
			'    window.FranerRenderSubmissions(event.data.payload);',
			'  }',
			'});',
			'',
			__( 'The overview must show the activity title and the total number of submissions, then aggregate the submissions into a readable report.', 'franer' ),
			__( 'Iterate context.submissions and read each submission.payload.data. Summarize answers (counts, averages, distributions) and visualize the most relevant fields as charts. Show submissions over time when timestamps are available.', 'franer' ),
			__( 'If context.truncated is true, state that only the most recent submissions are shown. If there are no submissions or a field is missing, show a friendly fallback instead of crashing.', 'franer' ),
			__( 'Include a collapsible raw JSON section using <details> and a print button using window.print().', 'franer' ),
			__( 'Use semantic, accessible HTML that works on mobile screens, and add clear comments to the HTML, CSS and JavaScript.', 'franer' ),
			__( 'Include this prompt as an HTML comment near the top of the document when possible, excluding any secrets, credentials, API keys or personal data. Do not invent backend URLs and do not include WordPress nonces.', 'franer' ),
			'',
			__( 'Return only the complete HTML document. Do not add explanations before or after it.', 'franer' ),
		);

		return implode( "\n", $lines );
	}
}
