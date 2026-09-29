/**
 * Tests for the editor-screen behaviour of admin/js/franer-admin.js: the
 * CodeMirror wiring, the tab/editor refresh, the drop zone states, the copy
 * fallback and the live slug preview.
 *
 * The script is a framework-free IIFE; in jsdom document.readyState is
 * "complete", so importing the script runs its initializers immediately.
 *
 * @package Franer
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

/**
 * Load the admin script fresh in the current jsdom window.
 *
 * @return {Promise<void>}
 */
async function loadAdmin() {
	vi.resetModules();
	await import( '../../admin/js/franer-admin.js' );
}

/**
 * A stand-in for the CodeMirror instance wp.codeEditor returns.
 *
 * @param {string} value Initial editor value.
 * @return {Object} The fake editor.
 */
function fakeCodeMirror( value = '' ) {
	let current = value;
	return {
		refresh: vi.fn(),
		setValue: vi.fn( ( text ) => {
			current = text;
		} ),
		getValue: () => current,
	};
}

/**
 * Install wp.codeEditor, returning one fake CodeMirror per textarea id.
 *
 * @param {Object} instances Map of textarea id to fake CodeMirror.
 * @return {Function} The initialize spy.
 */
function installCodeEditor( instances ) {
	const initialize = vi.fn( ( id ) => ( instances[ id ] ? { codemirror: instances[ id ] } : {} ) );
	window.wp = { codeEditor: { initialize } };
	return initialize;
}

/**
 * Dispatch a drag event on an element.
 *
 * @param {Element} target The element.
 * @param {string}  type   Event type.
 * @param {Array}   files  Files for dataTransfer, if any.
 * @return {Event} The dispatched event.
 */
function drag( target, type, files ) {
	const event = new window.Event( type, { bubbles: true, cancelable: true } );
	if ( files ) {
		event.dataTransfer = { files };
	}
	target.dispatchEvent( event );
	return event;
}

afterEach( () => {
	window.document.body.innerHTML = '';
	delete window.FranerAdmin;
	delete window.wp;
	vi.restoreAllMocks();
	vi.useRealTimers();
} );

describe( 'Franer code editor', () => {
	const EDITORS = `
		<textarea id="franer_html" class="franer-code-editor"></textarea>
		<textarea id="franer_view_html" class="franer-code-editor"></textarea>
		<textarea class="franer-code-editor"></textarea>`;

	test( 'initializes CodeMirror on every code textarea that has an id', async () => {
		window.FranerAdmin = { editorSettings: { codemirror: { mode: 'htmlmixed' } } };
		window.document.body.innerHTML = EDITORS;
		const initialize = installCodeEditor( {} );

		await loadAdmin();

		expect( initialize.mock.calls ).toEqual( [
			[ 'franer_html', { codemirror: { mode: 'htmlmixed' } } ],
			[ 'franer_view_html', { codemirror: { mode: 'htmlmixed' } } ],
		] );
	} );

	test.each( [
		[ 'there are no editor settings', {}, true ],
		[ 'wp.codeEditor is not loaded', { editorSettings: {} }, false ],
	] )( 'leaves the plain textareas alone when %s', async ( _label, config, withEditor ) => {
		window.FranerAdmin = config;
		window.document.body.innerHTML = EDITORS;
		const initialize = withEditor ? installCodeEditor( {} ) : null;

		await expect( loadAdmin() ).resolves.toBeUndefined();

		if ( initialize ) {
			expect( initialize ).not.toHaveBeenCalled();
		}
	} );

	test( 'refreshes the editor of a panel when its tab becomes visible', async () => {
		window.FranerAdmin = { editorSettings: {} };
		window.document.body.innerHTML = `
			<div data-franer-tabs>
				<button role="tab" id="t-a" aria-controls="p-a" aria-selected="true">A</button>
				<button role="tab" id="t-b" aria-controls="p-b" aria-selected="false">B</button>
				<button role="tab" id="t-c" aria-controls="missing" aria-selected="false">C</button>
				<div role="tabpanel" id="p-a"><textarea id="franer_html" class="franer-code-editor"></textarea></div>
				<div role="tabpanel" id="p-b" hidden><textarea id="franer_view_html" class="franer-code-editor"></textarea></div>
			</div>`;
		const activity = fakeCodeMirror();
		const view = fakeCodeMirror();
		installCodeEditor( { franer_html: activity, franer_view_html: view } );
		await loadAdmin();

		window.document.getElementById( 't-b' ).click();

		expect( view.refresh ).toHaveBeenCalledTimes( 1 );
		expect( activity.refresh ).not.toHaveBeenCalled();
		// A tab whose panel is missing still becomes the selected one.
		window.document.getElementById( 't-c' ).click();
		expect( window.document.getElementById( 't-c' ).getAttribute( 'aria-selected' ) ).toBe( 'true' );
		expect( window.document.getElementById( 'p-b' ).hidden ).toBe( true );
	} );

	test( 'ArrowLeft wraps to the last tab and other keys do nothing', async () => {
		window.FranerAdmin = {};
		window.document.body.innerHTML = `
			<div data-franer-tabs>
				<button role="tab" id="t-a" aria-controls="p-a" aria-selected="true">A</button>
				<button role="tab" id="t-b" aria-controls="p-b" aria-selected="false">B</button>
				<div role="tabpanel" id="p-a"></div>
				<div role="tabpanel" id="p-b" hidden></div>
			</div>`;
		await loadAdmin();
		const first = window.document.getElementById( 't-a' );

		const other = new window.KeyboardEvent( 'keydown', { key: 'Enter', cancelable: true } );
		first.dispatchEvent( other );
		expect( other.defaultPrevented ).toBe( false );
		expect( first.getAttribute( 'aria-selected' ) ).toBe( 'true' );

		const left = new window.KeyboardEvent( 'keydown', { key: 'ArrowLeft', cancelable: true } );
		first.dispatchEvent( left );
		expect( left.defaultPrevented ).toBe( true );
		expect( window.document.getElementById( 't-b' ).getAttribute( 'aria-selected' ) ).toBe( 'true' );
		expect( window.document.activeElement.id ).toBe( 't-b' );
		expect( window.document.getElementById( 'p-a' ).hidden ).toBe( true );
	} );
} );

describe( 'Franer drop zone', () => {
	let readerResult;
	let readerFails;

	beforeEach( () => {
		readerResult = '<html>dropped</html>';
		readerFails = false;
		function FakeReader() {}
		FakeReader.prototype.readAsText = function () {
			if ( readerFails ) {
				this.onerror();
				return;
			}
			this.result = readerResult;
			this.onload();
		};
		vi.stubGlobal( 'FileReader', FakeReader );
	} );

	afterEach( () => {
		vi.unstubAllGlobals();
	} );

	/**
	 * Render a drop zone and load the script.
	 *
	 * @param {Object} config FranerAdmin config.
	 * @return {Promise<{zone: Element, area: HTMLTextAreaElement}>}
	 */
	async function mount( config = {} ) {
		window.FranerAdmin = config;
		window.document.body.innerHTML = `
			<div data-franer-drop><textarea id="franer_html" class="franer-code-editor"></textarea></div>
			<div data-franer-drop><p>No textarea here</p></div>`;
		await loadAdmin();
		return {
			zone: window.document.querySelector( '[data-franer-drop]' ),
			area: window.document.getElementById( 'franer_html' ),
		};
	}

	test( 'highlights while a file is dragged over and clears on leave or drop', async () => {
		const { zone } = await mount();

		expect( drag( zone, 'dragenter' ).defaultPrevented ).toBe( true );
		expect( zone.classList.contains( 'is-dragover' ) ).toBe( true );
		zone.classList.remove( 'is-dragover' );
		drag( zone, 'dragover' );
		expect( zone.classList.contains( 'is-dragover' ) ).toBe( true );
		drag( zone, 'dragleave' );
		expect( zone.classList.contains( 'is-dragover' ) ).toBe( false );

		drag( zone, 'dragover' );
		drag( zone, 'drop', [] );
		expect( zone.classList.contains( 'is-dragover' ) ).toBe( false );
	} );

	test( 'ignores a drop without files', async () => {
		const { zone, area } = await mount();
		area.value = 'keep';

		drag( zone, 'drop' );
		drag( zone, 'drop', [] );

		expect( area.value ).toBe( 'keep' );
	} );

	test( 'accepts an .htm file by its name and announces the new value', async () => {
		const { zone, area } = await mount();
		const input = vi.fn();
		area.addEventListener( 'input', input );
		readerResult = '<p>legacy</p>';

		drag( zone, 'drop', [ { name: 'OLD.HTM', type: '' } ] );

		expect( area.value ).toBe( '<p>legacy</p>' );
		expect( input ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'loads the file into CodeMirror and asks before replacing its content', async () => {
		const editor = fakeCodeMirror( '<p>work in progress</p>' );
		installCodeEditor( { franer_html: editor } );
		const confirm = vi.spyOn( window, 'confirm' ).mockReturnValue( true );
		const { zone, area } = await mount( { editorSettings: {}, messages: {} } );

		drag( zone, 'drop', [ { name: 'a.html', type: 'text/html' } ] );

		expect( confirm ).toHaveBeenCalledWith( 'Replace the current content with the dropped file?' );
		expect( editor.setValue ).toHaveBeenCalledWith( '<html>dropped</html>' );
		expect( area.value ).toBe( '' );
	} );

	test( 'reports a file that cannot be read and keeps the content', async () => {
		const alert = vi.spyOn( window, 'alert' ).mockImplementation( () => {} );
		const { zone, area } = await mount( { messages: { dropReadError: 'Unreadable.' } } );
		readerFails = true;

		drag( zone, 'drop', [ { name: 'a.html', type: 'text/html' } ] );

		expect( alert ).toHaveBeenCalledWith( 'Unreadable.' );
		expect( area.value ).toBe( '' );
	} );

	test( 'uses the default message for a file that is not HTML', async () => {
		const alert = vi.spyOn( window, 'alert' ).mockImplementation( () => {} );
		const { zone } = await mount();

		drag( zone, 'drop', [ { name: 'notes.txt', type: 'text/plain' } ] );

		expect( alert ).toHaveBeenCalledWith( 'Please drop an .html file.' );
	} );
} );

describe( 'Franer copy buttons without the Clipboard API', () => {
	beforeEach( () => {
		Object.defineProperty( window.navigator, 'clipboard', { value: undefined, configurable: true } );
	} );

	/**
	 * Let the copy promise chain settle.
	 *
	 * @return {Promise<void>}
	 */
	async function settle() {
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();
	}

	test( 'copies through a temporary textarea and reports in the default status', async () => {
		window.FranerAdmin = {};
		window.document.body.innerHTML = `
			<button data-franer-copy-target="shortcode">Copy</button>
			<code id="shortcode">[franer id="3"]</code>
			<span id="franer-copy-prompt-status"></span>`;
		let copied = null;
		window.document.execCommand = vi.fn( () => {
			copied = window.document.querySelector( 'textarea[readonly]' ).value;
			return true;
		} );
		vi.useFakeTimers();
		await loadAdmin();

		window.document.querySelector( 'button' ).click();
		await settle();

		expect( window.document.execCommand ).toHaveBeenCalledWith( 'copy' );
		expect( copied ).toBe( '[franer id="3"]' );
		expect( window.document.querySelectorAll( 'textarea' ) ).toHaveLength( 0 );
		const status = window.document.getElementById( 'franer-copy-prompt-status' );
		expect( status.textContent ).toBe( 'Copied.' );
		vi.advanceTimersByTime( 2500 );
		expect( status.textContent ).toBe( '' );
	} );

	test( 'shows the failure on the button itself when there is no status element', async () => {
		window.FranerAdmin = { messages: { copyError: 'No copy.' } };
		window.document.body.innerHTML = `
			<button data-franer-copy-target="url">Copy URL</button>
			<input id="url" value="https://example.test/franer/demo">`;
		window.document.execCommand = vi.fn( () => {
			throw new Error( 'blocked' );
		} );
		vi.useFakeTimers();
		await loadAdmin();
		const button = window.document.querySelector( 'button' );

		button.click();
		await settle();

		expect( button.textContent ).toBe( 'No copy.' );
		vi.advanceTimersByTime( 1500 );
		expect( button.textContent ).toBe( 'Copy URL' );
	} );

	test( 'does nothing when the target element is missing', async () => {
		window.FranerAdmin = {};
		window.document.body.innerHTML = '<button data-franer-copy-target="gone">Copy</button>';
		window.document.execCommand = vi.fn();
		await loadAdmin();

		window.document.querySelector( 'button' ).click();
		await settle();

		expect( window.document.execCommand ).not.toHaveBeenCalled();
		expect( window.document.querySelector( 'button' ).textContent ).toBe( 'Copy' );
	} );
} );

describe( 'Franer slug preview', () => {
	test( 'keeps the slug URL-safe and mirrors it in the preview', async () => {
		window.FranerAdmin = {};
		window.document.body.innerHTML = `
			<input id="franer_slug" value="">
			<span data-franer-url-slug>…</span>`;
		await loadAdmin();
		const input = window.document.getElementById( 'franer_slug' );
		const preview = window.document.querySelector( '[data-franer-url-slug]' );

		input.value = 'Mi Actividad_2';
		input.dispatchEvent( new window.Event( 'input' ) );
		expect( input.value ).toBe( 'mi-actividad-2' );
		expect( preview.textContent ).toBe( 'mi-actividad-2' );

		input.value = 'ok-slug';
		input.dispatchEvent( new window.Event( 'input' ) );
		expect( preview.textContent ).toBe( 'ok-slug' );

		input.value = '';
		input.dispatchEvent( new window.Event( 'input' ) );
		expect( preview.textContent ).toBe( '…' );
	} );

	test( 'does nothing without a preview element', async () => {
		window.FranerAdmin = {};
		window.document.body.innerHTML = '<input id="franer_slug" value="Keep Me">';
		await loadAdmin();
		const input = window.document.getElementById( 'franer_slug' );

		input.dispatchEvent( new window.Event( 'input' ) );

		expect( input.value ).toBe( 'Keep Me' );
	} );
} );
