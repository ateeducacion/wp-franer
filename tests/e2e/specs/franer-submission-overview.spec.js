/**
 * Franer admin submissions-overview route.
 *
 * Smoke test for the admin-only "Submissions overview" page. It must be
 * reachable by an administrator (authenticated via the persisted storage state)
 * without PHP errors, and must show either the rendered overview iframe or the
 * "no template configured" notice for the seeded activity.
 *
 * Resilient: skips gracefully when the environment is not seeded.
 */
const { test, expect } = require( '@playwright/test' );

test.describe( 'Franer submissions overview', () => {
	test( 'overview page loads for the seeded activity without errors', async ( {
		page,
	} ) => {
		// Resolve the seeded activity ID from the submissions filter dropdown.
		const listResponse = await page.goto(
			'/wp-admin/edit.php?post_type=franer_site&page=franer-submissions',
			{ waitUntil: 'domcontentloaded' }
		);

		if ( ! listResponse || listResponse.status() >= 400 ) {
			test.skip( true, 'Submissions screen not available; environment not seeded.' );
		}

		const option = page
			.locator( 'select[name="site_id"] option' )
			.filter( { hasText: /MCODE40/i } )
			.first();

		const siteId = ( await option.count() )
			? await option.getAttribute( 'value' )
			: null;

		if ( ! siteId || '0' === siteId ) {
			test.skip( true, 'Seeded MCODE40 activity not present.' );
		}

		const response = await page.goto(
			`/wp-admin/edit.php?post_type=franer_site&page=franer-submission-view&site_id=${ siteId }`,
			{ waitUntil: 'domcontentloaded' }
		);

		expect( response ).not.toBeNull();
		expect( response.status() ).toBeLessThan( 400 );

		// No PHP deprecation / fatal leaked into the output.
		const body = await page.content();
		expect( body ).not.toContain( 'Deprecated:' );
		expect( body ).not.toContain( 'Fatal error' );
		expect( body ).not.toContain( 'headers already sent' );

		// The page heading renders (locale-agnostic selector).
		await expect( page.locator( 'h1.wp-heading-inline' ) ).toBeVisible();

		// Either the rendered iframe or the "no template" notice is present.
		const frameOrNotice = page.locator(
			'#franer-view-frame, .franer-submission-view-wrap .notice'
		);
		await expect( frameOrNotice.first() ).toBeVisible();
	} );
} );
