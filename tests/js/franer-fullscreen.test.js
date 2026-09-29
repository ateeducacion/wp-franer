/**
 * Tests for the Franer fullscreen toggle (public/js/franer-fullscreen.js).
 *
 * The script is a framework-free IIFE that reads window.FranerShell at load
 * time, finds the toggle button and wires it to the Fullscreen API on the
 * iframe wrapper. jsdom does not implement the Fullscreen API, so each test
 * stubs requestFullscreen/exitFullscreen and document.fullscreenElement, then
 * imports the script fresh (`vi.resetModules()` first, so it runs again).
 *
 * @package Franer
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

// Document-level listeners attached by the IIFE (fullscreenchange), tracked so
// they can be removed after each test — jsdom keeps one document for the file.
let attachedDocHandlers = [];

/**
 * Build the shell DOM the script expects and return its key elements.
 *
 * @return {{shell: Element, frameWrap: Element, btn: Element, text: Element}}
 */
function buildShell() {
	const shell = window.document.createElement( 'div' );
	shell.className = 'franer-shell';
	shell.innerHTML =
		'<div class="franer-shell__frame-wrap">' +
		'<button type="button" class="franer-shell__fullscreen" aria-pressed="false" hidden>' +
		'<span class="franer-shell__fullscreen-text">Fullscreen</span>' +
		'</button>' +
		'</div>';
	window.document.body.appendChild( shell );

	return {
		shell,
		frameWrap: shell.querySelector( '.franer-shell__frame-wrap' ),
		btn: shell.querySelector( '.franer-shell__fullscreen' ),
		text: shell.querySelector( '.franer-shell__fullscreen-text' ),
	};
}

/**
 * Load the fullscreen script in the current jsdom window.
 *
 * Records the document 'fullscreenchange'/'webkitfullscreenchange' listeners so
 * they can be removed in afterEach, keeping tests isolated.
 *
 * @return {Promise<void>}
 */
async function loadScript() {
	const realAdd = window.document.addEventListener.bind( window.document );
	const spy = vi
		.spyOn( window.document, 'addEventListener' )
		.mockImplementation( ( type, fn, opts ) => {
			if ( 'fullscreenchange' === type || 'webkitfullscreenchange' === type ) {
				attachedDocHandlers.push( { type, fn } );
			}
			return realAdd( type, fn, opts );
		} );
	vi.resetModules();
	await import( '../../public/js/franer-fullscreen.js' );
	spy.mockRestore();
}

/**
 * Set the element the document reports as fullscreen.
 *
 * @param {Element|null} el The fullscreen element, or null when not active.
 * @return {void}
 */
function setFullscreenElement( el ) {
	Object.defineProperty( window.document, 'fullscreenElement', {
		value: el,
		configurable: true,
	} );
}

describe( 'Franer fullscreen toggle', () => {
	let dom;

	beforeEach( () => {
		attachedDocHandlers = [];
		setFullscreenElement( null );
		window.FranerShell = {
			messages: { fullscreen: 'Fullscreen', exitFullscreen: 'Exit fullscreen' },
		};
		dom = buildShell();
	} );

	afterEach( () => {
		attachedDocHandlers.forEach( ( { type, fn } ) => {
			window.document.removeEventListener( type, fn );
		} );
		attachedDocHandlers = [];
		if ( dom.shell && dom.shell.parentNode ) {
			dom.shell.parentNode.removeChild( dom.shell );
		}
		dom = null;
		delete window.FranerShell;
		vi.restoreAllMocks();
	} );

	test( 'keeps the button hidden where the Fullscreen API is unavailable', async () => {
		// No requestFullscreen on the wrapper => unsupported.
		await loadScript();

		expect( dom.btn.hidden ).toBe( true );
	} );

	test( 'reveals the button and requests fullscreen on click when supported', async () => {
		dom.frameWrap.requestFullscreen = vi.fn().mockResolvedValue( undefined );

		await loadScript();

		expect( dom.btn.hidden ).toBe( false );

		dom.btn.click();

		expect( dom.frameWrap.requestFullscreen ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'exits fullscreen on click when the wrapper is already fullscreen', async () => {
		dom.frameWrap.requestFullscreen = vi.fn().mockResolvedValue( undefined );
		window.document.exitFullscreen = vi.fn().mockResolvedValue( undefined );

		await loadScript();

		setFullscreenElement( dom.frameWrap );
		dom.btn.click();

		expect( window.document.exitFullscreen ).toHaveBeenCalledTimes( 1 );
		expect( dom.frameWrap.requestFullscreen ).not.toHaveBeenCalled();
	} );

	test( 'syncs label and aria-pressed on fullscreenchange', async () => {
		dom.frameWrap.requestFullscreen = vi.fn().mockResolvedValue( undefined );

		await loadScript();

		// Initial (not fullscreen) state.
		expect( dom.btn.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
		expect( dom.text.textContent ).toBe( 'Fullscreen' );

		// Enter fullscreen.
		setFullscreenElement( dom.frameWrap );
		window.document.dispatchEvent( new window.Event( 'fullscreenchange' ) );

		expect( dom.btn.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect( dom.text.textContent ).toBe( 'Exit fullscreen' );
		expect( dom.btn.getAttribute( 'aria-label' ) ).toBe( 'Exit fullscreen' );

		// Leave fullscreen.
		setFullscreenElement( null );
		window.document.dispatchEvent( new window.Event( 'fullscreenchange' ) );

		expect( dom.btn.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
		expect( dom.text.textContent ).toBe( 'Fullscreen' );
	} );

	test( 'swallows a rejected requestFullscreen promise', async () => {
		dom.frameWrap.requestFullscreen = vi
			.fn()
			.mockRejectedValue( new Error( 'denied' ) );

		await loadScript();

		// Must not throw synchronously nor leave an unhandled rejection.
		expect( () => dom.btn.click() ).not.toThrow();
		await Promise.resolve();
	} );
} );
