<?php
/**
 * Tests for WC_AI_Syndication_Ucp.
 *
 * Pins the UCP discovery profile manifest shape against the official
 * business_profile schema at:
 *
 *   https://github.com/Universal-Commerce-Protocol/ucp/blob/main/source/discovery/profile_schema.json
 *
 * Tests are structured in three layers:
 *
 *   1. Strict UCP compliance — required fields, shapes, enum values.
 *      These must never drift or our manifest stops being spec-compliant.
 *   2. Pull-model posture — zero capabilities, zero payment_handlers.
 *      This is the plugin's core product decision (no delegated checkout,
 *      no identity linking, no agent-driven order flows).
 *   3. Plugin-specific config — the purchase_urls and attribution nested
 *      inside service.config. Permissive by design (UCP allows it), but
 *      agents that learn our namespace depend on the shape.
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

		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . ( $path ?: '/' )
		);
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://example.com/cart/' );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://example.com/checkout/' );
		Functions\when( 'rest_url' )->alias(
			static fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' )
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Store-context defaults. Individual tests override via
		// Functions\when() to exercise specific scenarios (e.g.
		// VAT-inclusive EU store, digital-only catalog).
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wc_prices_include_tax' )->justReturn( false );
		Functions\when( 'wc_shipping_enabled' )->justReturn( true );
		// WC() isn't stubbed — `build_store_context()` falls through
		// to `country => null` when the global function isn't
		// available. Specific country-testing scenarios stub it.
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Layer 1: Strict UCP compliance
	// ------------------------------------------------------------------

	public function test_manifest_has_required_top_level_ucp_key(): void {
		// The UCP business_profile schema requires `ucp` at the top
		// level. Everything else nests inside it.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayHasKey( 'ucp', $manifest );
	}

	public function test_ucp_object_has_required_fields(): void {
		// business_schema requires version, services, and
		// payment_handlers. `capabilities` is optional but we always
		// emit it as an empty object to be explicit.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayHasKey( 'version', $manifest['ucp'] );
		$this->assertArrayHasKey( 'services', $manifest['ucp'] );
		$this->assertArrayHasKey( 'payment_handlers', $manifest['ucp'] );
		$this->assertArrayHasKey( 'capabilities', $manifest['ucp'] );
	}

	public function test_version_is_yyyy_mm_dd_format(): void {
		// UCP schema pattern: ^\d{4}-\d{2}-\d{2}$ — semver like "1.0"
		// would be rejected by strict consumers.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}$/',
			$manifest['ucp']['version']
		);
	}

	public function test_version_constant_matches_manifest_version(): void {
		// Consistency check: the constant is what the serving code
		// uses elsewhere (cache key versioning, etc.), so it must match
		// what ends up in the manifest.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals(
			WC_AI_Syndication_Ucp::PROTOCOL_VERSION,
			$manifest['ucp']['version']
		);
	}

	// ------------------------------------------------------------------
	// Layer 1: Services registry (the one declared service)
	// ------------------------------------------------------------------

	public function test_services_registry_is_keyed_by_reverse_domain_name(): void {
		// UCP's propertyNames schema requires reverse-domain form for
		// both service and capability keys. The constant has to match
		// this pattern; verify at the manifest level too.
		$manifest = $this->ucp->generate_manifest( [] );

		$keys = array_keys( (array) $manifest['ucp']['services'] );
		$this->assertCount( 1, $keys );
		$this->assertMatchesRegularExpression(
			'/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9_]*)+$/',
			$keys[0]
		);
	}

	public function test_service_name_is_dev_ucp_shopping(): void {
		// Plugin 1.3.0 advertises `dev.ucp.shopping` — the canonical UCP
		// shopping service identifier — rather than the pre-1.3.0
		// `com.woocommerce.store_api`. Agents use this key to discover
		// our UCP endpoint base URL.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayHasKey(
			'dev.ucp.shopping',
			$manifest['ucp']['services']
		);
	}

	public function test_old_store_api_service_no_longer_declared(): void {
		// Regression guard: any future refactor that re-adds the
		// pre-1.3.0 `com.woocommerce.store_api` service alongside
		// `dev.ucp.shopping` would be misleading — the WC Store API
		// remains accessible at its standard path without needing
		// manifest advertisement.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayNotHasKey(
			'com.woocommerce.store_api',
			$manifest['ucp']['services']
		);
	}

	public function test_service_value_is_an_array_of_bindings(): void {
		// UCP schema: `services` is `object of string → array of service`.
		// Each key maps to an ARRAY, not a single binding, so a service
		// can declare multiple transport bindings in the future.
		$manifest = $this->ucp->generate_manifest( [] );
		$bindings = $manifest['ucp']['services']['dev.ucp.shopping'];

		$this->assertIsArray( $bindings );
		$this->assertCount( 1, $bindings );
	}

	public function test_service_binding_has_required_rest_fields(): void {
		// For business_schema REST transport, `endpoint` is required.
		// `version` and `transport` are required on all entities;
		// `spec` is required at platform level and good practice at
		// business level (lets agents find human docs).
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['services']['dev.ucp.shopping'][0];

		$this->assertArrayHasKey( 'version', $binding );
		$this->assertArrayHasKey( 'transport', $binding );
		$this->assertArrayHasKey( 'endpoint', $binding );
		$this->assertArrayHasKey( 'spec', $binding );
	}

	public function test_transport_enum_is_rest(): void {
		// UCP transport enum: rest | mcp | a2a | embedded.
		// Our UCP endpoint is REST-transported.
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['services']['dev.ucp.shopping'][0];

		$this->assertEquals( 'rest', $binding['transport'] );
	}

	public function test_endpoint_points_to_ucp_adapter_base_url(): void {
		// The UCP base URL is where agents dispatch POST
		// /catalog/search, /catalog/lookup, /checkout-sessions. If
		// this URL drifts from the registered REST routes, agents
		// following the manifest hit 404s.
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['services']['dev.ucp.shopping'][0];

		$this->assertEquals(
			'https://example.com/wp-json/wc/ucp/v1',
			$binding['endpoint']
		);
	}

	public function test_service_spec_url_points_to_ucp_overview(): void {
		// The service-level `spec` URL points at the UCP overview
		// documentation page on ucp.dev, pinned to our protocol
		// version. Pre-1.6.4 this pointed at the GitHub schema
		// directory listing — not a "specification document" per
		// the entity schema's intent. See 1.6.4 changelog for the
		// migration rationale.
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['services']['dev.ucp.shopping'][0];

		$this->assertStringStartsWith(
			'https://ucp.dev/' . WC_AI_Syndication_Ucp::PROTOCOL_VERSION,
			$binding['spec']
		);
		$this->assertStringEndsWith( '/specification/overview', $binding['spec'] );
	}

	public function test_service_schema_url_points_to_openapi_doc(): void {
		// 1.6.4 added `schema` to the service binding — pinned to
		// the OpenAPI 3.1 spec for the UCP Shopping REST service.
		// Agents wanting machine-readable contract validation use
		// this URL; the OpenAPI doc's `{endpoint}` server variable
		// is a placeholder they substitute with the merchant's
		// actual endpoint (our service binding's `endpoint` field).
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['services']['dev.ucp.shopping'][0];

		$this->assertArrayHasKey( 'schema', $binding );
		$this->assertStringStartsWith(
			'https://ucp.dev/' . WC_AI_Syndication_Ucp::PROTOCOL_VERSION,
			$binding['schema']
		);
		$this->assertStringEndsWith( '.openapi.json', $binding['schema'] );
	}

	// ------------------------------------------------------------------
	// Layer 2: Declared capabilities (catalog + checkout) + pull-model
	// payment posture (zero handlers)
	// ------------------------------------------------------------------

	public function test_capabilities_declares_shopping_catalog_sub_capabilities(): void {
		// Since 1.6.0 we advertise the two catalog sub-capabilities
		// explicitly (`.search` + `.lookup`) rather than the umbrella
		// `dev.ucp.shopping.catalog`. The April UCP spec formalized
		// these as separate schemas; splitting the advertisement
		// lets agents discover precisely which operations are
		// available. Both sub-capabilities resolve to the same
		// REST endpoint (`/wp-json/wc/ucp/v1/catalog/*`).
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayHasKey(
			'dev.ucp.shopping.catalog.search',
			$manifest['ucp']['capabilities']
		);
		$this->assertArrayHasKey(
			'dev.ucp.shopping.catalog.lookup',
			$manifest['ucp']['capabilities']
		);

		// Regression guard: the umbrella name is specifically NOT
		// advertised. An agent iterating the capability map that
		// matches on `dev.ucp.shopping.catalog` (without a trailing
		// `.search` / `.lookup`) should get zero hits — so it
		// migrates to the sub-capability keys rather than silently
		// continuing to rely on the deprecated name.
		$this->assertArrayNotHasKey(
			'dev.ucp.shopping.catalog',
			$manifest['ucp']['capabilities']
		);
	}

	public function test_capabilities_declares_shopping_checkout(): void {
		// checkout is stateless one-shot — see UcpCheckoutSessionsTest
		// for the full semantic. The manifest declares we implement
		// the capability name AND hints at the handoff-only flavor
		// via a `mode` field (see next test); the actual
		// redirect-only behavior is also communicated via
		// `status: requires_escalation` in response bodies.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayHasKey(
			'dev.ucp.shopping.checkout',
			$manifest['ucp']['capabilities']
		);
	}

	public function test_checkout_capability_declares_handoff_mode(): void {
		// Agents reading the manifest need a programmatic signal that
		// our checkout is redirect-only (no in-chat payment, no
		// server-side cart lifecycle) before deciding to invoke the
		// endpoint. Without this hint they have to call the endpoint
		// and parse `status: requires_escalation` in the response —
		// wasted roundtrip for agents that don't support handoff
		// flows. The `mode: handoff` field is a schema-compatible
		// additive hint (UCP entities allow additionalProperties).
		//
		// If this field is ever dropped, agents that relied on the
		// upfront signal would regress to the roundtrip. Locks in the
		// contract.
		$manifest = $this->ucp->generate_manifest( [] );

		$binding = $manifest['ucp']['capabilities']['dev.ucp.shopping.checkout'][0];
		$this->assertArrayHasKey( 'mode', $binding );
		$this->assertEquals( 'handoff', $binding['mode'] );
	}

	public function test_catalog_sub_capabilities_have_no_mode_hint(): void {
		// Catalog search and lookup are both fully supported —
		// "read-only" is implicit in the capability names; no mode
		// flag needed. Mode hints belong only on capabilities with
		// more than one operational posture (currently just checkout
		// with its handoff/non-handoff split).
		$manifest = $this->ucp->generate_manifest( [] );

		foreach ( [ 'dev.ucp.shopping.catalog.search', 'dev.ucp.shopping.catalog.lookup' ] as $cap ) {
			$binding = $manifest['ucp']['capabilities'][ $cap ][0];
			$this->assertArrayNotHasKey( 'mode', $binding, "$cap should have no mode hint" );
		}
	}

	public function test_each_capability_value_is_array_of_versioned_bindings(): void {
		// Per UCP business_profile schema, each capability key maps to
		// an ARRAY of binding objects (one per implementation version) —
		// the same {key: [{version}]} shape the services map uses. The
		// array wrapper leaves room to advertise multiple versions
		// concurrently; a bare object (single binding without the array)
		// would fail strict schema validation.
		$manifest = $this->ucp->generate_manifest( [] );

		$capabilities = [
			'dev.ucp.shopping.catalog.search',
			'dev.ucp.shopping.catalog.lookup',
			'dev.ucp.shopping.checkout',
		];

		foreach ( $capabilities as $cap ) {
			$bindings = $manifest['ucp']['capabilities'][ $cap ];

			$this->assertIsArray( $bindings, "$cap should be an array" );
			$this->assertCount( 1, $bindings, "$cap should declare exactly one binding" );
			$this->assertEquals(
				WC_AI_Syndication_Ucp::PROTOCOL_VERSION,
				$bindings[0]['version'],
				"$cap binding version should match plugin PROTOCOL_VERSION"
			);
		}
	}

	public function test_no_extraneous_capabilities_declared(): void {
		// Regression guard: we implement catalog.search + catalog.lookup
		// + checkout only. If a future refactor accidentally declared
		// cart, order, identity linking, payment token exchange, etc.,
		// agents would try to invoke operations we haven't built — and
		// fail. Declaring only what we've implemented is the honest
		// posture. When we DO add new capabilities (order is the likely
		// 1.7.0 candidate) this test gets updated explicitly.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEqualsCanonicalizing(
			[
				'dev.ucp.shopping.catalog.search',
				'dev.ucp.shopping.catalog.lookup',
				'dev.ucp.shopping.checkout',
			],
			array_keys( $manifest['ucp']['capabilities'] )
		);
	}

	public function test_payment_handlers_is_empty_object(): void {
		// Required top-level key per business_schema. Empty object
		// declares "zero handlers" — valid for merchants who don't
		// mediate payments through UCP.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals(
			'{}',
			wp_json_encode_or_native( $manifest['ucp']['payment_handlers'] )
		);
	}

	public function test_no_checkout_top_level_key(): void {
		// UCP has no top-level `checkout` object — checkout is a
		// `capability` businesses implement or don't. Our previous
		// manifest had a top-level `checkout` block declaring
		// "web_redirect only"; that's now implicit (zero capabilities).
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayNotHasKey( 'checkout', $manifest );
		$this->assertArrayNotHasKey( 'checkout', $manifest['ucp'] ?? [] );
	}

	public function test_no_stale_pre_ucp_top_level_fields(): void {
		// Regression guard: the 1.1.x manifest had these fields at the
		// top level. If a future refactor accidentally resurrects them
		// alongside the `ucp` key, the manifest violates the schema.
		//
		// Note: `store_context` IS a top-level field (added in 1.4.5) —
		// intentionally a sibling to `ucp`, not a stale leftover. Tests
		// for that live in the store_context section below.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayNotHasKey( 'protocol_version', $manifest );
		$this->assertArrayNotHasKey( 'store', $manifest );
		$this->assertArrayNotHasKey( 'purchase', $manifest );
		$this->assertArrayNotHasKey( 'attribution', $manifest );
		$this->assertArrayNotHasKey( 'discovery', $manifest );
		$this->assertArrayNotHasKey( 'rate_limits', $manifest );
		$this->assertArrayNotHasKey( 'store_api', $manifest );
	}

	// ------------------------------------------------------------------
	// Layer 2.5: store_context block (1.4.5+)
	// ------------------------------------------------------------------
	//
	// Added in 1.4.5 after cross-agent review feedback that the
	// manifest lacked merchant-level commerce context (currency,
	// locale, tax/shipping posture). These tests lock in the five
	// contract fields and cover the ICU→BCP 47 locale conversion
	// that's the most likely regression source — WordPress stores
	// locales in underscore form and it's easy to forward that
	// verbatim into the manifest by mistake.

	public function test_manifest_has_store_context_top_level_key(): void {
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayHasKey( 'store_context', $manifest );
		$this->assertIsArray( $manifest['store_context'] );
	}

	public function test_store_context_declares_iso_4217_currency(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );

		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertSame( 'EUR', $manifest['store_context']['currency'] );
	}

	public function test_store_context_locale_uses_bcp_47_hyphen_not_icu_underscore(): void {
		// WordPress stores `en_US`; agents consuming the manifest
		// expect web-standard `en-US`. This conversion is the single
		// biggest correctness risk in the block — stubbing the WP
		// side and asserting the manifest side locks it in.
		Functions\when( 'get_locale' )->justReturn( 'pt_BR' );

		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertSame( 'pt-BR', $manifest['store_context']['locale'] );
		$this->assertStringNotContainsString( '_', $manifest['store_context']['locale'] );
	}

	public function test_store_context_reports_prices_include_tax(): void {
		// EU-style VAT-inclusive storefront.
		Functions\when( 'wc_prices_include_tax' )->justReturn( true );

		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertTrue( $manifest['store_context']['prices_include_tax'] );
	}

	public function test_store_context_reports_prices_exclude_tax(): void {
		// US-style tax-exclusive storefront — default in the
		// setUp stub.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertFalse( $manifest['store_context']['prices_include_tax'] );
	}

	public function test_store_context_reports_shipping_disabled_for_digital_only(): void {
		Functions\when( 'wc_shipping_enabled' )->justReturn( false );

		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertFalse( $manifest['store_context']['shipping_enabled'] );
	}

	public function test_store_context_country_is_null_when_wc_countries_unavailable(): void {
		// WC() not stubbed in this test environment; the code path
		// falls through to null. Asserting this locks in the
		// graceful-degradation branch: the manifest must not crash
		// or emit garbage when the WC global isn't ready.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertNull( $manifest['store_context']['country'] );
	}

	public function test_store_context_fields_are_exactly_those_documented(): void {
		// Regression guard against field drift. If a future refactor
		// adds a new key to store_context without also updating
		// consumer documentation, this test fires. The fix is
		// deliberate: either update this test (conscious addition)
		// or remove the stray field.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertSame(
			[ 'currency', 'locale', 'country', 'prices_include_tax', 'shipping_enabled' ],
			array_keys( $manifest['store_context'] )
		);
	}

	public function test_spec_and_schema_urls_are_pinned_to_protocol_version(): void {
		// Iteration of spec-URL pinning through 1.4.5 → 1.6.4:
		//   - Pre-1.4.5: `/tree/main/` (moving target, wrong)
		//   - 1.4.5: `/tree/v{VERSION}/source/schemas/shopping` (pinned
		//           but at a GitHub directory listing, not a spec doc)
		//   - 1.6.4: `https://ucp.dev/{VERSION}/...` (pinned at the
		//           canonical docs site + OpenAPI schema)
		//
		// This test locks in the 1.6.4 shape: all external URLs
		// use `ucp.dev/{PROTOCOL_VERSION}/` so one constant drives
		// every spec/schema reference in the manifest.
		$manifest = $this->ucp->generate_manifest( [] );
		$service  = $manifest['ucp']['services']['dev.ucp.shopping'][0];
		$version  = WC_AI_Syndication_Ucp::PROTOCOL_VERSION;

		// Service-level URLs.
		$this->assertStringContainsString( "/{$version}/", $service['spec'] );
		$this->assertStringContainsString( "/{$version}/", $service['schema'] );

		// Capability-level URLs.
		$caps = $manifest['ucp']['capabilities'];
		foreach ( $caps as $name => $bindings ) {
			foreach ( $bindings as $binding ) {
				if ( isset( $binding['spec'] ) ) {
					$this->assertStringContainsString( "/{$version}/", $binding['spec'], "spec URL for $name must be version-pinned" );
				}
				if ( isset( $binding['schema'] ) ) {
					$this->assertStringContainsString( "/{$version}/", $binding['schema'], "schema URL for $name must be version-pinned" );
				}
			}
		}

		// Regression guard against the pre-1.6.4 GitHub tree URL.
		$this->assertStringNotContainsString( '/tree/main/', $service['spec'] );
		$this->assertStringNotContainsString( 'github.com', $service['spec'] );
	}

	public function test_every_capability_has_spec_and_schema_urls(): void {
		// 1.6.4 added per-capability `spec` + `schema` URLs.
		// Agents that want to validate response payloads against
		// the authoritative contract need these. Locks in both
		// fields on every advertised capability.
		$manifest = $this->ucp->generate_manifest( [] );

		foreach ( $manifest['ucp']['capabilities'] as $name => $bindings ) {
			foreach ( $bindings as $binding ) {
				$this->assertArrayHasKey( 'spec', $binding, "Capability $name missing spec URL" );
				$this->assertArrayHasKey( 'schema', $binding, "Capability $name missing schema URL" );
				$this->assertNotEmpty( $binding['spec'] );
				$this->assertNotEmpty( $binding['schema'] );
			}
		}
	}

	// ------------------------------------------------------------------
	// Layer 3: Plugin-specific checkout capability config
	//
	// Note: relocated from service-level to checkout-capability-level
	// in 1.6.4. Service-level config is for transport concerns (auth,
	// rate limits); capability-level config is for capability-semantic
	// concerns (purchase URL templates, UTM attribution). The fields
	// here describe how agents construct checkout URLs and attribute
	// orders — both semantically belong with the checkout capability.
	// ------------------------------------------------------------------

	private function get_config(): array {
		$manifest = $this->ucp->generate_manifest( [] );
		return $manifest['ucp']['capabilities']['dev.ucp.shopping.checkout'][0]['config'];
	}

	public function test_checkout_capability_config_has_purchase_urls_and_attribution(): void {
		$config = $this->get_config();

		$this->assertArrayHasKey( 'purchase_urls', $config );
		$this->assertArrayHasKey( 'attribution', $config );
	}

	public function test_service_binding_has_no_capability_config(): void {
		// Regression guard: the pre-1.6.4 placement of
		// `purchase_urls` + `attribution` under `services[0].config`
		// was semantically wrong. If a future refactor re-adds it
		// there (or leaves both copies), this catches the drift.
		//
		// Two valid post-1.6.4 states:
		//   - Service has NO `config` key (current implementation)
		//   - Service has `config` but without capability-semantic
		//     fields (future transport-config additions would live
		//     there, but purchase_urls/attribution never should)
		$manifest = $this->ucp->generate_manifest( [] );
		$service  = $manifest['ucp']['services']['dev.ucp.shopping'][0];

		if ( ! isset( $service['config'] ) ) {
			$this->assertTrue( true, 'Service has no config key — structurally impossible for capability fields to live there' );
			return;
		}

		$this->assertArrayNotHasKey(
			'purchase_urls',
			$service['config'],
			'purchase_urls belongs on the checkout capability, not the service binding'
		);
		$this->assertArrayNotHasKey(
			'attribution',
			$service['config'],
			'attribution belongs on the checkout capability, not the service binding'
		);
	}

	// ----- purchase_urls.checkout_link ----------------------------------

	public function test_checkout_link_templates_cover_all_supported_types(): void {
		// Agents generating purchase URLs need the full vocabulary of
		// product types the /checkout-link/ feature supports. Missing
		// one means that product type can't be handled by agents that
		// only read the manifest.
		$cl = $this->get_config()['purchase_urls']['checkout_link'];

		$this->assertArrayHasKey( 'simple', $cl );
		$this->assertArrayHasKey( 'variable', $cl );
		$this->assertArrayHasKey( 'multi_item', $cl );
		$this->assertArrayHasKey( 'with_coupon', $cl );
		$this->assertArrayHasKey( 'unsupported', $cl );
	}

	public function test_checkout_link_simple_template_matches_wc_spec(): void {
		// Per https://woocommerce.com/document/creating-sharable-checkout-urls-in-woocommerce/
		// single-product format is: /checkout-link/?products=PRODUCT_ID:QUANTITY
		$cl = $this->get_config()['purchase_urls']['checkout_link'];

		$this->assertEquals(
			'https://example.com/checkout-link/?products={product_id}:{quantity}',
			$cl['simple']
		);
	}

	public function test_checkout_link_multi_item_uses_comma_separator(): void {
		// Per WC docs: multi-product format is
		// `?products=id:qty,id:qty`.
		$cl = $this->get_config()['purchase_urls']['checkout_link'];

		$this->assertStringContainsString( ',', $cl['multi_item'] );
		$this->assertStringContainsString( ':', $cl['multi_item'] );
	}

	public function test_checkout_link_with_coupon_includes_coupon_param(): void {
		$cl = $this->get_config()['purchase_urls']['checkout_link'];

		$this->assertStringContainsString( 'coupon={coupon_code}', $cl['with_coupon'] );
	}

	public function test_checkout_link_declares_unsupported_types(): void {
		// Grouped, external, and subscription products CANNOT use the
		// /checkout-link/ feature. Agents need to know not to try.
		$cl = $this->get_config()['purchase_urls']['checkout_link'];

		$this->assertEqualsCanonicalizing(
			[ 'grouped', 'external', 'subscription' ],
			$cl['unsupported']
		);
	}

	// ----- purchase_urls.add_to_cart ------------------------------------

	public function test_add_to_cart_has_three_redirect_variants(): void {
		// Per https://woocommerce.com/document/quick-guide-to-woocommerce-add-to-cart-urls/
		// the three variants are: no redirect (home), redirect to cart,
		// redirect to checkout. All three have different base URLs.
		$a2c = $this->get_config()['purchase_urls']['add_to_cart'];

		$this->assertArrayHasKey( 'add_only', $a2c );
		$this->assertArrayHasKey( 'add_and_cart', $a2c );
		$this->assertArrayHasKey( 'add_and_checkout', $a2c );
	}

	public function test_add_and_cart_uses_cart_url_as_base(): void {
		$a2c = $this->get_config()['purchase_urls']['add_to_cart'];

		$this->assertStringStartsWith(
			'https://example.com/cart/',
			$a2c['add_and_cart']
		);
	}

	public function test_add_and_checkout_uses_checkout_url_as_base(): void {
		$a2c = $this->get_config()['purchase_urls']['add_to_cart'];

		$this->assertStringStartsWith(
			'https://example.com/checkout/',
			$a2c['add_and_checkout']
		);
	}

	public function test_add_to_cart_grouped_has_template_and_note(): void {
		// Grouped products have a separate entry because their URL
		// shape differs (quantity is a per-sub-product map, not a
		// single number). The note documents the quirk.
		$grouped = $this->get_config()['purchase_urls']['add_to_cart']['grouped'];

		$this->assertArrayHasKey( 'template', $grouped );
		$this->assertArrayHasKey( 'note', $grouped );
		$this->assertStringContainsString(
			'quantity[{sub_product_id}]={quantity}',
			$grouped['template']
		);
	}

	public function test_add_to_cart_external_note_present(): void {
		// External/affiliate products must not be add-to-carted —
		// agents link directly to the product's external_url field.
		$a2c = $this->get_config()['purchase_urls']['add_to_cart'];

		$this->assertArrayHasKey( 'external_note', $a2c );
		$this->assertStringContainsString( 'external_url', $a2c['external_note'] );
	}

	// ----- purchase_urls spec pointers ----------------------------------

	public function test_purchase_urls_points_to_canonical_wc_spec(): void {
		// Both spec URLs were verified during authorship. If they ever
		// break, AI agents reading the manifest get pointed at broken
		// docs. A link-check on these in CI would be ideal future work.
		$pu = $this->get_config()['purchase_urls'];

		$this->assertEquals(
			'https://woocommerce.com/document/creating-sharable-checkout-urls-in-woocommerce/',
			$pu['spec']
		);
		$this->assertEquals(
			'https://woocommerce.com/document/quick-guide-to-woocommerce-add-to-cart-urls/',
			$pu['add_to_cart_spec']
		);
	}

	// ----- attribution ---------------------------------------------------

	public function test_attribution_declares_woocommerce_order_attribution(): void {
		// The plugin's attribution strategy is "use WC's built-in
		// system" — not a custom invention. The `system` field makes
		// that explicit for agents that want to verify.
		$attr = $this->get_config()['attribution'];

		$this->assertEquals( 'woocommerce_order_attribution', $attr['system'] );
	}

	public function test_attribution_exposes_required_utm_parameters(): void {
		$attr = $this->get_config()['attribution'];

		$this->assertArrayHasKey( 'utm_source', $attr['parameters'] );
		$this->assertArrayHasKey( 'utm_medium', $attr['parameters'] );
		$this->assertArrayHasKey( 'utm_campaign', $attr['parameters'] );
		$this->assertArrayHasKey( 'ai_session_id', $attr['parameters'] );
	}

	public function test_attribution_utm_medium_documents_required_value(): void {
		// utm_medium MUST be "ai_agent" for the PHP-side detector to
		// capture the order. Document that in the param description
		// so agents know it's not free-form.
		$attr = $this->get_config()['attribution'];

		$this->assertStringContainsString(
			'ai_agent',
			$attr['parameters']['utm_medium']
		);
	}

	public function test_attribution_spec_points_to_wc_order_attribution_docs(): void {
		$attr = $this->get_config()['attribution'];

		$this->assertEquals(
			'https://woocommerce.com/document/order-attribution-tracking/',
			$attr['spec']
		);
	}

	// ------------------------------------------------------------------
	// Filter extensibility
	// ------------------------------------------------------------------

	public function test_manifest_is_filterable(): void {
		// The filter preserves the v1 signature: `apply_filters(
		// 'wc_ai_syndication_ucp_manifest', $manifest, $settings )`.
		// Third parties can extend the manifest (e.g., a plugin that
		// adds Payment Token Exchange would inject a payment handler
		// under ucp.payment_handlers).
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_syndication_ucp_manifest' === $hook ) {
					$value['ucp']['custom_key'] = 'extended';
				}
				return $value;
			}
		);

		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals( 'extended', $manifest['ucp']['custom_key'] );
	}
}

/**
 * Helper: encode JSON using native PHP json_encode (avoids depending on
 * wp_json_encode, which isn't stubbed for every test). The key behavior
 * we're verifying is that `(object) []` serializes as `{}` not `[]`.
 *
 * @param mixed $value Value to encode.
 * @return string
 */
function wp_json_encode_or_native( $value ): string {
	return json_encode( $value, JSON_UNESCAPED_SLASHES );
}
