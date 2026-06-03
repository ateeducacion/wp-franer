/**
 * Jest tests for the Franer admin scripts (admin/js/franer-admin.js).
 *
 * Focuses on the new accessible tabbed editor and the copy-to-clipboard
 * buttons. The script is a framework-free IIFE that runs its initializers on
 * DOM ready; in jsdom document.readyState is "complete", so evaluating the
 * source runs them immediately against the prepared DOM.
 *
 * @package Franer
 */
const fs = require( 'fs' );
const path = require( 'path' );

const SRC_PATH = path.join( __dirname, '..', '..', 'admin', 'js', 'franer-admin.js' );
const SRC = fs.readFileSync( SRC_PATH, 'utf8' );

/**
 * Render the tabbed-editor markup used by the franer_site editor.
 *
 * @return {void}
 */
function renderTabs() {
	window.document.body.innerHTML = `
		<div class="franer-tabs" data-franer-tabs>
			<div role="tablist">
				<button type="button" role="tab" id="t-activity"
					aria-controls="p-activity" aria-selected="true">Activity</button>
				<button type="button" role="tab" id="t-view"
					aria-controls="p-view" aria-selected="false" tabindex="-1">View</button>
			</div>
			<div role="tabpanel" id="p-activity" aria-labelledby="t-activity">
				<textarea id="franer_html" class="franer-code-editor"></textarea>
			</div>
			<div role="tabpanel" id="p-view" aria-labelledby="t-view" hidden>
				<textarea id="franer_view_html" class="franer-code-editor"></textarea>
			</div>
		</div>`;
}

/**
 * Load the admin script fresh in the current jsdom window.
 *
 * @return {void}
 */
function loadAdmin() {
	// eslint-disable-next-line no-eval
	window.eval( SRC );
}

describe( 'Franer admin tabs', () => {
	afterEach( () => {
		window.document.body.innerHTML = '';
		delete window.FranerAdmin;
		jest.restoreAllMocks();
	} );

	test( 'activating the second tab shows its panel and hides the first', () => {
		window.FranerAdmin = { messages: {} };
		renderTabs();
		loadAdmin();

		const activityTab = window.document.getElementById( 't-activity' );
		const viewTab = window.document.getElementById( 't-view' );
		const activityPanel = window.document.getElementById( 'p-activity' );
		const viewPanel = window.document.getElementById( 'p-view' );

		// Initial state: activity tab selected, view panel hidden.
		expect( activityPanel.hidden ).toBe( false );
		expect( viewPanel.hidden ).toBe( true );

		viewTab.click();

		expect( viewTab.getAttribute( 'aria-selected' ) ).toBe( 'true' );
		expect( activityTab.getAttribute( 'aria-selected' ) ).toBe( 'false' );
		expect( viewPanel.hidden ).toBe( false );
		expect( activityPanel.hidden ).toBe( true );
	} );

	test( 'ArrowRight moves selection to the next tab', () => {
		window.FranerAdmin = { messages: {} };
		renderTabs();
		loadAdmin();

		const activityTab = window.document.getElementById( 't-activity' );
		const viewTab = window.document.getElementById( 't-view' );

		activityTab.dispatchEvent(
			new window.KeyboardEvent( 'keydown', { key: 'ArrowRight', bubbles: true } )
		);

		expect( viewTab.getAttribute( 'aria-selected' ) ).toBe( 'true' );
	} );
} );

describe( 'Franer admin copy buttons', () => {
	afterEach( () => {
		window.document.body.innerHTML = '';
		delete window.FranerAdmin;
		jest.restoreAllMocks();
	} );

	test( 'copy button writes the target value to the clipboard and reports status', async () => {
		const writeText = jest.fn().mockResolvedValue();
		Object.defineProperty( window.navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		} );

		window.FranerAdmin = { messages: { copied: 'Copied!' } };
		window.document.body.innerHTML = `
			<button data-franer-copy-target="src" data-franer-copy-status="st">Copy</button>
			<textarea id="src">PROMPT BODY</textarea>
			<span id="st"></span>`;

		loadAdmin();

		window.document.querySelector( '[data-franer-copy-target]' ).click();
		await Promise.resolve();

		expect( writeText ).toHaveBeenCalledWith( 'PROMPT BODY' );
		await Promise.resolve();
		expect( window.document.getElementById( 'st' ).textContent ).toBe( 'Copied!' );
	} );
} );
