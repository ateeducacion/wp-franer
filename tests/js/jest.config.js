/**
 * Jest configuration for the Franer parent shell tests.
 *
 * @see https://jestjs.io/docs/configuration
 */
const path = require( 'path' );

module.exports = {
	rootDir: path.join( __dirname, '..', '..' ),
	testEnvironment: 'jsdom',
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
	clearMocks: true,
	verbose: true,
};
