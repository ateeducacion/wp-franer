/**
 * Vitest configuration for the Franer JavaScript unit tests.
 *
 * Run through `npm run test:js` (`wp-scripts test-unit-js`, which starts the
 * Vitest installed in this project).
 *
 * Each test loads the browser script it exercises with `vi.resetModules()` +
 * `await import()`, so the script runs again from scratch and the coverage
 * report attributes it to its source file. The report lands in
 * artifacts/coverage-js/ and CI uploads it to Codecov under the `js` flag.
 */
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	test: {
		environment: 'jsdom',
		include: [ 'tests/js/**/*.test.js' ],
		globals: false,
		// Explicit so a change of Vitest defaults cannot alter the suite:
		// clearMocks as the Jest config had it, the rest as Jest's defaults.
		clearMocks: true,
		mockReset: false,
		restoreMocks: false,
		reporters: [ 'verbose' ],
		coverage: {
			enabled: true,
			provider: 'v8',
			include: [ 'admin/js/*.js', 'public/js/*.js' ],
			reportsDirectory: 'artifacts/coverage-js',
			reporter: [ 'lcov', 'text-summary' ],
		},
	},
} );
