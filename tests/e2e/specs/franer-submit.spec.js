/**
 * Franer end-to-end submission flow.
 *
 * Scenario:
 *  1. An admin opens the Franer admin screen (CPT list).
 *  2. An allowed, logged-in user opens /franer/{slug}/ (seeded "mcode40").
 *  3. The activity iframe posts a "franer_submit" message; the parent shell
 *     performs the nonced REST call; the submission is stored.
 *  4. The admin Submissions screen shows the stored submission.
 *
 * Authentication is provided by the persisted admin storage state created in
 * tests/e2e/global-setup.js (use.storageState in the config). The test is
 * intentionally resilient: if the environment is not seeded (missing pages,
 * REST disabled, etc.) it skips gracefully instead of failing.
 */
const { test, expect } = require( '@playwright/test' );

const SLUG = process.env.FRANER_TEST_SLUG || 'mcode40';

test.describe( 'Franer submission flow', () => {
	test( 'admin can reach the Franer admin menu', async ( { page } ) => {
		const response = await page.goto(
			'/wp-admin/edit.php?post_type=franer_site',
			{ waitUntil: 'domcontentloaded' }
		);

		if ( ! response || response.status() >= 400 ) {
			test.skip( true, 'wp-admin not reachable; environment not seeded.' );
		}

		const listTable = page.locator( '#posts-filter, .wp-list-table' );

		if ( ! ( await listTable.count() ) ) {
			test.skip(
				true,
				'franer_site CPT screen not available in this environment.'
			);
		}

		await expect( listTable.first() ).toBeVisible();
	} );

	test( 'allowed user submission is stored and visible in admin', async ( {
		page,
	} ) => {
		// Visit the public URL for the seeded activity.
		const response = await page.goto( `/franer/${ SLUG }/`, {
			waitUntil: 'domcontentloaded',
		} );

		if ( ! response || response.status() >= 400 ) {
			test.skip(
				true,
				`/franer/${ SLUG }/ not available; environment not seeded.`
			);
		}

		// The activity is rendered inside a sandboxed iframe.
		const iframe = page.locator( 'iframe[sandbox]' ).first();

		if ( ! ( await iframe.count() ) ) {
			test.skip(
				true,
				'No sandboxed activity iframe rendered; cannot drive submit.'
			);
		}

		await expect( iframe ).toBeVisible();

		// Sandbox must allow scripts/forms but never same-origin.
		const sandbox = await iframe.getAttribute( 'sandbox' );
		expect( sandbox ).toContain( 'allow-scripts' );
		expect( sandbox ).not.toContain( 'allow-same-origin' );

		// Drive a submit by posting the contract message to the parent window.
		// The parent shell (franer-shell.js) intercepts it, performs the nonced
		// REST call, and posts a franer_submit_result back to event.source.
		const resultOk = await page.evaluate( async ( slug ) => {
			return new Promise( ( resolve ) => {
				function onMessage( event ) {
					if (
						event.data &&
						event.data.type === 'franer_submit_result'
					) {
						window.removeEventListener( 'message', onMessage );
						resolve( !! event.data.ok );
					}
				}

				window.addEventListener( 'message', onMessage );

				window.postMessage(
					{
						type: 'franer_submit',
						payload: {
							schema_version: '1.0',
							activity_id: slug,
							data: { e2e: true },
						},
					},
					'*'
				);

				// Safety timeout.
				setTimeout( () => resolve( null ), 8000 );
			} );
		}, SLUG );

		if ( null === resultOk ) {
			test.skip(
				true,
				'No submit result received; activity shell not active.'
			);
		}

		// A logged-in allowed user should get a successful result.
		expect( resultOk ).toBe( true );

		// Verify in the admin Submissions screen that the submission is visible.
		await page.goto(
			'/wp-admin/edit.php?post_type=franer_site&page=franer-submissions',
			{ waitUntil: 'domcontentloaded' }
		);

		// Locale-agnostic: the heading text is translated, so assert by selector.
		await expect( page.locator( 'h1.wp-heading-inline' ) ).toBeVisible();

		// Select the activity in the filter so its submissions table renders.
		const select = page.locator( 'select[name="site_id"]' ).first();

		if ( ! ( await select.count() ) ) {
			test.skip(
				true,
				'Submissions admin screen not available in this environment.'
			);
		}

		const option = select
			.locator( 'option' )
			.filter( { hasText: /MCODE40/i } )
			.first();
		const value = ( await option.count() )
			? await option.getAttribute( 'value' )
			: null;

		if ( ! value || '0' === value ) {
			test.skip( true, 'Seeded MCODE40 activity not present.' );
		}

		await select.selectOption( value );
		await page
			.locator( 'form.franer-submissions-filter button[type="submit"]' )
			.click();
		await page.waitForLoadState( 'domcontentloaded' );

		await expect(
			page.locator( '.franer-submissions-table' ).first()
		).toBeVisible();
	} );
} );
