<?php
/**
 * Tests for `WC_AI_Storefront_JsonLd::output_website_jsonld()`.
 *
 * Verifies that the global WebSite + SearchAction JSON-LD block is:
 *  - skipped when the plugin is disabled.
 *  - emitted inside a <script type="application/ld+json"> tag.
 *  - typed as WebSite with exactly two potentialAction SearchActions.
 *  - first action targets the native WP search URL.
 *  - second action targets the REST GET catalog/search endpoint.
 *  - served from transient cache on the second call.
 *  - suppressed when wc_ai_storefront_jsonld_website filter returns false.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class JsonLdWebsiteTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_JsonLd $jsonld;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->jsonld = new WC_AI_Storefront_JsonLd();

		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];

		// No cached data by default; caching is a no-op.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'rest_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Helper
	// ------------------------------------------------------------------

	private function capture(): string {
		ob_start();
		$this->jsonld->output_website_jsonld();
		return (string) ob_get_clean();
	}

	private function decode_output(): ?array {
		$output = $this->capture();
		if ( '' === $output ) {
			return null;
		}
		preg_match( '/<script[^>]*>(.*?)<\/script>/s', $output, $m );
		return json_decode( $m[1] ?? '{}', true );
	}

	// ------------------------------------------------------------------
	// Tests
	// ------------------------------------------------------------------

	public function test_skips_when_plugin_disabled(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];
		$this->assertSame( '', $this->capture() );
	}

	public function test_wraps_in_script_tag(): void {
		$output = $this->capture();
		$this->assertStringContainsString( '<script type="application/ld+json">', $output );
		$this->assertStringContainsString( '</script>', $output );
	}

	public function test_emits_website_schema_type(): void {
		$data = $this->decode_output();
		$this->assertSame( 'WebSite', $data['@type'] );
		$this->assertSame( 'https://schema.org', $data['@context'] );
	}

	public function test_emits_two_potential_actions(): void {
		$data = $this->decode_output();
		$this->assertCount( 2, $data['potentialAction'] );
		foreach ( $data['potentialAction'] as $action ) {
			$this->assertSame( 'SearchAction', $action['@type'] );
		}
	}

	public function test_first_action_targets_native_search(): void {
		$data     = $this->decode_output();
		$template = $data['potentialAction'][0]['target']['urlTemplate'];
		$this->assertStringContainsString( '?s={search_term}', $template );
		$this->assertStringContainsString( 'post_type=product', $template );
	}

	public function test_second_action_targets_rest_get_endpoint(): void {
		$data     = $this->decode_output();
		$template = $data['potentialAction'][1]['target']['urlTemplate'];
		$this->assertStringContainsString( 'catalog/search', $template );
		$this->assertStringContainsString( '?q={search_term}', $template );
	}

	public function test_served_from_transient_cache(): void {
		$cached = [
			'@context' => 'https://schema.org',
			'@type'    => 'WebSite',
			'url'      => 'https://cached.example.com/',
			'potentialAction' => [],
		];
		Functions\when( 'get_transient' )->justReturn( $cached );

		$data = $this->decode_output();
		$this->assertSame( 'https://cached.example.com/', $data['url'] );
	}

	public function test_suppressed_when_filter_returns_false(): void {
		Functions\when( 'apply_filters' )->returnArg( 1 ); // returns filter name — falsy when compared to data array
		// Re-stub so it returns false for the website filter specifically.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $data ) {
				if ( 'wc_ai_storefront_jsonld_website' === $hook ) {
					return false;
				}
				return $data;
			}
		);

		$this->assertSame( '', $this->capture() );
	}
}
