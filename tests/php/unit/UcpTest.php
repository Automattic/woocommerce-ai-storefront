<?php
/**
 * Tests for WC_AI_Syndication_Ucp.
 *
 * Focuses on `generate_manifest()` — the method that produces the JSON
 * payload served at `/.well-known/ucp`. These tests pin the manifest's
 * *shape*: the top-level keys present, the declared checkout policy
 * (web-redirect only, never delegated), protocol version, and purchase
 * URL templates. Shape regressions silently break AI crawlers that
 * consume this manifest.
 *
 * HTTP serving / header behavior is exercised minimally — that path is
 * hard to test without a full WP env and the complexity isn't worth it
 * for plain `status_header` / `header()` calls.
 *
 * @package WooCommerce_AI_Syndication
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Syndication_Ucp $ucp;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->ucp = new WC_AI_Syndication_Ucp();

		// Stub the WP + WC functions that `generate_manifest()` calls.
		// Using `when()->justReturn()` rather than `expect()` because we
		// don't care about invocation counts — only the resulting output.
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . ( $path ?: '/' )
		);
		Functions\when( 'wc_get_page_permalink' )->alias(
			static fn( $page ) => 'https://example.com/' . $page . '/'
		);
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://example.com/cart/' );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://example.com/checkout/' );
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => [
				'name'        => 'Example Store',
				'description' => 'A test storefront',
			][ $key ] ?? ''
		);
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		Functions\when( 'rest_url' )->alias(
			static fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' )
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		// apply_filters is a passthrough — we don't test filter hooks here.
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Top-level structure
	// ------------------------------------------------------------------

	public function test_manifest_has_required_top_level_sections(): void {
		$manifest = $this->ucp->generate_manifest( [] );

		// These are the contractual keys AI crawlers parse. Adding a key
		// is safe; removing or renaming one is a breaking change.
		$required_keys = [
			'protocol_version',
			'store',
			'checkout',
			'capabilities',
			'store_api',
			'purchase',
			'attribution',
			'discovery',
			'rate_limits',
		];

		foreach ( $required_keys as $key ) {
			$this->assertArrayHasKey( $key, $manifest, "Missing top-level key: {$key}" );
		}
	}

	public function test_protocol_version_is_declared(): void {
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals(
			WC_AI_Syndication_Ucp::PROTOCOL_VERSION,
			$manifest['protocol_version']
		);
	}

	// ------------------------------------------------------------------
	// Checkout policy — the plugin's core promise
	// ------------------------------------------------------------------

	public function test_checkout_declares_web_redirect_only(): void {
		// The plugin's entire "data sovereignty" pitch rests on this:
		// checkout happens on the merchant's site, never delegated, never
		// in-chat. If these flags ever flip, the plugin's value prop is
		// broken — catch that loudly.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals( 'web_redirect', $manifest['checkout']['method'] );
		$this->assertFalse( $manifest['checkout']['in_chat'] );
		$this->assertFalse( $manifest['checkout']['delegated'] );
	}

	public function test_checkout_exposes_cart_and_checkout_urls(): void {
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals(
			'https://example.com/checkout/',
			$manifest['checkout']['url']
		);
		$this->assertEquals(
			'https://example.com/cart/',
			$manifest['checkout']['cart_url']
		);
	}

	// ------------------------------------------------------------------
	// Store metadata
	// ------------------------------------------------------------------

	public function test_store_metadata_is_populated(): void {
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals( 'Example Store', $manifest['store']['name'] );
		$this->assertEquals( 'A test storefront', $manifest['store']['description'] );
		$this->assertEquals( 'https://example.com/', $manifest['store']['url'] );
		$this->assertEquals( 'USD', $manifest['store']['currency'] );
		$this->assertEquals( 'en_US', $manifest['store']['locale'] );
	}

	public function test_store_name_html_entities_are_decoded(): void {
		// WordPress get_bloginfo() HTML-encodes by default — a store named
		// "Joe's Shop" comes back as "Joe&#039;s Shop". Raw-encoded entities
		// in the JSON would confuse AI crawlers. Confirm they're decoded.
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => [
				'name'        => 'Joe&#039;s Shop &amp; Cafe',
				'description' => 'Best &quot;coffee&quot; &amp; pastries',
			][ $key ] ?? ''
		);

		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals( "Joe's Shop & Cafe", $manifest['store']['name'] );
		$this->assertEquals(
			'Best "coffee" & pastries',
			$manifest['store']['description']
		);
	}

	// ------------------------------------------------------------------
	// Purchase URL templates
	// ------------------------------------------------------------------

	public function test_purchase_section_documents_all_product_types(): void {
		$manifest = $this->ucp->generate_manifest( [] );

		$checkout_link = $manifest['purchase']['checkout_link'];
		$this->assertArrayHasKey( 'simple', $checkout_link );
		$this->assertArrayHasKey( 'variable', $checkout_link );
		$this->assertArrayHasKey( 'multi_item', $checkout_link );
		$this->assertArrayHasKey( 'with_coupon', $checkout_link );
		$this->assertArrayHasKey( 'grouped', $checkout_link );

		$add_to_cart = $manifest['purchase']['add_to_cart'];
		$this->assertArrayHasKey( 'simple', $add_to_cart );
		$this->assertArrayHasKey( 'variable', $add_to_cart );
		$this->assertArrayHasKey( 'grouped', $add_to_cart );
	}

	public function test_checkout_link_templates_use_checkout_link_path(): void {
		// The `/checkout-link/` path is WooCommerce's native one-step
		// checkout format. Regressions that switch to a different path
		// (e.g. `/cart/`) would break agent-driven purchase flows.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertStringContainsString(
			'/checkout-link/',
			$manifest['purchase']['checkout_link']['simple']
		);
		$this->assertStringContainsString(
			'{product_id}',
			$manifest['purchase']['checkout_link']['simple']
		);
	}

	public function test_grouped_product_template_uses_checkout_as_base(): void {
		// Grouped products can't be purchased via /checkout-link/ — they
		// use ?add-to-cart with the checkout page as the base so the
		// customer lands on checkout after items are added. This wrinkle
		// is documented in the `note` field; verify the URL reflects it.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertStringContainsString(
			'/checkout/',
			$manifest['purchase']['checkout_link']['grouped']
		);
		$this->assertStringContainsString(
			'add-to-cart=',
			$manifest['purchase']['checkout_link']['grouped']
		);
	}

	// ------------------------------------------------------------------
	// Capabilities
	// ------------------------------------------------------------------

	public function test_capabilities_declares_core_features(): void {
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertTrue( $manifest['capabilities']['product_discovery'] );
		$this->assertTrue( $manifest['capabilities']['checkout_links'] );
		$this->assertTrue( $manifest['capabilities']['attribution'] );
	}

	// ------------------------------------------------------------------
	// Store API reference
	// ------------------------------------------------------------------

	public function test_store_api_points_to_public_wc_store_endpoint(): void {
		// The plugin relies on WooCommerce's public, unauthenticated Store
		// API for product data. Crawlers need the base URL to make calls.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals(
			'https://example.com/wp-json/wc/store/v1',
			$manifest['store_api']['base_url']
		);
	}

	// ------------------------------------------------------------------
	// Attribution section
	// ------------------------------------------------------------------

	public function test_attribution_uses_standard_woocommerce_system(): void {
		// The plugin's attribution strategy reuses standard WooCommerce
		// Order Attribution (utm_source / utm_medium meta). A regression
		// here would silently break revenue reporting.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals(
			'woocommerce_order_attribution',
			$manifest['attribution']['system']
		);
		$this->assertArrayHasKey( 'utm_source', $manifest['attribution']['parameters'] );
		$this->assertArrayHasKey( 'utm_medium', $manifest['attribution']['parameters'] );
		$this->assertArrayHasKey(
			'ai_session_id',
			$manifest['attribution']['parameters']
		);
	}

	// ------------------------------------------------------------------
	// Rate limits
	// ------------------------------------------------------------------

	public function test_rate_limits_read_from_settings(): void {
		$manifest = $this->ucp->generate_manifest( [ 'rate_limit_rpm' => 50 ] );

		$this->assertEquals( 50, $manifest['rate_limits']['requests_per_minute'] );
	}

	public function test_rate_limits_default_to_25_when_missing(): void {
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals( 25, $manifest['rate_limits']['requests_per_minute'] );
	}

	// ------------------------------------------------------------------
	// Discovery references
	// ------------------------------------------------------------------

	public function test_discovery_points_to_llms_txt_and_sitemap(): void {
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals(
			'https://example.com/llms.txt',
			$manifest['discovery']['llms_txt']
		);
		$this->assertStringContainsString(
			'sitemap',
			$manifest['discovery']['sitemap']
		);
	}

	// ------------------------------------------------------------------
	// Filter extensibility
	// ------------------------------------------------------------------

	public function test_manifest_is_filterable(): void {
		// We override the default `apply_filters` passthrough to verify
		// third parties can extend the manifest — e.g. a payments plugin
		// declaring extra `capabilities.reservations` support.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, $settings ) {
				if ( 'wc_ai_syndication_ucp_manifest' === $hook ) {
					$value['custom_key'] = 'extended';
				}
				return $value;
			}
		);

		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals( 'extended', $manifest['custom_key'] );
	}
}
