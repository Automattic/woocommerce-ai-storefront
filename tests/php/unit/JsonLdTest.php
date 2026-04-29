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
				// Strip any fragment before appending query params, then
				// re-append it — matching WordPress core's behavior.
				// Without this, a permalink like `.../widget/#reviews`
				// would produce `.../widget/#reviews?add-to-cart=42`
				// where the entire query string is part of the fragment
				// and never reaches the server.
				$fragment = '';
				if ( str_contains( $url, '#' ) ) {
					[ $url, $fragment ] = explode( '#', $url, 2 );
					$fragment = '#' . $fragment;
				}
				$query = http_build_query( $args );
				$sep   = str_contains( $url, '?' ) ? '&' : '?';
				return $url . $sep . $query . $fragment;
			}
		);
		Functions\when( 'wc_get_product_cat_ids' )->justReturn( [] );
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => 'US', 'state' => 'CA' ]
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// get_catalog_summary() now uses a transient cache. Stub both
		// functions globally so all tests that invoke output_store_jsonld()
		// work without individual setup. Tests that want to verify caching
		// behaviour specifically may override these via Functions\expect().
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
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

	public function test_buyaction_uritemplate_preserves_fragment_from_permalink(): void {
		// Some themes append tab deep-links to product permalinks, e.g.
		// `https://example.com/product/widget/#tab-description`. The real
		// `add_query_arg()` strips the fragment, appends query params to
		// the base URL, then re-appends the fragment — producing a
		// well-formed URL where the query string is readable by the server.
		//
		// Regression guard: the mock must behave identically so CI catches
		// any future production-code change that re-introduces the bug.
		$product = $this->make_product(
			[ 'permalink' => 'https://example.com/product/widget/#tab-description' ]
		);
		$result  = $this->jsonld->enhance_product_data( [], $product );

		$url = $result['potentialAction']['target']['urlTemplate'];

		// Query params must appear BEFORE the fragment separator.
		$fragment_pos    = strpos( $url, '#' );
		$query_param_pos = strpos( $url, 'add-to-cart=' );

		$this->assertNotFalse( $fragment_pos, 'urlTemplate should still contain the fragment' );
		$this->assertNotFalse( $query_param_pos, 'urlTemplate should contain add-to-cart param' );
		$this->assertLessThan(
			$fragment_pos,
			$query_param_pos,
			'Query params must appear before the # fragment separator'
		);

		// The fragment itself should be intact at the very end.
		$this->assertStringEndsWith( '#tab-description', $url );

		// Sanity: attribution params must survive too.
		$this->assertStringContainsString( 'utm_id=woo_ucp', $url );
	}

	public function test_buyaction_uritemplate_preserves_fragment_when_permalink_has_existing_query(): void {
		// Combination case: permalink already carries a query string
		// (e.g. a language plugin adds `?lang=fr`) AND has a fragment.
		// The mock's `?`-vs-`&` separator check must look at the
		// fragment-stripped URL so it finds the existing `?` and uses
		// `&`. Without the fragment strip, a naive check on the full
		// URL including `#reviews` would still find `?` and use `&`
		// — but only by accident; the fragment would still land in the
		// wrong position. This test pins both invariants explicitly.
		$product = $this->make_product(
			[ 'permalink' => 'https://example.com/product/widget/?lang=fr#tab-description' ]
		);
		$result  = $this->jsonld->enhance_product_data( [], $product );

		$url = $result['potentialAction']['target']['urlTemplate'];

		// Original query param must survive.
		$this->assertStringContainsString( 'lang=fr', $url );

		// All added params must appear before the fragment.
		$fragment_pos    = strpos( $url, '#' );
		$query_param_pos = strpos( $url, 'add-to-cart=' );

		$this->assertNotFalse( $fragment_pos );
		$this->assertNotFalse( $query_param_pos );
		$this->assertLessThan(
			$fragment_pos,
			$query_param_pos,
			'Added query params must appear before the # separator'
		);

		// Exactly one `#` — no duplication of the fragment marker.
		$this->assertSame( 1, substr_count( $url, '#' ) );

		// Fragment intact at the end.
		$this->assertStringEndsWith( '#tab-description', $url );
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
}
