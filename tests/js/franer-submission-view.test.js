/**
 * Tests for the Franer submission-view bridge
 * (admin/js/franer-submission-view.js).
 *
 * The bridge is a framework-free IIFE that reads window.FranerSubmissionView at
 * load time, finds the view iframe and posts the decoded submission JSON to it
 * via postMessage. Each test sets up jsdom globals and a fake iframe, then imports
 * the script fresh (`vi.resetModules()` first, so it runs again).
 *
 * @package Franer
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
/**
 * Load the bridge script fresh in the current jsdom window.
 *
 * @return {Promise<void>}
 */
async function load() {
	vi.resetModules();
	await import( '../../admin/js/franer-submission-view.js' );
}

/**
 * Build a fake iframe element registered in the document by id.
 *
 * @param {string} id The iframe id.
 * @return {Object} The fake contentWindow with a postMessage spy.
 */
function makeFrame( id ) {
	const fakeWindow = { postMessage: vi.fn() };
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
		vi.restoreAllMocks();
	} );

	test( 'posts franer_view_payload to the iframe with the localized payload', async () => {
		const { fakeWindow } = makeFrame( 'franer-view-frame' );
		const payload = {
			submission_id: 7,
			site: { id: 3, slug: 'demo', title: 'Demo' },
			submission: { id: 7, user_id: 4, payload: { data: { a: 1 } } },
		};
		window.FranerSubmissionView = { frameId: 'franer-view-frame', payload };

		await load();

		expect( fakeWindow.postMessage ).toHaveBeenCalled();
		const [ message, targetOrigin ] = fakeWindow.postMessage.mock.calls[ 0 ];
		expect( message.type ).toBe( 'franer_view_payload' );
		expect( message.payload ).toEqual( payload );
		// Opaque (sandboxed, no same-origin) frame: "*" is the only usable target.
		expect( targetOrigin ).toBe( '*' );
	} );

	test( 'delivers again when the iframe fires its load event', async () => {
		const { frame, fakeWindow } = makeFrame( 'franer-view-frame' );
		window.FranerSubmissionView = {
			frameId: 'franer-view-frame',
			payload: { submission_id: 1 },
		};

		await load();

		const before = fakeWindow.postMessage.mock.calls.length;
		frame.dispatchEvent( new window.Event( 'load' ) );
		expect( fakeWindow.postMessage.mock.calls.length ).toBeGreaterThan( before );
	} );

	test( 'does nothing (and does not throw) when the frame is absent', async () => {
		window.FranerSubmissionView = {
			frameId: 'missing-frame',
			payload: { submission_id: 1 },
		};

		await expect( load() ).resolves.toBeUndefined();
	} );

	test( 'handles a missing/empty config defensively (posts an empty payload)', async () => {
		const { fakeWindow } = makeFrame( 'franer-view-frame' );
		// No payload provided at all.
		window.FranerSubmissionView = {};

		await expect( load() ).resolves.toBeUndefined();

		expect( fakeWindow.postMessage ).toHaveBeenCalled();
		const [ message ] = fakeWindow.postMessage.mock.calls[ 0 ];
		expect( message.type ).toBe( 'franer_view_payload' );
		expect( message.payload ).toEqual( {} );
	} );
} );
