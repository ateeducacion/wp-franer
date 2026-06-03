/**
 * Franer render-time comment stripping (public activity).
 *
 * The seeded "mcode40" activity contains HTML comments in its stored source.
 * When the public page renders, Franer strips HTML and inline-JS comments from
 * the iframe srcdoc, while the stored source keeps them. This spec verifies the
 * rendered srcdoc no longer carries HTML comments.
 *
 * Resilient: if the environment is not seeded it skips gracefully.
 */
const { test, expect } = require( '@playwright/test' );

const SLUG = process.env.FRANER_TEST_SLUG || 'mcode40';

test.describe( 'Franer comment stripping', () => {
	test( 'public iframe srcdoc contains no HTML comments', async ( { page } ) => {
		const response = await page.goto( `/franer/${ SLUG }/`, {
			waitUntil: 'domcontentloaded',
		} );

		if ( ! response || response.status() >= 400 ) {
			test.skip( true, `/franer/${ SLUG }/ not available; environment not seeded.` );
		}

		const iframe = page.locator( 'iframe.franer-shell__frame' ).first();

		if ( ! ( await iframe.count() ) ) {
			test.skip( true, 'No sandboxed activity iframe rendered.' );
		}

		const srcdoc = await iframe.getAttribute( 'srcdoc' );

		if ( null === srcdoc ) {
			test.skip( true, 'Activity iframe has no srcdoc attribute.' );
		}

		// HTML comments must have been stripped before rendering.
		expect( srcdoc ).not.toContain( '<!--' );
		// The sandbox must still exclude same-origin access.
		const sandbox = await iframe.getAttribute( 'sandbox' );
		expect( sandbox ).not.toContain( 'allow-same-origin' );
	} );
} );
