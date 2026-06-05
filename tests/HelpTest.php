<?php
/**
 * Tests for the Franer_Help admin documentation screen.
 *
 * Covers the two reusable AI prompts and the render guard for the help page.
 *
 * @package Franer
 */

/**
 * Verifies the prompt contracts and the help-page capability gate.
 */
class HelpTest extends WP_UnitTestCase {

	/**
	 * The activity prompt must expose the JavaScript contract globals.
	 *
	 * @return void
	 */
	public function test_activity_prompt_contains_contract() {
		$prompt = Franer_Help::get_default_activity_prompt();

		$this->assertStringContainsString( 'window.FranerCollect', $prompt );
		$this->assertStringContainsString( 'window.FranerSubmit', $prompt );
		$this->assertStringContainsString( 'window.FranerPrefill', $prompt );
		$this->assertStringContainsString( 'REPLACE_WITH_SLUG', $prompt );
	}

	/**
	 * The submissions-overview prompt must expose its render contract.
	 *
	 * @return void
	 */
	public function test_view_prompt_contains_render_contract() {
		$prompt = Franer_Help::get_default_view_prompt();

		$this->assertStringContainsString( 'window.FranerRenderSubmissions', $prompt );
		$this->assertStringContainsString( 'franer_view_payload', $prompt );
	}

	/**
	 * A user without manage_options must be blocked from the help page.
	 *
	 * @return void
	 */
	public function test_render_help_page_denies_without_capability() {
		wp_set_current_user( 0 );

		$this->expectException( WPDieException::class );
		( new Franer_Help() )->render_help_page();
	}

	/**
	 * An administrator should get the rendered help partial.
	 *
	 * @return void
	 */
	public function test_render_help_page_renders_for_admin() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		( new Franer_Help() )->render_help_page();
		$html = ob_get_clean();

		$this->assertNotEmpty( $html );
	}
}
