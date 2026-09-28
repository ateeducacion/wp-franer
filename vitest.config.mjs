/**
 * Vitest configuration for the Franer JavaScript unit tests.
 *
 * Run through `npm run test:js` (`wp-scripts test-unit-js`, which starts the
 * Vitest installed in this project).
 *
 * No coverage is collected: the tests evaluate the browser scripts with
 * `window.eval( source )`, which the coverage provider cannot attribute to the
 * source files, so a report would show 0 % for code the suite does exercise.
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
	},
} );
