/**
 * Jest tests for the drag-and-drop HTML loader in admin/js/franer-admin.js.
 *
 * The script is a framework-free IIFE that wires `[data-franer-drop]` zones on
 * DOM ready; in jsdom document.readyState is "complete", so evaluating the
 * source runs the initializers immediately against the prepared DOM.
 *
 * @package Franer
 */
const fs = require( 'fs' );
const path = require( 'path' );

const SRC_PATH = path.join( __dirname, '..', '..', 'admin', 'js', 'franer-admin.js' );
const SRC = fs.readFileSync( SRC_PATH, 'utf8' );

// Content returned by the mocked FileReader for the next read.
let fileContent = '<html>dropped</html>';

/**
 * Render a drop zone wrapping a code-editor textarea.
 *
 * @return {void}
 */
function renderDropZone() {
	window.document.body.innerHTML = `
		<div class="franer-drop" data-franer-drop>
			<textarea id="franer_html" class="franer-code-editor"></textarea>
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

/**
 * Dispatch a drop event carrying the given files on a zone.
 *
 * @param {Element} zone  The drop zone element.
 * @param {Array}   files The dataTransfer files list.
 * @return {void}
 */
function dropFiles( zone, files ) {
	const event = new window.Event( 'drop', { bubbles: true } );
	event.dataTransfer = { files };
	zone.dispatchEvent( event );
}

describe( 'Franer HTML drag-and-drop', () => {
	beforeEach( () => {
		// Synchronous FileReader stub so assertions run after onload.
		function FakeReader() {}
		FakeReader.prototype.readAsText = function () {
			this.result = fileContent;
			if ( this.onload ) {
				this.onload();
			}
		};
		window.FileReader = FakeReader;
	} );

	afterEach( () => {
		window.document.body.innerHTML = '';
		delete window.FranerAdmin;
		jest.restoreAllMocks();
	} );

	test( 'dropping an HTML file fills an empty editor', () => {
		window.FranerAdmin = { messages: {} };
		fileContent = '<html>activity</html>';
		renderDropZone();
		loadAdmin();

		const zone = window.document.querySelector( '[data-franer-drop]' );
		const area = window.document.getElementById( 'franer_html' );

		dropFiles( zone, [ { name: 'activity.html', type: 'text/html' } ] );

		expect( area.value ).toBe( '<html>activity</html>' );
	} );

	test( 'dropping onto a non-empty editor asks for confirmation', () => {
		window.FranerAdmin = { messages: { dropConfirm: 'Replace?' } };
		fileContent = '<html>new</html>';
		renderDropZone();
		loadAdmin();

		const zone = window.document.querySelector( '[data-franer-drop]' );
		const area = window.document.getElementById( 'franer_html' );
		area.value = '<html>existing</html>';

		// Declined: content is preserved.
		const confirmSpy = jest
			.spyOn( window, 'confirm' )
			.mockReturnValue( false );
		dropFiles( zone, [ { name: 'a.html', type: 'text/html' } ] );
		expect( confirmSpy ).toHaveBeenCalledWith( 'Replace?' );
		expect( area.value ).toBe( '<html>existing</html>' );

		// Accepted: content is replaced.
		confirmSpy.mockReturnValue( true );
		dropFiles( zone, [ { name: 'a.html', type: 'text/html' } ] );
		expect( area.value ).toBe( '<html>new</html>' );
	} );

	test( 'dropping a non-HTML file is rejected and leaves the editor unchanged', () => {
		window.FranerAdmin = { messages: { dropInvalidType: 'Only .html' } };
		fileContent = 'IGNORED';
		renderDropZone();
		loadAdmin();

		const zone = window.document.querySelector( '[data-franer-drop]' );
		const area = window.document.getElementById( 'franer_html' );
		area.value = 'keep me';

		const alertSpy = jest.spyOn( window, 'alert' ).mockImplementation( () => {} );
		dropFiles( zone, [ { name: 'notes.txt', type: 'text/plain' } ] );

		expect( alertSpy ).toHaveBeenCalledWith( 'Only .html' );
		expect( area.value ).toBe( 'keep me' );
	} );
} );
