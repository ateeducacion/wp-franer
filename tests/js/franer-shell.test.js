/**
 * Jest tests for the Franer parent shell (public/js/franer-shell.js).
 *
 * The shell is a framework-free IIFE that reads window.FranerShell at load
 * time and attaches a 'message' listener. Because of that, each test resets
 * jsdom globals, sets up window.FranerShell and a fake iframe (event.source),
 * then loads the script fresh via jest.isolateModules + a manual eval.
 *
 * @package Franer
 */
const fs = require( 'fs' );
const path = require( 'path' );

const SHELL_PATH = path.join( __dirname, '..', '..', 'public', 'js', 'franer-shell.js' );
const SHELL_SOURCE = fs.readFileSync( SHELL_PATH, 'utf8' );

// Tracks the 'message' listeners attached by loadShell so they can be removed
// after each test. jsdom keeps a single window for the whole file, so without
// this cleanup the IIFE's listener would accumulate across tests.
let attachedMessageHandlers = [];

/**
 * Load the shell script in the current jsdom window.
 *
 * Evaluating the IIFE attaches the 'message' listener and snapshots the
 * current window.FranerShell config. The listener it registers is recorded so
 * it can be removed in afterEach, keeping tests isolated.
 *
 * @return {void}
 */
function loadShell() {
	const realAdd = window.addEventListener.bind( window );
	const spy = jest
		.spyOn( window, 'addEventListener' )
		.mockImplementation( ( type, fn, opts ) => {
			if ( 'message' === type ) {
				attachedMessageHandlers.push( fn );
			}
			return realAdd( type, fn, opts );
		} );
	// eslint-disable-next-line no-eval
	window.eval( SHELL_SOURCE );
	spy.mockRestore();
}

/**
 * Dispatch a message event with a controllable source window.
 *
 * @param {*}      data   The event.data value.
 * @param {Object} source The fake originating iframe contentWindow.
 * @return {void}
 */
function dispatchMessage( data, source ) {
	const event = new window.MessageEvent( 'message', { data } );
	// jsdom does not let us set event.source via the constructor, so define it.
	Object.defineProperty( event, 'source', { value: source, configurable: true } );
	window.dispatchEvent( event );
}

/**
 * Build a deferred promise that resolves on the next fetch resolution tick.
 *
 * @return {Promise<void>} Resolves after pending microtasks flush.
 */
function flushPromises() {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'Franer parent shell', () => {
	let fakeIframe;
	let frameEl;

	beforeEach( () => {
		attachedMessageHandlers = [];
		fakeIframe = { postMessage: jest.fn() };

		// The shell only trusts messages whose source is the contentWindow of an
		// <iframe class="franer-shell__frame"> in the document. Register fakeIframe
		// as that contentWindow so a dispatched event.source matches by identity.
		frameEl = window.document.createElement( 'iframe' );
		frameEl.className = 'franer-shell__frame';
		Object.defineProperty( frameEl, 'contentWindow', {
			value: fakeIframe,
			configurable: true,
		} );
		window.document.body.appendChild( frameEl );

		window.FranerShell = {
			restUrl: 'https://example.test/wp-json/franer/v1/sites/mcode40/submissions',
			nonce: 'test-nonce',
			slug: 'mcode40',
			messages: { error: 'Generic error', network: 'Network error' },
		};
	} );

	afterEach( () => {
		attachedMessageHandlers.forEach( ( fn ) => {
			window.removeEventListener( 'message', fn );
		} );
		attachedMessageHandlers = [];
		if ( frameEl && frameEl.parentNode ) {
			frameEl.parentNode.removeChild( frameEl );
		}
		frameEl = null;
		delete window.fetch;
		jest.restoreAllMocks();
	} );

	test( 'ignores unrelated message events', () => {
		window.fetch = jest.fn();
		loadShell();

		dispatchMessage( { type: 'something_else', payload: {} }, fakeIframe );
		dispatchMessage( 'a plain string', fakeIframe );
		dispatchMessage( null, fakeIframe );
		dispatchMessage( { type: 'franer_submit' }, fakeIframe ); // missing payload object.

		expect( window.fetch ).not.toHaveBeenCalled();
		expect( fakeIframe.postMessage ).not.toHaveBeenCalled();
	} );

	test( 'posts to restUrl with X-WP-Nonce and same-origin credentials on valid submit', async () => {
		window.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			status: 201,
			json: () => Promise.resolve( { submission_id: 123, status: 'saved' } ),
		} );

		loadShell();

		const payload = {
			schema_version: '1.0',
			activity_id: 'mcode40',
			data: { q1: 'yes' },
		};
		dispatchMessage( { type: 'franer_submit', payload }, fakeIframe );

		await flushPromises();

		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		const [ url, options ] = window.fetch.mock.calls[ 0 ];
		expect( url ).toBe( window.FranerShell.restUrl );
		expect( options.method ).toBe( 'POST' );
		expect( options.credentials ).toBe( 'same-origin' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'test-nonce' );
		expect( JSON.parse( options.body ) ).toEqual( payload );
	} );

	test( 'posts back franer_submit_result ok:true on a 201 response', async () => {
		window.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			status: 201,
			json: () => Promise.resolve( { submission_id: 123, status: 'saved' } ),
		} );

		loadShell();

		dispatchMessage(
			{ type: 'franer_submit', payload: { schema_version: '1.0', data: { a: 1 } } },
			fakeIframe
		);

		await flushPromises();

		expect( fakeIframe.postMessage ).toHaveBeenCalledTimes( 1 );
		const [ message ] = fakeIframe.postMessage.mock.calls[ 0 ];
		expect( message.type ).toBe( 'franer_submit_result' );
		expect( message.ok ).toBe( true );
		expect( message.result ).toEqual( { submission_id: 123, status: 'saved' } );
	} );

	test( 'posts back franer_submit_result ok:false on an error response', async () => {
		window.fetch = jest.fn().mockResolvedValue( {
			ok: false,
			status: 409,
			json: () => Promise.resolve( { code: 'franer_duplicate', message: 'Duplicate submission not allowed' } ),
		} );

		loadShell();

		dispatchMessage(
			{ type: 'franer_submit', payload: { schema_version: '1.0', data: { a: 1 } } },
			fakeIframe
		);

		await flushPromises();

		expect( fakeIframe.postMessage ).toHaveBeenCalledTimes( 1 );
		const [ message ] = fakeIframe.postMessage.mock.calls[ 0 ];
		expect( message.type ).toBe( 'franer_submit_result' );
		expect( message.ok ).toBe( false );
		expect( message.result.code ).toBe( 'franer_duplicate' );
		expect( message.result.message ).toBe( 'Duplicate submission not allowed' );
	} );

	test( 'posts back ok:false with a network code when fetch rejects', async () => {
		window.fetch = jest.fn().mockRejectedValue( new Error( 'boom' ) );

		loadShell();

		dispatchMessage(
			{ type: 'franer_submit', payload: { schema_version: '1.0', data: { a: 1 } } },
			fakeIframe
		);

		await flushPromises();

		expect( fakeIframe.postMessage ).toHaveBeenCalledTimes( 1 );
		const [ message ] = fakeIframe.postMessage.mock.calls[ 0 ];
		expect( message.ok ).toBe( false );
		expect( message.result.code ).toBe( 'network' );
		expect( message.result.message ).toBe( 'Network error' );
	} );

	test( 'ignores a franer_submit from a source that is not our activity iframe', async () => {
		window.fetch = jest.fn();
		loadShell();

		// A foreign window/frame spoofing the franer_submit shape. It is NOT the
		// contentWindow of a .franer-shell__frame iframe, so it must be ignored.
		const spoofSource = { postMessage: jest.fn() };
		dispatchMessage(
			{ type: 'franer_submit', payload: { schema_version: '1.0', data: { evil: 1 } } },
			spoofSource
		);

		await flushPromises();

		// No credentialed REST call, and no result leaked back to the attacker.
		expect( window.fetch ).not.toHaveBeenCalled();
		expect( spoofSource.postMessage ).not.toHaveBeenCalled();
		expect( fakeIframe.postMessage ).not.toHaveBeenCalled();
	} );

	test( 'still accepts a franer_submit from the real activity iframe', async () => {
		window.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			status: 201,
			json: () => Promise.resolve( { submission_id: 7, status: 'saved' } ),
		} );

		loadShell();

		dispatchMessage(
			{ type: 'franer_submit', payload: { schema_version: '1.0', data: { a: 1 } } },
			fakeIframe
		);

		await flushPromises();

		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		expect( fakeIframe.postMessage ).toHaveBeenCalledTimes( 1 );
	} );
} );
