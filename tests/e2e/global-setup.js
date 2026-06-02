/**
 * Playwright global setup for Franer E2E tests.
 *
 * Logs into wp-admin once with raw Playwright and persists the authenticated
 * storage state to STORAGE_STATE_PATH so every spec runs as an administrator.
 *
 * This is intentionally self-contained (no @wordpress/e2e-test-utils-playwright
 * dependency) so it keeps working regardless of upstream packaging changes and
 * runs identically in CI.
 *
 * @param {import('@playwright/test').FullConfig} config The resolved config.
 * @return {Promise<void>}
 */
const { chromium } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

module.exports = async () => {
	const baseURL = process.env.WP_BASE_URL || 'http://localhost:8889';
	const storageStatePath =
		process.env.STORAGE_STATE_PATH ||
		path.join( process.cwd(), 'artifacts', 'storage-states', 'admin.json' );
	const user = process.env.WP_ADMIN_USER || 'admin';
	const pass = process.env.WP_ADMIN_PASSWORD || 'password';

	fs.mkdirSync( path.dirname( storageStatePath ), { recursive: true } );

	const browser = await chromium.launch();
	const page = await browser.newPage( { ignoreHTTPSErrors: true } );

	await page.goto( `${ baseURL }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'domcontentloaded' );

	await page.context().storageState( { path: storageStatePath } );
	await browser.close();
};
