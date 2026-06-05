<?php
/**
 * Tests for the Franer_I18n text-domain loader.
 *
 * @package Franer
 */

/**
 * Verifies that the plugin text domain is loaded for the "franer" domain.
 */
class I18nTest extends WP_UnitTestCase {

	/**
	 * load_plugin_textdomain() should register the bundled languages directory.
	 *
	 * On modern WordPress, load_plugin_textdomain() registers the catalogue path
	 * with the global WP_Textdomain_Registry for just-in-time loading rather than
	 * loading the .mo eagerly. Asserting the registry resolves the franer domain to
	 * the plugin's own languages/ directory proves the method wired the correct
	 * path. The 'plugin_locale' filter pins the locale so the lookup is
	 * deterministic regardless of the site locale, and is removed afterwards.
	 *
	 * @return void
	 */
	public function test_load_plugin_textdomain_registers_languages_path() {
		global $wp_textdomain_registry;

		$force_es = static function ( $locale, $domain ) {
			return 'franer' === $domain ? 'es_ES' : $locale;
		};
		add_filter( 'plugin_locale', $force_es, 10, 2 );

		try {
			( new Franer_I18n() )->load_plugin_textdomain();

			$path = $wp_textdomain_registry->get( 'franer', 'es_ES' );

			$this->assertIsString( $path );
			$this->assertStringContainsString( '/franer/languages/', $path );
		} finally {
			remove_filter( 'plugin_locale', $force_es, 10 );
		}
	}
}
