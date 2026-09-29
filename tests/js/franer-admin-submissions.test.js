/**
 * Tests for the Submissions-page behaviour of admin/js/franer-admin.js: the
 * detail drawer (readable view, JSON tab, navigation, closing) and the per-row
 * delete buttons.
 *
 * The drawer binds a keydown listener on `document`, which jsdom keeps for the
 * whole file, so each load records it and afterEach removes it.
 *
 * @package Franer
 */
import { afterEach, describe, expect, test, vi } from 'vitest';

let documentKeyHandlers = [];

/**
 * Load the admin script fresh, recording the document keydown listeners.
 *
 * @return {Promise<void>}
 */
async function loadAdmin() {
	const realAdd = window.document.addEventListener.bind( window.document );
	const spy = vi
		.spyOn( window.document, 'addEventListener' )
		.mockImplementation( ( type, fn, opts ) => {
			if ( 'keydown' === type ) {
				documentKeyHandlers.push( fn );
			}
			return realAdd( type, fn, opts );
		} );
	vi.resetModules();
	await import( '../../admin/js/franer-admin.js' );
	spy.mockRestore();
}

afterEach( () => {
	documentKeyHandlers.forEach( ( fn ) => window.document.removeEventListener( 'keydown', fn ) );
	documentKeyHandlers = [];
	window.document.body.innerHTML = '';
	delete window.FranerAdmin;
	vi.restoreAllMocks();
} );

/**
 * Escape a value for an HTML attribute.
 *
 * @param {string} text Raw text.
 * @return {string} Escaped text.
 */
function attr( text ) {
	return text.replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
}

/**
 * One row button of the submissions list.
 *
 * @param {number} id      Submission id.
 * @param {Object} payload Submission payload.
 * @param {Object} extra   Extra attributes.
 * @return {string} HTML.
 */
function row( id, payload, extra = '' ) {
	const json = 'string' === typeof payload ? payload : JSON.stringify( payload, null, 2 );
	return `<button class="franer-view-json" data-franer-id="${ id }" data-franer-nonce="n${ id }"
		data-franer-payload="${ attr( json ) }" ${ extra }>View</button>`;
}

const DRAWER = `
	<div id="franer-json-modal" hidden>
		<span id="franer-drawer-eyebrow"></span>
		<button data-franer-drawer-tab="summary">Summary</button>
		<button data-franer-drawer-tab="json">JSON</button>
		<div data-franer-drawer-panel="summary"><div id="franer-drawer-readable"></div></div>
		<div data-franer-drawer-panel="json" hidden>
			<textarea id="franer-modal-content"></textarea>
			<input id="franer-edit-id"><input id="franer-edit-nonce">
		</div>
		<button data-franer-drawer-prev>Prev</button>
		<button data-franer-drawer-next>Next</button>
		<button class="franer-modal__close">Close</button>
	</div>`;

/**
 * Render the submissions page and load the script.
 *
 * @param {string} rows   Row buttons.
 * @param {Object} schema Field schema, or a raw string for invalid JSON.
 * @param {Object} config FranerAdmin config.
 * @return {Promise<Element>} The drawer.
 */
async function mount( rows, schema = {}, config = { messages: {} } ) {
	window.FranerAdmin = config;
	const schemaText = 'string' === typeof schema ? schema : JSON.stringify( schema );
	window.document.body.innerHTML = `${ rows }
		<script type="application/json" id="franer-fields-schema">${ schemaText }</script>
		${ DRAWER }`;
	await loadAdmin();
	return window.document.getElementById( 'franer-json-modal' );
}

/**
 * The question/answer pairs of the readable view.
 *
 * @return {Array} [ question, answerHtml ] pairs.
 */
function readable() {
	return Array.from( window.document.querySelectorAll( '#franer-drawer-readable .franer-qa' ) ).map( ( qa ) => [
		qa.querySelector( '.franer-qa__q' ).textContent,
		( qa.querySelector( '.franer-qa__a' ) || qa.querySelector( 'p' ) ).innerHTML,
	] );
}

/**
 * Press a key on the document.
 *
 * @param {string} key Key name.
 */
function press( key ) {
	window.document.dispatchEvent( new window.KeyboardEvent( 'keydown', { key } ) );
}

describe( 'Franer submission drawer', () => {
	test( 'opens on a row with its id, nonce, JSON and readable summary', async () => {
		const payload = { score: 4, answers: { q1_name: 'Ana' } };
		const drawer = await mount( row( 7, payload ) );
		const opener = window.document.querySelector( '.franer-view-json' );
		opener.focus();

		opener.click();

		expect( drawer.hidden ).toBe( false );
		expect( window.document.getElementById( 'franer-edit-id' ).value ).toBe( '7' );
		expect( window.document.getElementById( 'franer-edit-nonce' ).value ).toBe( 'n7' );
		expect( window.document.getElementById( 'franer-drawer-eyebrow' ).textContent ).toBe( '#7' );
		expect( JSON.parse( window.document.getElementById( 'franer-modal-content' ).value ) ).toEqual( payload );
		// Nested keys are flattened and labelled from their last segment.
		expect( readable() ).toEqual( [
			[ 'Score', '4' ],
			[ 'Q1 name', 'Ana' ],
		] );
		expect( window.document.querySelector( '[data-franer-drawer-panel="summary"]' ).hidden ).toBe( false );
		expect( window.document.querySelector( '[data-franer-drawer-panel="json"]' ).hidden ).toBe( true );
	} );

	test( 'renders each answer by its inferred type and escapes the values', async () => {
		const payload = {
			stars: 3,
			feedback: 'Great <b>class</b>',
			empty_comment: '',
			choices: [ 'a', 'b' ],
			missing: null,
			blank: '',
			html: '<img src=x>',
		};
		await mount( row( 1, payload ), {
			stars: { label: 'How many stars?', type: 'rating' },
			feedback: { label: 'Comments', type: 'text' },
			empty_comment: { type: 'text' },
		}, { messages: { noComment: 'No comment' } } );

		window.document.querySelector( '.franer-view-json' ).click();

		const rows = readable();
		expect( rows[ 0 ][ 0 ] ).toBe( 'How many stars?' );
		expect( window.document.querySelectorAll( '.franer-star.is-on' ) ).toHaveLength( 3 );
		expect( window.document.querySelectorAll( '.franer-star' ) ).toHaveLength( 5 );
		expect( rows[ 0 ][ 1 ] ).toContain( '3/5' );
		expect( rows[ 1 ] ).toEqual( [ 'Comments', '“Great &lt;b&gt;class&lt;/b&gt;”' ] );
		expect( rows[ 2 ] ).toEqual( [ 'Empty comment', 'No comment' ] );
		expect( rows[ 3 ] ).toEqual( [ 'Choices', '<code>["a","b"]</code>' ] );
		expect( rows[ 4 ] ).toEqual( [ 'Missing', '—' ] );
		expect( rows[ 5 ] ).toEqual( [ 'Blank', '—' ] );
		expect( rows[ 6 ] ).toEqual( [ 'Html', '&lt;img src=x&gt;' ] );
		expect( window.document.querySelector( '#franer-drawer-readable img' ) ).toBeNull();
	} );

	test( 'marks answers given against an earlier version of the form', async () => {
		await mount( row( 2, { a: 1 }, 'data-franer-outdated="1"' ), {}, { messages: { outdated: 'Old form.' } } );

		window.document.querySelector( '.franer-view-json' ).click();

		expect( window.document.querySelector( '.franer-drawer__notice' ).textContent ).toBe( 'Old form.' );
	} );

	test( 'says so when the payload is not a JSON object, and still shows it raw', async () => {
		await mount( row( 3, 'not json' ) + row( 4, '42' ), {}, { messages: {} } );
		const [ broken, scalar ] = window.document.querySelectorAll( '.franer-view-json' );

		broken.click();
		expect( window.document.getElementById( 'franer-drawer-readable' ).textContent ).toBe( 'Could not read this submission.' );
		expect( window.document.getElementById( 'franer-modal-content' ).value ).toBe( 'not json' );

		scalar.click();
		expect( window.document.getElementById( 'franer-drawer-readable' ).textContent ).toBe( 'Could not read this submission.' );
	} );

	test( 'falls back to readable labels when the schema is invalid', async () => {
		await mount( row( 5, { 'first-name': 'Eva', ___: 'x' } ), '{broken' );

		window.document.querySelector( '.franer-view-json' ).click();

		expect( readable() ).toEqual( [
			[ 'First name', 'Eva' ],
			[ '', 'x' ],
		] );
	} );

	test( 'switches between the summary and the JSON tab', async () => {
		await mount( row( 1, { a: 1 } ) );
		window.document.querySelector( '.franer-view-json' ).click();
		const jsonTab = window.document.querySelector( '[data-franer-drawer-tab="json"]' );

		jsonTab.click();

		expect( jsonTab.classList.contains( 'is-on' ) ).toBe( true );
		expect( window.document.querySelector( '[data-franer-drawer-tab="summary"]' ).classList.contains( 'is-on' ) ).toBe( false );
		expect( window.document.querySelector( '[data-franer-drawer-panel="json"]' ).hidden ).toBe( false );
		expect( window.document.querySelector( '[data-franer-drawer-panel="summary"]' ).hidden ).toBe( true );
	} );

	test( 'moves through the list with the buttons and the arrow keys, stopping at the ends', async () => {
		await mount( row( 10, { n: 'first' } ) + row( 11, { n: 'second' } ) );
		const eyebrow = window.document.getElementById( 'franer-drawer-eyebrow' );
		window.document.querySelector( '.franer-view-json' ).click();

		window.document.querySelector( '[data-franer-drawer-next]' ).click();
		expect( eyebrow.textContent ).toBe( '#11' );
		press( 'ArrowRight' );
		expect( eyebrow.textContent ).toBe( '#11' );

		window.document.querySelector( '[data-franer-drawer-prev]' ).click();
		expect( eyebrow.textContent ).toBe( '#10' );
		press( 'ArrowLeft' );
		expect( eyebrow.textContent ).toBe( '#10' );

		press( 'ArrowRight' );
		expect( eyebrow.textContent ).toBe( '#11' );
		expect( readable() ).toEqual( [ [ 'N', 'second' ] ] );
	} );

	test( 'closes with Escape or the close button, clearing the JSON and restoring focus', async () => {
		const drawer = await mount( row( 1, { a: 1 } ) );
		const opener = window.document.querySelector( '.franer-view-json' );
		opener.focus();
		opener.click();

		press( 'Escape' );
		expect( drawer.hidden ).toBe( true );
		expect( window.document.getElementById( 'franer-modal-content' ).value ).toBe( '' );
		expect( window.document.activeElement ).toBe( opener );

		opener.click();
		window.document.querySelector( '.franer-modal__close' ).click();
		expect( drawer.hidden ).toBe( true );
	} );

	test( 'ignores the keyboard while the drawer is closed', async () => {
		const drawer = await mount( row( 1, { a: 1 } ) );

		press( 'ArrowRight' );
		press( 'Escape' );

		expect( drawer.hidden ).toBe( true );
		expect( window.document.getElementById( 'franer-drawer-eyebrow' ).textContent ).toBe( '' );
	} );
} );

describe( 'Franer delete buttons', () => {
	const PAGE = `
		<button class="franer-delete-btn" data-franer-id="9" data-franer-delete-nonce="del9">Delete</button>
		<form id="franer-delete-form">
			<input id="franer-delete-id"><input id="franer-delete-nonce">
		</form>`;

	test( 'fills the delete form and submits it once confirmed', async () => {
		window.FranerAdmin = { messages: { deleteConfirm: 'Delete #9?' } };
		window.document.body.innerHTML = PAGE;
		await loadAdmin();
		const form = window.document.getElementById( 'franer-delete-form' );
		form.submit = vi.fn();
		const confirm = vi.spyOn( window, 'confirm' ).mockReturnValue( true );

		window.document.querySelector( '.franer-delete-btn' ).click();

		expect( confirm ).toHaveBeenCalledWith( 'Delete #9?' );
		expect( window.document.getElementById( 'franer-delete-id' ).value ).toBe( '9' );
		expect( window.document.getElementById( 'franer-delete-nonce' ).value ).toBe( 'del9' );
		expect( form.submit ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'does nothing when the deletion is not confirmed', async () => {
		window.FranerAdmin = {};
		window.document.body.innerHTML = PAGE;
		await loadAdmin();
		const form = window.document.getElementById( 'franer-delete-form' );
		form.submit = vi.fn();
		const confirm = vi.spyOn( window, 'confirm' ).mockReturnValue( false );

		window.document.querySelector( '.franer-delete-btn' ).click();

		expect( confirm ).toHaveBeenCalledWith( 'Delete this submission? This cannot be undone.' );
		expect( window.document.getElementById( 'franer-delete-id' ).value ).toBe( '' );
		expect( form.submit ).not.toHaveBeenCalled();
	} );
} );
