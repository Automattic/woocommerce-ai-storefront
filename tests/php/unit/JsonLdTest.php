<?php
/**
 * Tests for WC_AI_Storefront_JsonLd.
 *
 * Focuses on `enhance_product_data()` — the filter that augments
 * WooCommerce's native product JSON-LD with fields that help AI
 * agents (BuyAction with attribution placeholders, inventory,
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

class JsonLdTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_JsonLd $jsonld;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->jsonld = new WC_AI_Storefront_JsonLd();

		// Default: syndication enabled, no category restriction. Tests
		// that exercise the disabled path or product-exclusion override.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];

		// add_query_arg is the only WP function we consistently need
		// across tests — simple passthrough that appends params.
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				$query = http_build_query( $args );
				$sep   = str_contains( $url, '?' ) ? '&' : '?';
				return $url . $sep . $query;
			}
		);
		Functions\when( 'wc_get_product_cat_ids' )->justReturn( [] );
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => 'US', 'state' => 'CA' ]
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Stub the per-product final-sale meta read to "not flagged"
		// across all tests in this file. Per-product override coverage
		// lives in JsonLdReturnPolicyTest's dedicated branch.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		// Default `wp_get_post_parent_id()` to 0 — these tests use
		// non-variation product mocks. Override-scope resolution
		// happens at the `enhance_product_data` entry point.
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
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
		$this->assertStringContainsString( 'add-to-cart=42', $url );
		$this->assertStringContainsString( 'utm_source=%7Bagent_id%7D', $url );
		// Canonical UTM shape (0.5.0+): medium=referral (Google-canonical),
		// utm_id=woo_ucp flags "we routed this".
		$this->assertStringContainsString( 'utm_medium=referral', $url );
		$this->assertStringContainsString( 'utm_id=woo_ucp', $url );
		$this->assertStringContainsString( 'ai_session_id=%7Bsession_id%7D', $url );
	}

	public function test_buyaction_declares_web_platforms(): void {
		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data( [], $product );

		$platforms = $result['potentialAction']['target']['actionPlatform'];
		$this->assertContains( 'https://schema.org/DesktopWebPlatform', $platforms );
		$this->assertContains( 'https://schema.org/MobileWebPlatform', $platforms );
	}

	// ------------------------------------------------------------------
	// SearchAction urlTemplate (Store-level JSON-LD)
	// ------------------------------------------------------------------
	//
	// The Store-level `output_store_jsonld()` emits a SearchAction
	// urlTemplate that ALSO carries the canonical UTM shape (0.5.0+).
	// Pre-this-test, only BuyAction's urlTemplate was pinned by tests
	// — a refactor that reverted SearchAction to the legacy
	// `utm_medium=ai_agent` shape would pass CI silently. This test
	// captures stdout and asserts the canonical shape substrings on
	// the SearchAction emission.
	//
	// We assert substrings rather than parse the full JSON because
	// `output_store_jsonld()` echoes a complete `<script type=...>`
	// wrapper plus the JSON body; a substring check avoids brittle
	// JSON-shape-decoding for what is effectively a regression guard.

	public function test_searchaction_url_template_emits_canonical_utm_shape(): void {
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

		$this->assertStringContainsString( 'utm_medium=referral', $url );
		$this->assertStringContainsString( 'utm_id=woo_ucp', $url );
		// Regression guard against the legacy shape leaking back in.
		$this->assertStringNotContainsString( 'utm_medium=ai_agent', $url );
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
		// so `<` and `>` hex-escape to `<` / `>`. The script
		// tag's CDATA is preserved; Schema.org parsers handle the hex
		// escapes per the JSON spec.
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
		// Real `wp_json_encode` so we exercise the actual flag set
		// rather than the string-builder alias used elsewhere.
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, $flags = 0 ) => json_encode( $data, $flags )
		);
		Functions\when( '__' )->returnArg( 1 );

		ob_start();
		$this->jsonld->output_store_jsonld();
		$output = ob_get_clean();

		// Critical assertion: the literal `</script>` byte sequence
		// MUST NOT appear inside the JSON body, only as the closing
		// of our own intended `<script type="application/ld+json">`
		// wrapper. Total occurrence count = 1 (our closing tag).
		$this->assertSame(
			1,
			substr_count( $output, '</script>' ),
			'JSON body must not contain literal </script> — only our wrapper closing tag is permitted.'
		);

		// Extract the JSON between the script tags and confirm it
		// parses — the hex-escaped output is still valid JSON-LD.
		$matches = [];
		preg_match( '/<script type="application\/ld\+json">(.*?)<\/script>/s', $output, $matches );
		$this->assertCount( 2, $matches, 'Expected exactly one matched script-tag pair.' );
		$decoded = json_decode( $matches[1], true );
		$this->assertIsArray( $decoded, 'JSON inside the script tag must parse to an array.' );
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
		$color = Mockery::mock();
		$color->shouldReceive( 'get_visible' )->andReturn( true );
		$color->shouldReceive( 'get_name' )->andReturn( 'pa_color' );

		$size = Mockery::mock();
		$size->shouldReceive( 'get_visible' )->andReturn( true );
		$size->shouldReceive( 'get_name' )->andReturn( 'pa_size' );

		$product = $this->make_product( [
			'attributes' => [
				'pa_color' => $color,
				'pa_size'  => $size,
			],
		] );
		$product->shouldReceive( 'get_attribute' )
			->with( 'pa_color' )->andReturn( 'Red' );
		$product->shouldReceive( 'get_attribute' )
			->with( 'pa_size' )->andReturn( 'Large' );

		Functions\when( 'wc_attribute_label' )->alias(
			static fn( $slug ) => ucfirst( str_replace( 'pa_', '', $slug ) )
		);

		$result = $this->jsonld->enhance_product_data( [], $product );

		$this->assertCount( 2, $result['additionalProperty'] );
		$this->assertEquals( 'Color', $result['additionalProperty'][0]['name'] );
		$this->assertEquals( 'Red', $result['additionalProperty'][0]['value'] );
		$this->assertEquals( 'PropertyValue', $result['additionalProperty'][0]['@type'] );
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
		// PR-C: hasMerchantReturnPolicy emission is settings-driven and
		// lives at the Offer level. Default mode `unconfigured` emits
		// no block; switch to `returns_accepted` to assert presence.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => [
				'mode' => 'returns_accepted',
				'days' => 30,
				'fees' => 'FreeReturn',
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
}
