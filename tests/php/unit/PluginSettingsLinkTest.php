<?php
/**
 * Tests for the Plugins-screen "Settings" action link.
 *
 * WC_AI_Storefront::add_settings_action_link() is static + pure, so it is
 * exercised behaviorally without instantiating the (un-instantiable-in-
 * isolation) singleton. The registration itself is asserted structurally
 * against the orchestrator source, the same idiom ActivationTest /
 * FrontendHookWiringTest use.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class PluginSettingsLinkTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_settings_action_link_prepended(): void {
		Functions\when( 'admin_url' )->alias(
			static fn( $path = '' ) => 'https://example.com/wp-admin/' . $path
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$existing = array( 'deactivate' => '<a href="#deactivate">Deactivate</a>' );
		$result   = WC_AI_Storefront::add_settings_action_link( $existing );

		// The Settings link is prepended (first element) and points at the
		// WooCommerce submenu settings page.
		$first = reset( $result );
		$this->assertStringContainsString( 'admin.php?page=wc-ai-storefront', $first );
		$this->assertStringContainsString( '>Settings</a>', $first );

		// The existing link is preserved (not clobbered).
		$this->assertCount( 2, $result );
		$this->assertContains( '<a href="#deactivate">Deactivate</a>', $result );
	}

	public function test_real_orchestrator_shares_the_slug_constant(): void {
		// Guard real/stub consistency + DRY: the real file defines the slug
		// constant, and BOTH the admin menu and the Settings link build off it
		// (never a duplicated literal). The behavioral test above runs against
		// the stub's mirror, so this pins the real implementation to it.
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-wc-ai-storefront.php' );
		$this->assertStringContainsString( "const ADMIN_PAGE_SLUG = 'wc-ai-storefront';", $source );
		$this->assertStringContainsString( "'admin.php?page=' . self::ADMIN_PAGE_SLUG", $source );
		$this->assertStringContainsString( 'self::ADMIN_PAGE_SLUG,', $source );
	}

	public function test_settings_action_link_registered_in_orchestrator(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-wc-ai-storefront.php' );
		$this->assertMatchesRegularExpression(
			'/add_filter\(\s*[\'"]plugin_action_links_[\'"][^;]*add_settings_action_link/',
			$source,
			'The Plugins-screen Settings link filter must be registered.'
		);
	}
}
