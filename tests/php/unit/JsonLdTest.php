<?php
/**
 * Tests for WC_AI_Storefront_JsonLd.
 *
 * Focuses on `enhance_product_data()` — the filter that augments
 * WooCommerce's native product JSON-LD with fields that help AI
 * agents (BuyAction with a bare checkout URL, inventory,
 * dimensions, attributes, shipping, returns).
 *
 * This class inserts data into HTML, so unit coverage is also a
 * light defense against XSS: values must be conveyed through
 * WordPress's own escaping helpers, and the enhancer should not
 * synthesize string concatenation that could inject unescaped input.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

// Minimal stand-in for Jetpack Publicize's Connections class so the
// `class_exists( '\Automattic\Jetpack\Publicize\Connections' )` guard in
// `collect_same_as()` engages and the Jetpack extraction body actually
// runs under test. The real connection data is supplied per-test via the
// `get_transient( 'jetpack_social_connections_list' )` stub — this class
// only needs to EXIST. Defined via class_alias (mirroring the pattern in
// tests/php/stubs/class-wcpay-multi-currency-stubs.php) and guarded so the
// definition is idempotent across the suite.
if ( ! class_exists( '\Automattic\Jetpack\Publicize\Connections' ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- inline test double
	class WC_AI_Storefront_Jetpack_Publicize_Connections_Stub {
		public static function get_all( $args = array() ) {
			return array();
		}
	}
	class_alias(
		'WC_AI_Storefront_Jetpack_Publicize_Connections_Stub',
		'Automattic\Jetpack\Publicize\Connections'
	);
}

class JsonLdTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_JsonLd $jsonld;

	/**
	 * Per-post postmeta lookup table consulted by the `get_post_meta`
	 * stub installed in setUp(). Tests populate via `make_variation()`
	 * (when a variation_attributes override is supplied) so the
	 * variation's `attribute_<slug>` reads return the test fixture's
	 * values. Other meta keys still fall through to empty.
	 *
	 * @var array<int,array<string,string>>
	 */
	private array $post_meta_by_id = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->post_meta_by_id = [];
		WC_Shipping_Zones::$test_zones = [];
		$this->jsonld = new WC_AI_Storefront_JsonLd();

		// Default: syndication enabled, no category restriction. Tests
		// that exercise the disabled path or product-exclusion override.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];

		// `add_query_arg()` mock mirroring WP's actual behavior. Two
		// places this differs from PHP's native `http_build_query`:
		//
		// 1. WP's `add_query_arg` → `build_query` → `_http_build_query`
		//    is called with `$urlencode = false`, so values are NOT
		//    URL-encoded. A pre-0.11 mock built on `http_build_query`
		//    silently produced `utm_source=%7Bagent_id%7D` while
		//    production emitted `utm_source={agent_id}` — RFC 6570
		//    placeholders survive verbatim.
		// 2. WP strips and re-appends the URI fragment around the
		//    query string. Without this, a permalink like
		//    `.../widget/#reviews` would produce
		//    `.../widget/#reviews?add-to-cart=42` where the whole
		//    query is in the fragment and never reaches the server.
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				$fragment = '';
				if ( str_contains( $url, '#' ) ) {
					[ $url, $fragment ] = explode( '#', $url, 2 );
					$fragment = '#' . $fragment;
				}
				$pairs = array();
				foreach ( $args as $k => $v ) {
					$pairs[] = $k . '=' . $v;
				}
				// Simulate a URL that has been HTML-escaped before reaching
				// add_query_arg() — e.g. a third-party filter that passes
				// the permalink through esc_url(). Real WordPress
				// add_query_arg() itself returns plain '&', but an
				// escaped incoming URL causes it to inherit '&amp;'
				// separators in the output. Using '&amp;' here ensures
				// tests that assert html_entity_decode() is applied will
				// actually fail if the decode is ever removed.
				$query = implode( '&amp;', $pairs );
				$sep   = str_contains( $url, '?' ) ? '&amp;' : '?';
				return $url . $sep . $query . $fragment;
			}
		);
		// `home_url()` is the base of the Shareable Checkout URL the
		// BuyAction emits. Stub a stable value so URL-shape assertions
		// don't depend on the WP test bootstrap's site URL.
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
		Functions\when( 'wc_get_product_cat_ids' )->justReturn( [] );
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => 'US', 'state' => 'CA' ]
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// `do_shortcode()` is called when formatting a variation's OWN
		// description for variant-field inheritance (mirroring WC core's
		// description formatting). Pass the content through unchanged so
		// tests don't need a shortcode registry; the description-format
		// tests assert on `wp_strip_all_tags( do_shortcode( ... ) )`.
		Functions\when( 'do_shortcode' )->returnArg( 1 );

		// get_catalog_summary() now uses a transient cache. Stub both
		// functions globally so all tests that invoke output_store_jsonld()
		// work without individual setup. Tests that want to verify caching
		// behaviour specifically may override these via Functions\expect().
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		// Stub the per-product final-sale meta read to "not flagged"
		// across all tests in this file. Per-product override coverage
		// lives in JsonLdReturnPolicyTest's dedicated branch.
		//
		// Variation-attribute postmeta (`attribute_<slug>`) reads consult
		// the per-test `$this->post_meta_by_id` table populated by
		// `make_variation()`. The closure captures `$this` so each test
		// gets the table state at call time, not at setUp time.
		//
		// FOOT-GUN: an individual test calling
		// `Functions\when( 'get_post_meta' )->justReturn( ... )` will
		// silently REPLACE this aliased version (Brain Monkey keeps the
		// most recent binding). When that happens, every variation in
		// the test loses its `attribute_<slug>` reads and ProductGroup
		// detection falls back to "no varying axis." If you need to
		// override `get_post_meta` for a specific key in one test, use
		// a fresh alias that consults `$this->post_meta_by_id` for the
		// keys this stub already serves and your custom logic for the
		// rest — don't `justReturn` over the whole function.
		$test = $this;
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key = '', $single = false ) use ( $test ) {
				$post_id = (int) $post_id;
				if ( isset( $test->post_meta_by_id[ $post_id ][ $key ] ) ) {
					return $test->post_meta_by_id[ $post_id ][ $key ];
				}
				return '';
			}
		);
		// Default `wp_get_post_parent_id()` to 0 — these tests use
		// non-variation product mocks. Override-scope resolution
		// happens at the `enhance_product_data` entry point.
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );

		// `output_store_jsonld()` reads identity fields from existing
		// WP/WC data. Stub the readers to "merchant has nothing
		// configured" by default so pre-existing tests of unrelated
		// store-JSON-LD behavior (search action URL, taxonomy hex
		// escaping, etc.) don't have to know about identity fields.
		// Tests that exercise the identity path override these via
		// per-test `Functions\when()` calls (Brain Monkey overwrites
		// the latest binding).
		//
		// `WC()` is deliberately NOT stubbed here. Once Brain Monkey
		// registers a `Functions\when()` for a function name, every
		// later test in the suite must explicitly re-register or call
		// `Mockery::close()` clean — otherwise the global stub leaks
		// and unrelated tests (UcpCatalogLookupTest, UcpTest, etc.)
		// see `MissingFunctionExpectations` on real `WC()` calls.
		// Identity tests stub `WC` inline; tests that don't touch the
		// address path don't need it.
		Functions\when( 'get_theme_mod' )->justReturn( 0 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'sanitize_email' )->returnArg();
		// `esc_url_raw` is called by `collect_same_as()` (issue #445) to
		// sanitize each auto-sourced social-profile URL before it lands
		// in `sameAs`. Pass-through for tests; the http/https allow-list
		// filtering is done in PHP (testable), and real URL escaping is
		// WP core's concern. Default `get_option` returns '' for the
		// `wpseo_social` / `rank-math-options-titles` reads, so without a
		// per-test override no `sameAs` is collected.
		Functions\when( 'esc_url_raw' )->returnArg();
		// Structural validity check rather than a hardcoded `false`
		// return. WP's real `is_email()` accepts anything that has an
		// `@` somewhere with non-empty local- and domain-parts; that's
		// enough for unit-test purposes and prevents tests that
		// configure a real email from silently passing for the wrong
		// reason (an always-false stub would fail the email check
		// regardless of the input the test set up). Tests that need
		// to assert the "invalid email" branch use a sentinel like
		// `gibberish` (no `@`), which falls through this alias to
		// false naturally.
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				if ( ! is_string( $email ) || '' === $email ) {
					return false;
				}
				$at = strpos( $email, '@' );
				return false !== $at && $at > 0 && $at < strlen( $email ) - 1;
			}
		);
	}

	protected function tearDown(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		WC_AI_Storefront::$test_settings = [];
		WC_Shipping_Zones::$test_zones   = [];
		// Reset the subscription stub's static state unconditionally.
		// Subscription tests previously did this via per-test
		// `tearDownSubscriptions()` calls, but those don't fire when an
		// assertion throws mid-test, leaking state into later tests.
		// PHPUnit's `tearDown()` runs after every test (pass or fail),
		// so this is the correct cleanup site.
		if ( class_exists( 'WC_Subscriptions_Product', false ) ) {
			WC_Subscriptions_Product::$test_data = [];
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a rich WC_Product mock. Defaults mirror a simple product
	 * with no stock tracking, no weight, no dimensions, no attributes —
	 * each test layers on what it needs via `shouldReceive()`.
	 */
	private function make_product( array $overrides = [] ): Mockery\MockInterface {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $overrides['id'] ?? 42 );
		$product->shouldReceive( 'get_name' )
			->andReturn( $overrides['name'] ?? 'Test Product' );
		$product->shouldReceive( 'get_permalink' )
			->andReturn( $overrides['permalink'] ?? 'https://example.com/product/test/' );
		$product->shouldReceive( 'managing_stock' )
			->andReturn( $overrides['managing_stock'] ?? false );
		$product->shouldReceive( 'get_stock_quantity' )
			->andReturn( $overrides['stock_quantity'] ?? null );
		$product->shouldReceive( 'has_weight' )
			->andReturn( $overrides['has_weight'] ?? false );
		$product->shouldReceive( 'get_weight' )
			->andReturn( $overrides['weight'] ?? '' );
		$product->shouldReceive( 'has_dimensions' )
			->andReturn( $overrides['has_dimensions'] ?? false );
		$product->shouldReceive( 'get_dimensions' )
			->andReturn( $overrides['dimensions'] ?? [] );
		$product->shouldReceive( 'get_attributes' )
			->andReturn( $overrides['attributes'] ?? [] );
		$product->shouldReceive( 'get_variation_attributes' )
			->andReturn( $overrides['variation_attributes'] ?? [] );
		$product->shouldReceive( 'get_image_id' )
			->andReturn( $overrides['image_id'] ?? 0 );
		$product->shouldReceive( 'get_children' )
			->andReturn( $overrides['children'] ?? [] );
		$product->shouldReceive( 'get_sku' )
			->andReturn( $overrides['sku'] ?? '' );
		$product->shouldReceive( 'get_cross_sell_ids' )
			->andReturn( $overrides['cross_sell_ids'] ?? [] );
		$product->shouldReceive( 'get_upsell_ids' )
			->andReturn( $overrides['upsell_ids'] ?? [] );
		// Default to purchasable so existing tests don't need to set
		// it; the unpurchasable-guard tests (#373) override this to
		// `false` to exercise the gate.
		$product->shouldReceive( 'is_purchasable' )
			->andReturn( $overrides['is_purchasable'] ?? true );

		// Sale window (#582). `add_sale_window()` gates on `is_on_sale()`
		// and reads both boundary dates; the per-variant path reads the same
		// three methods off the variation mock. Default to not-on-sale with
		// null dates so existing tests exercise the "no window" branch;
		// sale-window tests pass `is_on_sale => true` plus WC_DateTime-shaped
		// `date_on_sale_from` / `date_on_sale_to` mocks.
		$product->shouldReceive( 'is_on_sale' )
			->andReturn( $overrides['is_on_sale'] ?? false );
		$product->shouldReceive( 'get_date_on_sale_from' )
			->andReturn( $overrides['date_on_sale_from'] ?? null );
		$product->shouldReceive( 'get_date_on_sale_to' )
			->andReturn( $overrides['date_on_sale_to'] ?? null );

		// Default to needing shipping (physical product) so existing
		// shipping tests are unaffected; the shipping-gate tests (#504)
		// override to `false` to exercise the virtual-product path.
		$product->shouldReceive( 'needs_shipping' )
			->andReturn( $overrides['needs_shipping'] ?? true );

		// `is_type()` accepts a string or array per WC core. Default the
		// product's type to 'simple' so existing tests keep working
		// without an explicit override; bundle/grouped tests pass
		// `'type' => 'bundle' | 'grouped'` to flip the BuyAction URL
		// branch in `build_checkout_url_template()`.
		$type = $overrides['type'] ?? 'simple';
		$product->shouldReceive( 'is_type' )->andReturnUsing(
			static function ( $check ) use ( $type ) {
				return is_array( $check ) ? in_array( $type, $check, true ) : $type === $check;
			}
		);
		return $product;
	}

	// ------------------------------------------------------------------
	// Gating
	// ------------------------------------------------------------------

	public function test_enhancement_is_bypassed_when_syndication_disabled(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];

		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data( [ '@type' => 'Product' ], $product );

		// The input markup should pass through untouched — no BuyAction
		// injected, no new keys added.
		$this->assertEquals( [ '@type' => 'Product' ], $result );
	}

	public function test_enhancement_is_bypassed_when_product_not_syndicated(): void {
		// Force the static stub's `is_product_syndicated` to return false
		// by setting a restrictive selection mode.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 999 ], // not our product id 42
		];

		$product = $this->make_product( [ 'id' => 42 ] );
		$result  = $this->jsonld->enhance_product_data( [ '@type' => 'Product' ], $product );

		// Product id 42 isn't in the allow-list -> no enhancement.
		$this->assertArrayNotHasKey( 'potentialAction', $result );
	}

	// ------------------------------------------------------------------
	// BuyAction — the core enhancement
	// ------------------------------------------------------------------

	public function test_adds_buyaction_with_attribution_placeholders(): void {
		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayHasKey( 'potentialAction', $result );
		$this->assertEquals( 'BuyAction', $result['potentialAction']['@type'] );

		$url = $result['potentialAction']['target']['urlTemplate'];
		// Shareable Checkout URL format (0.11.0+): the URL goes through
		// WC's `/checkout-link/` rewrite handler with `?products=ID:1`.
		// Values are NOT URL-encoded — WP's `add_query_arg()` uses
		// `_http_build_query( ..., $urlencode = false )`, which lets
		// the `:` separator and the RFC 6570 `{...}` placeholders
		// survive verbatim so consumers can substitute variables
		// without first decoding the URL.
		$this->assertStringContainsString( '/checkout-link/', $url );
		$this->assertStringContainsString( 'products=42:1', $url );
		// Bare URL (#574): no UTM params — a human clicking a search-surfaced
		// checkout link must get native WC attribution, not a literal {agent_id}.
		$this->assertStringNotContainsString( 'utm_source', $url );
		$this->assertStringNotContainsString( 'utm_medium', $url );
		$this->assertStringNotContainsString( 'utm_id', $url );
		$this->assertStringNotContainsString( '{agent_id}', $url );
		$this->assertStringNotContainsString( '{', $url ); // no template braces at all
		// Regression guard: `ai_session_id` placeholder was removed in
		// the channel-split work — JSON-LD-routed traffic is by
		// definition stateless (no UCP session), so asking a crawler /
		// AI surface to substitute `{session_id}` was semantically
		// incoherent. Sessions belong on the /checkout-sessions
		// continue_url path, where agents actually have one to stamp.
		$this->assertStringNotContainsString( 'ai_session_id', $url );
	}

	public function test_buyaction_url_uses_home_checkout_link_not_product_permalink(): void {
		// Regression guard: the URL must NOT be derived from the
		// product permalink (the pre-0.11.0 shape used `add-to-cart=ID`
		// on the product page). A custom permalink shouldn't appear in
		// the output.
		$product = $this->make_product(
			[ 'permalink' => 'https://example.com/product/widget/#tab-description' ]
		);
		$result  = $this->jsonld->enhance_product_data( [], $product );

		$url = $result['potentialAction']['target']['urlTemplate'];

		$this->assertStringStartsWith( 'https://example.com/checkout-link/', $url );
		$this->assertStringNotContainsString( '/product/widget/', $url );
		$this->assertStringNotContainsString( '#tab-description', $url );
		$this->assertStringNotContainsString( 'add-to-cart=', $url );
	}

	public function test_buyaction_declares_web_platforms(): void {
		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data( [], $product );

		$platforms = $result['potentialAction']['target']['actionPlatform'];
		$this->assertContains( 'https://schema.org/DesktopWebPlatform', $platforms );
		$this->assertContains( 'https://schema.org/MobileWebPlatform', $platforms );
	}

	// ------------------------------------------------------------------
	// Offer.checkoutPageURLTemplate (#328) — coexists with BuyAction
	// ------------------------------------------------------------------

	public function test_offer_emits_checkout_page_url_template_with_same_url_as_buyaction(): void {
		// Both signals MUST carry the same URL value — different consumers
		// key on different Schema.org paths but should resolve to the same
		// destination.
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '20.00' ) ),
		);
		$product = $this->make_product();

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame(
			$result['potentialAction']['target']['urlTemplate'],
			$result['offers'][0]['checkoutPageURLTemplate'],
			'BuyAction.urlTemplate and Offer.checkoutPageURLTemplate must carry the same URL value'
		);
	}

	public function test_offer_checkout_page_url_template_uses_shareable_checkout_format(): void {
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '20.00' ) ),
		);
		$product = $this->make_product();

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$url = $result['offers'][0]['checkoutPageURLTemplate'];
		$this->assertStringStartsWith( 'https://example.com/checkout-link/', $url );
		$this->assertStringContainsString( 'products=42:1', $url );
		$this->assertStringNotContainsString( 'utm_', $url );
		$this->assertStringNotContainsString( '{agent_id}', $url );
	}

	public function test_checkout_urls_are_bare_on_both_properties(): void {
		// Both BuyAction.target.urlTemplate and Offer.checkoutPageURLTemplate
		// must be UTM-free and brace-free so a human clicking a
		// search-surfaced checkout link attributes natively via WC core's
		// own Order Attribution instead of a literal, unsubstituted
		// `{agent_id}` placeholder (#574). Consolidated regression lock —
		// the per-type bare-URL assertions above already cover simple,
		// bundle, and grouped products individually.
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '20.00' ) ),
		);
		$product = $this->make_product();

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$buy   = $result['potentialAction']['target']['urlTemplate'] ?? '';
		$offer = $result['offers'][0]['checkoutPageURLTemplate'] ?? '';

		foreach ( array( $buy, $offer ) as $url ) {
			$this->assertStringContainsString( '/checkout-link/', $url );
			$this->assertStringNotContainsString( 'utm_', $url );
			$this->assertStringNotContainsString( '{', $url );
		}
		$this->assertSame( $buy, $offer, 'Both properties still emit the same (bare) URL' );
	}

	// ------------------------------------------------------------------
	// BuyAction / checkoutPageURLTemplate — no HTML entities in JSON strings
	// ------------------------------------------------------------------
	//
	// A product permalink may arrive HTML-escaped if a filter passed it
	// through esc_url() or similar before our code runs. When
	// add_query_arg() receives a URL with '&amp;' already in it, those
	// entities propagate into the query string. Any non-browser consumer
	// (curl, Python requests, LLM tool calls) reading the raw JSON then
	// gets literal '&amp;' and produces a broken checkout URL. The
	// production code calls html_entity_decode() on add_query_arg()'s
	// output before storing it, so the JSON string always carries
	// plain '&'.

	public function test_buyaction_url_contains_no_html_entities(): void {
		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data( [], $product );

		$url = $result['potentialAction']['target']['urlTemplate'];
		$this->assertStringNotContainsString( '&amp;', $url );
	}

	public function test_offer_checkout_page_url_template_contains_no_html_entities(): void {
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '20.00' ) ),
		);
		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data( $markup, $product );

		$url = $result['offers'][0]['checkoutPageURLTemplate'];
		$this->assertStringNotContainsString( '&amp;', $url );
	}

	public function test_buyaction_url_contains_no_html_entities_for_bundle(): void {
		$product = $this->make_product( [
			'type'      => 'bundle',
			'permalink' => 'https://example.com/product/starter-kit/',
		] );
		$result  = $this->jsonld->enhance_product_data( [], $product );

		$url = $result['potentialAction']['target']['urlTemplate'];
		$this->assertStringNotContainsString( '&amp;', $url );
		$this->assertStringNotContainsString( 'utm_', $url );
		$this->assertStringNotContainsString( '{agent_id}', $url );
	}

	public function test_buyaction_url_contains_no_html_entities_for_grouped(): void {
		$product = $this->make_product( [
			'type'      => 'grouped',
			'permalink' => 'https://example.com/product/dinner-set/',
		] );
		$result  = $this->jsonld->enhance_product_data( [], $product );

		$url = $result['potentialAction']['target']['urlTemplate'];
		$this->assertStringNotContainsString( '&amp;', $url );
		$this->assertStringNotContainsString( 'utm_', $url );
		$this->assertStringNotContainsString( '{agent_id}', $url );
	}

	public function test_offer_checkout_page_url_template_contains_no_html_entities_for_bundle(): void {
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '20.00' ) ),
		);
		$product = $this->make_product( [
			'type'      => 'bundle',
			'permalink' => 'https://example.com/product/starter-kit/',
		] );
		$result  = $this->jsonld->enhance_product_data( $markup, $product );

		$url = $result['offers'][0]['checkoutPageURLTemplate'];
		$this->assertStringNotContainsString( '&amp;', $url );
		$this->assertStringNotContainsString( 'utm_', $url );
		$this->assertStringNotContainsString( '{agent_id}', $url );
	}

	public function test_offer_checkout_page_url_template_contains_no_html_entities_for_grouped(): void {
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '20.00' ) ),
		);
		$product = $this->make_product( [
			'type'      => 'grouped',
			'permalink' => 'https://example.com/product/dinner-set/',
		] );
		$result  = $this->jsonld->enhance_product_data( $markup, $product );

		$url = $result['offers'][0]['checkoutPageURLTemplate'];
		$this->assertStringNotContainsString( '&amp;', $url );
		$this->assertStringNotContainsString( 'utm_', $url );
		$this->assertStringNotContainsString( '{agent_id}', $url );
	}

	// ------------------------------------------------------------------
	// BuyAction — bundle / grouped permalink emission (0.13.2)
	// ------------------------------------------------------------------
	//
	// `?products=ID:1` can't represent a bundle (the bundled-item axes
	// aren't expressible in the shorthand) or a grouped product (the
	// grouped parent has no SKU of its own — only its children do).
	// `build_checkout_url_template()` falls back to the product permalink
	// for these types so the buyer lands on the PDP where WC's existing
	// bundle/grouped UI handles configuration.
	//
	// The deterministic `/checkout/?add-to-cart=BUNDLE&bundle_quantity_…=…`
	// shape used by the UCP REST `continue_url` is unsuitable here — it
	// requires per-render runtime resolution of every bundled item via
	// the Store API, which is the wrong cost profile for static JSON-LD.

	public function test_buyaction_url_uses_permalink_for_bundle_product(): void {
		$product = $this->make_product( [
			'id'        => 99,
			'type'      => 'bundle',
			'permalink' => 'https://example.com/product/starter-kit/',
		] );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$url = $result['potentialAction']['target']['urlTemplate'];
		// Permalink, not Shareable Checkout — the legacy `/checkout-link/`
		// rewrite would silently strip the bundled-item config and
		// either fail on add-to-cart or short-circuit to the PDP.
		$this->assertStringStartsWith( 'https://example.com/product/starter-kit/', $url );
		$this->assertStringNotContainsString( '/checkout-link/', $url );
		$this->assertStringNotContainsString( 'products=99:1', $url );
		// Bare URL (#574): the permalink carries no UTM params either.
		$this->assertStringNotContainsString( 'utm_', $url );
		$this->assertStringNotContainsString( '{agent_id}', $url );
		// Companion regression guard for the variation branch — see
		// the BuyAction test above for why `ai_session_id` was dropped.
		$this->assertStringNotContainsString( 'ai_session_id', $url );
	}

	public function test_buyaction_url_uses_permalink_for_grouped_product(): void {
		$product = $this->make_product( [
			'id'        => 88,
			'type'      => 'grouped',
			'permalink' => 'https://example.com/product/dinner-set/',
		] );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$url = $result['potentialAction']['target']['urlTemplate'];
		// Grouped parent has no SKU — `?products=GROUPED_ID:1` would try
		// to add a non-purchasable wrapper. Permalink lands on the
		// PDP-driven child-quantity selector instead.
		$this->assertStringStartsWith( 'https://example.com/product/dinner-set/', $url );
		$this->assertStringNotContainsString( '/checkout-link/', $url );
		$this->assertStringNotContainsString( 'products=88:1', $url );
		$this->assertStringNotContainsString( 'utm_', $url );
		$this->assertStringNotContainsString( '{agent_id}', $url );
	}

	public function test_offer_checkout_page_url_template_uses_permalink_for_bundle(): void {
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '20.00' ) ),
		);
		$product = $this->make_product( [
			'id'        => 99,
			'type'      => 'bundle',
			'permalink' => 'https://example.com/product/starter-kit/',
		] );

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		// Both signals must agree — different consumers key on different
		// Schema.org paths, but they should resolve to the same URL.
		$this->assertSame(
			$result['potentialAction']['target']['urlTemplate'],
			$result['offers'][0]['checkoutPageURLTemplate']
		);
		$this->assertStringStartsWith(
			'https://example.com/product/starter-kit/',
			$result['offers'][0]['checkoutPageURLTemplate']
		);
	}

	public function test_offer_checkout_page_url_template_uses_permalink_for_grouped(): void {
		// Symmetric coverage with the bundle Offer test: grouped travels
		// the same `build_checkout_url_template()` branch but reaches
		// `add_checkout_page_url_template()` via a separate code path
		// from `add_buy_action()`. A future change that early-returns
		// for grouped on the Offer path would otherwise regress only
		// this signal silently.
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '20.00' ) ),
		);
		$product = $this->make_product( [
			'id'        => 88,
			'type'      => 'grouped',
			'permalink' => 'https://example.com/product/dinner-set/',
		] );

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame(
			$result['potentialAction']['target']['urlTemplate'],
			$result['offers'][0]['checkoutPageURLTemplate']
		);
		$this->assertStringStartsWith(
			'https://example.com/product/dinner-set/',
			$result['offers'][0]['checkoutPageURLTemplate']
		);
	}

	public function test_buyaction_url_appends_utm_when_permalink_already_has_query_string(): void {
		// Edge case: a custom permalink with an existing query string
		// (uncommon for products but possible with custom rewrites or
		// language plugins). `add_query_arg()` should append with `&`
		// and preserve the existing parameter, not overwrite it.
		$product = $this->make_product( [
			'id'        => 99,
			'type'      => 'bundle',
			'permalink' => 'https://example.com/product/starter-kit/?lang=en',
		] );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$url = $result['potentialAction']['target']['urlTemplate'];
		$this->assertStringContainsString( 'lang=en', $url );
		$this->assertStringNotContainsString( 'utm_', $url );
		$this->assertStringNotContainsString( '{agent_id}', $url );
		// One `?` only — the rest must be `&` separators.
		$this->assertSame( 1, substr_count( $url, '?' ) );
	}

	public function test_buyaction_url_keeps_shareable_checkout_for_simple_product(): void {
		// Regression guard: the bundle/grouped branch must NOT swallow
		// the simple-product path that ships the deterministic
		// `?products=ID:1` form.
		$product = $this->make_product( [ 'id' => 42, 'type' => 'simple' ] );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$url = $result['potentialAction']['target']['urlTemplate'];
		$this->assertStringStartsWith( 'https://example.com/checkout-link/', $url );
		$this->assertStringContainsString( 'products=42:1', $url );
	}

	public function test_buyaction_url_keeps_shareable_checkout_for_variable_parent(): void {
		// Regression guard for the variable-parent (PDP-level) Product
		// entry — variations themselves are covered by the per-variant
		// tests further down. Variable parents land on the same
		// Shareable Checkout shape with the parent ID.
		$product = $this->make_product( [ 'id' => 100, 'type' => 'variable' ] );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$url = $result['potentialAction']['target']['urlTemplate'];
		$this->assertStringStartsWith( 'https://example.com/checkout-link/', $url );
		$this->assertStringContainsString( 'products=100:1', $url );
	}

	// ------------------------------------------------------------------
	// detect_varies_by() — Schema.org URLs for varying variation axes (#328)
	// ------------------------------------------------------------------

	/**
	 * Direct test of the static helper via reflection. Covers slug→URL
	 * mapping, "actually varies" filtering (>1 distinct value), and
	 * unmapped-axis text-label fallback.
	 */
	private function invoke_detect_varies_by( Mockery\MockInterface $product ): array {
		$method = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'detect_varies_by' );
		return $method->invoke( null, $product );
	}

	public function test_detect_varies_by_returns_schema_urls_for_mapped_attributes(): void {
		$product = $this->make_product( [
			'variation_attributes' => array(
				'pa_color' => array( 'navy', 'white', 'gray' ),
				'pa_size'  => array( 'l', 'm', 's', 'xl' ),
			),
		] );

		$result = $this->invoke_detect_varies_by( $product );

		$this->assertContains( 'https://schema.org/color', $result );
		$this->assertContains( 'https://schema.org/size', $result );
		$this->assertCount( 2, $result );
	}

	public function test_detect_varies_by_excludes_uniform_axes(): void {
		// pa_color has only one distinct value ("navy") — all variations
		// are navy, only sizes differ. Color shouldn't appear in variesBy.
		$product = $this->make_product( [
			'variation_attributes' => array(
				'pa_color' => array( 'navy' ),
				'pa_size'  => array( 'l', 'm', 's' ),
			),
		] );

		$result = $this->invoke_detect_varies_by( $product );

		$this->assertSame( array( 'https://schema.org/size' ), $result );
	}

	public function test_detect_varies_by_returns_empty_for_non_variable_product(): void {
		// Simple product (no `variation_attributes` override → returns []).
		$product = $this->make_product();

		$result = $this->invoke_detect_varies_by( $product );

		$this->assertSame( array(), $result );
	}

	public function test_detect_varies_by_returns_empty_for_variable_with_no_varying_axes(): void {
		// Edge case: variable product where every axis has at most one
		// value (Product 16 misconfigured-variable territory).
		$product = $this->make_product( [
			'variation_attributes' => array(
				'pa_color' => array( '' ),
				'pa_size'  => array( '' ),
			),
		] );

		$result = $this->invoke_detect_varies_by( $product );

		$this->assertSame( array(), $result );
	}

	public function test_detect_varies_by_emits_text_label_for_unmapped_axis(): void {
		// Custom merchant axes (not in CORE_ATTRIBUTE_MAP) emit as plain
		// Text labels so agents see SOME signal. Schema.org `variesBy`
		// accepts both URL-shaped Text and plain Text per spec.
		Functions\when( 'wc_attribute_label' )->alias(
			static fn( $slug ) => ucfirst( str_replace( 'pa_', '', $slug ) )
		);
		$product = $this->make_product( [
			'variation_attributes' => array(
				'pa_style' => array( 'casual', 'formal', 'sport' ),
			),
		] );

		$result = $this->invoke_detect_varies_by( $product );

		$this->assertSame( array( 'Style' ), $result );
	}

	// ------------------------------------------------------------------
	// build_variant_entry() — per-variation Product blocks (#328)
	// ------------------------------------------------------------------

	/**
	 * Build a `WC_Product_Variation`-shaped mock for the per-variant tests.
	 *
	 * Variations expose the same WC_Product API plus `get_variation_attributes()`
	 * which on a variation returns its specific picks like
	 * `['attribute_pa_color' => 'white']` (different from the parent's
	 * version which returns the SET of values across all variations).
	 */
	private function make_variation( array $overrides = [] ): Mockery\MockInterface {
		// Default to `type === 'variation'` for fidelity with WC core.
		// Without this, `is_type('variation')` on the mock returns false
		// (it falls through to `make_product`'s default of `'simple'`),
		// so any production code path that checks for variation status
		// via `is_type` would silently take the wrong branch in tests.
		$overrides['type'] = $overrides['type'] ?? 'variation';
		$variation         = $this->make_product( $overrides );
		$variation->shouldReceive( 'get_price' )->andReturn( $overrides['price'] ?? '20.00' );
		$variation->shouldReceive( 'is_in_stock' )->andReturn( $overrides['in_stock'] ?? true );
		// Variations carry their own (usually empty) description. The
		// inheritance step in `maybe_convert_to_product_group()` reads
		// `get_description()` to decide whether to keep the variation's
		// own copy or fall back to the parent's. Default empty so the
		// common case (no per-variation description) exercises the
		// parent-inheritance branch; tests that want the override branch
		// pass a non-empty `description` override.
		$variation->shouldReceive( 'get_description' )->andReturn( $overrides['description'] ?? '' );
		// Sale-window methods (`is_on_sale`, `get_date_on_sale_from`,
		// `get_date_on_sale_to`) are registered on the shared mock in
		// `make_product()` and honor the same overrides here, since
		// `make_variation()` funnels its `$overrides` through it. The
		// `priceValidUntil` derivation prefers the variation's OWN sale-end
		// date over the inherited parent value; tests pass a `date_on_sale_to`
		// WC_DateTime-shaped mock (with a `getTimestamp()`) to exercise the
		// own-sale-end branch, and `date_on_sale_from` + `is_on_sale` to
		// exercise the per-variant `validFrom`/`validThrough` emission (#582).

		// `add_variant_basics()` reads typed-property values directly
		// from variation postmeta (`attribute_<slug>`) — see the doc in
		// `read_variation_core_attributes()` for why. Translate the
		// `variation_attributes` test override into the postmeta lookup
		// table so existing test fixtures keep working without each test
		// having to know about the indirection.
		$variation_id = (int) ( $overrides['id'] ?? 42 );
		foreach ( ( $overrides['variation_attributes'] ?? [] ) as $key => $value ) {
			$meta_key = str_starts_with( (string) $key, 'attribute_' )
				? (string) $key
				: 'attribute_' . (string) $key;
			$this->post_meta_by_id[ $variation_id ][ $meta_key ] = (string) $value;
		}
		return $variation;
	}

	private function invoke_build_variant_entry(
		Mockery\MockInterface $variation,
		Mockery\MockInterface $parent
	): array {
		$method = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'build_variant_entry' );
		return $method->invoke(
			$this->jsonld,
			$variation,
			$parent,
			[ 'enabled' => 'yes', 'return_policy' => [ 'mode' => 'unconfigured' ] ],
			'US'
		);
	}

	/**
	 * Directly invoke the private `add_inherited_variant_fields()` so the
	 * no-clobber guards (unreachable via the public path, since
	 * `build_variant_entry()` never sets description/brand/category or
	 * offer seller/priceValidUntil/url) can be exercised. Mirrors the
	 * `invoke_build_variant_entry()` ReflectionMethod pattern above. The
	 * `$entry` is passed by reference and mutated in place.
	 *
	 * @param array $entry         Variant entry, mutated in place.
	 * @param array $parent_markup Parent ProductGroup markup.
	 */
	private function invoke_add_inherited_variant_fields(
		array &$entry,
		Mockery\MockInterface $variation,
		array $parent_markup
	): void {
		$method = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'add_inherited_variant_fields' );
		$args   = array( &$entry, $variation, $parent_markup );
		$method->invokeArgs( $this->jsonld, $args );
	}

	public function test_variant_entry_has_product_type_and_sku(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent    = $this->make_product( [ 'id' => 100 ] );
		$variation = $this->make_variation( [ 'id' => 101, 'sku' => 'tee-white-l' ] );

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		$this->assertSame( 'Product', $entry['@type'] );
		$this->assertSame( 'tee-white-l', $entry['sku'] );
	}

	public function test_variant_entry_emits_id_url_and_name(): void {
		// `@id` and `name` are Schema.org Product fundamentals — agents
		// dereference `@id` to fetch the variant's own page and
		// `name` is what surfaces in rich-result snippets. Regression
		// guard for PR #338 review feedback (these went missing in the
		// initial implementation).
		//
		// This fixture uses a DISTINCT variation permalink (parent +
		// query args), exercising the common path where WC's
		// `get_permalink()` returned the right URL on its own — the
		// override at `add_variant_basics()` does NOT fire because
		// `$permalink !== $parent_permalink`. See the override-path
		// tests below for the misconfigured-variation case (#341).
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent    = $this->make_product( [ 'id' => 100 ] );
		$variation = $this->make_variation( [
			'id'        => 101,
			'name'      => 'Hoodie - Blue, Logo: Yes',
			'permalink' => 'https://example.com/product/hoodie/?attribute_pa_color=blue&attribute_logo=Yes',
		] );

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		$this->assertSame(
			'https://example.com/product/hoodie/?attribute_pa_color=blue&attribute_logo=Yes',
			$entry['@id']
		);
		$this->assertSame( $entry['@id'], $entry['url'] );
		$this->assertSame( 'Hoodie - Blue, Logo: Yes', $entry['name'] );
	}

	public function test_variant_id_synthesizes_query_args_when_permalink_falls_through(): void {
		// The override path: WC's `WC_Product_Variation::get_permalink()`
		// is gated by the parent's "Used for variations" flag. When
		// that flag is unset on every variation attribute, the method
		// returns the bare parent URL instead of the parent +
		// `?attribute_<slug>=value` query args. Pre-#341 every variant
		// shared the same `@id` (the parent URL), which broke
		// variant-graph traversal for AI agents — they couldn't
		// dereference one variant's `@id` and tell it apart from its
		// siblings.
		//
		// Fix: detect the fall-through (variation permalink ===
		// parent permalink) and synthesize the URL from the same
		// `read_variation_core_attributes()` postmeta source the
		// typed-property override consumes. Result: `@id` carries
		// the variant's specific color in `?attribute_pa_color=red`
		// form, distinct per-variant.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent_url = 'https://example.com/product/hoodie/';
		$parent     = $this->make_product( [
			'id'        => 100,
			'permalink' => $parent_url,
		] );
		// Variation's permalink matches the parent's — simulating
		// WC's fall-through behavior on a misconfigured variable
		// product. Postmeta carries the variation's color value via
		// the `variation_attributes` test override (which routes
		// through `make_variation()` to seed the same postmeta
		// `read_variation_core_attributes()` reads).
		$variation = $this->make_variation( [
			'id'                   => 101,
			'permalink'            => $parent_url,
			'variation_attributes' => array( 'pa_color' => 'red' ),
		] );

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		$this->assertSame(
			'https://example.com/product/hoodie/?attribute_pa_color=red',
			$entry['@id']
		);
		$this->assertSame( $entry['@id'], $entry['url'] );
	}

	public function test_variant_id_synthesized_fallthrough_contains_no_html_entities(): void {
		// Multi-attribute fall-through: when a variation has two or more
		// core-typed attributes and the parent's permalink === variation's
		// permalink, the synthesized @id contains at least one '&'
		// separator. The '&amp;' in the stub's implode() output exercises
		// the html_entity_decode() call at line 1274. A single-attribute
		// case never produces a separator and therefore never fails without
		// the fix.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent_url = 'https://example.com/product/hoodie/';
		$parent     = $this->make_product( [
			'id'        => 100,
			'permalink' => $parent_url,
		] );
		$variation  = $this->make_variation( [
			'id'                   => 101,
			'permalink'            => $parent_url,
			'variation_attributes' => array( 'pa_color' => 'blue', 'pa_size' => 'L' ),
		] );

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		$this->assertStringNotContainsString( '&amp;', $entry['@id'] );
		$this->assertStringContainsString( 'attribute_pa_color=blue', $entry['@id'] );
		$this->assertStringContainsString( 'attribute_pa_size=L', $entry['@id'] );
		$this->assertSame( $entry['@id'], $entry['url'] );
	}

	public function test_variant_id_stays_at_parent_when_only_unmapped_attributes_differ(): void {
		// Override scope-cap: when the variation's only differentiating
		// attribute is unmapped (Logo, Style, Heel Height, etc. — not
		// in CORE_ATTRIBUTE_MAP), `read_variation_core_attributes()`
		// returns empty and the override doesn't fire. The variant
		// keeps the bare parent URL. Rationale: surfacing variation
		// noise the merchant intentionally hid (by leaving the
		// "Used for variations" flag unset) would over-step the
		// override's narrow scope. The override is opinionated about
		// the four core typed slugs — those have canonical
		// Schema.org property mappings and AI agents look for them
		// by name. Unmapped slugs honor the merchant flag.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent_url = 'https://example.com/product/hoodie/';
		$parent     = $this->make_product( [
			'id'        => 100,
			'permalink' => $parent_url,
		] );
		$variation = $this->make_variation( [
			'id'                   => 101,
			'permalink'            => $parent_url,
			// Only unmapped attribute — `logo` is not in CORE_ATTRIBUTE_MAP.
			'variation_attributes' => array( 'logo' => 'Yes' ),
		] );

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		$this->assertSame( $parent_url, $entry['@id'] );
		// `url` must mirror `@id` on every code path, including the
		// scope-cap fall-through. Pins the contract that they don't
		// diverge — a refactor that wired the override into one but
		// not the other would fail here.
		$this->assertSame( $entry['@id'], $entry['url'] );
	}

	public function test_variant_entry_falls_back_to_id_when_no_sku(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent    = $this->make_product( [ 'id' => 100 ] );
		$variation = $this->make_variation( [ 'id' => 101, 'sku' => '' ] );

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		$this->assertSame( '101', $entry['sku'] );
	}

	public function test_variant_entry_emits_typed_color_from_variation_attribute(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		// Free-text attribute (no `pa_` prefix): value used directly.
		$parent    = $this->make_product();
		$variation = $this->make_variation( [
			'variation_attributes' => array( 'attribute_color' => 'White' ),
		] );

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		$this->assertSame( 'White', $entry['color'] );
	}

	public function test_variant_entry_offer_carries_price_currency_availability(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent    = $this->make_product();
		$variation = $this->make_variation( [
			'price'    => '20.00',
			'in_stock' => true,
		] );

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		$this->assertCount( 1, $entry['offers'] );
		$this->assertSame( '20.00', $entry['offers'][0]['price'] );
		$this->assertSame( 'USD', $entry['offers'][0]['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $entry['offers'][0]['availability'] );
	}

	public function test_variant_entry_offer_marks_out_of_stock(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent    = $this->make_product();
		$variation = $this->make_variation( [ 'in_stock' => false ] );

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		$this->assertSame(
			'https://schema.org/OutOfStock',
			$entry['offers'][0]['availability']
		);
	}

	public function test_variant_entry_buy_action_uses_variation_id_not_parent(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent    = $this->make_product( [ 'id' => 100 ] );
		$variation = $this->make_variation( [ 'id' => 999 ] );

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		$url = $entry['potentialAction']['target']['urlTemplate'];
		$this->assertStringContainsString( 'products=999:1', $url );
		$this->assertStringNotContainsString( 'products=100', $url );
		$this->assertStringNotContainsString( 'utm_', $url );
		$this->assertStringNotContainsString( '{', $url );
	}

	public function test_variant_entry_offer_carries_checkout_page_url_template_with_variation_id(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent    = $this->make_product( [ 'id' => 100 ] );
		$variation = $this->make_variation( [ 'id' => 999 ] );

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		$url = $entry['potentialAction']['target']['urlTemplate'];
		$this->assertSame(
			$url,
			$entry['offers'][0]['checkoutPageURLTemplate'],
			'Per-variant BuyAction URL and checkoutPageURLTemplate must match'
		);
		$this->assertStringNotContainsString( 'utm_', $url );
		$this->assertStringNotContainsString( '{', $url );
	}

	public function test_variant_entry_omits_buy_action_and_url_template_when_unpurchasable(): void {
		// #373: an unpurchasable variation (e.g. no price set) emits its
		// descriptive fields (@id, name, sku, image, offers[].price) but
		// MUST NOT emit `potentialAction` (BuyAction) nor
		// `offers[0].checkoutPageURLTemplate`. SEO crawlers and non-UCP
		// agents that only read JSON-LD must not be handed a URL that
		// WC would refuse at checkout. The price/availability remain so
		// the variant entry isn't a black hole — just no monetary action
		// is attached.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent    = $this->make_product( [ 'id' => 100 ] );
		$variation = $this->make_variation( [ 'id' => 999, 'is_purchasable' => false ] );

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		// Descriptive fields still emit.
		$this->assertArrayHasKey( 'sku', $entry );
		$this->assertArrayHasKey( 'name', $entry );
		$this->assertArrayHasKey( 'offers', $entry );
		$this->assertArrayHasKey( 'price', $entry['offers'][0] );

		// Action URLs are suppressed.
		$this->assertArrayNotHasKey( 'potentialAction', $entry );
		$this->assertArrayNotHasKey( 'checkoutPageURLTemplate', $entry['offers'][0] );
	}

	public function test_enhance_product_omits_buy_action_when_parent_unpurchasable(): void {
		// #373: an unpurchasable simple/parent product emits its
		// descriptive markup but no BuyAction or checkoutPageURLTemplate.
		// Variable parents that convert to ProductGroup later have their
		// own per-variant gating; this covers the simple-product path
		// and the variable-parent-that-doesn't-convert edge case.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes' ];
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$product = $this->make_product( [ 'is_purchasable' => false ] );
		$markup  = [
			'@type'  => 'Product',
			'name'   => 'Broken Product',
			'offers' => [
				[
					'@type'         => 'Offer',
					'price'         => '0',
					'priceCurrency' => 'USD',
				],
			],
		];

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		// Descriptive shape preserved.
		$this->assertSame( 'Product', $result['@type'] );
		$this->assertArrayHasKey( 'offers', $result );

		// Action URLs suppressed.
		$this->assertArrayNotHasKey( 'potentialAction', $result );
		$this->assertArrayNotHasKey( 'checkoutPageURLTemplate', $result['offers'][0] );
	}

	// ------------------------------------------------------------------
	// ProductGroup conversion — variable-product end-to-end (#328)
	// ------------------------------------------------------------------
	//
	// `enhance_product_data()` runs all the simple-product enrichers
	// first (they describe shared characteristics that belong on the
	// ProductGroup parent), then `maybe_convert_to_product_group()`
	// reshapes the markup. These tests pin the converted shape:
	// @type flips, productGroupID/variesBy/hasVariant added, parent's
	// offers[] and potentialAction dropped (variants own them).

	private function setup_wc_get_product_for_variations( array $variations ): void {
		// Stub `wc_get_product()` to return one of `$variations` keyed by ID.
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => $variations[ (int) $id ] ?? false
		);
	}

	public function test_variable_product_emits_as_product_group(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$variation = $this->make_variation( [ 'id' => 101, 'sku' => 'tee-w' ] );
		$this->setup_wc_get_product_for_variations( [ 101 => $variation ] );

		$parent = $this->make_product( [
			'id'       => 100,
			'sku'      => 'tee-parent',
			'children' => [ 101 ],
			'variation_attributes' => array(
				'pa_color' => array( 'navy', 'white' ),
			),
		] );

		$markup = array(
			'@type'  => 'Product',
			'name'   => 'V-Neck T-Shirt',
			'offers' => array( array( '@type' => 'Offer', 'price' => '20.00' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $parent );

		$this->assertSame( 'ProductGroup', $result['@type'] );
		$this->assertSame( 'tee-parent', $result['productGroupID'] );
	}

	public function test_product_group_variesBy_lists_actually_varying_axes_only(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$variation = $this->make_variation( [ 'id' => 101 ] );
		$this->setup_wc_get_product_for_variations( [ 101 => $variation ] );

		$parent = $this->make_product( [
			'id'       => 100,
			'children' => [ 101 ],
			'variation_attributes' => array(
				'pa_color' => array( 'navy' ),  // uniform — should NOT appear
				'pa_size'  => array( 'l', 'm', 's' ),
			),
		] );

		$result = $this->jsonld->enhance_product_data( [], $parent );

		$this->assertSame(
			array( 'https://schema.org/size' ),
			$result['variesBy']
		);
	}

	public function test_product_group_drops_parent_offers_and_potential_action(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$variation = $this->make_variation( [ 'id' => 101 ] );
		$this->setup_wc_get_product_for_variations( [ 101 => $variation ] );

		$parent = $this->make_product( [
			'id'                   => 100,
			'children'             => [ 101 ],
			'variation_attributes' => array( 'pa_color' => array( 'navy', 'red' ) ),
		] );

		$markup = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '20.00' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $parent );

		$this->assertArrayNotHasKey( 'offers', $result );
		$this->assertArrayNotHasKey( 'potentialAction', $result );
	}

	public function test_product_group_emits_one_has_variant_entry_per_child(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$v1 = $this->make_variation( [ 'id' => 101, 'sku' => 'tee-w' ] );
		$v2 = $this->make_variation( [ 'id' => 102, 'sku' => 'tee-n' ] );
		$v3 = $this->make_variation( [ 'id' => 103, 'sku' => 'tee-g' ] );
		$this->setup_wc_get_product_for_variations( [ 101 => $v1, 102 => $v2, 103 => $v3 ] );

		$parent = $this->make_product( [
			'id'                   => 100,
			'children'             => [ 101, 102, 103 ],
			'variation_attributes' => array( 'pa_color' => array( 'white', 'navy', 'green' ) ),
		] );

		$result = $this->jsonld->enhance_product_data( [], $parent );

		$this->assertCount( 3, $result['hasVariant'] );
		$skus = array_column( $result['hasVariant'], 'sku' );
		$this->assertSame( array( 'tee-w', 'tee-n', 'tee-g' ), $skus );
	}

	public function test_product_group_falls_back_to_simple_product_when_no_variations(): void {
		// Locked decision: variable + 0 children → keep simple Product
		// shape (don't convert). Edge case for misconfigured stores
		// where variations were deleted.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent = $this->make_product( [
			'id'                   => 100,
			'children'             => [],
			'variation_attributes' => array( 'pa_color' => array( 'navy' ) ),
		] );

		$result = $this->jsonld->enhance_product_data( [ '@type' => 'Product' ], $parent );

		$this->assertSame( 'Product', $result['@type'] );
		$this->assertArrayNotHasKey( 'hasVariant', $result );
		$this->assertArrayNotHasKey( 'productGroupID', $result );
	}

	public function test_misconfigured_variable_with_core_typed_axis_still_emits_product_group(): void {
		// The "core typed override" path: parent attribute `pa_color`
		// is NOT flagged "Used for variations" (so
		// `get_variation_attributes()` returns []), but the variation
		// children themselves have `attribute_pa_color` postmeta with
		// distinct values. Schema.org has a canonical typed property
		// for color, AI agents care about it, and the data is right
		// there on each variation — we override the missing parent
		// flag and emit ProductGroup with `variesBy: [color]` and
		// per-variant typed `color` values.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$v1 = $this->make_variation( [ 'id' => 101, 'sku' => 'h-r', 'variation_attributes' => [ 'pa_color' => 'red' ] ] );
		$v2 = $this->make_variation( [ 'id' => 102, 'sku' => 'h-g', 'variation_attributes' => [ 'pa_color' => 'green' ] ] );
		$v3 = $this->make_variation( [ 'id' => 103, 'sku' => 'h-b', 'variation_attributes' => [ 'pa_color' => 'blue' ] ] );
		$this->setup_wc_get_product_for_variations( [ 101 => $v1, 102 => $v2, 103 => $v3 ] );

		// Parent: variable, has children, but `get_variation_attributes()`
		// returns empty (parent flag missing on `pa_color`).
		$parent = $this->make_product( [
			'id'                   => 100,
			'children'             => [ 101, 102, 103 ],
			'variation_attributes' => array(),  // parent flag unset
		] );

		Functions\when( 'get_term_by' )->justReturn( false );  // free-text fallback for term-name lookup

		$result = $this->jsonld->enhance_product_data( [], $parent );

		$this->assertSame( 'ProductGroup', $result['@type'] );
		$this->assertSame( array( 'https://schema.org/color' ), $result['variesBy'] );
		$this->assertCount( 3, $result['hasVariant'] );
		$colors = array_column( $result['hasVariant'], 'color' );
		$this->assertSame( array( 'red', 'green', 'blue' ), $colors );
	}

	public function test_misconfigured_variable_falls_back_to_simple_product(): void {
		// Product 16 / Hoodie territory in the dev fixtures: variable
		// type with variation children but **no** attribute is flagged
		// "Used for variations". `detect_varies_by()` returns an empty
		// array. Emitting `hasVariant` without `variesBy` would hand
		// agents N near-identical blocks they can't tell apart, so we
		// fall back to simple-Product shape — keep `offers` and
		// `potentialAction`, no `hasVariant`, no `productGroupID`.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$v1 = $this->make_variation( [ 'id' => 101, 'variation_attributes' => [] ] );
		$this->setup_wc_get_product_for_variations( [ 101 => $v1 ] );

		$parent = $this->make_product( [
			'id'                   => 100,
			'children'             => [ 101 ],
			'variation_attributes' => array(),  // no varying axes
		] );

		$markup = array( '@type' => 'Product', 'offers' => array( array( '@type' => 'Offer' ) ) );
		$result = $this->jsonld->enhance_product_data( $markup, $parent );

		$this->assertSame( 'Product', $result['@type'] );
		$this->assertArrayNotHasKey( 'hasVariant', $result );
		$this->assertArrayNotHasKey( 'productGroupID', $result );
		$this->assertArrayNotHasKey( 'variesBy', $result );
		// The simple-Product enrichers (BuyAction, currency, etc.) still ran.
		$this->assertArrayHasKey( 'potentialAction', $result );
		$this->assertArrayHasKey( 'offers', $result );
	}

	public function test_unbuyable_variations_fall_back_to_simple_product(): void {
		// Regression guard for PR #338 review feedback: when
		// `get_children()` returns IDs but `wc_get_product()` resolves
		// none of them (data corruption, soft-deleted variations, or a
		// stale WP cache), we MUST NOT emit a `ProductGroup` with empty
		// `hasVariant` and the parent's `offers` + `potentialAction`
		// already dropped. That would produce a strictly-worse shape
		// than the simple-Product fallback (no offers, no buy action,
		// no variants — completely unbuyable). Build the variants
		// FIRST and only commit the conversion when at least one
		// resolved.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		// Stub `wc_get_product()` to return false for every child ID
		// — simulating the corrupted-data case.
		Functions\when( 'wc_get_product' )->justReturn( false );

		$parent = $this->make_product( [
			'id'                   => 100,
			'children'             => [ 901, 902, 903 ],
			'variation_attributes' => array( 'pa_color' => array( 'red', 'blue' ) ),
		] );

		$markup = array(
			'@type'           => 'Product',
			'offers'          => array( array( '@type' => 'Offer', 'price' => '20' ) ),
			'potentialAction' => array( '@type' => 'BuyAction' ),
		);
		$result = $this->jsonld->enhance_product_data( $markup, $parent );

		$this->assertSame( 'Product', $result['@type'] );
		$this->assertArrayNotHasKey( 'hasVariant', $result );
		$this->assertArrayNotHasKey( 'productGroupID', $result );
		$this->assertArrayNotHasKey( 'variesBy', $result );
		// Critical: the parent-level offers + potentialAction must
		// survive intact. Pre-fix, both were unconditionally dropped.
		$this->assertArrayHasKey( 'offers', $result );
		$this->assertArrayHasKey( 'potentialAction', $result );
	}

	public function test_simple_product_does_not_convert_to_product_group(): void {
		// Regression guard: simple products (no `get_variation_attributes()`
		// stub on the WC_Product base) must not get the ProductGroup
		// conversion. The capability gate is method_exists.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$product = $this->make_product( [ 'id' => 50 ] );

		$markup = array( '@type' => 'Product', 'name' => 'Sunglasses' );
		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( 'Product', $result['@type'] );
		$this->assertArrayNotHasKey( 'hasVariant', $result );
	}

	// ------------------------------------------------------------------
	// add_inherited_variant_fields — variant nodes inherit the parent
	// ProductGroup's WC-core base markup (description, brand, category,
	// offer seller/priceValidUntil) that the from-scratch variant
	// builder would otherwise drop. Google flags variants with "no
	// description" / missing priceValidUntil otherwise. (#variant-completeness)
	// ------------------------------------------------------------------

	/**
	 * Build a variable parent + single-variation fixture whose parent
	 * markup already carries the WC-core base fields a simple product
	 * keeps. Returns the enhanced result so each inheritance test can
	 * assert on `hasVariant[0]`.
	 *
	 * @param array $parent_markup_overrides Extra/replacement parent markup keys.
	 * @param array $variation_overrides     Passed to make_variation().
	 * @return array Enhanced markup (a ProductGroup).
	 */
	private function enhance_variable_with_parent_markup(
		array $parent_markup_overrides = array(),
		array $variation_overrides = array()
	): array {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$variation = $this->make_variation(
			array_merge( [ 'id' => 101, 'sku' => 'tee-w' ], $variation_overrides )
		);
		$this->setup_wc_get_product_for_variations( [ 101 => $variation ] );

		$parent = $this->make_product( [
			'id'                   => 100,
			'sku'                  => 'tee-parent',
			'children'             => [ 101 ],
			'variation_attributes' => array( 'pa_color' => array( 'navy', 'white' ) ),
		] );

		// The parent markup mirrors WooCommerce core's full base shape:
		// description, brand, category, and an offer carrying
		// seller/priceValidUntil. These are exactly the fields the
		// from-scratch variant builder drops.
		$markup = array_merge(
			array(
				'@type'       => 'Product',
				'name'        => 'V-Neck T-Shirt',
				'description' => 'Parent description from WooCommerce core.',
				'brand'       => array( '@type' => 'Brand', 'name' => 'Acme' ),
				'category'    => 'Tops',
				'offers'      => array(
					array(
						'@type'           => 'Offer',
						'price'           => '20.00',
						'seller'          => array( '@type' => 'Organization', 'name' => 'Acme Store' ),
						'priceValidUntil' => '2027-01-01',
					),
				),
			),
			$parent_markup_overrides
		);

		$result = $this->jsonld->enhance_product_data( $markup, $parent );
		$this->assertSame( 'ProductGroup', $result['@type'], 'fixture should convert to ProductGroup' );
		$this->assertArrayHasKey( 'hasVariant', $result );
		return $result;
	}

	public function test_variant_inherits_description_from_parent_when_variation_has_none(): void {
		// The variation has no description of its own (make_variation
		// defaults `get_description()` to ''), so the variant node must
		// inherit the parent ProductGroup's WC-core-formatted
		// description. Without inheritance Google reports the variant as
		// having "no description".
		$result  = $this->enhance_variable_with_parent_markup();
		$variant = $result['hasVariant'][0];

		$this->assertSame(
			'Parent description from WooCommerce core.',
			$variant['description']
		);
	}

	public function test_variant_uses_own_description_overriding_parent(): void {
		// When the variation carries its own description, the variant
		// node keeps it (formatted exactly like WC core:
		// wp_strip_all_tags( do_shortcode( ... ) )) and does NOT fall
		// back to the parent's. The fixture's variation description has
		// surrounding whitespace + HTML to prove the formatting runs.
		$result  = $this->enhance_variable_with_parent_markup(
			array(),
			array( 'description' => "  <strong>White</strong> tee, slim fit.  " )
		);
		$variant = $result['hasVariant'][0];

		$this->assertSame( 'White tee, slim fit.', $variant['description'] );
	}

	public function test_variant_inherits_brand_and_category_from_parent(): void {
		$result  = $this->enhance_variable_with_parent_markup();
		$variant = $result['hasVariant'][0];

		$this->assertSame(
			array( '@type' => 'Brand', 'name' => 'Acme' ),
			$variant['brand']
		);
		$this->assertSame( 'Tops', $variant['category'] );
	}

	public function test_variant_offer_inherits_seller_and_price_valid_until(): void {
		$result = $this->enhance_variable_with_parent_markup();
		$offer  = $result['hasVariant'][0]['offers'][0];

		$this->assertSame(
			array( '@type' => 'Organization', 'name' => 'Acme Store' ),
			$offer['seller']
		);
		$this->assertSame( '2027-01-01', $offer['priceValidUntil'] );
	}

	public function test_variant_offer_url_is_set_from_entry_url(): void {
		// The from-scratch variant offer skeleton has no `url`; the
		// inheritance step copies the variant entry's own url onto the
		// offer so agents reading `offers[0].url` land on the variant.
		$result = $this->enhance_variable_with_parent_markup();
		$entry  = $result['hasVariant'][0];

		$this->assertArrayHasKey( 'url', $entry['offers'][0] );
		$this->assertSame( $entry['url'], $entry['offers'][0]['url'] );
	}

	public function test_variant_omits_inherited_fields_when_parent_lacks_them(): void {
		// Regression guard: a minimal parent markup (no description,
		// brand, category, seller, or priceValidUntil) must not trigger
		// PHP warnings and must not synthesize empty inherited fields on
		// the variant. The variant simply omits what the parent never had.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$variation = $this->make_variation( [ 'id' => 101, 'sku' => 'tee-w' ] );
		$this->setup_wc_get_product_for_variations( [ 101 => $variation ] );

		$parent = $this->make_product( [
			'id'                   => 100,
			'children'             => [ 101 ],
			'variation_attributes' => array( 'pa_color' => array( 'navy', 'white' ) ),
		] );

		// Bare-minimum input markup — none of the inheritable base fields.
		$result  = $this->jsonld->enhance_product_data( [], $parent );
		$variant = $result['hasVariant'][0];

		$this->assertArrayNotHasKey( 'description', $variant );
		$this->assertArrayNotHasKey( 'brand', $variant );
		$this->assertArrayNotHasKey( 'category', $variant );
		// The variant offer still exists (built from scratch) but carries
		// no inherited seller/priceValidUntil.
		$this->assertArrayNotHasKey( 'seller', $variant['offers'][0] );
		$this->assertArrayNotHasKey( 'priceValidUntil', $variant['offers'][0] );
	}

	public function test_variant_price_valid_until_prefers_variation_own_sale_end(): void {
		// priceValidUntil is NOT store-level. WC core derives the parent's
		// from the parent's sale-end (or a store default), but each
		// variation can run its own sale. When the variation carries its
		// OWN sale-end date, the variant offer must reflect THAT date, not
		// blindly inherit the parent's — otherwise a variation on a
		// different sale window advertises a wrong-but-plausible expiry.
		//
		// The variation's `get_date_on_sale_to()` returns a
		// `DateTimeInterface` (real WC returns `WC_DateTime`, a DateTime
		// subclass); the production guard narrows on `instanceof
		// \DateTimeInterface`, so a real immutable DateTime is the
		// faithful fixture. 2026-08-01 differs from the parent's
		// 2027-12-31 so the assertion proves the own-date won, not the
		// fallback.
		$sale_to = new \DateTimeImmutable( '2026-08-01T00:00:00+00:00' );

		$result = $this->enhance_variable_with_parent_markup(
			// Parent offer carries a DIFFERENT (store-default) expiry.
			array(
				'offers' => array(
					array(
						'@type'           => 'Offer',
						'price'           => '20.00',
						'priceValidUntil' => '2027-12-31',
					),
				),
			),
			array( 'date_on_sale_to' => $sale_to )
		);
		$offer = $result['hasVariant'][0]['offers'][0];

		$this->assertSame( '2026-08-01', $offer['priceValidUntil'] );
	}

	public function test_inherited_variant_fields_never_overwrite_existing_variant_values(): void {
		// Lock the no-clobber contract. The guards in
		// `add_inherited_variant_fields()` are unreachable via the public
		// ProductGroup path today (build_variant_entry() never sets
		// description/brand/category or offer seller/priceValidUntil/url),
		// so they'd otherwise be untested. Invoke the private method
		// directly with an `$entry` that ALREADY carries sentinel values
		// for every guarded field, plus a `$parent_markup` carrying
		// DIFFERENT values, and assert none of the parent's values bleed
		// over the variant's own.
		$variation = $this->make_variation( [
			'id'              => 101,
			'description'     => 'VARIATION OWN DESC',
			// Even with an own sale-end, the offer already has a
			// priceValidUntil sentinel, so the guard must skip derivation.
			'date_on_sale_to' => null,
		] );

		$entry = array(
			'@type'       => 'Product',
			'url'         => 'https://example.com/variant/sentinel/',
			'description' => 'VARIANT SENTINEL DESC',
			'brand'       => array( '@type' => 'Brand', 'name' => 'VariantBrand' ),
			'category'    => 'VariantCategory',
			'offers'      => array(
				array(
					'@type'           => 'Offer',
					'seller'          => array( '@type' => 'Organization', 'name' => 'VariantSeller' ),
					'priceValidUntil' => '2025-05-05',
					'url'             => 'https://example.com/offer/sentinel/',
				),
			),
		);

		$parent_markup = array(
			'@type'       => 'ProductGroup',
			'description' => 'PARENT DESC SHOULD NOT WIN',
			'brand'       => array( '@type' => 'Brand', 'name' => 'ParentBrand' ),
			'category'    => 'ParentCategory',
			'offers'      => array(
				array(
					'@type'           => 'Offer',
					'seller'          => array( '@type' => 'Organization', 'name' => 'ParentSeller' ),
					'priceValidUntil' => '2099-09-09',
				),
			),
		);

		$this->invoke_add_inherited_variant_fields( $entry, $variation, $parent_markup );

		// Every variant-set field is untouched; no parent value copied over.
		$this->assertSame( 'VARIANT SENTINEL DESC', $entry['description'] );
		$this->assertSame( array( '@type' => 'Brand', 'name' => 'VariantBrand' ), $entry['brand'] );
		$this->assertSame( 'VariantCategory', $entry['category'] );
		$this->assertSame(
			array( '@type' => 'Organization', 'name' => 'VariantSeller' ),
			$entry['offers'][0]['seller']
		);
		$this->assertSame( '2025-05-05', $entry['offers'][0]['priceValidUntil'] );
		$this->assertSame( 'https://example.com/offer/sentinel/', $entry['offers'][0]['url'] );
	}

	// ------------------------------------------------------------------
	// add_related_products — Schema.org isRelatedTo (cross-sells) and
	// isSimilarTo (upsells). Reference-only (`@id` URL) emission, not
	// full Product blocks. Three guards: syndication visibility,
	// deleted-product skip, hard cap of MAX_RELATED_PRODUCT_REFS.
	// (#335)
	// ------------------------------------------------------------------

	/**
	 * Build a minimal WC_Product mock for use as the *target* of a
	 * cross-sell / upsell pointer. Tests stub `wc_get_product()` to
	 * return mocks built by this helper. Visibility (in
	 * `is_product_syndicated()` terms) is controlled by the test's
	 * `WC_AI_Storefront::$test_settings` rather than per-mock state —
	 * see the syndication-exclusion tests below for the
	 * `selected_products` allow-list pattern.
	 */
	private function make_related_target(
		int $id,
		string $permalink
	): Mockery\MockInterface {
		$mock = Mockery::mock( 'WC_Product' );
		$mock->shouldReceive( 'get_id' )->andReturn( $id );
		$mock->shouldReceive( 'get_permalink' )->andReturn( $permalink );
		return $mock;
	}

	public function test_no_cross_sells_emits_no_is_related_to(): void {
		$product = $this->make_product();

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayNotHasKey( 'isRelatedTo', $result );
	}

	public function test_no_upsells_emits_no_is_similar_to(): void {
		$product = $this->make_product();

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayNotHasKey( 'isSimilarTo', $result );
	}

	public function test_cross_sells_emit_is_related_to_with_at_id_shape(): void {
		// Schema.org isRelatedTo = "loosely related"; WC cross-sells =
		// cart-page complementary purchases. Each entry is `@id`-only
		// so AI agents dereference to the linked product's own block.
		$product = $this->make_product( [ 'cross_sell_ids' => array( 201, 202 ) ] );

		$t201 = $this->make_related_target( 201, 'https://example.com/product/coat/' );
		$t202 = $this->make_related_target( 202, 'https://example.com/product/scarf/' );
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => 201 === (int) $id ? $t201 : ( 202 === (int) $id ? $t202 : false )
		);

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertSame(
			array(
				array( '@id' => 'https://example.com/product/coat/' ),
				array( '@id' => 'https://example.com/product/scarf/' ),
			),
			$result['isRelatedTo']
		);
	}

	public function test_upsells_emit_is_similar_to_with_at_id_shape(): void {
		// Schema.org isSimilarTo = "functionally similar"; WC upsells =
		// premium / alternate version of the same item.
		$product = $this->make_product( [ 'upsell_ids' => array( 301, 302 ) ] );

		$t301 = $this->make_related_target( 301, 'https://example.com/product/sweater-premium/' );
		$t302 = $this->make_related_target( 302, 'https://example.com/product/sweater-deluxe/' );
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => 301 === (int) $id ? $t301 : ( 302 === (int) $id ? $t302 : false )
		);

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertSame(
			array(
				array( '@id' => 'https://example.com/product/sweater-premium/' ),
				array( '@id' => 'https://example.com/product/sweater-deluxe/' ),
			),
			$result['isSimilarTo']
		);
	}

	public function test_cross_sell_excluded_from_syndication_is_filtered_out(): void {
		// Visibility consistency: cross-sells that fail
		// is_product_syndicated() must not appear in isRelatedTo, or
		// excluded products are reachable via graph traversal.
		// Use `selected` mode with an allow-list that excludes 402 to
		// drive `is_product_syndicated()` to return false for it.
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => array( 42, 401 ),  // parent (42) + visible cross-sell (401)
		);
		$product = $this->make_product( [ 'cross_sell_ids' => array( 401, 402 ) ] );

		$t_visible = $this->make_related_target( 401, 'https://example.com/product/visible/' );
		$t_hidden  = $this->make_related_target( 402, 'https://example.com/product/hidden/' );
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => 401 === (int) $id ? $t_visible : ( 402 === (int) $id ? $t_hidden : false )
		);

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertSame(
			array( array( '@id' => 'https://example.com/product/visible/' ) ),
			$result['isRelatedTo']
		);
	}

	public function test_upsell_excluded_from_syndication_is_filtered_out(): void {
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => array( 42, 501 ),  // parent (42) + visible upsell (501)
		);
		$product = $this->make_product( [ 'upsell_ids' => array( 501, 502 ) ] );

		$t_visible = $this->make_related_target( 501, 'https://example.com/product/v/' );
		$t_hidden  = $this->make_related_target( 502, 'https://example.com/product/h/' );
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => 501 === (int) $id ? $t_visible : ( 502 === (int) $id ? $t_hidden : false )
		);

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertSame(
			array( array( '@id' => 'https://example.com/product/v/' ) ),
			$result['isSimilarTo']
		);
	}

	public function test_deleted_cross_sell_product_is_skipped(): void {
		// `wc_get_product()` returns false for deleted/trashed IDs.
		// WC doesn't auto-prune stale cross-sell IDs when the
		// referenced product is deleted, so this case is common on
		// older stores.
		$product = $this->make_product( [ 'cross_sell_ids' => array( 601, 9999 ) ] );

		$alive = $this->make_related_target( 601, 'https://example.com/product/alive/' );
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => 601 === (int) $id ? $alive : false
		);

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertSame(
			array( array( '@id' => 'https://example.com/product/alive/' ) ),
			$result['isRelatedTo']
		);
	}

	public function test_existing_is_related_to_is_not_overwritten(): void {
		// If WC core or another plugin's filter already set
		// isRelatedTo at higher priority, defer — same pattern as the
		// typed-property emission for color/size/material/pattern.
		$product = $this->make_product( [ 'cross_sell_ids' => array( 701 ) ] );

		$markup = array(
			'isRelatedTo' => array( array( '@id' => 'https://upstream.example.com/p/' ) ),
		);
		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame(
			array( array( '@id' => 'https://upstream.example.com/p/' ) ),
			$result['isRelatedTo']
		);
	}

	public function test_explicitly_empty_is_related_to_is_not_overwritten(): void {
		// `isset()` returns true for an explicit empty array, so a
		// caller that deliberately set `isRelatedTo => array()` to
		// suppress emission gets that respected. This is a deliberate
		// choice over `array_key_exists() && ! empty()` — the latter
		// would silently overwrite a caller's "no, really, emit
		// nothing" intent with our cross-sell list. Pin the behavior
		// so a future "fix" doesn't quietly flip it.
		$product = $this->make_product( [ 'cross_sell_ids' => array( 901, 902 ) ] );

		$t901 = $this->make_related_target( 901, 'https://example.com/product/p901/' );
		$t902 = $this->make_related_target( 902, 'https://example.com/product/p902/' );
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => 901 === (int) $id ? $t901 : ( 902 === (int) $id ? $t902 : false )
		);

		$markup = array( 'isRelatedTo' => array() );
		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( array(), $result['isRelatedTo'] );
	}

	public function test_explicitly_empty_is_similar_to_is_not_overwritten(): void {
		// Symmetric guard for the isSimilarTo branch. The two
		// properties go through identical isset() checks; pin the
		// upsell side too so a future refactor that special-cases one
		// branch but not the other can't silently regress.
		$product = $this->make_product( [ 'upsell_ids' => array( 911, 912 ) ] );

		$t911 = $this->make_related_target( 911, 'https://example.com/product/p911/' );
		$t912 = $this->make_related_target( 912, 'https://example.com/product/p912/' );
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => 911 === (int) $id ? $t911 : ( 912 === (int) $id ? $t912 : false )
		);

		$markup = array( 'isSimilarTo' => array() );
		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( array(), $result['isSimilarTo'] );
	}

	public function test_existing_is_similar_to_is_not_overwritten(): void {
		$product = $this->make_product( [ 'upsell_ids' => array( 801 ) ] );

		$markup = array(
			'isSimilarTo' => array( array( '@id' => 'https://upstream.example.com/p/' ) ),
		);
		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame(
			array( array( '@id' => 'https://upstream.example.com/p/' ) ),
			$result['isSimilarTo']
		);
	}

	public function test_duplicate_cross_sell_ids_are_deduplicated_before_emission(): void {
		// WC's editor doesn't enforce uniqueness on cross/upsell ID
		// storage. Corrupted or imported postmeta can carry the same
		// ID multiple times — without per-list dedup, we'd emit ten
		// identical `@id` entries and never fall through to the
		// distinct products beyond. Pin: pass `[201, 201, 202, 202]`,
		// expect two distinct refs (in first-seen order) and a single
		// `wc_get_product()` resolution per ID.
		$product = $this->make_product( [ 'cross_sell_ids' => array( 201, 201, 202, 202 ) ] );

		$resolve_count = array( 201 => 0, 202 => 0 );
		$t201 = $this->make_related_target( 201, 'https://example.com/product/p201/' );
		$t202 = $this->make_related_target( 202, 'https://example.com/product/p202/' );
		Functions\when( 'wc_get_product' )->alias(
			static function ( $id ) use ( $t201, $t202, &$resolve_count ) {
				$id = (int) $id;
				if ( isset( $resolve_count[ $id ] ) ) {
					++$resolve_count[ $id ];
				}
				return 201 === $id ? $t201 : ( 202 === $id ? $t202 : false );
			}
		);

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertSame(
			array(
				array( '@id' => 'https://example.com/product/p201/' ),
				array( '@id' => 'https://example.com/product/p202/' ),
			),
			$result['isRelatedTo']
		);
		// Each ID resolved exactly once — dedup happens before the
		// build loop, not after.
		$this->assertSame( 1, $resolve_count[201] );
		$this->assertSame( 1, $resolve_count[202] );
	}

	public function test_both_keys_set_short_circuits_and_does_not_call_wc_get_product(): void {
		// When both isRelatedTo and isSimilarTo are already populated
		// (e.g. by a higher-priority third-party filter), the method
		// must short-circuit BEFORE doing any work — no
		// get_cross_sell_ids() reads, no candidate-ID building, no
		// cache priming, no `wc_get_product()` calls. Pin this with a
		// `wc_get_product()` alias that fails the test if it's
		// called, since the absence of the call is the whole point.
		$product = $this->make_product( [
			'cross_sell_ids' => array( 1101 ),
			'upsell_ids'     => array( 1102 ),
		] );

		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) {
				$this->fail( 'wc_get_product() must not be called when both keys are pre-populated; got id=' . (int) $id );
			}
		);

		$markup = array(
			'isRelatedTo' => array( array( '@id' => 'https://upstream.example.com/r/' ) ),
			'isSimilarTo' => array( array( '@id' => 'https://upstream.example.com/s/' ) ),
		);
		$result = $this->jsonld->enhance_product_data( $markup, $product );

		// Both keys preserved, neither overwritten.
		$this->assertSame(
			array( array( '@id' => 'https://upstream.example.com/r/' ) ),
			$result['isRelatedTo']
		);
		$this->assertSame(
			array( array( '@id' => 'https://upstream.example.com/s/' ) ),
			$result['isSimilarTo']
		);
	}

	public function test_related_products_capped_at_max_refs_constant(): void {
		// Hard cap of 10 entries per property prevents markup blowout
		// on stores with very large cross-sell lists. Pass 12 IDs;
		// expect 10 in the output.
		$ids = range( 1001, 1012 );
		$product = $this->make_product( [ 'cross_sell_ids' => $ids ] );

		$targets = array();
		foreach ( $ids as $id ) {
			$targets[ $id ] = $this->make_related_target(
				$id,
				'https://example.com/product/p' . $id . '/'
			);
		}
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => $targets[ (int) $id ] ?? false
		);

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertCount( 10, $result['isRelatedTo'] );
		// First 10 in input order.
		$this->assertSame(
			'https://example.com/product/p1001/',
			$result['isRelatedTo'][0]['@id']
		);
		$this->assertSame(
			'https://example.com/product/p1010/',
			$result['isRelatedTo'][9]['@id']
		);
	}

	// ------------------------------------------------------------------
	// allow_product_group_type — registers `productgroup` on the WC core
	// per-page allow-list so the rewritten @type survives
	// `WC_Structured_Data::get_structured_data()`. Without this hook
	// the entire ProductGroup block is silently dropped on the
	// front-end (regression observed against TT5 + WC 10.7).
	// ------------------------------------------------------------------

	public function test_allow_product_group_type_appends_productgroup_on_single_product(): void {
		Functions\when( 'is_product' )->justReturn( true );

		$result = $this->jsonld->allow_product_group_type( [ 'product', 'breadcrumblist' ] );

		$this->assertContains( 'productgroup', $result );
		// Existing types must survive untouched — we only append.
		$this->assertContains( 'product', $result );
		$this->assertContains( 'breadcrumblist', $result );
	}

	public function test_allow_product_group_type_does_not_append_off_product_pages(): void {
		// On non-product pages (cart, archive, generic post) we have no
		// reason to admit `productgroup`, and admitting it indiscriminately
		// could surface a stale ProductGroup block from a transient if a
		// future feature ever caches structured data globally.
		Functions\when( 'is_product' )->justReturn( false );

		$result = $this->jsonld->allow_product_group_type( [ 'product', 'breadcrumblist' ] );

		$this->assertNotContains( 'productgroup', $result );
	}

	public function test_allow_product_group_type_does_not_duplicate_when_already_present(): void {
		// Another plugin (or this filter running multiple times against
		// the same `$types` array) may have already added
		// `productgroup`. Avoid duplicates — they're noise in the
		// allow-list even though WC core's intersection step doesn't
		// break on them.
		Functions\when( 'is_product' )->justReturn( true );

		$result = $this->jsonld->allow_product_group_type( [ 'product', 'productgroup', 'breadcrumblist' ] );

		$this->assertSame( 1, count( array_keys( $result, 'productgroup', true ) ) );
		$this->assertContains( 'product', $result );
		$this->assertContains( 'breadcrumblist', $result );
	}

	public function test_offer_checkout_page_url_template_omitted_when_no_offers(): void {
		// Defensive: products without an offer (rare — typically
		// non-purchasable) shouldn't get a stranded checkoutPageURLTemplate
		// on a non-existent Offer.
		$product = $this->make_product();

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayNotHasKey( 'offers', $result );
	}

	// ------------------------------------------------------------------
	// SearchAction urlTemplate (Store-level JSON-LD)
	// ------------------------------------------------------------------
	//
	// The Store-level `output_store_jsonld()` emits a SearchAction
	// target that is bare (no UTMs) — a human's sitelinks search that
	// later converts is attributed natively by WooCommerce, same as
	// any other organic visit. `{search_term}` is the only (required)
	// placeholder: a consumer MUST substitute it to run a search.
	//
	// We assert substrings rather than parse the full JSON because
	// `output_store_jsonld()` echoes a complete `<script type=...>`
	// wrapper plus the JSON body; a substring check avoids brittle
	// JSON-shape-decoding for what is effectively a regression guard.

	public function test_searchaction_url_template_is_bare(): void {
		// Capture the SearchAction urlTemplate by intercepting the
		// `wc_ai_storefront_jsonld_store` filter that `output_store_jsonld()`
		// applies to its `$store_data` array right before echoing.
		// Using filter capture rather than buffered-output capture
		// avoids the get_terms / wc_get_products stubbing rabbit hole
		// in `get_catalog_summary()` — we just want to verify what
		// utm shape lands on the SearchAction urlTemplate.
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
		Functions\when( 'get_bloginfo' )->returnArg();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_terms' )->justReturn( [] );
		// `is_wp_error` is defined globally by Brain Monkey's WP
		// preset before Patchwork can redefine it, so we don't stub
		// it here — `get_terms()` returns `[]` (a plain array, not a
		// WP_Error), so the natural `is_wp_error([])` returns false
		// and execution falls through.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		// `__()` is invoked on the OfferCatalog name; not relevant
		// to this test but Brain Monkey errors without it.
		Functions\when( '__' )->returnArg( 1 );

		// Capture the array passed through the filter so we can
		// assert on its structure directly. The filter signature is
		// `apply_filters( $tag, $value, ...$args )`; the existing
		// setUp stub returns `$args[2]` (i.e. the value) — we
		// intercept here for the specific tag we care about and
		// pass-through for others.
		$captured = null;
		// Variadic third+ params: `output_store_jsonld()` invokes
		// `apply_filters( 'wc_ai_storefront_jsonld_store', $store_data, $settings )`
		// with three args. A 2-arg alias would throw `ArgumentCountError`
		// on PHP 8 strict-mode internals. Variadic capture forwards
		// any extras without inspecting them.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value, ...$extras ) use ( &$captured ) {
				if ( $tag === 'wc_ai_storefront_jsonld_store' ) {
					$captured = $value;
				}
				return $value;
			}
		);

		// Suppress the actual echo so PHPUnit's risky-test detector
		// doesn't flag stray output. Wrapping in ob_* keeps the
		// buffer balanced even if the function throws.
		ob_start();
		try {
			$this->jsonld->output_store_jsonld();
		} finally {
			ob_end_clean();
		}

		$this->assertIsArray( $captured, 'wc_ai_storefront_jsonld_store filter should fire' );
		$url = $captured['potentialAction']['target']['urlTemplate'];

		$this->assertStringContainsString( 's={search_term}', $url );
		$this->assertStringContainsString( 'post_type=product', $url );
		// Regression guard: no UTMs, no leftover attribution placeholder.
		$this->assertStringNotContainsString( 'utm_', $url );
		$this->assertStringNotContainsString( '{agent_id}', $url );
	}

	public function test_store_jsonld_hex_escapes_script_close_tag_in_taxonomy_names(): void {
		// Regression guard for the script-tag-breakout class: any
		// string field flowing into the JSON-LD body that contains
		// `</script>` would, under the previous `JSON_UNESCAPED_SLASHES`
		// flag, survive verbatim and break out of the
		// `<script type="application/ld+json">` CDATA context. A
		// taxonomy name like `</script><script>alert(1)</script>`
		// (creatable by any role with `manage_categories`, typically
		// Editor) becomes a stored XSS on the homepage and shop page.
		//
		// Fix uses `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`
		// so `<` and `>` serialize as `\u003C` and `\u003E` (and
		// the other flagged characters likewise serialize as escaped
		// code points). The script tag's CDATA is preserved; Schema.org
		// parsers handle these escapes per the JSON spec.
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
		// Site name carries the malicious payload — the most reachable
		// path: an admin types it into Settings → General → Site Title.
		Functions\when( 'get_bloginfo' )->alias(
			static function ( $key ) {
				if ( 'name' === $key ) {
					return '</script><script>alert("xss")</script>';
				}
				if ( 'description' === $key ) {
					return 'normal description';
				}
				return '';
			}
		);
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		// Add a taxonomy with the same payload — covers the Editor-role
		// `manage_categories` reach as well.
		$category       = new stdClass();
		$category->name = '</script><script>document.cookie</script>';
		$category->slug = 'malicious-category';
		$category->count = 1;
		Functions\when( 'get_terms' )->justReturn( [ $category ] );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/category/malicious-category/' );
		// Real `wp_json_encode` stand-in via PHP's encoder so we
		// exercise the actual flag handling rather than the
		// string-builder alias used elsewhere in this file. Aliasing
		// directly to `json_encode` (per Copilot review on PR #131)
		// is consistent with surrounding tests and forwards all
		// arguments correctly.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->returnArg( 1 );

		ob_start();
		$this->jsonld->output_store_jsonld();
		$output = ob_get_clean();

		// Positive proof the emission ran: presence of the wrapping
		// opening tag. Without this, a regression that returned early
		// before echoing anything would leave `$output` empty — and
		// the rest of the assertions below would all trivially pass.
		$this->assertStringContainsString(
			'<script type="application/ld+json">',
			$output,
			'output_store_jsonld() must emit the wrapping script tag.'
		);

		// Critical assertion: the literal `</script>` byte sequence
		// MUST NOT appear inside the JSON body, only as the closing
		// of our own intended wrapper. The fixture injects two payloads
		// (in site name AND in category name) each containing two
		// `</script>` occurrences, so a complete fix produces exactly
		// 1 occurrence (the wrapper close); a complete regression
		// produces 5 (1 wrapper + 2 fields × 2 occurrences). Anything
		// in between is a partial regression and also fails the
		// `=== 1` check, so this single assertion catches every
		// regression class.
		$this->assertSame(
			1,
			substr_count( $output, '</script>' ),
			'JSON body must contain ZERO literal </script> sequences (only our wrapper close is permitted).'
		);

		// Same defense for the OPENING-tag-injection variant. Sneaky
		// regressions that hex-escape `</script>` but leave `<script`
		// raw would still allow injection of NEW script blocks into
		// the page. The fixture payloads each contain one literal
		// `<script` (the second tag in `</script><script>...`); a
		// complete fix produces exactly 1 (our wrapper open).
		$this->assertSame(
			1,
			substr_count( $output, '<script' ),
			'JSON body must not contain a literal <script (only our wrapper open is permitted).'
		);

		// Same defense for HTML-comment injection. The flag set
		// hex-escapes `<` so `<!--` becomes `<!--` — the
		// canonical comment-injection vector should be blocked too.
		// Fixture doesn't inject this, but a future test extension
		// adding a `<!--` payload would land here without a code
		// change.
		$this->assertStringNotContainsString(
			'<!--',
			$output,
			'JSON body must not contain HTML-comment open sequence.'
		);

		// Extract the JSON between the script tags and confirm it
		// parses — the hex-escaped output is still valid JSON-LD.
		// `preg_match_all` over `preg_match` (per Copilot review on
		// PR #131): `preg_match` returns the FIRST match only, so a
		// regression that emitted TWO `<script type=...>` blocks
		// would slip past `preg_match`'s result-shape check entirely.
		// `preg_match_all` with PREG_SET_ORDER groups each match's
		// captures together so we can assert exactly one block.
		$matches = [];
		preg_match_all(
			'/<script type="application\/ld\+json">(.*?)<\/script>/s',
			$output,
			$matches,
			PREG_SET_ORDER
		);
		$this->assertCount( 1, $matches, 'Expected exactly one <script type="application/ld+json"> block in output.' );
		$decoded = json_decode( $matches[0][1], true );
		$this->assertIsArray( $decoded, 'JSON inside the script tag must parse to an array.' );

		// Cross-field round-trip: BOTH the site name AND the category
		// name should be preserved as data. A regression that fixed
		// only one path (e.g., site-name encoding fixed but
		// `get_catalog_summary()`'s category-name path still raw)
		// would fail one of these.
		$this->assertEquals(
			'</script><script>document.cookie</script>',
			$decoded['hasOfferCatalog']['itemListElement'][0]['name'],
			'Malicious category name must round-trip through hex-escape and JSON-decode.'
		);
		// Round-trip through decode confirms the malicious string is
		// preserved as data (not as breakout markup): the decoded
		// `name` equals the original input.
		$this->assertEquals(
			'</script><script>alert("xss")</script>',
			$decoded['name'],
			'Malicious site-name must round-trip cleanly through hex-escape and JSON-decode.'
		);
	}

	// ------------------------------------------------------------------
	// Inventory (only when managing stock)
	// ------------------------------------------------------------------

	public function test_inventory_level_added_at_offer_level_when_stock_is_tracked(): void {
		// Production input shape: WC core emits `offers` as a list of
		// Offer dicts. Emission must land at `offers[0]`, never as a
		// string key on the outer `offers` list (would mix list +
		// assoc shapes — PHP serializes that as a JSON object, not
		// an Offer array).
		$product = $this->make_product( [
			'managing_stock' => true,
			'stock_quantity' => 17,
		] );

		$result = $this->jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$product
		);

		$this->assertEquals(
			[
				'@type' => 'QuantitativeValue',
				'value' => 17,
			],
			$result['offers'][0]['inventoryLevel']
		);
		// Regression guard: `inventoryLevel` must never be a string
		// key on the outer `offers` list. The earlier-shipped form
		// `$markup['offers']['inventoryLevel'] = ...` would smuggle
		// it in there and break Offer-array shape on serialization.
		$this->assertArrayNotHasKey( 'inventoryLevel', $result['offers'] );
	}

	public function test_inventory_level_omitted_when_offers_is_assoc_shape(): void {
		// Defensive: a third-party filter could pass associative
		// `offers` (e.g., `['@type' => 'Offer']` instead of
		// `[['@type' => 'Offer']]`). The `isset($markup['offers'][0])`
		// guard returns false on assoc input, so emission correctly
		// skips. Without this test, a future refactor that loosens
		// the guard to `is_array($markup['offers'])` could
		// accidentally re-introduce the original assoc-key write
		// against this shape.
		$product = $this->make_product( [
			'managing_stock' => true,
			'stock_quantity' => 17,
		] );

		$result = $this->jsonld->enhance_product_data(
			[ 'offers' => [ '@type' => 'Offer' ] ],
			$product
		);

		// inventoryLevel must be absent at every level — neither
		// stamped as a string key on `offers` (the original bug)
		// nor injected at the top level.
		$this->assertArrayNotHasKey( 'inventoryLevel', $result['offers'] );
		$this->assertArrayNotHasKey( 'inventoryLevel', $result );
	}

	public function test_inventory_level_omitted_when_stock_is_not_tracked(): void {
		$product = $this->make_product( [ 'managing_stock' => false ] );

		$result = $this->jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$product
		);

		$this->assertArrayNotHasKey( 'inventoryLevel', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'inventoryLevel', $result['offers'] );
	}

	public function test_inventory_level_omitted_when_quantity_is_null(): void {
		// Edge case: managing_stock returns true but quantity is null
		// (e.g. during a transient stock-sync race). The generator must
		// not emit a QuantitativeValue with `value: null`.
		$product = $this->make_product( [
			'managing_stock' => true,
			'stock_quantity' => null,
		] );

		$result = $this->jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$product
		);

		$this->assertArrayNotHasKey( 'inventoryLevel', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'inventoryLevel', $result['offers'] );
	}

	public function test_inventory_level_omitted_when_offers_is_missing(): void {
		// Defensive: if a third-party filter strips `offers` entirely
		// before our hook fires, the inventoryLevel emission must
		// gracefully skip rather than write to a non-existent path.
		// The `isset($markup['offers'][0])` guard mirrors the same
		// defense used by the priceCurrency / hasMerchantReturnPolicy
		// emissions.
		$product = $this->make_product( [
			'managing_stock' => true,
			'stock_quantity' => 17,
		] );

		$result = $this->jsonld->enhance_product_data(
			// No 'offers' key at all.
			[ '@type' => 'Product' ],
			$product
		);

		// No fatal, no spurious offers key, no inventoryLevel
		// orphaned at the top level.
		$this->assertArrayNotHasKey( 'inventoryLevel', $result );
	}

	// ------------------------------------------------------------------
	// Weight and dimensions — UN/CEFACT unit codes
	// ------------------------------------------------------------------

	public function test_weight_uses_uncefact_unit_code(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = '' ) =>
				'woocommerce_weight_unit' === $key ? 'kg' : $default
		);

		$product = $this->make_product( [
			'has_weight' => true,
			'weight'     => '1.5',
		] );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertEquals( '1.5', $result['weight']['value'] );
		$this->assertEquals( 'KGM', $result['weight']['unitCode'] );
	}

	public function test_weight_unit_code_maps_imperial(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = '' ) =>
				'woocommerce_weight_unit' === $key ? 'lbs' : $default
		);

		$product = $this->make_product( [
			'has_weight' => true,
			'weight'     => '3',
		] );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertEquals( 'LBR', $result['weight']['unitCode'] );
	}

	public function test_unknown_weight_unit_falls_back_to_kgm(): void {
		// Defensive: if someone configures a custom unit through a
		// filter or filesystem edit, we shouldn't produce an invalid
		// JSON-LD unit code. Default to KGM (kilogram).
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = '' ) =>
				'woocommerce_weight_unit' === $key ? 'stones' : $default
		);

		$product = $this->make_product( [
			'has_weight' => true,
			'weight'     => '1',
		] );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertEquals( 'KGM', $result['weight']['unitCode'] );
	}

	public function test_dimensions_emit_depth_width_height(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = '' ) =>
				'woocommerce_dimension_unit' === $key ? 'cm' : $default
		);

		$product = $this->make_product( [
			'has_dimensions' => true,
			'dimensions'     => [ 'length' => '10', 'width' => '20', 'height' => '30' ],
		] );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertEquals( '10', $result['depth']['value'] );
		$this->assertEquals( '20', $result['width']['value'] );
		$this->assertEquals( '30', $result['height']['value'] );
		$this->assertEquals( 'CMT', $result['depth']['unitCode'] );
	}

	// ------------------------------------------------------------------
	// Attributes
	// ------------------------------------------------------------------

	public function test_visible_attributes_are_emitted_as_additional_properties(): void {
		// Uses unmapped slugs (pa_style, pa_origin) — pa_color/pa_size now
		// route to typed Schema.org properties; non-core attributes stay
		// in additionalProperty.
		$style = Mockery::mock();
		$style->shouldReceive( 'get_visible' )->andReturn( true );
		$style->shouldReceive( 'get_name' )->andReturn( 'pa_style' );

		$origin = Mockery::mock();
		$origin->shouldReceive( 'get_visible' )->andReturn( true );
		$origin->shouldReceive( 'get_name' )->andReturn( 'pa_origin' );

		$product = $this->make_product( [
			'attributes' => [
				'pa_style'  => $style,
				'pa_origin' => $origin,
			],
		] );
		$product->shouldReceive( 'get_attribute' )
			->with( 'pa_style' )->andReturn( 'Casual' );
		$product->shouldReceive( 'get_attribute' )
			->with( 'pa_origin' )->andReturn( 'Portugal' );

		Functions\when( 'wc_attribute_label' )->alias(
			static fn( $slug ) => ucfirst( str_replace( 'pa_', '', $slug ) )
		);

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertCount( 2, $result['additionalProperty'] );
		// Search by name so the test isn't sensitive to emit order.
		$by_name = array_column( $result['additionalProperty'], null, 'name' );
		$this->assertSame( 'PropertyValue', $by_name['Style']['@type'] );
		$this->assertSame( 'Casual', $by_name['Style']['value'] );
		$this->assertSame( 'PropertyValue', $by_name['Origin']['@type'] );
		$this->assertSame( 'Portugal', $by_name['Origin']['value'] );
	}

	public function test_invisible_attributes_are_skipped(): void {
		// Admin-only attributes (visible=false in the product editor)
		// shouldn't leak into public structured data.
		$internal = Mockery::mock();
		$internal->shouldReceive( 'get_visible' )->andReturn( false );
		// get_name is never called on invisible attributes; Mockery
		// would allow extra calls, but the branch should short-circuit.

		$product = $this->make_product( [
			'attributes' => [ 'internal_code' => $internal ],
		] );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayNotHasKey( 'additionalProperty', $result );
	}

	public function test_empty_attribute_values_are_skipped(): void {
		$empty = Mockery::mock();
		$empty->shouldReceive( 'get_visible' )->andReturn( true );
		$empty->shouldReceive( 'get_name' )->andReturn( 'pa_style' );

		$product = $this->make_product( [
			'attributes' => [ 'pa_style' => $empty ],
		] );
		$product->shouldReceive( 'get_attribute' )
			->with( 'pa_style' )->andReturn( '' );
		Functions\when( 'wc_attribute_label' )->justReturn( 'Style' );

		$result = $this->jsonld->enhance_product_data( [], $product );

		// Empty values add no information and would render as blank
		// PropertyValues; they're filtered out.
		$this->assertArrayNotHasKey( 'additionalProperty', $result );
	}

	public function test_whitespace_only_unmapped_attribute_value_is_skipped(): void {
		// Same gate as `test_empty_attribute_values_are_skipped` but for
		// whitespace-only input — `emit_attributes()` trims and skips on
		// empty post-trim, so a value like `'   '` doesn't render as a
		// blank PropertyValue.
		$attribute = Mockery::mock();
		$attribute->shouldReceive( 'get_visible' )->andReturn( true );
		$attribute->shouldReceive( 'get_name' )->andReturn( 'pa_style' );

		$product = $this->make_product( [
			'attributes' => [ 'pa_style' => $attribute ],
		] );
		$product->shouldReceive( 'get_attribute' )
			->with( 'pa_style' )->andReturn( '   ' );
		Functions\when( 'wc_attribute_label' )->justReturn( 'Style' );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayNotHasKey( 'additionalProperty', $result );
	}

	// ------------------------------------------------------------------
	// Core attribute → typed Schema.org property mapping (#327)
	// ------------------------------------------------------------------

	/**
	 * Build a single-attribute product mock for the typed-property tests.
	 *
	 * @param string $slug             Attribute slug (e.g. `pa_color`).
	 * @param string $value            Joined attribute value as WC returns it.
	 * @param array  $product_overrides Extra overrides for `make_product()` —
	 *                                  e.g. `'variation_attributes'` to mark
	 *                                  the slug as variation-defining.
	 */
	private function make_product_with_attr( string $slug, string $value, array $product_overrides = [] ): Mockery\MockInterface {
		$attribute = Mockery::mock();
		$attribute->shouldReceive( 'get_visible' )->andReturn( true );
		$attribute->shouldReceive( 'get_name' )->andReturn( $slug );

		$product = $this->make_product( array_merge(
			[ 'attributes' => [ $slug => $attribute ] ],
			$product_overrides
		) );
		$product->shouldReceive( 'get_attribute' )
			->with( $slug )->andReturn( $value );
		Functions\when( 'wc_attribute_label' )->justReturn(
			ucfirst( str_replace( 'pa_', '', $slug ) )
		);
		return $product;
	}

	public function test_pa_color_emits_as_typed_color_property(): void {
		$product = $this->make_product_with_attr( 'pa_color', 'Black' );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertSame( 'Black', $result['color'] );
		$this->assertArrayNotHasKey( 'additionalProperty', $result );
	}

	public function test_pa_size_emits_as_typed_size_property(): void {
		$product = $this->make_product_with_attr( 'pa_size', 'L' );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertSame( 'L', $result['size'] );
		$this->assertArrayNotHasKey( 'additionalProperty', $result );
	}

	public function test_pa_material_emits_as_typed_material_property(): void {
		$product = $this->make_product_with_attr( 'pa_material', 'Cotton' );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertSame( 'Cotton', $result['material'] );
		$this->assertArrayNotHasKey( 'additionalProperty', $result );
	}

	public function test_pa_pattern_emits_as_typed_pattern_property(): void {
		$product = $this->make_product_with_attr( 'pa_pattern', 'Striped' );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertSame( 'Striped', $result['pattern'] );
		$this->assertArrayNotHasKey( 'additionalProperty', $result );
	}

	public function test_uk_spelling_colour_maps_to_color(): void {
		// `colour` (UK) and `pa_colour` route to schema:color the same as
		// the US-spelled variants. WC sample-products uses `pa_color`,
		// but custom merchant taxonomies do appear in both spellings.
		$product = $this->make_product_with_attr( 'pa_colour', 'Navy' );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertSame( 'Navy', $result['color'] );
		$this->assertArrayNotHasKey( 'additionalProperty', $result );
	}

	public function test_free_text_capitalized_color_attribute_maps_to_color(): void {
		// Free-text custom attributes preserve the merchant-typed casing
		// in `get_name()` (e.g. "Color"). Slug lookup is case-insensitive.
		$product = $this->make_product_with_attr( 'Color', 'Red' );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertSame( 'Red', $result['color'] );
		$this->assertArrayNotHasKey( 'additionalProperty', $result );
	}

	public function test_multi_value_core_attribute_skips_typed_emission(): void {
		// Schema.org's `color` is `Text` — a single value. Multi-value
		// inputs (e.g. "Black, Navy" on a simple product) can't honestly
		// be represented as a single typed property. Skip emission, fall
		// back to additionalProperty.
		$product = $this->make_product_with_attr( 'pa_color', 'Black, Navy' );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayNotHasKey( 'color', $result );
		$this->assertCount( 1, $result['additionalProperty'] );
		$this->assertSame( 'Black, Navy', $result['additionalProperty'][0]['value'] );
	}

	public function test_multi_value_pipe_joined_core_attribute_skips_typed_emission(): void {
		// Either WC delimiter (`,` taxonomy or `|` free-text) triggers the
		// multi-value detection.
		$product = $this->make_product_with_attr( 'Color', 'Black | Tan' );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayNotHasKey( 'color', $result );
		$this->assertCount( 1, $result['additionalProperty'] );
		$this->assertSame( 'Black | Tan', $result['additionalProperty'][0]['value'] );
	}

	public function test_variation_defining_core_attribute_is_skipped_from_typed_property(): void {
		// Variation-defining attributes describe variants, not the
		// parent product. Emitting `color: "Navy"` at the parent would
		// claim a single intrinsic color the parent doesn't have. Per-
		// variant JSON-LD is tracked in #328; until then, variation-
		// specific data isn't emitted as Schema.org.
		$product = $this->make_product_with_attr(
			'pa_color',
			'Navy, White, Gray',
			[
				'variation_attributes' => [ 'pa_color' => [ 'navy', 'white', 'gray' ] ],
			]
		);

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayNotHasKey( 'color', $result );
	}

	public function test_variation_defining_core_attribute_is_skipped_from_additional_property(): void {
		// Variation-defining attributes describe variants, not the
		// parent product — emitting them at the parent level (typed or
		// additionalProperty) would misrepresent the parent. Per-variant
		// emission is intentionally omitted until #328 lands.
		$product = $this->make_product_with_attr(
			'pa_color',
			'Navy, White, Gray',
			[
				'variation_attributes' => [ 'pa_color' => [ 'navy', 'white', 'gray' ] ],
			]
		);

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayNotHasKey( 'additionalProperty', $result );
	}

	public function test_existing_typed_property_in_markup_is_not_overwritten(): void {
		// Two-part contract:
		//   1. The upstream-set typed property is preserved (we defer).
		//   2. The merchant's attribute still emits to additionalProperty
		//      so its data signal isn't lost when upstream chose a
		//      different value. This is "caller control" — the caller
		//      gets to choose the typed claim, the merchant's data
		//      reaches agents either way.
		$product = $this->make_product_with_attr( 'pa_color', 'Black' );

		$result = $this->jsonld->enhance_product_data( [ 'color' => 'PreSet' ], $product );

		$this->assertSame( 'PreSet', $result['color'] );
		$this->assertCount( 1, $result['additionalProperty'] );
		$this->assertSame( 'Color', $result['additionalProperty'][0]['name'] );
		$this->assertSame( 'Black', $result['additionalProperty'][0]['value'] );
	}

	public function test_unmapped_attribute_still_emits_to_additional_property(): void {
		// Non-core attributes (Style, Origin, Heel Height, etc.) trust
		// the merchant — single OR multi-value, emit as-is.
		$product = $this->make_product_with_attr( 'pa_style', 'Casual' );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayNotHasKey( 'style', $result );  // not a typed Schema.org property
		$this->assertCount( 1, $result['additionalProperty'] );
		$this->assertSame( 'Style', $result['additionalProperty'][0]['name'] );
		$this->assertSame( 'Casual', $result['additionalProperty'][0]['value'] );
	}

	public function test_existing_additional_property_entries_are_preserved(): void {
		// If WC core or another plugin populated `additionalProperty`
		// with entries before our filter ran, our merchant-attribute
		// emissions append to that array rather than overwriting it.
		$product = $this->make_product_with_attr( 'pa_style', 'Casual' );

		$pre_existing = array(
			array(
				'@type' => 'PropertyValue',
				'name'  => 'Upstream',
				'value' => 'Preserved',
			),
		);
		$result = $this->jsonld->enhance_product_data(
			array( 'additionalProperty' => $pre_existing ),
			$product
		);

		$this->assertCount( 2, $result['additionalProperty'] );
		$by_name = array_column( $result['additionalProperty'], null, 'name' );
		$this->assertSame( 'Preserved', $by_name['Upstream']['value'] );
		$this->assertSame( 'Casual', $by_name['Style']['value'] );
	}

	public function test_existing_additional_property_with_filter_keys_is_preserved(): void {
		// `array_filter()` preserves keys, so an upstream filter chain
		// that drops bogus entries can leave a numeric-keyed array with
		// gaps (e.g. `[1 => ..., 3 => ...]`). `array_is_list()` returns
		// false for such arrays — without re-keying via `array_values()`
		// the merge would have wrapped the whole array as a single
		// nested element. This test locks the re-key behavior.
		$product = $this->make_product_with_attr( 'pa_style', 'Casual' );

		$pre_existing_filtered = array(
			1 => array( '@type' => 'PropertyValue', 'name' => 'A', 'value' => 'a' ),
			3 => array( '@type' => 'PropertyValue', 'name' => 'B', 'value' => 'b' ),
		);
		$result = $this->jsonld->enhance_product_data(
			array( 'additionalProperty' => $pre_existing_filtered ),
			$product
		);

		$this->assertCount( 3, $result['additionalProperty'] );
		$by_name = array_column( $result['additionalProperty'], null, 'name' );
		$this->assertSame( 'a', $by_name['A']['value'] );
		$this->assertSame( 'b', $by_name['B']['value'] );
		$this->assertSame( 'Casual', $by_name['Style']['value'] );
	}

	public function test_existing_single_additional_property_object_is_preserved(): void {
		// Schema.org allows `additionalProperty` as a single value or an
		// array. If upstream emitted a single PropertyValue (not wrapped
		// in an array), our merge normalizes it to array form before
		// appending — no data loss.
		$product = $this->make_product_with_attr( 'pa_style', 'Casual' );

		$pre_existing_single = array(
			'@type' => 'PropertyValue',
			'name'  => 'Upstream',
			'value' => 'Preserved',
		);
		$result = $this->jsonld->enhance_product_data(
			array( 'additionalProperty' => $pre_existing_single ),
			$product
		);

		$this->assertCount( 2, $result['additionalProperty'] );
		$by_name = array_column( $result['additionalProperty'], null, 'name' );
		$this->assertSame( 'Preserved', $by_name['Upstream']['value'] );
		$this->assertSame( 'Casual', $by_name['Style']['value'] );
	}

	public function test_invisible_core_attribute_is_not_mapped(): void {
		$attribute = Mockery::mock();
		$attribute->shouldReceive( 'get_visible' )->andReturn( false );

		$product = $this->make_product( [
			'attributes' => [ 'pa_color' => $attribute ],
		] );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayNotHasKey( 'color', $result );
	}

	public function test_whitespace_only_core_attribute_value_is_skipped(): void {
		// Defensive: `get_attribute()` returning whitespace-only string
		// shouldn't emit `color: "   "`.
		$product = $this->make_product_with_attr( 'pa_color', '   ' );

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertArrayNotHasKey( 'color', $result );
	}

	// ------------------------------------------------------------------
	// Shipping + returns
	// ------------------------------------------------------------------

	public function test_shipping_details_include_store_country(): void {
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => 'GB' ]
		);

		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$product
		);

		// shippingDetails moved from Product → Offer level in PR-C
		// (Schema.org / Google preferred placement).
		$this->assertEquals(
			'GB',
			$result['offers'][0]['shippingDetails']['shippingDestination']['addressCountry']
		);
	}

	public function test_return_policy_is_declared(): void {
		// hasMerchantReturnPolicy emission is settings-driven and lives at
		// the Offer level. Default mode `unconfigured` emits no block;
		// switch to `details`/`returns_accepted` to assert presence.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => [
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
			],
		];

		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$product
		);

		$this->assertArrayHasKey( 'hasMerchantReturnPolicy', $result['offers'][0] );
		$this->assertEquals(
			'MerchantReturnPolicy',
			$result['offers'][0]['hasMerchantReturnPolicy']['@type']
		);
	}

	public function test_shipping_and_return_omitted_when_base_country_missing(): void {
		// Fresh WC installs before the store wizard is run can return
		// an empty country. Don't emit broken shippingDetails.
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => '' ]
		);

		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$product
		);

		$this->assertArrayNotHasKey( 'shippingDetails', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $result['offers'][0] );
	}

	public function test_shipping_details_omitted_for_virtual_product(): void {
		// A virtual / downloadable product has no shipping, so it must not
		// advertise shippingDetails (or the nested handlingTime) even though
		// the store country IS set. Mirrors the products feed's
		// `requires_shipping => needs_shipping()` gate (#504).
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'GB' ] );

		$product = $this->make_product( [ 'needs_shipping' => false ] );
		$result  = $this->jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$product
		);

		$this->assertArrayNotHasKey( 'shippingDetails', $result['offers'][0] );
	}

	public function test_shipping_details_present_for_physical_product(): void {
		// Counterpart guard: a physical product (needs_shipping() === true,
		// the make_product default) keeps its shippingDetails.
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'GB' ] );

		$product = $this->make_product( [ 'needs_shipping' => true ] );
		$result  = $this->jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$product
		);

		$this->assertArrayHasKey( 'shippingDetails', $result['offers'][0] );
	}

	public function test_variant_shipping_details_omitted_for_virtual_variation(): void {
		// The per-variant path gates on the VARIATION's needs_shipping(): a
		// virtual variation gets no shippingDetails on its entry, even though
		// invoke_build_variant_entry() passes a non-empty country ('US').
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$variation = $this->make_variation( [ 'id' => 101, 'needs_shipping' => false ] );
		$parent    = $this->make_product();

		$entry = $this->invoke_build_variant_entry( $variation, $parent );

		$this->assertArrayNotHasKey( 'shippingDetails', $entry['offers'][0] );
	}

	// ------------------------------------------------------------------
	// Shipping rate — free shipping detection
	// ------------------------------------------------------------------

	/**
	 * Build a mock WC_Shipping_Zone covering the given country codes.
	 * Pass an empty array for a Rest-of-World zone (no location restrictions).
	 *
	 * @param string[] $country_codes
	 * @param object[] $methods       Shipping method mocks returned by get_shipping_methods(true).
	 */
	private function make_zone( array $country_codes, array $methods ): Mockery\MockInterface {
		$locations = array_map(
			static function ( string $code ) {
				$loc       = new stdClass();
				$loc->type = 'country';
				$loc->code = $code;
				return $loc;
			},
			$country_codes
		);

		$zone = Mockery::mock( 'WC_Shipping_Zone' );
		$zone->shouldReceive( 'get_zone_locations' )->andReturn( $locations );
		$zone->shouldReceive( 'get_shipping_methods' )->with( true )->andReturn( $methods );
		return $zone;
	}

	/** Build a mock free-shipping method. */
	private function make_free_method( string $requires = '' ): Mockery\MockInterface {
		$method           = Mockery::mock( 'WC_Shipping_Free_Shipping' );
		$method->requires = $requires;
		return $method;
	}

	/**
	 * Thin subclass that replaces get_shipping_zones() with a controlled
	 * return so tests don't need WC_Shipping_Zones static infrastructure.
	 */
	private function make_jsonld_with_zones( array $zones ): WC_AI_Storefront_JsonLd {
		return new class( $zones ) extends WC_AI_Storefront_JsonLd {
			public function __construct( private array $stub_zones ) {}
			protected function get_shipping_zones(): array {
				return $this->stub_zones;
			}
		};
	}

	public function test_get_shipping_zones_returns_zone_objects_not_data_arrays(): void {
		// Regression guard for the get_zones() vs get_shipping_zones() mix-up.
		// WC_Shipping_Zones::get_zones() returns data arrays; only
		// get_shipping_zones() returns WC_Shipping_Zone objects. If the
		// production method ever regresses to get_zones(), every normal zone
		// will fail the instanceof check and free-shipping detection silently
		// breaks for all non-RoW zones.
		//
		// We inject two WC_Shipping_Zone mocks into the stub's $test_zones
		// (keyed by id — the exact shape WC_Shipping_Zones::get_shipping_zones()
		// returns in production) and call the REAL production get_shipping_zones()
		// method. The test therefore catches any regression in the production
		// code, not just in a mirrored copy of it.
		$zone_42 = Mockery::mock( 'WC_Shipping_Zone' );
		$zone_99 = Mockery::mock( 'WC_Shipping_Zone' );

		WC_Shipping_Zones::$test_zones = array( 42 => $zone_42, 99 => $zone_99 );

		// Expose the protected method for direct assertion without overriding it.
		$jsonld = new class extends WC_AI_Storefront_JsonLd {
			public function call_get_shipping_zones(): array {
				return $this->get_shipping_zones();
			}
		};

		$result = $jsonld->call_get_shipping_zones();

		$this->assertCount( 3, $result, 'Two named zones plus RoW' );
		$this->assertSame( $zone_42, $result[0] );
		$this->assertSame( $zone_99, $result[1] );
		$this->assertInstanceOf( WC_Shipping_Zone::class, $result[2], 'Last entry must be the RoW WC_Shipping_Zone(0)' );
		// If get_zones() had been used instead, $result[0] and $result[1] would
		// be plain arrays — this assertion catches that regression.
		$this->assertInstanceOf( WC_Shipping_Zone::class, $result[0] );
		$this->assertInstanceOf( WC_Shipping_Zone::class, $result[1] );
	}

	public function test_shipping_rate_zero_emitted_when_unconditional_free_shipping_exists(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$zone   = $this->make_zone( [ 'US' ], [ $this->make_free_method( '' ) ] );
		$jsonld = $this->make_jsonld_with_zones( [ $zone ] );

		$result = $jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$this->make_product()
		);

		$rate = $result['offers'][0]['shippingDetails']['shippingRate'];
		$this->assertEquals( 'MonetaryAmount', $rate['@type'] );
		$this->assertSame( 0, $rate['value'] );
		$this->assertEquals( 'USD', $rate['currency'] );
	}

	public function test_shipping_rate_omitted_when_only_threshold_free_shipping_exists(): void {
		// requires: 'min_amount' = free above a spend threshold, not unconditionally free.
		$zone   = $this->make_zone( [ 'US' ], [ $this->make_free_method( 'min_amount' ) ] );
		$jsonld = $this->make_jsonld_with_zones( [ $zone ] );

		$result = $jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$this->make_product()
		);

		$this->assertArrayNotHasKey( 'shippingRate', $result['offers'][0]['shippingDetails'] );
		// DefinedRegion must still be present.
		$this->assertEquals( 'US', $result['offers'][0]['shippingDetails']['shippingDestination']['addressCountry'] );
	}

	public function test_shipping_rate_omitted_when_no_shipping_zones(): void {
		$jsonld = $this->make_jsonld_with_zones( [] );

		$result = $jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$this->make_product()
		);

		$this->assertArrayNotHasKey( 'shippingRate', $result['offers'][0]['shippingDetails'] );
		$this->assertEquals( 'US', $result['offers'][0]['shippingDetails']['shippingDestination']['addressCountry'] );
	}

	public function test_shipping_rate_omitted_when_no_zone_covers_store_country(): void {
		// Zone covers CA only; store is US.
		$zone   = $this->make_zone( [ 'CA' ], [ $this->make_free_method( '' ) ] );
		$jsonld = $this->make_jsonld_with_zones( [ $zone ] );

		$result = $jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$this->make_product()
		);

		$this->assertArrayNotHasKey( 'shippingRate', $result['offers'][0]['shippingDetails'] );
	}

	public function test_row_zone_covers_any_country(): void {
		// Empty locations = Rest of World — matches any country.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );

		$zone = Mockery::mock( 'WC_Shipping_Zone' );
		$zone->shouldReceive( 'get_zone_locations' )->andReturn( [] );
		$zone->shouldReceive( 'get_shipping_methods' )->with( true )->andReturn( [ $this->make_free_method( '' ) ] );

		$jsonld = $this->make_jsonld_with_zones( [ $zone ] );

		$result = $jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$this->make_product()
		);

		$rate = $result['offers'][0]['shippingDetails']['shippingRate'];
		$this->assertSame( 0, $rate['value'] );
		$this->assertEquals( 'EUR', $rate['currency'] );
	}

	public function test_shipping_rate_omitted_when_method_is_not_free_shipping_type(): void {
		// A flat-rate method in a matching zone must not trigger the free-shipping
		// rate — only WC_Shipping_Free_Shipping instances qualify.
		$flat_rate           = Mockery::mock( 'WC_Shipping_Flat_Rate' );
		$flat_rate->requires = '';
		$zone                = $this->make_zone( [ 'US' ], [ $flat_rate ] );
		$jsonld              = $this->make_jsonld_with_zones( [ $zone ] );

		$result = $jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$this->make_product()
		);

		$this->assertArrayNotHasKey( 'shippingRate', $result['offers'][0]['shippingDetails'] );
	}

	/**
	 * @dataProvider requires_values_that_prevent_free_rate
	 */
	public function test_shipping_rate_omitted_when_requires_is_not_empty( string $requires ): void {
		$zone   = $this->make_zone( [ 'US' ], [ $this->make_free_method( $requires ) ] );
		$jsonld = $this->make_jsonld_with_zones( [ $zone ] );

		$result = $jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$this->make_product()
		);

		$this->assertArrayNotHasKey( 'shippingRate', $result['offers'][0]['shippingDetails'] );
	}

	public static function requires_values_that_prevent_free_rate(): array {
		return array(
			'coupon' => array( 'coupon' ),
			'either' => array( 'either' ),
			'both'   => array( 'both' ),
		);
	}

	public function test_shipping_rate_cache_stores_negative_result(): void {
		// A zone that covers US but has only conditional free shipping must write
		// false into the cache so a second call does NOT re-walk the zones.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$zone       = Mockery::mock( 'WC_Shipping_Zone' );
		$call_count = 0;
		$zone->shouldReceive( 'get_zone_locations' )->andReturnUsing(
			static function () use ( &$call_count ) {
				++$call_count;
				$loc       = new stdClass();
				$loc->type = 'country';
				$loc->code = 'US';
				return [ $loc ];
			}
		);
		$zone->shouldReceive( 'get_shipping_methods' )->with( true )
			->andReturn( [ $this->make_free_method( 'min_amount' ) ] );

		$jsonld  = $this->make_jsonld_with_zones( [ $zone ] );
		$product = $this->make_product();
		$input   = [ 'offers' => [ [ '@type' => 'Offer' ] ] ];

		$jsonld->enhance_product_data( $input, $product );
		$jsonld->enhance_product_data( $input, $product );

		$this->assertSame( 1, $call_count, 'Negative result must be cached — zone walk must not repeat' );
	}

	public function test_non_zone_entry_in_zone_list_is_skipped(): void {
		// get_shipping_zones() could return non-WC_Shipping_Zone values
		// (e.g. raw associative arrays from WC_Shipping_Zones::get_zones()).
		// The implementation guards with instanceof — verify no crash and no rate.
		$not_a_zone = [ 'id' => 1, 'zone_name' => 'Test' ];
		$jsonld     = $this->make_jsonld_with_zones( [ $not_a_zone ] );

		$result = $jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$this->make_product()
		);

		$this->assertArrayNotHasKey( 'shippingRate', $result['offers'][0]['shippingDetails'] );
	}

	public function test_zone_with_state_location_type_does_not_match_country(): void {
		// A zone whose only location is a state entry must NOT be treated as
		// covering the whole country — zone_covers_country checks type === 'country'.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$loc       = new stdClass();
		$loc->type = 'state';
		$loc->code = 'US:NY';

		$zone = Mockery::mock( 'WC_Shipping_Zone' );
		$zone->shouldReceive( 'get_zone_locations' )->andReturn( [ $loc ] );
		$zone->shouldReceive( 'get_shipping_methods' )->with( true )
			->andReturn( [ $this->make_free_method( '' ) ] );

		$jsonld = $this->make_jsonld_with_zones( [ $zone ] );

		$result = $jsonld->enhance_product_data(
			[ 'offers' => [ [ '@type' => 'Offer' ] ] ],
			$this->make_product()
		);

		$this->assertArrayNotHasKey( 'shippingRate', $result['offers'][0]['shippingDetails'] );
	}

	public function test_shipping_rate_cache_avoids_redundant_zone_walk(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$zone       = Mockery::mock( 'WC_Shipping_Zone' );
		$call_count = 0;
		$zone->shouldReceive( 'get_zone_locations' )->andReturnUsing(
			static function () use ( &$call_count ) {
				++$call_count;
				$loc       = new stdClass();
				$loc->type = 'country';
				$loc->code = 'US';
				return [ $loc ];
			}
		);
		$zone->shouldReceive( 'get_shipping_methods' )->with( true )->andReturn( [ $this->make_free_method( '' ) ] );

		$jsonld  = $this->make_jsonld_with_zones( [ $zone ] );
		$product = $this->make_product();
		$input   = [ 'offers' => [ [ '@type' => 'Offer' ] ] ];

		$jsonld->enhance_product_data( $input, $product );
		$jsonld->enhance_product_data( $input, $product );
		$jsonld->enhance_product_data( $input, $product );

		$this->assertSame( 1, $call_count, 'Zone walk must be cached after the first call for the same country' );
	}

	// ------------------------------------------------------------------
	// Filter extensibility
	// ------------------------------------------------------------------

	public function test_enhanced_markup_is_filterable(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $markup, $product, $settings ) {
				if ( 'wc_ai_storefront_jsonld_product' === $hook ) {
					$markup['custom_field'] = 'extension_value';
				}
				return $markup;
			}
		);

		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data( [], $product );

		$this->assertEquals( 'extension_value', $result['custom_field'] );
	}

	public function test_jsonld_product_filter_receives_safe_settings_subset(): void {
		// M-4: the wc_ai_storefront_jsonld_product filter must pass a
		// minimal settings subset — not the full settings array —
		// so third-party callbacks cannot read security-sensitive fields
		// like rate_limit_rpm, allowed_crawlers, or allow_unknown_ucp_agents.
		// Pin the exact key set so a regression that passes the full
		// array is caught immediately.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => [ 'mode' => 'unconfigured' ],
			'rate_limit_rpm'         => 99,          // Must NOT reach the filter.
			'allowed_crawlers'       => [ 'ChatGPT-User' ],  // Must NOT reach the filter.
			'allow_unknown_ucp_agents' => 'yes',     // Must NOT reach the filter.
		];

		$captured_subset = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $markup, $product, $subset ) use ( &$captured_subset ) {
				if ( 'wc_ai_storefront_jsonld_product' === $hook ) {
					$captured_subset = $subset;
				}
				return $markup;
			}
		);

		$this->jsonld->enhance_product_data( [], $this->make_product() );

		$this->assertIsArray( $captured_subset, 'Filter must fire and pass a settings subset.' );

		// Keys that MUST be present.
		$this->assertArrayHasKey( 'enabled', $captured_subset );
		$this->assertArrayHasKey( 'product_selection_mode', $captured_subset );
		$this->assertArrayHasKey( 'return_policy', $captured_subset );

		// Security-sensitive keys that MUST NOT be present.
		$this->assertArrayNotHasKey( 'rate_limit_rpm', $captured_subset );
		$this->assertArrayNotHasKey( 'allowed_crawlers', $captured_subset );
		$this->assertArrayNotHasKey( 'allow_unknown_ucp_agents', $captured_subset );
		$this->assertArrayNotHasKey( 'selected_products', $captured_subset );
	}

	public function test_jsonld_store_filter_receives_safe_settings_subset(): void {
		// Mirror of the above for the wc_ai_storefront_jsonld_store filter.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => [ 'mode' => 'unconfigured' ],
			'rate_limit_rpm'         => 99,
			'allowed_crawlers'       => [ 'ChatGPT-User' ],
			'allow_unknown_ucp_agents' => 'yes',
		];

		$captured_subset = null;
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.com' . $p );
		Functions\when( 'get_bloginfo' )->returnArg();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_terms' )->justReturn( [] );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value, ...$rest ) use ( &$captured_subset ) {
				if ( 'wc_ai_storefront_jsonld_store' === $tag ) {
					$captured_subset = $rest[0] ?? null;
				}
				return $value;
			}
		);

		ob_start();
		try {
			$this->jsonld->output_store_jsonld();
		} finally {
			ob_end_clean();
		}

		$this->assertIsArray( $captured_subset, 'Store filter must fire and pass a settings subset.' );
		$this->assertArrayHasKey( 'enabled', $captured_subset );
		$this->assertArrayHasKey( 'product_selection_mode', $captured_subset );
		$this->assertArrayHasKey( 'return_policy', $captured_subset );
		$this->assertArrayNotHasKey( 'rate_limit_rpm', $captured_subset );
		$this->assertArrayNotHasKey( 'allowed_crawlers', $captured_subset );
		$this->assertArrayNotHasKey( 'allow_unknown_ucp_agents', $captured_subset );
	}

	// ------------------------------------------------------------------
	// Unit code instance caching (#170)
	// ------------------------------------------------------------------

	public function test_weight_unit_code_calls_get_option_only_once_across_multiple_products(): void {
		// Regression guard for issue #170: get_option() must be called at
		// most once per instance regardless of how many products are processed.
		$call_count = 0;
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = '' ) use ( &$call_count ) {
				if ( 'woocommerce_weight_unit' === $key ) {
					++$call_count;
					return 'kg';
				}
				if ( 'woocommerce_dimension_unit' === $key ) {
					return 'cm';
				}
				return $default;
			}
		);

		$product = $this->make_product( [ 'has_weight' => true, 'weight' => '1' ] );

		// Call enhance_product_data three times on the same instance
		// — as would happen on a shop archive page with multiple products.
		$this->jsonld->enhance_product_data( array(), $product );
		$this->jsonld->enhance_product_data( array(), $product );
		$this->jsonld->enhance_product_data( array(), $product );

		$this->assertSame(
			1,
			$call_count,
			'get_option(woocommerce_weight_unit) must be called exactly once per instance (instance cache)'
		);
	}

	public function test_dimension_unit_code_calls_get_option_only_once_across_multiple_products(): void {
		// Mirror of the weight test for dimension unit.
		$call_count = 0;
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = '' ) use ( &$call_count ) {
				if ( 'woocommerce_weight_unit' === $key ) {
					return 'kg';
				}
				if ( 'woocommerce_dimension_unit' === $key ) {
					++$call_count;
					return 'cm';
				}
				return $default;
			}
		);

		$product = $this->make_product(
			array(
				'has_dimensions' => true,
				'dimensions'     => array( 'length' => '10', 'width' => '5', 'height' => '3' ),
			)
		);

		$this->jsonld->enhance_product_data( array(), $product );
		$this->jsonld->enhance_product_data( array(), $product );
		$this->jsonld->enhance_product_data( array(), $product );

		$this->assertSame(
			1,
			$call_count,
			'get_option(woocommerce_dimension_unit) must be called exactly once per instance (instance cache)'
		);
	}

	// ------------------------------------------------------------------
	// Catalog summary transient caching (#167)
	// ------------------------------------------------------------------

	public function test_catalog_summary_is_served_from_transient_cache_on_second_call(): void {
		// Regression guard for issue #167: get_catalog_summary() must
		// return the cached value on a second invocation without calling
		// get_terms() again.
		$get_terms_call_count = 0;

		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.com' . $p );
		Functions\when( 'get_bloginfo' )->returnArg();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// First call: cache miss, runs get_terms().
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_terms' )->alias(
			static function () use ( &$get_terms_call_count ) {
				++$get_terms_call_count;
				return array(); // Empty catalog for simplicity.
			}
		);

		ob_start();
		try {
			$this->jsonld->output_store_jsonld();
		} finally {
			ob_end_clean();
		}

		$this->assertSame( 1, $get_terms_call_count, 'get_terms() must be called on cache miss' );
	}

	public function test_catalog_summary_stores_result_in_transient(): void {
		// Verify that after a cache miss the result is written via
		// set_transient() so the next request gets a cache hit.
		$set_transient_called = false;
		$set_key              = null;

		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.com' . $p );
		Functions\when( 'get_bloginfo' )->returnArg();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false ); // Cache miss.
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $ttl ) use ( &$set_transient_called, &$set_key ) {
				$set_transient_called = true;
				$set_key              = $key;
				return true;
			}
		);

		ob_start();
		try {
			$this->jsonld->output_store_jsonld();
		} finally {
			ob_end_clean();
		}

		$this->assertTrue( $set_transient_called, 'set_transient() must be called after a cache miss' );
		$this->assertSame( 'wc_ai_storefront_catalog_summary', $set_key );
	}

	// ------------------------------------------------------------------
	// Handling time — ShippingDeliveryTime emission
	// ------------------------------------------------------------------

	private function make_product_with_shipping(): Mockery\MockInterface {
		return $this->make_product( [ 'id' => 42 ] );
	}

	private function base_markup(): array {
		return [
			'@type'  => 'Product',
			'offers' => [
				[
					'@type' => 'Offer',
					'price' => '9.99',
				],
			],
		];
	}

	public function test_handling_time_emitted_when_both_min_and_max_set(): void {
		WC_AI_Storefront::$test_settings = [
			'enabled'       => 'yes',
			'handling_time' => [ 'min' => 1, 'max' => 3 ],
		];
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$product = $this->make_product_with_shipping();
		$result  = $this->jsonld->enhance_product_data( $this->base_markup(), $product );

		$delivery = $result['offers'][0]['shippingDetails']['deliveryTime'] ?? null;
		$this->assertNotNull( $delivery, 'deliveryTime must be present when handling_time is set' );
		$this->assertSame( 'ShippingDeliveryTime', $delivery['@type'] );

		$ht = $delivery['handlingTime'];
		$this->assertSame( 'QuantitativeValue', $ht['@type'] );
		$this->assertSame( 1, $ht['minValue'] );
		$this->assertSame( 3, $ht['maxValue'] );
		$this->assertSame( 'DAY', $ht['unitCode'] );
	}

	public function test_handling_time_omitted_when_min_is_zero(): void {
		WC_AI_Storefront::$test_settings = [
			'enabled'       => 'yes',
			'handling_time' => [ 'min' => 0, 'max' => 3 ],
		];
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$product = $this->make_product_with_shipping();
		$result  = $this->jsonld->enhance_product_data( $this->base_markup(), $product );

		$this->assertArrayNotHasKey(
			'deliveryTime',
			$result['offers'][0]['shippingDetails'] ?? []
		);
	}

	public function test_handling_time_omitted_when_max_is_zero(): void {
		WC_AI_Storefront::$test_settings = [
			'enabled'       => 'yes',
			'handling_time' => [ 'min' => 2, 'max' => 0 ],
		];
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$product = $this->make_product_with_shipping();
		$result  = $this->jsonld->enhance_product_data( $this->base_markup(), $product );

		$this->assertArrayNotHasKey(
			'deliveryTime',
			$result['offers'][0]['shippingDetails'] ?? []
		);
	}

	public function test_handling_time_omitted_when_setting_absent(): void {
		// handling_time key entirely absent from settings.
		WC_AI_Storefront::$test_settings = [
			'enabled' => 'yes',
		];
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$product = $this->make_product_with_shipping();
		$result  = $this->jsonld->enhance_product_data( $this->base_markup(), $product );

		$this->assertArrayNotHasKey(
			'deliveryTime',
			$result['offers'][0]['shippingDetails'] ?? []
		);
	}

	public function test_handling_time_omitted_when_no_shipping_details_block(): void {
		// No offers[0] in markup — shippingDetails is never added, so
		// handling time has nowhere to attach.
		WC_AI_Storefront::$test_settings = [
			'enabled'       => 'yes',
			'handling_time' => [ 'min' => 1, 'max' => 2 ],
		];
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		// Suppress base-location return so no shippingDetails block is placed.
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => '', 'state' => '' ] );

		$product = $this->make_product_with_shipping();
		$result  = $this->jsonld->enhance_product_data( [ '@type' => 'Product' ], $product );

		$this->assertArrayNotHasKey( 'shippingDetails', $result['offers'][0] ?? [] );
	}

	public function test_handling_time_omitted_when_stored_pair_is_invalid(): void {
		// Simulates a DB row written by a filter or direct WP option update
		// that bypassed WC_AI_Storefront_Handling_Time::sanitize(), leaving
		// min > max in storage. The emitter must not publish a structurally
		// invalid Schema.org QuantitativeValue block.
		WC_AI_Storefront::$test_settings = [
			'enabled'       => 'yes',
			'handling_time' => [ 'min' => 5, 'max' => 2 ],
		];
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$product = $this->make_product_with_shipping();
		$result  = $this->jsonld->enhance_product_data( $this->base_markup(), $product );

		$this->assertArrayNotHasKey(
			'handlingTime',
			$result['offers'][0]['shippingDetails']['deliveryTime'] ?? [],
			'Emitter must skip handlingTime block when stored min > max.'
		);
	}

	// ------------------------------------------------------------------
	// OnlineStore identity fields (homepage / shop page JSON-LD)
	//
	// `output_store_jsonld()` emits Schema.org `OnlineStore` (an
	// `Organization` subtype) so AI-readiness audits that look for
	// brand-identity entities can verify the merchant. Identity fields
	// are auto-sourced from existing WP/WC data — no plugin-owned
	// merchant settings, no admin UI:
	//
	//   - `logo` — custom-logo theme mod, with site-icon as fallback
	//   - `address` — `WC()->countries->get_base_*` (Schema.org
	//     PostalAddress); streetAddress is NEVER emitted (privacy guard
	//     against publishing residential addresses)
	//   - `contactPoint.email` — two-stage resolver:
	//       1. `woocommerce_email_reply_to_address` when
	//          `woocommerce_email_reply_to_enabled === 'yes'` (WC's
	//          purpose-built customer-reply field)
	//       2. `woocommerce_email_from_address` as fallback, but
	//          rejected when its local-part starts with a noreply
	//          pattern (`noreply`, `no-reply`, `donotreply`,
	//          `do-not-reply`), with optional `+tag` suffix
	//     Each candidate validated via `is_email`. `admin_email` is
	//     intentionally NOT a fallback (privacy: it's used for
	//     password resets and security notifications).
	//
	// The filter `wc_ai_storefront_jsonld_store` is the documented
	// injection point for ecosystem plugins (Jetpack, Yoast) that
	// already capture social profiles or phone — those are NOT emitted
	// from the plugin itself.
	// ------------------------------------------------------------------

	/**
	 * Capture the array passed through `wc_ai_storefront_jsonld_store`
	 * during a call to `output_store_jsonld()`.
	 *
	 * Mirrors the pattern in `test_searchaction_url_template_is_bare()`:
	 * intercept the filter (the value is fully assembled when the
	 * filter fires, so identity fields are visible there) and let
	 * `output_store_jsonld()` echo into a buffer we discard.
	 *
	 * The optional `$emitter` argument lets identity tests inject a
	 * subclass that overrides `build_postal_address()` with a fixture,
	 * avoiding a global `WC()` stub (which Brain Monkey's strict mode
	 * would leak into unrelated tests across the suite).
	 */
	private function capture_store_jsonld_filter_value( ?WC_AI_Storefront_JsonLd $emitter = null ): ?array {
		$this->stub_store_jsonld_environment();
		Functions\when( 'get_terms' )->justReturn( [] );
		return $this->run_store_jsonld_capture( $emitter );
	}

	/**
	 * Set up the WP/WC stubs `output_store_jsonld()` reads, EXCEPT for
	 * `get_terms` (which drives `get_catalog_summary()`) — leaving
	 * that to per-test overrides for tests that need non-empty
	 * catalog data. Tests can call `stub_store_jsonld_environment()`
	 * directly, set their own `get_terms` / `get_term_link` /
	 * `get_transient` stubs in any order, and finish with
	 * `run_store_jsonld_capture()`.
	 */
	private function stub_store_jsonld_environment(): void {
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
		Functions\when( 'get_bloginfo' )->returnArg();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->returnArg( 1 );
	}

	/**
	 * Run `output_store_jsonld()` with the `apply_filters` capture
	 * hook installed, return the structured-data array as it appeared
	 * to the `wc_ai_storefront_jsonld_store` filter callback.
	 *
	 * Caller must have already invoked `stub_store_jsonld_environment()`
	 * (or `capture_store_jsonld_filter_value()`'s defaults) so the
	 * WP/WC stubs are in place.
	 */
	private function run_store_jsonld_capture( ?WC_AI_Storefront_JsonLd $emitter = null ): ?array {
		$emitter = $emitter ?? $this->jsonld;

		$captured = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value, ...$extras ) use ( &$captured ) {
				if ( $tag === 'wc_ai_storefront_jsonld_store' ) {
					$captured = $value;
				}
				return $value;
			}
		);

		ob_start();
		try {
			$emitter->output_store_jsonld();
		} finally {
			ob_end_clean();
		}

		return $captured;
	}

	/**
	 * Build a JsonLd subclass that overrides `build_postal_address()`
	 * with a fixed return value. Avoids the need to globally stub
	 * `WC()` (which Brain Monkey strict mode would leak across the
	 * suite). Use this when the test cares about how the *emitter*
	 * handles a specific PostalAddress shape, not how the address
	 * itself is built.
	 */
	private function jsonld_with_address( array $address ): WC_AI_Storefront_JsonLd {
		// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- inline test fixture
		return new class( $address ) extends WC_AI_Storefront_JsonLd {
			private array $fixture;

			public function __construct( array $fixture ) {
				$this->fixture = $fixture;
			}

			public function build_postal_address(): array {
				return $this->fixture;
			}
		};
	}

	/**
	 * Build a JsonLd subclass that injects a `WC_Countries`-shaped
	 * stub (an object exposing the `get_base_*()` methods
	 * `build_postal_address()` reads). Use this when the test cares
	 * about how `build_postal_address()` itself transforms WC data
	 * — e.g., the streetAddress-suppression privacy guard. Stub
	 * methods can return arbitrary strings; even if WC has a value
	 * for a field we deliberately don't emit, the stub method gets
	 * called only to verify the omission.
	 */
	private function jsonld_with_wc_countries( object $countries ): WC_AI_Storefront_JsonLd {
		// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- inline test fixture
		return new class( $countries ) extends WC_AI_Storefront_JsonLd {
			private object $countries;

			public function __construct( object $countries ) {
				$this->countries = $countries;
			}

			protected function get_wc_countries() {
				return $this->countries;
			}
		};
	}

	public function test_store_jsonld_uses_onlinebusiness_type(): void {
		// `OnlineBusiness` (the parent of `OnlineStore` in the
		// `Thing → Organization → OnlineBusiness → OnlineStore` chain)
		// replaced `OnlineStore` in #334. The wider type covers WC's
		// full install base — services, subscriptions, donations,
		// lead-gen, digital downloads, and traditional retail — without
		// claiming product retail. Regression guard: a revert to
		// `OnlineStore` would fail here.
		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertIsArray( $captured );
		$this->assertSame( 'OnlineBusiness', $captured['@type'] ?? null );
	}

	// ------------------------------------------------------------------
	// knowsAbout — Schema.org Organization "what this org knows about"
	// pointer, sourced from get_catalog_summary() top category names.
	// (#334)
	// ------------------------------------------------------------------

	public function test_store_jsonld_emits_knows_about_from_catalog_summary(): void {
		// `knowsAbout` reuses the cached `get_catalog_summary()` data —
		// no new query — and emits a Text[] of category names. Drives
		// `get_terms` directly to seed two categories. Uses the split
		// helper pair (`stub_store_jsonld_environment` + override +
		// `run_store_jsonld_capture`) instead of the all-in-one
		// `capture_store_jsonld_filter_value` because the latter
		// stubs `get_terms` to [] AFTER any earlier override would
		// land — Brain Monkey's last-call-wins clobbers it.
		$this->stub_store_jsonld_environment();
		Functions\when( 'get_terms' )->justReturn(
			array(
				(object) array( 'term_id' => 11, 'name' => 'Clothing', 'count' => 10 ),
				(object) array( 'term_id' => 12, 'name' => 'Hoodies',  'count' => 4 ),
			)
		);
		Functions\when( 'get_term_link' )->alias(
			static fn( $term ) => 'https://example.com/category/' . strtolower( $term->name ) . '/'
		);

		$captured = $this->run_store_jsonld_capture();

		$this->assertSame(
			array( 'Clothing', 'Hoodies' ),
			$captured['knowsAbout'] ?? null
		);
	}

	public function test_store_jsonld_omits_knows_about_when_catalog_is_empty(): void {
		// Default `get_terms` stub in `capture_store_jsonld_filter_value()`
		// returns []. Don't claim the org "knows about" nothing — omit
		// the key entirely.
		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertArrayNotHasKey( 'knowsAbout', $captured ?? array() );
	}

	public function test_store_jsonld_omits_knows_about_when_transient_is_corrupted(): void {
		// Defensive guard: `get_catalog_summary()` reads via
		// `get_transient()`, which can in principle hand back a
		// non-array value if the cache was corrupted by external
		// code or holds a stale value from a prior schema. Calling
		// `array_column()` on a non-array would TypeError under
		// PHP 8.1+. The `is_array($catalog)` guard at the call site
		// must convert that into "skip emission" — same shape as
		// the empty-catalog case.
		$this->stub_store_jsonld_environment();
		// Hand the call site a corrupted transient: a string, not
		// an array. Bypasses `get_terms` entirely because
		// `get_catalog_summary()` returns `$cached` early when
		// `false !== $cached`.
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) {
				return 'wc_ai_storefront_catalog_summary' === $key
					? 'corrupted-stale-string-from-prior-schema'
					: false;
			}
		);

		$captured = $this->run_store_jsonld_capture();

		$this->assertArrayNotHasKey( 'knowsAbout', $captured ?? array() );
	}

	public function test_store_jsonld_calls_get_catalog_summary_only_once_per_render(): void {
		// Refactor regression guard: `hasOfferCatalog.itemListElement`
		// AND `knowsAbout` both consume the catalog summary. Pre-#334
		// the call was inlined inside the array literal; the refactor
		// hoisted it to a local variable. Confirm the transient cache
		// is consulted exactly once per page render — the wrapper that
		// drives `get_catalog_summary()` reads `get_transient`, so we
		// count those reads as a proxy for catalog-summary
		// invocations.
		$this->stub_store_jsonld_environment();
		$transient_calls = 0;
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$transient_calls ) {
				if ( 'wc_ai_storefront_catalog_summary' === $key ) {
					++$transient_calls;
				}
				return false;  // miss → triggers get_terms() on each call
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_terms' )->justReturn(
			array( (object) array( 'term_id' => 1, 'name' => 'X', 'count' => 1 ) )
		);
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/x/' );

		$this->run_store_jsonld_capture();

		$this->assertSame( 1, $transient_calls, 'get_catalog_summary() must run exactly once per page render.' );
	}

	// ------------------------------------------------------------------
	// Organization-level hasMerchantReturnPolicy — homepage emission
	// (#337 phase 1). Reuses build_return_policy_block(), so the block
	// shape matches per-Offer emission for the same configuration.
	// ------------------------------------------------------------------

	public function test_store_jsonld_emits_org_level_return_policy_when_configured(): void {
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => array(
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'free',
			),
		);
		Functions\when( 'wc_get_base_location' )->justReturn(
			array( 'country' => 'US' )
		);

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertArrayHasKey( 'hasMerchantReturnPolicy', $captured );
		$this->assertSame(
			'MerchantReturnPolicy',
			$captured['hasMerchantReturnPolicy']['@type']
		);
		$this->assertSame( 'US', $captured['hasMerchantReturnPolicy']['applicableCountry'] );
		$this->assertSame( 30, $captured['hasMerchantReturnPolicy']['merchantReturnDays'] );
	}

	public function test_store_jsonld_omits_org_level_return_policy_when_unconfigured(): void {
		// `mode: unconfigured` is the no-policy state. Don't emit a
		// MerchantReturnPolicy claiming nothing — Schema.org
		// consumers reading the absence of the key correctly
		// interpret "no public commitment."
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => array( 'mode' => 'unconfigured' ),
		);
		Functions\when( 'wc_get_base_location' )->justReturn(
			array( 'country' => 'US' )
		);

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $captured ?? array() );
	}

	public function test_store_jsonld_omits_org_level_return_policy_when_setting_missing(): void {
		// `return_policy` key entirely absent from settings — same
		// null-policy posture as `mode: unconfigured`. The defensive
		// fallback in `output_store_jsonld()` should normalize to
		// `mode: unconfigured` and skip emission.
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			// no 'return_policy' key
		);
		Functions\when( 'wc_get_base_location' )->justReturn(
			array( 'country' => 'US' )
		);

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $captured ?? array() );
	}

	public function test_org_level_and_per_offer_return_policy_blocks_are_identical_for_same_config(): void {
		// Shared-shape regression guard. The Org-level emission in
		// `output_store_jsonld()` and the per-Offer emission in
		// `add_return_policy()` (called via `enhance_product_data()`)
		// must produce identical MerchantReturnPolicy block shapes
		// for the same return-policy settings + country, so an AI
		// agent reading either surface gets the same commitment
		// claim for the common (non-final-sale) case.
		//
		// Captures both blocks from their actual call sites:
		// - Org-level: via `capture_store_jsonld_filter_value()` →
		//   `$captured['hasMerchantReturnPolicy']`
		// - Per-Offer: via `enhance_product_data()` →
		//   `$result['offers'][0]['hasMerchantReturnPolicy']`
		//
		// Both call sites consume `build_return_policy_block()`
		// (now `protected`), so the two captures should be
		// `assertSame`-equal. A future refactor that diverges the
		// two call sites' inputs (e.g. passes a different country)
		// or wraps the per-Offer block in additional fields would
		// fail this test loudly. Phase 2 of #337 — making per-Offer
		// emission conditional on the per-product final-sale
		// override — will rely on this contract holding for
		// non-final-sale products.
		$policy = array(
			'mode'     => 'details',
			'category' => 'returns_accepted',
			'days'     => 14,
			'fees'     => 'restocking',
		);
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => $policy,
		);
		Functions\when( 'wc_get_base_location' )->justReturn( array( 'country' => 'US' ) );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		// Org-level capture.
		$captured_store = $this->capture_store_jsonld_filter_value();
		$org_block      = $captured_store['hasMerchantReturnPolicy'] ?? null;

		// Per-Offer capture: run a non-final-sale product through
		// `enhance_product_data()`. `get_post_meta` is stubbed in
		// setUp to return '' (no per-product final-sale override),
		// so the per-Offer call falls through to the same
		// `build_return_policy_block($policy, 'US', $product_id)`
		// that the Org-level call site invokes with `null` for
		// `$product_id` — both produce a `returns_accepted` block
		// with the same days/fees.
		$product = $this->make_product( [ 'id' => 42 ] );
		$markup  = array( 'offers' => array( array( '@type' => 'Offer' ) ) );
		$result  = $this->jsonld->enhance_product_data( $markup, $product );
		$per_offer_block = $result['offers'][0]['hasMerchantReturnPolicy'] ?? null;

		$this->assertNotNull( $org_block, 'Org-level emission must produce a block.' );
		$this->assertNotNull( $per_offer_block, 'Per-Offer emission must produce a block.' );
		$this->assertSame(
			$org_block,
			$per_offer_block,
			'Org-level and per-Offer MerchantReturnPolicy blocks must be identical for the same config.'
		);
	}

	public function test_store_jsonld_emits_logo_from_custom_logo_theme_mod(): void {
		// Custom-logo wins over site-icon: the merchant explicitly
		// chose a brand mark for the storefront header.
		$logo_id = 4242;
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $name ) => 'custom_logo' === $name ? $logo_id : null
		);
		Functions\when( 'wp_get_attachment_image_src' )->alias(
			static fn( $id ) => $id === $logo_id ? [ 'https://example.com/wp-content/uploads/brand.png', 800, 200, false ] : false
		);
		Functions\when( 'get_site_icon_url' )->justReturn( 'https://example.com/site-icon.png' );

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertSame( 'https://example.com/wp-content/uploads/brand.png', $captured['logo'] ?? null );
	}

	public function test_store_jsonld_falls_back_to_site_icon_when_no_custom_logo(): void {
		Functions\when( 'get_site_icon_url' )->justReturn( 'https://example.com/site-icon.png' );

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertSame( 'https://example.com/site-icon.png', $captured['logo'] ?? null );
	}

	public function test_store_jsonld_omits_logo_when_neither_custom_logo_nor_site_icon_set(): void {
		// Schema.org's `logo` is for the merchant's primary brand mark.
		// Emitting nothing is more honest than emitting a default WP
		// favicon URL that would mislead crawlers about brand identity.
		// (setUp's defaults already simulate "no logo configured".)
		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertArrayNotHasKey( 'logo', $captured ?? [] );
	}

	public function test_store_jsonld_emits_postal_address_from_wc_base_settings(): void {
		// Country + city + region + postcode populated. Note:
		// streetAddress is NEVER emitted (privacy guard), so this
		// fixture intentionally omits it even at the seam layer to
		// match what `build_postal_address()` actually produces.
		// Test exercises how the emitter merges the address block
		// into the JSON-LD; coverage of the suppression itself lives
		// in `test_store_jsonld_omits_streetaddress_*`.
		$emitter = $this->jsonld_with_address(
			[
				'@type'           => 'PostalAddress',
				'addressCountry'  => 'US',
				'addressLocality' => 'Springfield',
				'addressRegion'   => 'IL',
				'postalCode'      => '62701',
			]
		);

		$captured = $this->capture_store_jsonld_filter_value( $emitter );
		$address  = $captured['address'] ?? null;

		$this->assertIsArray( $address );
		$this->assertSame( 'PostalAddress', $address['@type'] );
		$this->assertSame( 'US', $address['addressCountry'] );
		$this->assertSame( 'Springfield', $address['addressLocality'] );
		$this->assertSame( 'IL', $address['addressRegion'] );
		$this->assertSame( '62701', $address['postalCode'] );
		$this->assertArrayNotHasKey( 'streetAddress', $address );
	}

	public function test_build_postal_address_suppresses_street_address_even_when_wc_has_it(): void {
		// Privacy regression guard. Many small Woo merchants populate
		// WooCommerce > Settings > General with their home address
		// because WC requires it for tax calculations. They do NOT
		// expect that field to be published in machine-readable form
		// on the homepage. For an OnlineStore, streetAddress adds
		// little verification value (buyers don't visit) — so we
		// suppress it even when WC has the data.
		//
		// The stub returns a populated street address; the emitter
		// must drop it. A regression that re-adds the streetAddress
		// emit (intentional or accidental) would fail this test.
		$countries = new class {
			public function get_base_country() { return 'US'; }
			public function get_base_address() { return '123 Main St'; }
			public function get_base_address_2() { return 'Suite 4B'; }
			public function get_base_city() { return 'Springfield'; }
			public function get_base_state() { return 'IL'; }
			public function get_base_postcode() { return '62701'; }
		};

		$emitter = $this->jsonld_with_wc_countries( $countries );
		$captured = $this->capture_store_jsonld_filter_value( $emitter );
		$address  = $captured['address'] ?? null;

		$this->assertIsArray( $address );
		$this->assertArrayNotHasKey(
			'streetAddress',
			$address,
			'streetAddress must NEVER be emitted on OnlineStore — privacy regression.'
		);
		// Sanity check: the rest of the address still emits, so we
		// haven't broken the address block entirely.
		$this->assertSame( 'US', $address['addressCountry'] );
		$this->assertSame( 'Springfield', $address['addressLocality'] );
		$this->assertSame( 'IL', $address['addressRegion'] );
		$this->assertSame( '62701', $address['postalCode'] );
	}

	public function test_build_postal_address_omits_when_wc_has_no_country(): void {
		// Real exercise of the early-return path: WC base country is
		// blank. `build_postal_address()` returns []; the emitter
		// then omits the whole `address` key. (The handful of
		// existing tests using `jsonld_with_address([])` exercise
		// the emitter side of this; this test pins the behavior of
		// `build_postal_address()` itself when the live WC source is
		// unconfigured.)
		$countries = new class {
			public function get_base_country() { return ''; }
			public function get_base_address() { return ''; }
			public function get_base_address_2() { return ''; }
			public function get_base_city() { return ''; }
			public function get_base_state() { return ''; }
			public function get_base_postcode() { return ''; }
		};

		$emitter = $this->jsonld_with_wc_countries( $countries );
		$captured = $this->capture_store_jsonld_filter_value( $emitter );

		$this->assertArrayNotHasKey( 'address', $captured ?? [] );
	}

	public function test_store_jsonld_omits_address_when_postal_address_is_empty(): void {
		// `build_postal_address()` returns [] when WC has no base
		// country (its omit-when-empty signal). The emitter must skip
		// the `address` key entirely rather than emitting an empty
		// stub.
		$emitter = $this->jsonld_with_address( [] );

		$captured = $this->capture_store_jsonld_filter_value( $emitter );

		$this->assertArrayNotHasKey( 'address', $captured ?? [] );
	}

	/**
	 * Build a `get_option` alias that returns values from a fixture
	 * map and the option's own default for anything not in the map.
	 * Tests can express WC email-config scenarios as a small array
	 * rather than nesting ternaries inline.
	 */
	private function stub_options( array $values ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = '' ) use ( $values ) {
				return array_key_exists( $name, $values ) ? $values[ $name ] : $default;
			}
		);
	}

	public function test_store_jsonld_emits_contactpoint_from_wc_from_address_when_no_reply_to(): void {
		// Default WC posture: reply-to disabled. From address is the
		// only signal. Validated, not noreply-shaped → published as
		// the public contact email. setUp's `is_email` alias handles
		// validation; no per-test override needed.
		$this->stub_options( [
			'woocommerce_email_reply_to_enabled' => 'no',
			'woocommerce_email_from_address'     => 'support@example.com',
		] );

		$captured = $this->capture_store_jsonld_filter_value();
		$cp       = $captured['contactPoint'] ?? null;

		$this->assertIsArray( $cp );
		$this->assertSame( 'ContactPoint', $cp['@type'] );
		$this->assertSame( 'Customer Service', $cp['contactType'] );
		$this->assertSame( 'support@example.com', $cp['email'] );
	}

	public function test_store_jsonld_prefers_reply_to_address_when_enabled(): void {
		// Both From and Reply-To configured with valid, non-noreply
		// addresses. Reply-to wins because it's WC's purpose-built
		// "where customers should reach me" field, set explicitly
		// when the merchant routes replies somewhere other than From.
		//
		// From is intentionally a *valid alternative* (`support@`,
		// not `noreply@`) so a regression in the resolver — say,
		// "always return From" — would publish `support@example.com`
		// rather than fall through to omit, and the test catches it.
		// A test that used `noreply@` for From could pass even with
		// such a regression because the noreply guard would still
		// produce omit, masking the precedence bug.
		$this->stub_options( [
			'woocommerce_email_reply_to_enabled' => 'yes',
			'woocommerce_email_reply_to_address' => 'help@example.com',
			'woocommerce_email_from_address'     => 'support@example.com',
		] );

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertSame( 'help@example.com', $captured['contactPoint']['email'] ?? null );
	}

	public function test_store_jsonld_falls_back_to_from_when_reply_to_enabled_but_address_blank(): void {
		// Configuration error: enabled flag set but address never
		// filled in. The merchant intended a public contact channel
		// (they enabled reply-to), so we shouldn't omit — fall
		// through to From if it's a usable address.
		$this->stub_options( [
			'woocommerce_email_reply_to_enabled' => 'yes',
			'woocommerce_email_reply_to_address' => '',
			'woocommerce_email_from_address'     => 'support@example.com',
		] );

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertSame( 'support@example.com', $captured['contactPoint']['email'] ?? null );
	}

	public function test_store_jsonld_omits_contactpoint_when_from_is_noreply(): void {
		// Many merchants set From to noreply@ to avoid bounce-handling.
		// Publishing it as a customer-facing contact would route real
		// questions into a black hole. With reply-to disabled and the
		// only From candidate being noreply-shaped, we omit.
		$this->stub_options( [
			'woocommerce_email_reply_to_enabled' => 'no',
			'woocommerce_email_from_address'     => 'noreply@example.com',
		] );

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertArrayNotHasKey(
			'contactPoint',
			$captured ?? [],
			'noreply-shaped From must not be published as a public contact.'
		);
	}

	/**
	 * @dataProvider noreply_local_parts_provider
	 */
	public function test_store_jsonld_recognizes_common_noreply_patterns( string $local_part ): void {
		// All four canonical noreply shapes (case-insensitive,
		// hyphenated and unhyphenated). Lock the heuristic — a future
		// refactor that narrows the pattern would silently start
		// publishing one of these as a public contact.
		$this->stub_options( [
			'woocommerce_email_reply_to_enabled' => 'no',
			'woocommerce_email_from_address'     => $local_part . '@example.com',
		] );

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertArrayNotHasKey( 'contactPoint', $captured ?? [] );
	}

	public static function noreply_local_parts_provider(): array {
		return [
			'noreply'                       => [ 'noreply' ],
			'NoReply mixed case'            => [ 'NoReply' ],
			'NOREPLY upper case'            => [ 'NOREPLY' ],
			'no-reply hyphenated'           => [ 'no-reply' ],
			'No-Reply mixed case'           => [ 'No-Reply' ],
			'donotreply'                    => [ 'donotreply' ],
			'do-not-reply'                  => [ 'do-not-reply' ],
			'Do-Not-Reply'                  => [ 'Do-Not-Reply' ],
			// RFC 5233 plus-addressing variants — these route to the
			// same underlying mailbox as the bare prefix at most
			// providers (Gmail, Outlook, Postfix, etc.), so they're
			// noreply addresses for publishing purposes.
			'noreply+orders'                => [ 'noreply+orders' ],
			'no-reply+customer-service'     => [ 'no-reply+customer-service' ],
			'donotreply+tag'                => [ 'donotreply+tag' ],
			'do-not-reply+billing'          => [ 'do-not-reply+billing' ],
			'NoReply+Mixed plus-addressing' => [ 'NoReply+Mixed' ],
		];
	}

	public function test_store_jsonld_does_not_match_noreply_in_domain_part(): void {
		// `support@noreply.example.com` is a legitimate customer-service
		// mailbox that happens to be hosted on a `noreply.*` subdomain.
		// Local-part-only matching means we don't false-positive on it.
		$this->stub_options( [
			'woocommerce_email_reply_to_enabled' => 'no',
			'woocommerce_email_from_address'     => 'support@noreply.example.com',
		] );

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertSame( 'support@noreply.example.com', $captured['contactPoint']['email'] ?? null );
	}

	public function test_store_jsonld_does_not_match_noreply_substring_in_local_part(): void {
		// `noreplies@store.com` is NOT a noreply address — the
		// local-part is a different word that happens to start with
		// the same letters. We match exact prefixes (with optional
		// `+tag`), not substrings, so this should be publishable.
		// Regression guard against an over-eager refactor that switches
		// the prefix check to a `str_starts_with` substring match.
		$this->stub_options( [
			'woocommerce_email_reply_to_enabled' => 'no',
			'woocommerce_email_from_address'     => 'noreplies@store.com',
		] );

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertSame(
			'noreplies@store.com',
			$captured['contactPoint']['email'] ?? null,
			'Substring match must NOT trigger noreply guard — only exact prefix or prefix+tag.'
		);
	}

	public function test_store_jsonld_omits_contactpoint_when_both_options_empty(): void {
		// setUp's defaults exercise this case directly: `get_option`
		// returns '' for everything, and the `is_email` structural
		// validator alias rejects empty strings (no `@` to find).
		// Reply-to disabled by default, from-address blank → omit.
		// Whole block is omitted (no admin_email fallback).
		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertArrayNotHasKey( 'contactPoint', $captured ?? [] );
	}

	public function test_store_jsonld_omits_contactpoint_when_from_address_is_invalid(): void {
		// is_email rejects structurally broken values. Sentinel:
		// `gibberish` (no @, no TLD) — setUp's structural is_email
		// alias rejects this naturally because it lacks an `@`.
		$this->stub_options( [
			'woocommerce_email_reply_to_enabled' => 'no',
			'woocommerce_email_from_address'     => 'gibberish',
		] );

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertArrayNotHasKey( 'contactPoint', $captured ?? [] );
	}

	public function test_store_jsonld_does_not_fall_back_to_admin_email(): void {
		// Regression guard for the explicit decision NOT to use
		// admin_email as a fallback. admin_email is intentionally
		// private (password resets, security notifications); merchants
		// do not expect it to be published in JSON-LD. This test
		// would fail if a future "helpful" refactor adds a fallback
		// chain.
		// Both WC email options blank, admin_email populated with a
		// valid address. The resolver must never even read
		// admin_email — the structural is_email alias would accept
		// `private-admin@example.com` if the resolver ever tried it.
		$this->stub_options( [
			'woocommerce_email_reply_to_enabled' => 'no',
			'woocommerce_email_from_address'     => '',
			'admin_email'                        => 'private-admin@example.com',
		] );

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertArrayNotHasKey(
			'contactPoint',
			$captured ?? [],
			'admin_email must NEVER be a public-facing contact fallback.'
		);
	}

	// ------------------------------------------------------------------
	// sameAs — auto-sourced social-profile URLs (issue #445)
	//
	// `output_store_jsonld()` now auto-sources `sameAs` from common
	// providers (Jetpack Publicize, Yoast `wpseo_social`, RankMath
	// titles options) via `collect_same_as()`, set BEFORE the
	// `wc_ai_storefront_jsonld_store` filter so a merchant's filter still
	// overrides/augments. Each provider is independently guarded — an
	// absent or differently-shaped provider is skipped silently.
	//
	// These tests drive the Yoast path (the most stable, option-only
	// source), confirm dedup + provider priority order across providers,
	// confirm omission when no provider yields URLs, and confirm the filter
	// still wins. Both Jetpack paths are covered: the happy path (transient
	// present → `profile_link` → sameAs) and the empty-transient path. The
	// dummy `\Automattic\Jetpack\Publicize\Connections` stand-in defined at
	// the top of this file makes the `class_exists` guard engage; the
	// connection data itself comes from the `get_transient` stub per test.
	// ------------------------------------------------------------------

	public function test_store_jsonld_sameas_omitted_when_no_provider_yields_urls(): void {
		// Default stub environment: `get_transient` returns false (no
		// Jetpack connections cached) and `get_option` returns '' for
		// `wpseo_social` and `rank-math-options-titles`. With nothing to
		// collect, the `sameAs` key must be absent.
		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertArrayNotHasKey( 'sameAs', $captured ?? array() );
	}

	public function test_store_jsonld_sameas_sourced_from_yoast_wpseo_social(): void {
		// Yoast stores social config under the `wpseo_social` option.
		// `facebook_site` is already a full URL; `twitter_site` is a bare
		// handle that must be expanded to `https://twitter.com/{handle}`.
		// (`twitter` is a boolean card toggle in Yoast, NOT the handle.)
		$this->stub_options(
			array(
				'wpseo_social' => array(
					'facebook_site' => 'https://facebook.com/saltwarp',
					'twitter_site'  => 'saltwarp',
					'instagram_url' => 'https://instagram.com/saltwarp',
				),
			)
		);

		$captured = $this->capture_store_jsonld_filter_value();
		$same_as  = $captured['sameAs'] ?? null;

		$this->assertIsArray( $same_as );
		$this->assertContains( 'https://facebook.com/saltwarp', $same_as );
		$this->assertContains( 'https://twitter.com/saltwarp', $same_as );
		$this->assertContains( 'https://instagram.com/saltwarp', $same_as );
	}

	public function test_store_jsonld_sameas_ignores_yoast_twitter_boolean_toggle(): void {
		// Regression guard for the wrong-key bug: Yoast's `twitter` key is a
		// boolean card toggle (default true), NOT a handle. Reading it as a
		// handle must NOT produce a bogus `https://twitter.com/1` URL — the
		// handle is `twitter_site`, absent here, so no Twitter URL emits.
		$this->stub_options(
			array(
				'wpseo_social' => array(
					'twitter'       => true,
					'facebook_site' => 'https://facebook.com/saltwarp',
				),
			)
		);

		$captured = $this->capture_store_jsonld_filter_value();
		$same_as  = $captured['sameAs'] ?? array();

		$this->assertContains( 'https://facebook.com/saltwarp', $same_as );
		$this->assertNotContains( 'https://twitter.com/1', $same_as );
		$this->assertSame(
			array(),
			array_values( array_filter( $same_as, static fn( $u ) => str_contains( $u, 'twitter.com' ) ) ),
			'The Twitter boolean toggle must never be read as a handle.'
		);
	}

	public function test_store_jsonld_sameas_includes_yoast_mastodon_url(): void {
		// `mastodon_url` is a full URL Yoast added for the fediverse; it
		// must be picked up alongside the other URL keys.
		$this->stub_options(
			array(
				'wpseo_social' => array( 'mastodon_url' => 'https://mastodon.example/@saltwarp' ),
			)
		);

		$captured = $this->capture_store_jsonld_filter_value();
		$same_as  = $captured['sameAs'] ?? array();

		$this->assertContains( 'https://mastodon.example/@saltwarp', $same_as );
	}

	public function test_store_jsonld_sameas_expands_yoast_twitter_handle_to_url(): void {
		// `twitter_site` is a handle, not a URL, and Yoast stores it with a
		// leading `@`. Regression guard: emitting the bare handle would be
		// an invalid `sameAs` value, and the `@` must be stripped from the
		// expanded URL.
		$this->stub_options(
			array(
				'wpseo_social' => array( 'twitter_site' => '@saltwarp' ),
			)
		);

		$captured = $this->capture_store_jsonld_filter_value();
		$same_as  = $captured['sameAs'] ?? array();

		$this->assertContains( 'https://twitter.com/saltwarp', $same_as );
		$this->assertNotContains( 'saltwarp', $same_as );
		$this->assertNotContains( 'https://twitter.com/@saltwarp', $same_as );
	}

	public function test_store_jsonld_sameas_handles_yoast_other_social_urls_array(): void {
		// Yoast's `other_social_urls` is an array of extra profile URLs.
		// Each non-empty entry should be included.
		$this->stub_options(
			array(
				'wpseo_social' => array(
					'other_social_urls' => array(
						'https://example.com/tiktok',
						'https://example.com/threads',
						'', // empty entry must be skipped
					),
				),
			)
		);

		$captured = $this->capture_store_jsonld_filter_value();
		$same_as  = $captured['sameAs'] ?? array();

		$this->assertContains( 'https://example.com/tiktok', $same_as );
		$this->assertContains( 'https://example.com/threads', $same_as );
	}

	public function test_store_jsonld_sameas_dedupes_across_providers(): void {
		// When two providers report the same profile URL, `sameAs` must
		// carry it once. Here Yoast lists Facebook under both
		// `facebook_site` and again inside `other_social_urls`.
		$this->stub_options(
			array(
				'wpseo_social' => array(
					'facebook_site'     => 'https://facebook.com/saltwarp',
					'other_social_urls' => array( 'https://facebook.com/saltwarp' ),
				),
			)
		);

		$captured = $this->capture_store_jsonld_filter_value();
		$same_as  = $captured['sameAs'] ?? array();

		$this->assertSame(
			array( 'https://facebook.com/saltwarp' ),
			array_values( array_filter( $same_as, static fn( $u ) => str_contains( $u, 'facebook.com/saltwarp' ) ) ),
			'Duplicate profile URLs across providers must be deduped.'
		);
	}

	public function test_store_jsonld_sameas_rejects_non_http_urls(): void {
		// Only http/https URLs are valid `sameAs` targets. Every other
		// scheme and every scheme-less value — a `javascript:`/`mailto:`/
		// `data:` payload, an `ftp:` link, a protocol-relative `//host`,
		// or a bare handle that never got expanded to a URL — must be
		// dropped by the scheme guard, while a legitimate https profile in
		// the same provider survives. `esc_url_raw` is stubbed to a
		// pass-through (see setUp), so this isolates collect_same_as()'s
		// own http/https scheme check rather than WP core's escaping.
		$this->stub_options(
			array(
				'wpseo_social' => array(
					'facebook_site' => 'https://facebook.com/saltwarp',
					'instagram_url' => 'javascript:alert(1)',
					'linkedin_url'  => 'mailto:hi@saltwarp.shop',
					'youtube_url'   => 'data:text/html;base64,PHNjcmlwdD4=',
					'pinterest_url' => 'ftp://files.saltwarp.shop',
					'myspace_url'   => '//evil.example/profile',
					'wikipedia_url' => 'saltwarp',
				),
			)
		);

		$captured = $this->capture_store_jsonld_filter_value();
		$same_as  = $captured['sameAs'] ?? array();

		// The one valid https profile survives.
		$this->assertContains( 'https://facebook.com/saltwarp', $same_as );
		// Every non-http(s) / scheme-less value is rejected.
		$this->assertNotContains( 'javascript:alert(1)', $same_as );
		$this->assertNotContains( 'mailto:hi@saltwarp.shop', $same_as );
		$this->assertNotContains( 'data:text/html;base64,PHNjcmlwdD4=', $same_as );
		$this->assertNotContains( 'ftp://files.saltwarp.shop', $same_as );
		$this->assertNotContains( '//evil.example/profile', $same_as );
		$this->assertNotContains( 'saltwarp', $same_as );
	}

	public function test_store_jsonld_sameas_sourced_from_rankmath_titles_option(): void {
		// RankMath stores per-network social URLs under the
		// `rank-math-options-titles` option with `social_url_*` keys (e.g.
		// `social_url_facebook`). There is NO `social_url_twitter` — the
		// handle lives under `twitter_author_names` and is expanded.
		$this->stub_options(
			array(
				'rank-math-options-titles' => array(
					'social_url_facebook' => 'https://facebook.com/rankmathshop',
					'twitter_author_names' => '@rankmathshop',
				),
			)
		);

		$captured = $this->capture_store_jsonld_filter_value();
		$same_as  = $captured['sameAs'] ?? array();

		$this->assertContains( 'https://facebook.com/rankmathshop', $same_as );
		$this->assertContains( 'https://twitter.com/rankmathshop', $same_as );
	}

	public function test_store_jsonld_sameas_sourced_from_rankmath_additional_profiles(): void {
		// RankMath's `social_additional_profiles` is the field it
		// explicitly designates for the schema `sameAs` property — a
		// newline-separated list of profile URLs. Each line must be
		// collected.
		$this->stub_options(
			array(
				'rank-math-options-titles' => array(
					'social_additional_profiles' => "https://example.com/rm-tiktok\nhttps://example.com/rm-threads\n",
				),
			)
		);

		$captured = $this->capture_store_jsonld_filter_value();
		$same_as  = $captured['sameAs'] ?? array();

		$this->assertContains( 'https://example.com/rm-tiktok', $same_as );
		$this->assertContains( 'https://example.com/rm-threads', $same_as );
	}

	public function test_store_jsonld_sameas_sourced_from_jetpack_publicize_connections(): void {
		// Jetpack happy path: the Publicize Connections class exists (dummy
		// stand-in at top of file), and Jetpack's cached connection list
		// transient holds connection rows. Each row's `profile_link` is a
		// public profile URL that must land in `sameAs`; a malformed row
		// with no `profile_link` is skipped silently.
		$this->stub_store_jsonld_environment();
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) {
				if ( 'jetpack_social_connections_list' === $key ) {
					return array(
						array( 'profile_link' => 'https://mastodon.example/@store' ),
						array( 'connection_data' => 'x' ), // malformed: no profile_link
					);
				}
				return false;
			}
		);

		$captured = $this->run_store_jsonld_capture();
		$same_as  = $captured['sameAs'] ?? array();

		$this->assertContains( 'https://mastodon.example/@store', $same_as );
		$this->assertCount( 1, $same_as, 'The malformed connection row must be skipped.' );
	}

	public function test_store_jsonld_sameas_jetpack_empty_transient_yields_no_error_no_urls(): void {
		// Jetpack present but no connections cached (`get_transient` →
		// false, the setUp default): collection completes without error
		// and emits no `sameAs` key. Pins the defensive guard on the
		// empty/cold-cache path.
		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertIsArray( $captured );
		$this->assertArrayNotHasKey( 'sameAs', $captured );
	}

	public function test_store_jsonld_sameas_dedupes_and_orders_across_all_providers(): void {
		// Same Facebook URL reported by Jetpack, Yoast, AND RankMath →
		// exactly one entry. Provider priority is Jetpack → Yoast →
		// RankMath, and dedup keeps the first occurrence, so the resulting
		// array order must reflect that precedence (Jetpack's Mastodon
		// first, then the shared Facebook URL from Jetpack's position,
		// then Yoast's Instagram, then RankMath's LinkedIn).
		$this->stub_store_jsonld_environment();
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) {
				if ( 'jetpack_social_connections_list' === $key ) {
					return array(
						array( 'profile_link' => 'https://mastodon.example/@store' ),
						array( 'profile_link' => 'https://facebook.com/shared' ),
					);
				}
				return false;
			}
		);
		$this->stub_options(
			array(
				'wpseo_social'             => array(
					'facebook_site' => 'https://facebook.com/shared', // dup of Jetpack
					'instagram_url' => 'https://instagram.com/shared',
				),
				'rank-math-options-titles' => array(
					'social_url_facebook' => 'https://facebook.com/shared', // dup again
					'social_url_linkedin' => 'https://linkedin.com/company/shared',
				),
			)
		);

		$captured = $this->run_store_jsonld_capture();
		$same_as  = $captured['sameAs'] ?? array();

		// Facebook appears exactly once despite three providers reporting it.
		$this->assertSame(
			1,
			count( array_filter( $same_as, static fn( $u ) => 'https://facebook.com/shared' === $u ) ),
			'Cross-provider duplicate must collapse to one entry.'
		);
		// Provider precedence Jetpack → Yoast → RankMath, first occurrence wins.
		$this->assertSame(
			array(
				'https://mastodon.example/@store',
				'https://facebook.com/shared',
				'https://instagram.com/shared',
				'https://linkedin.com/company/shared',
			),
			$same_as
		);
	}

	public function test_store_jsonld_sameas_tolerates_malformed_provider_shapes(): void {
		// Defensive-guard test: a non-array `wpseo_social` (sentinel
		// `gibberish`) and a URL key set to an int must not warn or throw,
		// and must not produce a `sameAs` key. Uses obviously-fake
		// sentinels per repo convention.
		$this->stub_options(
			array(
				'wpseo_social'             => 'gibberish', // not an array
				'rank-math-options-titles' => array(
					'social_url_facebook' => 424242, // int, not a string
				),
			)
		);

		$captured = $this->capture_store_jsonld_filter_value();

		$this->assertIsArray( $captured );
		$this->assertArrayNotHasKey( 'sameAs', $captured );
	}

	public function test_store_jsonld_sameas_filter_still_overrides_auto_sourced(): void {
		// The auto-sourced `sameAs` is set BEFORE the
		// `wc_ai_storefront_jsonld_store` filter, so a merchant filter
		// can replace it wholesale. Seed Yoast data (so auto-source
		// produces a value), then have the filter overwrite `sameAs`
		// with its own list and assert the filter's value wins.
		$this->stub_store_jsonld_environment();
		Functions\when( 'get_terms' )->justReturn( array() );
		$this->stub_options(
			array(
				'wpseo_social' => array( 'facebook_site' => 'https://facebook.com/auto-sourced' ),
			)
		);

		$captured = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value, ...$extras ) use ( &$captured ) {
				if ( 'wc_ai_storefront_jsonld_store' === $tag ) {
					// Confirm the plugin auto-sourced a value before the
					// filter ran (the override is meaningful only if there
					// was something to override).
					$captured              = $value;
					$value['sameAs']       = array( 'https://merchant.example/override' );
				}
				return $value;
			}
		);

		ob_start();
		try {
			$this->jsonld->output_store_jsonld();
		} finally {
			$output = (string) ob_get_clean();
		}

		// Decode the emitted JSON-LD so slash-escaping in the raw script
		// string (`https:\/\/...`) doesn't trip substring assertions.
		preg_match( '/<script[^>]*>(.*?)<\/script>/s', $output, $m );
		$emitted = json_decode( $m[1] ?? '{}', true );

		// The plugin auto-sourced the Yoast URL pre-filter...
		$this->assertContains( 'https://facebook.com/auto-sourced', $captured['sameAs'] ?? array() );
		// ...and the merchant filter's override is the only thing emitted.
		$this->assertSame( array( 'https://merchant.example/override' ), $emitted['sameAs'] ?? null );
	}

	// ------------------------------------------------------------------
	// Subscription signal helpers (#368 Step 1)
	//
	// Pure mappings — no I/O, no WC dependency. Used by the subscription
	// signal emitter to fill `UnitPriceSpecification.billingDuration`
	// and `Offer.eligibleDuration.unitCode`.
	// ------------------------------------------------------------------

	/**
	 * Invoke a private static method on `WC_AI_Storefront_JsonLd` via reflection.
	 *
	 * @param string $method Method name (e.g. 'period_to_iso8601_duration').
	 * @param mixed  ...$args Positional arguments to pass through.
	 * @return mixed
	 */
	private function invoke_jsonld_static( string $method, ...$args ) {
		$ref = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, $method );
		return $ref->invoke( null, ...$args );
	}

	public function test_period_to_iso8601_duration_maps_each_wc_period(): void {
		$this->assertSame( 'P1D',  $this->invoke_jsonld_static( 'period_to_iso8601_duration', 'day', 1 ) );
		$this->assertSame( 'P14D', $this->invoke_jsonld_static( 'period_to_iso8601_duration', 'day', 14 ) );
		$this->assertSame( 'P1W',  $this->invoke_jsonld_static( 'period_to_iso8601_duration', 'week', 1 ) );
		$this->assertSame( 'P2W',  $this->invoke_jsonld_static( 'period_to_iso8601_duration', 'week', 2 ) );
		$this->assertSame( 'P1M',  $this->invoke_jsonld_static( 'period_to_iso8601_duration', 'month', 1 ) );
		$this->assertSame( 'P3M',  $this->invoke_jsonld_static( 'period_to_iso8601_duration', 'month', 3 ) );
		$this->assertSame( 'P6M',  $this->invoke_jsonld_static( 'period_to_iso8601_duration', 'month', 6 ) );
		$this->assertSame( 'P1Y',  $this->invoke_jsonld_static( 'period_to_iso8601_duration', 'year', 1 ) );
	}

	public function test_period_to_iso8601_duration_falls_back_to_month_for_unknown_period(): void {
		// Unknown periods get treated as months — safer to emit a
		// slightly-wrong duration than to fatal the JSON-LD render
		// for what is, in practice, a vanishingly rare data shape.
		$this->assertSame( 'P1M', $this->invoke_jsonld_static( 'period_to_iso8601_duration', 'fortnight', 1 ) );
		$this->assertSame( 'P1M', $this->invoke_jsonld_static( 'period_to_iso8601_duration', '', 1 ) );
	}

	public function test_period_to_iso8601_duration_returns_zero_duration_for_non_positive_count(): void {
		// Zero or negative counts on a subscription period are
		// nonsensical input — return P0D so any consumer parsing the
		// string sees a zero duration rather than an invalid form.
		$this->assertSame( 'P0D', $this->invoke_jsonld_static( 'period_to_iso8601_duration', 'month', 0 ) );
		$this->assertSame( 'P0D', $this->invoke_jsonld_static( 'period_to_iso8601_duration', 'month', -3 ) );
	}

	public function test_period_to_uncefact_code_maps_each_wc_period(): void {
		// UN/CEFACT Common Code (Recommendation N°20) — what Google
		// Merchant Center and other major consumers ingest for
		// QuantitativeValue.unitCode.
		$this->assertSame( 'DAY', $this->invoke_jsonld_static( 'period_to_uncefact_code', 'day' ) );
		$this->assertSame( 'WEE', $this->invoke_jsonld_static( 'period_to_uncefact_code', 'week' ) );
		$this->assertSame( 'MON', $this->invoke_jsonld_static( 'period_to_uncefact_code', 'month' ) );
		$this->assertSame( 'ANN', $this->invoke_jsonld_static( 'period_to_uncefact_code', 'year' ) );
	}

	public function test_period_to_uncefact_code_falls_back_to_month_for_unknown_period(): void {
		// Same safe-default rationale as period_to_iso8601_duration.
		$this->assertSame( 'MON', $this->invoke_jsonld_static( 'period_to_uncefact_code', 'fortnight' ) );
		$this->assertSame( 'MON', $this->invoke_jsonld_static( 'period_to_uncefact_code', '' ) );
	}

	// ------------------------------------------------------------------
	// add_subscription_signals — #368 Steps 3-7
	//
	// Enriches `offers[0]` with `priceSpecification` (UnitPriceSpecification),
	// `addOn` (one-shot sign-up fee), and `eligibleDuration` (finite
	// subscription length). Reads WC Subscriptions configuration via
	// the `WC_Subscriptions_Product` static stub.
	// ------------------------------------------------------------------

	/**
	 * Seed the WC_Subscriptions_Product test stub with per-product
	 * configuration. Tests call this in setup, then invoke
	 * `enhance_product_data` against a make_product mock whose
	 * `get_id()` returns the same key.
	 */
	private function seed_subscription( int $product_id, array $overrides = [] ): void {
		WC_Subscriptions_Product::$test_data[ $product_id ] = array_merge(
			[
				'period'       => 'month',
				'interval'     => 1,
				'length'       => 0,
				'sign_up_fee'  => '0',
				'trial_length' => 0,
				'trial_period' => 'month',
			],
			$overrides
		);
	}

	public function test_simple_subscription_emits_recurring_price_specification(): void {
		// Annual subscription at $100/year, no trial, no sign-up fee,
		// indefinite. Should emit a single UnitPriceSpecification
		// entry with priceComponentType=Subscription and billingDuration=P1Y.
		$this->seed_subscription( 42, [ 'period' => 'year', 'interval' => 1 ] );
		$product = $this->make_product();
		$markup  = [
			'@type'  => 'Product',
			'offers' => [ [ '@type' => 'Offer', 'price' => '100.00', 'priceCurrency' => 'USD' ] ],
		];

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertArrayHasKey( 'priceSpecification', $result['offers'][0] );
		$this->assertCount( 1, $result['offers'][0]['priceSpecification'] );
		$spec = $result['offers'][0]['priceSpecification'][0];
		$this->assertSame( 'UnitPriceSpecification', $spec['@type'] );
		$this->assertSame( 'https://schema.org/Subscription', $spec['priceComponentType'] );
		$this->assertSame( '100.00', $spec['price'] );
		$this->assertSame( 'USD', $spec['priceCurrency'] );
		$this->assertSame( 'P1Y', $spec['billingDuration'] );
		$this->assertArrayNotHasKey( 'billingStart', $spec, 'No trial → no billingStart.' );
		// No fee → no addOn, no ActivationFee entry, no eligibleDuration.
		$this->assertArrayNotHasKey( 'addOn', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'eligibleDuration', $result['offers'][0] );

	}

	public function test_subscription_with_trial_emits_two_element_price_specification(): void {
		// 14-day free trial, then $10/month recurring. Should emit:
		// - trial entry: price=0, billingDuration=P14D
		// - recurring entry: price=10, billingDuration=P1M
		//
		// The trial-then-paid sequence is communicated via array position
		// (trial first, recurring second) + price=0 on the trial entry.
		// No `billingStart` is emitted on the recurring entry — Schema.org's
		// `billingStart` is typed `Number` (not Duration / ISO 8601 string),
		// so emitting `P14D` there would violate the spec's type contract.
		// Array semantics + price-discrimination convey the same intent.
		$this->seed_subscription( 42, [
			'period'       => 'month',
			'interval'     => 1,
			'trial_length' => 14,
			'trial_period' => 'day',
		] );
		$product = $this->make_product();
		$markup  = [
			'@type'  => 'Product',
			'offers' => [ [ '@type' => 'Offer', 'price' => '10.00', 'priceCurrency' => 'USD' ] ],
		];

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$specs = $result['offers'][0]['priceSpecification'];
		$this->assertCount( 2, $specs );
		// Trial entry — free for 14 days, must be FIRST in the array.
		$this->assertSame( '0', $specs[0]['price'] );
		$this->assertSame( 'P14D', $specs[0]['billingDuration'] );
		$this->assertSame( 'https://schema.org/Subscription', $specs[0]['priceComponentType'] );
		$this->assertArrayNotHasKey( 'billingStart', $specs[0] );
		// Recurring entry — $10/month, second in the array. Crucially:
		// NO `billingStart` field (regression guard against the spec
		// violation flagged in PR #371 review-toolkit pass).
		$this->assertSame( '10.00', $specs[1]['price'] );
		$this->assertSame( 'P1M', $specs[1]['billingDuration'] );
		$this->assertArrayNotHasKey(
			'billingStart',
			$specs[1],
			'billingStart is Number-typed per Schema.org — must not be emitted as an ISO 8601 string.'
		);

	}

	public function test_subscription_with_signup_fee_emits_both_addOn_and_inline_activation_fee(): void {
		// $5 sign-up fee + $10/month recurring. Decision #1 "future-ready
		// now" — emit BOTH `Offer.addOn` (released vocabulary) AND an
		// inline UnitPriceSpecification with priceComponentType=ActivationFee
		// (still-experimental enumeration, semantically richer).
		// Spec-legal duplication.
		Functions\when( '__' )->returnArg();
		$this->seed_subscription( 42, [
			'period'       => 'month',
			'interval'     => 1,
			'sign_up_fee'  => '5.00',
		] );
		$product = $this->make_product();
		$markup  = [
			'@type'  => 'Product',
			'offers' => [ [ '@type' => 'Offer', 'price' => '10.00', 'priceCurrency' => 'USD' ] ],
		];

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		// Inline ActivationFee priceComponent — last entry in the priceSpecification array.
		$specs = $result['offers'][0]['priceSpecification'];
		$this->assertCount( 2, $specs, 'Recurring + ActivationFee entries.' );
		$activation_fee = end( $specs );
		$this->assertSame( 'UnitPriceSpecification', $activation_fee['@type'] );
		$this->assertSame( 'https://schema.org/ActivationFee', $activation_fee['priceComponentType'] );
		$this->assertSame( '5.00', $activation_fee['price'] );

		// Offer.addOn — compat shape for consumers that don't recognize
		// priceComponentType.
		$this->assertArrayHasKey( 'addOn', $result['offers'][0] );
		$this->assertSame( 'Offer', $result['offers'][0]['addOn']['@type'] );
		$this->assertSame( '5.00', $result['offers'][0]['addOn']['price'] );
		$this->assertSame( 'Sign-up fee', $result['offers'][0]['addOn']['name'] );

	}

	public function test_subscription_with_finite_length_emits_eligible_duration(): void {
		// 12-month finite-length subscription. Should emit
		// eligibleDuration as a QuantitativeValue with unitCode=MON.
		$this->seed_subscription( 42, [
			'period'   => 'month',
			'interval' => 1,
			'length'   => 12,
		] );
		$product = $this->make_product();
		$markup  = [
			'@type'  => 'Product',
			'offers' => [ [ '@type' => 'Offer', 'price' => '10.00', 'priceCurrency' => 'USD' ] ],
		];

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertArrayHasKey( 'eligibleDuration', $result['offers'][0] );
		$dur = $result['offers'][0]['eligibleDuration'];
		$this->assertSame( 'QuantitativeValue', $dur['@type'] );
		$this->assertSame( 12, $dur['value'] );
		$this->assertSame( 'MON', $dur['unitCode'] );

	}

	public function test_subscription_signals_skipped_for_non_subscription_product(): void {
		// This test covers the `is_subscription( $product )` gate
		// specifically — i.e. WC Subscriptions IS loaded (the
		// `function_exists('wcs_is_subscription')` + `class_exists`
		// guards pass) but THIS product isn't a subscription. Absence
		// from the stub's `$test_data` map makes `is_subscription()`
		// return false for the product ID.
		//
		// The plugin-not-active path (the outer `function_exists` /
		// `class_exists` gates) can't be exercised in this test class
		// because the stubs are unconditionally loaded by
		// `tests/php/stubs.php` — that gate is covered structurally
		// by the gates' own existence, not by a test.
		WC_Subscriptions_Product::$test_data = []; // Explicit reset.

		$product = $this->make_product();
		$markup  = [
			'@type'  => 'Product',
			'offers' => [ [ '@type' => 'Offer', 'price' => '10.00', 'priceCurrency' => 'USD' ] ],
		];

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertArrayNotHasKey( 'priceSpecification', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'addOn', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'eligibleDuration', $result['offers'][0] );
	}

	public function test_subscription_signals_skipped_when_no_offers(): void {
		// Defensive: enhance_product_data may run against a markup
		// without offers[] (rare but possible — JSON-LD filters can
		// strip the offer array). The enricher should no-op silently.
		$this->seed_subscription( 42 );
		$product = $this->make_product();
		$markup  = [ '@type' => 'Product' ];

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		// We don't care what offers[0] contains; just that no fatal occurs.
		$this->assertIsArray( $result );

	}

	public function test_variable_subscription_emits_per_variant_price_specification(): void {
		// Variable-subscription parent's hasVariant entries each carry
		// their own priceSpecification with their own billingDuration —
		// subscription_variations can have different periods (3972 might
		// bill monthly, 3975 yearly). The per-variant path runs through
		// `build_variant_entry()` which invokes `add_subscription_signals`
		// per variation.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent = $this->make_product( [ 'id' => 100 ] );

		// Two variations: 1-month at $10 and 1-year at $75.
		$monthly = $this->make_variation( [
			'id'    => 101,
			'sku'   => 'sub-monthly',
			'price' => '10.00',
		] );
		$yearly = $this->make_variation( [
			'id'    => 102,
			'sku'   => 'sub-yearly',
			'price' => '75.00',
		] );
		$this->seed_subscription( 101, [ 'period' => 'month', 'interval' => 1 ] );
		$this->seed_subscription( 102, [ 'period' => 'year',  'interval' => 1 ] );

		$monthly_entry = $this->invoke_build_variant_entry( $monthly, $parent );
		$yearly_entry  = $this->invoke_build_variant_entry( $yearly,  $parent );

		// Each variant has its own priceSpecification with its own
		// billingDuration — proves the per-variant subscription
		// metadata flows through without crossing wires between
		// variants.
		$this->assertArrayHasKey( 'priceSpecification', $monthly_entry['offers'][0] );
		$this->assertSame(
			'P1M',
			$monthly_entry['offers'][0]['priceSpecification'][0]['billingDuration']
		);
		$this->assertSame(
			'10.00',
			$monthly_entry['offers'][0]['priceSpecification'][0]['price']
		);

		$this->assertArrayHasKey( 'priceSpecification', $yearly_entry['offers'][0] );
		$this->assertSame(
			'P1Y',
			$yearly_entry['offers'][0]['priceSpecification'][0]['billingDuration']
		);
		$this->assertSame(
			'75.00',
			$yearly_entry['offers'][0]['priceSpecification'][0]['price']
		);

	}

	public function test_variable_subscription_does_not_leak_signals_into_non_subscription_variation(): void {
		// Realistic edge case: a variable-subscription parent could have
		// a mix of subscription_variation and plain (non-recurring)
		// variation children. The enricher must no-op for each variation
		// independently — a sibling subscription's metadata must NOT
		// leak into a non-subscription variation's Offer.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$parent = $this->make_product( [ 'id' => 100 ] );

		// One subscription variation, one plain variation. Only the
		// subscription gets seeded in $test_data — the plain one
		// returns false from is_subscription().
		$subscription_variation = $this->make_variation( [ 'id' => 201, 'price' => '10.00' ] );
		$plain_variation        = $this->make_variation( [ 'id' => 202, 'price' => '25.00' ] );
		$this->seed_subscription( 201, [ 'period' => 'month', 'interval' => 1 ] );

		$sub_entry   = $this->invoke_build_variant_entry( $subscription_variation, $parent );
		$plain_entry = $this->invoke_build_variant_entry( $plain_variation, $parent );

		// Subscription variation gets the subscription enrichment.
		$this->assertArrayHasKey( 'priceSpecification', $sub_entry['offers'][0] );
		// Plain variation gets NO subscription fields — the enricher
		// no-oped for it. Regression guard against a refactor that
		// would inherit period/interval from a sibling or the parent.
		$this->assertArrayNotHasKey(
			'priceSpecification',
			$plain_entry['offers'][0],
			'Non-subscription variation must not inherit subscription metadata from a sibling.'
		);
		$this->assertArrayNotHasKey( 'addOn', $plain_entry['offers'][0] );
		$this->assertArrayNotHasKey( 'eligibleDuration', $plain_entry['offers'][0] );

	}

	public function test_subscription_signals_skipped_when_interval_is_zero_or_negative(): void {
		// Corrupted subscription product (interval = 0) would otherwise
		// emit `billingDuration: P0D` — spec-legal but nonsensical
		// ("billed every 0 days"). Mirrors the trial path's
		// `$trial_length > 0` gate. Asymmetric defensiveness between
		// recurring and trial paths was the silent-failure-hunter
		// finding on PR #371's first review pass.
		$this->seed_subscription( 42, [ 'period' => 'month', 'interval' => 0 ] );
		$product = $this->make_product();
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '10.00', 'priceCurrency' => 'USD' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		// No subscription signals emitted — corrupt config short-circuits
		// the entire enrichment, log is the only side-effect.
		$this->assertArrayNotHasKey( 'priceSpecification', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'addOn', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'eligibleDuration', $result['offers'][0] );

	}

	public function test_subscription_signals_use_get_woocommerce_currency_when_offer_currency_missing(): void {
		// Edge case: the upstream Offer doesn't carry `priceCurrency`
		// (rare — `add_currency()` runs first and hoists it, but a
		// third-party filter could strip the field). The enricher
		// must fall back to `get_woocommerce_currency()` rather than
		// emit an empty-string priceCurrency on the priceSpecification.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );

		$this->seed_subscription( 42, [ 'period' => 'month', 'interval' => 1 ] );
		$product = $this->make_product();
		// `offers[0]` deliberately lacks `priceCurrency`.
		$markup = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '10.00' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame(
			'EUR',
			$result['offers'][0]['priceSpecification'][0]['priceCurrency'],
			'priceSpecification must fall back to get_woocommerce_currency() when the Offer has no priceCurrency.'
		);

	}

	// ------------------------------------------------------------------
	// Offer flat `price` hoist (#502)
	//
	// Recent WooCommerce core builds the product Offer with the price ONLY
	// inside `priceSpecification` (an array of UnitPriceSpecification) and
	// never sets a flat `offers.price`. Google's merchant listing reads
	// `offers.price`, so the missing flat field surfaces as a "Missing
	// field price" Rich Results error (WC ref: woocommerce/woocommerce#55043).
	// `add_currency()` hoists the current price (priceSpecification[0]) up to
	// the Offer level, mirroring the existing priceCurrency hoist.
	// ------------------------------------------------------------------

	public function test_offer_flat_price_hoisted_from_pricespecification(): void {
		$product = $this->make_product();
		// WC core's shape: price only in priceSpecification, no flat price.
		$markup = array(
			'@type'  => 'Product',
			'offers' => array(
				array(
					'@type'              => 'Offer',
					'priceSpecification' => array(
						array(
							'@type'         => 'UnitPriceSpecification',
							'price'         => '15.00',
							'priceCurrency' => 'USD',
						),
					),
				),
			),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( '15.00', $result['offers'][0]['price'] );
		$this->assertSame( 'USD', $result['offers'][0]['priceCurrency'] );
	}

	public function test_offer_flat_price_hoists_current_sale_price_not_list_price(): void {
		// On-sale Offer: WC core array_unshift()es the sale (current) price to
		// priceSpecification[0] and the regular price (priceType: ListPrice)
		// follows. The flat price must be the CURRENT price, never the higher
		// ListPrice — and the ListPrice entry must survive so Google still
		// renders the sale.
		$product = $this->make_product();
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array(
				array(
					'@type'              => 'Offer',
					'priceSpecification' => array(
						array(
							'@type'         => 'UnitPriceSpecification',
							'price'         => '2.99',
							'priceCurrency' => 'USD',
						),
						array(
							'@type'         => 'UnitPriceSpecification',
							'price'         => '4.99',
							'priceCurrency' => 'USD',
							'priceType'     => 'https://schema.org/ListPrice',
						),
					),
				),
			),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( '2.99', $result['offers'][0]['price'] );
		$this->assertSame(
			'https://schema.org/ListPrice',
			$result['offers'][0]['priceSpecification'][1]['priceType']
		);
		$this->assertSame( '4.99', $result['offers'][0]['priceSpecification'][1]['price'] );
	}

	public function test_offer_flat_price_not_overwritten_when_already_present(): void {
		// Guard: when an Offer already carries a flat price (older WC, or a
		// third-party filter), the hoist must not clobber it.
		$product = $this->make_product();
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array(
				array(
					'@type'              => 'Offer',
					'price'              => '9.99',
					'priceCurrency'      => 'USD',
					'priceSpecification' => array(
						array(
							'@type'         => 'UnitPriceSpecification',
							'price'         => '15.00',
							'priceCurrency' => 'USD',
						),
					),
				),
			),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( '9.99', $result['offers'][0]['price'] );
	}

	// ------------------------------------------------------------------
	// Sale window: validFrom / validThrough on the Offer (#582).
	// `add_sale_window()` sources both boundaries from the product's WC
	// sale schedule and emits full ISO 8601 (store-timezone offset) only
	// when the product is actually on sale.
	// ------------------------------------------------------------------

	public function test_sale_window_emits_valid_from_and_through_when_on_sale(): void {
		// A real DateTime with a non-UTC offset proves the emitted string is
		// `format('c')` (ISO 8601 WITH offset), not a UTC/date-only coercion.
		$from    = new \DateTimeImmutable( '2026-07-01T00:00:00+01:00' );
		$through = new \DateTimeImmutable( '2026-07-31T23:59:59+01:00' );
		$product = $this->make_product( array(
			'is_on_sale'         => true,
			'date_on_sale_from'  => $from,
			'date_on_sale_to'    => $through,
		) );
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '9.99' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( '2026-07-01T00:00:00+01:00', $result['offers'][0]['validFrom'] );
		$this->assertSame( '2026-07-31T23:59:59+01:00', $result['offers'][0]['validThrough'] );
	}

	public function test_sale_window_emits_only_valid_from_when_no_end_date(): void {
		// WooCommerce allows an open-ended sale (start only). Each field is
		// emitted independently; validThrough must be absent.
		$product = $this->make_product( array(
			'is_on_sale'        => true,
			'date_on_sale_from' => new \DateTimeImmutable( '2026-07-01T00:00:00+00:00' ),
			'date_on_sale_to'   => null,
		) );
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '9.99' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( '2026-07-01T00:00:00+00:00', $result['offers'][0]['validFrom'] );
		$this->assertArrayNotHasKey( 'validThrough', $result['offers'][0] );
	}

	public function test_sale_window_emits_only_valid_through_when_no_start_date(): void {
		// Open-ended sale (end only) — the mirror of the previous case.
		$product = $this->make_product( array(
			'is_on_sale'        => true,
			'date_on_sale_from' => null,
			'date_on_sale_to'   => new \DateTimeImmutable( '2026-07-31T23:59:59+00:00' ),
		) );
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '9.99' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertArrayNotHasKey( 'validFrom', $result['offers'][0] );
		$this->assertSame( '2026-07-31T23:59:59+00:00', $result['offers'][0]['validThrough'] );
	}

	public function test_sale_window_omitted_when_not_on_sale(): void {
		// Dates may be SET but the schedule expired / not started → is_on_sale()
		// is false and neither field may be emitted.
		$product = $this->make_product( array(
			'is_on_sale'        => false,
			'date_on_sale_from' => new \DateTimeImmutable( '2020-01-01T00:00:00+00:00' ),
			'date_on_sale_to'   => new \DateTimeImmutable( '2020-01-31T00:00:00+00:00' ),
		) );
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '9.99' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertArrayNotHasKey( 'validFrom', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'validThrough', $result['offers'][0] );
	}

	public function test_sale_window_not_emitted_on_aggregate_offer(): void {
		// A variable parent's price-range offer is an AggregateOffer; a single
		// window on it would be ambiguous. Per-variant windows are handled in
		// the ProductGroup path instead.
		$product = $this->make_product( array(
			'is_on_sale'        => true,
			'date_on_sale_from' => new \DateTimeImmutable( '2026-07-01T00:00:00+00:00' ),
			'date_on_sale_to'   => new \DateTimeImmutable( '2026-07-31T00:00:00+00:00' ),
		) );
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array(
				array(
					'@type'     => 'AggregateOffer',
					'lowPrice'  => '9.99',
					'highPrice' => '19.99',
				),
			),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertArrayNotHasKey( 'validFrom', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'validThrough', $result['offers'][0] );
	}

	public function test_sale_window_does_not_overwrite_existing_offer_values(): void {
		// No-clobber: when an upstream filter already set validFrom/validThrough,
		// the helper must leave both untouched.
		$product = $this->make_product( array(
			'is_on_sale'        => true,
			'date_on_sale_from' => new \DateTimeImmutable( '2026-07-01T00:00:00+00:00' ),
			'date_on_sale_to'   => new \DateTimeImmutable( '2026-07-31T00:00:00+00:00' ),
		) );
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array(
				array(
					'@type'        => 'Offer',
					'price'        => '9.99',
					'validFrom'    => 'UPSTREAM_FROM',
					'validThrough' => 'UPSTREAM_THROUGH',
				),
			),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( 'UPSTREAM_FROM', $result['offers'][0]['validFrom'] );
		$this->assertSame( 'UPSTREAM_THROUGH', $result['offers'][0]['validThrough'] );
	}

	public function test_variant_sale_window_uses_own_dates_no_parent_fallback(): void {
		// Each variation runs its own sale. A variation WITH its own window
		// emits validFrom/validThrough from THAT window; a parent window must
		// never bleed onto a variant that has no sale of its own.
		$from    = new \DateTimeImmutable( '2026-08-01T00:00:00+02:00' );
		$through = new \DateTimeImmutable( '2026-08-15T23:59:59+02:00' );

		$result = $this->enhance_variable_with_parent_markup(
			// Parent offer carries a DIFFERENT window that must NOT be inherited.
			array(
				'offers' => array(
					array(
						'@type'        => 'Offer',
						'price'        => '20.00',
						'validFrom'    => '2026-01-01T00:00:00+00:00',
						'validThrough' => '2026-12-31T00:00:00+00:00',
					),
				),
			),
			array(
				'is_on_sale'        => true,
				'date_on_sale_from' => $from,
				'date_on_sale_to'   => $through,
			)
		);
		$offer = $result['hasVariant'][0]['offers'][0];

		$this->assertSame( '2026-08-01T00:00:00+02:00', $offer['validFrom'] );
		$this->assertSame( '2026-08-15T23:59:59+02:00', $offer['validThrough'] );
	}

	public function test_variant_without_own_sale_window_inherits_neither_boundary(): void {
		// A variation not on sale must carry NO window — even when the parent
		// markup advertises one. Inheriting it would be a wrong-but-plausible
		// sale period on a variant that isn't discounted.
		$result = $this->enhance_variable_with_parent_markup(
			array(
				'offers' => array(
					array(
						'@type'        => 'Offer',
						'price'        => '20.00',
						'validFrom'    => '2026-01-01T00:00:00+00:00',
						'validThrough' => '2026-12-31T00:00:00+00:00',
					),
				),
			),
			array( 'is_on_sale' => false )
		);
		$offer = $result['hasVariant'][0]['offers'][0];

		$this->assertArrayNotHasKey( 'validFrom', $offer );
		$this->assertArrayNotHasKey( 'validThrough', $offer );
	}

	public function test_sale_window_uses_wc_datetime_offset_for_manual_offset_stores(): void {
		// Regression guard for the manual-UTC-offset store bug: WooCommerce
		// stores dates for stores configured with a fixed `gmt_offset` (WP
		// "UTC+1", no named `timezone_string`) as a UTC-internal WC_DateTime
		// plus a DETACHED `utc_offset` property. Because WC_DateTime does not
		// override format(), `$date->format('c')` would emit `+00:00` AND a
		// wall-clock shifted off the merchant's local time. iso8601_or_empty()
		// must instead honor WC_DateTime::getOffset() (the merchant's real
		// offset) so the emitted window reflects local civil time. A
		// DateTimeImmutable fixture CANNOT reproduce this divergence — only a
		// real WC_DateTime with a set utc_offset can, which is why the faithful
		// WC_DateTime stub exists.
		//
		// Merchant intends a sale starting 2026-07-15 09:30 local in a UTC+1
		// store. The instant is therefore 08:30 UTC; the correct emission is
		// `2026-07-15T09:30:00+01:00`, never `2026-07-15T08:30:00+00:00`.
		$from = new \WC_DateTime( '2026-07-15T08:30:00', new \DateTimeZone( 'UTC' ) );
		$from->set_utc_offset( 3600 );
		$product = $this->make_product( array(
			'is_on_sale'        => true,
			'date_on_sale_from' => $from,
			'date_on_sale_to'   => null,
		) );
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '9.99' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( '2026-07-15T09:30:00+01:00', $result['offers'][0]['validFrom'] );
	}

	public function test_sale_window_uses_wc_datetime_offset_for_negative_manual_offset(): void {
		// Mirror of the positive-offset case for a UTC-5 store, proving the
		// sign handling in iso8601_or_empty() is correct in both directions.
		// Merchant intends 09:30 local in UTC-5 → instant is 14:30 UTC →
		// correct emission `2026-07-15T09:30:00-05:00`.
		$to = new \WC_DateTime( '2026-07-15T14:30:00', new \DateTimeZone( 'UTC' ) );
		$to->set_utc_offset( -18000 );
		$product = $this->make_product( array(
			'is_on_sale'        => true,
			'date_on_sale_from' => null,
			'date_on_sale_to'   => $to,
		) );
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '9.99' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( '2026-07-15T09:30:00-05:00', $result['offers'][0]['validThrough'] );
	}

	public function test_sale_window_named_zone_wc_datetime_preserves_offset(): void {
		// The named-timezone shape (the common case): a real WC_DateTime whose
		// underlying DateTime tz is a named zone. getOffset() returns the
		// zone's live offset (DST-aware), so the emitted string carries e.g.
		// +02:00 for CEST. Uses a real WC_DateTime (not DateTimeImmutable) so
		// the assertion exercises the actual production object type.
		$from = new \WC_DateTime( '2026-07-15T09:30:00', new \DateTimeZone( 'Europe/Berlin' ) );
		$product = $this->make_product( array(
			'is_on_sale'        => true,
			'date_on_sale_from' => $from,
			'date_on_sale_to'   => null,
		) );
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '9.99' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( '2026-07-15T09:30:00+02:00', $result['offers'][0]['validFrom'] );
	}

	public function test_sale_window_omitted_when_on_sale_but_no_dates(): void {
		// A product can be on sale via a bare sale_price with NO schedule at
		// all (both date getters null). is_on_sale() is true, but there is no
		// window to emit — neither field may appear. Closes the last cell of
		// the on-sale/date truth table.
		$product = $this->make_product( array(
			'is_on_sale'        => true,
			'date_on_sale_from' => null,
			'date_on_sale_to'   => null,
		) );
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '9.99' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertArrayNotHasKey( 'validFrom', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'validThrough', $result['offers'][0] );
	}

	public function test_sale_window_coexists_with_subscription_price_specification(): void {
		// A WC Subscriptions product can be on sale. add_sale_window() runs
		// immediately before add_subscription_signals(); the latter REPLACES
		// offers[0].priceSpecification but must not touch validFrom/validThrough.
		// Lock the co-existence contract: a subscription-on-sale offer carries
		// BOTH the recurring priceSpecification AND the sale window. A future
		// refactor of add_subscription_signals() that rebuilt offers[0] from
		// scratch would silently drop the window — this test would catch it.
		$this->seed_subscription( 42, array( 'period' => 'month', 'interval' => 1 ) );
		$product = $this->make_product( array(
			'is_on_sale'        => true,
			'date_on_sale_from' => new \DateTimeImmutable( '2026-07-01T00:00:00+00:00' ),
			'date_on_sale_to'   => new \DateTimeImmutable( '2026-07-31T23:59:59+00:00' ),
		) );
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'price' => '10.00', 'priceCurrency' => 'USD' ) ),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );
		$offer  = $result['offers'][0];

		$this->assertArrayHasKey( 'priceSpecification', $offer, 'subscription priceSpecification must survive' );
		$this->assertSame( '2026-07-01T00:00:00+00:00', $offer['validFrom'] );
		$this->assertSame( '2026-07-31T23:59:59+00:00', $offer['validThrough'] );
	}

	public function test_offer_currency_hoisted_but_no_price_when_spec_lacks_price(): void {
		// A priceSpecification[0] that carries priceCurrency but NO price
		// (e.g. a $0 / "contact for price" product) must hoist the currency
		// yet add no flat price — and must NEVER emit `price => null`, which
		// would be worse for Google than the original missing field.
		$product = $this->make_product();
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array(
				array(
					'@type'              => 'Offer',
					'priceSpecification' => array(
						array(
							'@type'         => 'UnitPriceSpecification',
							'priceCurrency' => 'USD',
						),
					),
				),
			),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertSame( 'USD', $result['offers'][0]['priceCurrency'] );
		$this->assertArrayNotHasKey( 'price', $result['offers'][0] );
	}

	public function test_aggregate_offer_does_not_receive_a_flat_price(): void {
		// WC core emits an `AggregateOffer` (lowPrice/highPrice) for a variable
		// product spanning a price range. The hoist must NOT stamp a scalar
		// `price` onto it — that's redundant and Google-invalid for an
		// AggregateOffer. The (type-agnostic) currency hoist still applies.
		$product = $this->make_product();
		$markup  = array(
			'@type'  => 'Product',
			'offers' => array(
				array(
					'@type'              => 'AggregateOffer',
					'lowPrice'           => '10.00',
					'highPrice'          => '20.00',
					'priceSpecification' => array(
						array(
							'@type'         => 'UnitPriceSpecification',
							'price'         => '10.00',
							'priceCurrency' => 'USD',
						),
					),
				),
			),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $product );

		$this->assertArrayNotHasKey( 'price', $result['offers'][0] );
		$this->assertSame( '10.00', $result['offers'][0]['lowPrice'] );
		$this->assertSame( '20.00', $result['offers'][0]['highPrice'] );
		$this->assertSame( 'USD', $result['offers'][0]['priceCurrency'] );
	}

	// ------------------------------------------------------------------
	// `currenciesAccepted` — single vs multi-currency emission.
	// ------------------------------------------------------------------
	//
	// Schema.org's `currenciesAccepted` accepts a space-separated string
	// of ISO-4217 codes. Single-currency stores must still emit one bare
	// code (no separator). Multi-currency stores (driven by the
	// `WC_AI_Storefront_Multi_Currency` helper, with the
	// `wc_ai_storefront_accepted_currencies` filter as the override
	// surface for non-WooPayments integrations) must emit the full list
	// joined by single spaces, base currency first.

	public function test_output_store_jsonld_currenciesaccepted_single_currency_emits_base(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->returnArg();
		Functions\when( 'get_terms' )->justReturn( [] );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->returnArg( 1 );

		ob_start();
		( new WC_AI_Storefront_JsonLd() )->output_store_jsonld();
		$output = ob_get_clean();

		$this->assertStringContainsString( '"currenciesAccepted":"USD"', $output );
	}

	public function test_output_store_jsonld_currenciesaccepted_multi_currency_emits_space_separated_list(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->returnArg();
		Functions\when( 'get_terms' )->justReturn( [] );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->returnArg( 1 );
		// Variadic capture: `output_store_jsonld()` also fires the
		// `wc_ai_storefront_jsonld_store` filter with three positional
		// args. A strict 2-arg signature would still work in PHP
		// (extra args are silently dropped on user-defined callables),
		// but matching the surrounding test pattern keeps the closure
		// resilient to any future tightening of Brain Monkey's arg
		// dispatcher.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$extras ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR', 'GBP' );
				}
				return $value;
			}
		);

		ob_start();
		( new WC_AI_Storefront_JsonLd() )->output_store_jsonld();
		$output = ob_get_clean();

		$this->assertStringContainsString( '"currenciesAccepted":"USD EUR GBP"', $output );
	}
}
