/**
 * Jest tests for the Franer submission-view bridge
 * (admin/js/franer-submission-view.js).
 *
 * The bridge is a framework-free IIFE that reads window.FranerSubmissionView at
 * load time, finds the view iframe and posts the decoded submission JSON to it
 * via postMessage. Each test sets up jsdom globals and a fake iframe, then loads
 * the script fresh by evaluating its source in the jsdom window.
 *
 * @package Franer
 */
const fs = require( 'fs' );
const path = require( 'path' );

const SRC_PATH = path.join(
	__dirname,
	'..',
	'..',
	'admin',
	'js',
	'franer-submission-view.js'
);
const SRC = fs.readFileSync( SRC_PATH, 'utf8' );

/**
 * Build a fake iframe element registered in the document by id.
 *
 * @param {string} id The iframe id.
 * @return {Object} The fake contentWindow with a postMessage spy.
 */
function makeFrame( id ) {
	const fakeWindow = { postMessage: jest.fn() };
	const frame = window.document.createElement( 'iframe' );
	frame.id = id;
	Object.defineProperty( frame, 'contentWindow', {
		value: fakeWindow,
		configurable: true,
	} );
	window.document.body.appendChild( frame );
	return { frame, fakeWindow };
}

describe( 'Franer submission-view bridge', () => {
	afterEach( () => {
		window.document.body.innerHTML = '';
		delete window.FranerSubmissionView;
		jest.restoreAllMocks();
	} );

	test( 'posts franer_view_payload to the iframe with the localized payload', () => {
		const { fakeWindow } = makeFrame( 'franer-view-frame' );
		const payload = {
			submission_id: 7,
			site: { id: 3, slug: 'demo', title: 'Demo' },
			submission: { id: 7, user_id: 4, payload: { data: { a: 1 } } },
		};
		window.FranerSubmissionView = { frameId: 'franer-view-frame', payload };

		// eslint-disable-next-line no-eval
		window.eval( SRC );

		expect( fakeWindow.postMessage ).toHaveBeenCalled();
		const [ message, targetOrigin ] = fakeWindow.postMessage.mock.calls[ 0 ];
		expect( message.type ).toBe( 'franer_view_payload' );
		expect( message.payload ).toEqual( payload );
		// Opaque (sandboxed, no same-origin) frame: "*" is the only usable target.
		expect( targetOrigin ).toBe( '*' );
	} );

	test( 'delivers again when the iframe fires its load event', () => {
		const { frame, fakeWindow } = makeFrame( 'franer-view-frame' );
		window.FranerSubmissionView = {
			frameId: 'franer-view-frame',
			payload: { submission_id: 1 },
		};

		// eslint-disable-next-line no-eval
		window.eval( SRC );

		const before = fakeWindow.postMessage.mock.calls.length;
		frame.dispatchEvent( new window.Event( 'load' ) );
		expect( fakeWindow.postMessage.mock.calls.length ).toBeGreaterThan( before );
	} );

	test( 'does nothing (and does not throw) when the frame is absent', () => {
		window.FranerSubmissionView = {
			frameId: 'missing-frame',
			payload: { submission_id: 1 },
		};

		expect( () => {
			// eslint-disable-next-line no-eval
			window.eval( SRC );
		} ).not.toThrow();
	} );

	test( 'handles a missing/empty config defensively (posts an empty payload)', () => {
		const { fakeWindow } = makeFrame( 'franer-view-frame' );
		// No payload provided at all.
		window.FranerSubmissionView = {};

		expect( () => {
			// eslint-disable-next-line no-eval
			window.eval( SRC );
		} ).not.toThrow();

		expect( fakeWindow.postMessage ).toHaveBeenCalled();
		const [ message ] = fakeWindow.postMessage.mock.calls[ 0 ];
		expect( message.type ).toBe( 'franer_view_payload' );
		expect( message.payload ).toEqual( {} );
	} );
} );
