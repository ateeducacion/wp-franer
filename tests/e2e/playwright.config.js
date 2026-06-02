/**
 * Playwright configuration for Franer E2E tests.
 *
 * Adapted from the wp-documentate configuration. Uses the wp-env "tests"
 * environment (port 8889) by default.
 *
 * @see https://playwright.dev/docs/test-configuration
 */
const path = require( 'path' );
const { defineConfig, devices } = require( '@playwright/test' );

process.env.WP_ARTIFACTS_PATH ??= path.join( process.cwd(), 'artifacts' );
process.env.STORAGE_STATE_PATH ??= path.join(
	process.env.WP_ARTIFACTS_PATH,
	'storage-states/admin.json'
);

// Use the wp-env tests environment (port 8889) by default.
const baseUrl = process.env.WP_BASE_URL || 'http://localhost:8889';

module.exports = defineConfig( {
	reporter: process.env.CI ? [ [ 'github' ] ] : [ [ 'list' ] ],
	forbidOnly: !! process.env.CI,
	fullyParallel: false,
	workers: process.env.CI
		? '100%'
		: parseInt( process.env.PLAYWRIGHT_WORKERS || '', 10 ) || 1,
	retries: process.env.CI ? 2 : 0,
	timeout: parseInt( process.env.TIMEOUT || '', 10 ) || 60_000,
	reportSlowTests: { max: 5, threshold: 15_000 },
	testDir: path.join( __dirname, 'specs' ),
	outputDir: path.join( process.env.WP_ARTIFACTS_PATH, 'test-results' ),
	snapshotPathTemplate:
		'{testDir}/{testFileDir}/__snapshots__/{arg}-{projectName}{ext}',
	globalSetup: require.resolve( './global-setup.js' ),
	use: {
		baseURL: baseUrl,
		headless: true,
		viewport: {
			width: 960,
			height: 700,
		},
		ignoreHTTPSErrors: true,
		locale: 'en-US',
		contextOptions: {
			reducedMotion: 'reduce',
			strictSelectors: true,
		},
		storageState: process.env.STORAGE_STATE_PATH,
		actionTimeout: 10_000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'on-first-retry',
	},
	webServer: {
		command: 'npm run wp-env start',
		url: baseUrl,
		timeout: 120_000,
		reuseExistingServer: true,
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
