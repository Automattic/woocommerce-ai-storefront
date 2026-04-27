<?php
/**
 * Tests for WC_AI_Storefront_Ucp.
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
 *   3. Plugin-specific config — the `store_context` nested inside
 *      the `com.woocommerce.ai_storefront` extension capability.
 *      Permissive by design (UCP allows it), but agents that learn
 *      our namespace depend on the shape.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Ucp $ucp;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->ucp = new WC_AI_Storefront_Ucp();

		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . ( $path ?: '/' )
		);
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://example.com/cart/' );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://example.com/checkout/' );
		Functions\when( 'rest_url' )->alias(
			static fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' )
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// `__()` returns the input string verbatim — manifest content
		// includes `agent_guide` whose value goes through `__()`. Without
		// this stub Brain Monkey errors with "function not mocked" the
		// moment `generate_manifest()` builds the extension capability.
		Functions\when( '__' )->returnArg( 1 );

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
			WC_AI_Storefront_Ucp::PROTOCOL_VERSION,
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
			'https://ucp.dev/' . WC_AI_Storefront_Ucp::PROTOCOL_VERSION,
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
			'https://ucp.dev/' . WC_AI_Storefront_Ucp::PROTOCOL_VERSION,
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

	public function test_checkout_capability_has_no_mode_or_config(): void {
		// 1.6.5 reversal: pre-1.6.5 we emitted `mode: "handoff"` +
		// a `config` block with URL templates on the checkout
		// capability. Neither is defined in UCP's capability.json —
		// both were additive under the spec's
		// `additionalProperties: true`. The canonical UCP checkout
		// handoff contract is runtime: the endpoint returns
		// `status: "requires_escalation"` + a `continue_url`. The
		// spec's SHOULD directive prefers business-provided
		// continue_url over platform-constructed checkout permalinks,
		// which argued against keeping the template library here.
		//
		// Post-1.6.5, the checkout capability is canonical UCP only.
		// Merchant-specific `store_context` lives in the
		// `com.woocommerce.ai_storefront` extension capability;
		// attribution was subsequently dropped from the extension
		// too (the server-side `continue_url` injects UTM values
		// from the UCP-Agent header, making a machine-readable
		// attribution block redundant with the live contract).
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['capabilities']['dev.ucp.shopping.checkout'][0];

		$this->assertArrayNotHasKey( 'mode', $binding, 'mode was removed in 1.6.5 — non-canonical hint replaced by runtime status signal' );
		$this->assertArrayNotHasKey( 'config', $binding, 'config was removed in 1.6.5 — URL templates moved to llms.txt; canonical flow is POST /checkout-sessions' );
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

		// Includes the 1.6.5 extension capability
		// (com.woocommerce.ai_storefront) — extensions follow the
		// same [{binding}] shape as canonical capabilities per the
		// UCP capability schema.
		$capabilities = [
			'dev.ucp.shopping.catalog.search',
			'dev.ucp.shopping.catalog.lookup',
			'dev.ucp.shopping.checkout',
			'com.woocommerce.ai_storefront',
		];

		foreach ( $capabilities as $cap ) {
			$bindings = $manifest['ucp']['capabilities'][ $cap ];

			$this->assertIsArray( $bindings, "$cap should be an array" );
			$this->assertCount( 1, $bindings, "$cap should declare exactly one binding" );
			$this->assertEquals(
				WC_AI_Storefront_Ucp::PROTOCOL_VERSION,
				$bindings[0]['version'],
				"$cap binding version should match plugin PROTOCOL_VERSION"
			);
		}
	}

	public function test_declared_capabilities_are_exactly_three_canonical_plus_one_extension(): void {
		// Regression guard on the exact capability set:
		//   - 3 canonical UCP capabilities we implement
		//     (catalog.search, catalog.lookup, checkout)
		//   - 1 merchant-specific extension carrying store_context
		//     only (com.woocommerce.ai_storefront)
		//
		// Extensions use the `extends` field to link back to the
		// parent capability/service; canonical capabilities have
		// no `extends`. That's the structural invariant below.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEqualsCanonicalizing(
			[
				'dev.ucp.shopping.catalog.search',
				'dev.ucp.shopping.catalog.lookup',
				'dev.ucp.shopping.checkout',
				'com.woocommerce.ai_storefront',
			],
			array_keys( $manifest['ucp']['capabilities'] )
		);

		// Canonical capabilities MUST NOT have `extends` (they ARE
		// the base capabilities other things extend).
		foreach ( [ 'dev.ucp.shopping.catalog.search', 'dev.ucp.shopping.catalog.lookup', 'dev.ucp.shopping.checkout' ] as $canonical ) {
			$this->assertArrayNotHasKey(
				'extends',
				$manifest['ucp']['capabilities'][ $canonical ][0],
				"$canonical is a canonical capability — it should not carry an extends field"
			);
		}

		// The extension MUST carry `extends` pointing at parent
		// capability(ies). Per the UCP 2026-04-08 capability schema:
		// "Parent capability(s) this extends. Use array for multi-
		// parent extensions." Pre-0.1.9 this asserted the string form
		// pointing at the service ID `dev.ucp.shopping` (a service is
		// not a capability — schema regex passed but the description
		// didn't endorse it). 0.1.9 switched to the array form
		// listing all three canonical shopping capabilities, since
		// the extension's `store_context` applies to search, lookup,
		// AND checkout (not to "the service" abstractly).
		$ext = $manifest['ucp']['capabilities']['com.woocommerce.ai_storefront'][0];
		$this->assertSame(
			[
				'dev.ucp.shopping.catalog.search',
				'dev.ucp.shopping.catalog.lookup',
				'dev.ucp.shopping.checkout',
			],
			$ext['extends']
		);
	}

	public function test_extends_entries_are_real_capabilities(): void {
		// Semantic invariant: every entry in `extends` must reference
		// a key that actually exists under `manifest.capabilities`.
		// The shape-only assertion above (literal array equality)
		// catches typos in the test, but a future PR that edits both
		// the production array AND this test to reference a fictional
		// capability ID — say, `dev.ucp.shopping.catalog.fictional` —
		// would pass shape-equality and silently produce a structurally
		// invalid manifest. This loop closes that gap by tying
		// `extends` to the actual declared capability set.
		//
		// Doubles as a regression guard against the `CANONICAL_CAPABILITIES`
		// constant + canonical-capability declarations drifting apart:
		// if a future PR renames a capability suffix in only one place,
		// this test fires.
		$manifest    = $this->ucp->generate_manifest( [] );
		$declared    = array_keys( $manifest['ucp']['capabilities'] );
		$ext         = $manifest['ucp']['capabilities']['com.woocommerce.ai_storefront'][0];
		$extends_ids = (array) $ext['extends'];

		$this->assertNotEmpty( $extends_ids, 'extends must declare at least one parent' );

		foreach ( $extends_ids as $cap_id ) {
			$this->assertContains(
				$cap_id,
				$declared,
				"extends references `$cap_id` but no such capability is declared in manifest.capabilities"
			);
		}
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
	// Layer 2.5: com.woocommerce.ai_storefront extension capability
	// ------------------------------------------------------------------
	//
	// Pre-1.6.5: store_context lived as a root-level sibling of the
	// `ucp` wrapper. 1.6.5 moved it inside the extension capability
	// `com.woocommerce.ai_storefront.config.store_context` per the
	// UCP spec's extension pattern (a capability with `extends`
	// pointing at a parent). The five store_context contract fields
	// are unchanged; only the path in the manifest changed.
	//
	// 1.6.5 also parked an `attribution` config block under the same
	// extension capability; it was later removed once the server-side
	// `continue_url` attribution contract was deemed sufficient
	// (utm_source + utm_medium are injected by the `/checkout-sessions`
	// endpoint, so agents don't need to read UTM conventions off the
	// manifest). `store_context` is the sole remaining config key.

	/**
	 * Resolve the extension's config.store_context block. Pre-1.6.5
	 * this was `$manifest['store_context']`; post-1.6.5 it's
	 * `$manifest['ucp']['capabilities']['com.woocommerce.ai_storefront'][0]['config']['store_context']`.
	 */
	private function get_store_context(): array {
		$manifest = $this->ucp->generate_manifest( [] );
		return $manifest['ucp']['capabilities']['com.woocommerce.ai_storefront'][0]['config']['store_context'];
	}

	public function test_store_context_no_longer_lives_at_manifest_root(): void {
		// Regression guard: the 1.4.5 → 1.6.4 placement at
		// `$manifest['store_context']` was moved inside the extension
		// capability in 1.6.5. A future refactor that re-emits it at
		// root (e.g. for perceived convenience) would collide with
		// this assertion and force a conscious re-decision.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayNotHasKey( 'store_context', $manifest );
	}

	public function test_store_context_declares_iso_4217_currency(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );

		$ctx = $this->get_store_context();

		$this->assertSame( 'EUR', $ctx['currency'] );
	}

	public function test_store_context_locale_uses_bcp_47_hyphen_not_icu_underscore(): void {
		// WordPress stores `en_US`; agents consuming the manifest
		// expect web-standard `en-US`. This conversion is the single
		// biggest correctness risk in the block — stubbing the WP
		// side and asserting the manifest side locks it in.
		Functions\when( 'get_locale' )->justReturn( 'pt_BR' );

		$ctx = $this->get_store_context();

		$this->assertSame( 'pt-BR', $ctx['locale'] );
		$this->assertStringNotContainsString( '_', $ctx['locale'] );
	}

	public function test_store_context_reports_prices_include_tax(): void {
		// EU-style VAT-inclusive storefront.
		Functions\when( 'wc_prices_include_tax' )->justReturn( true );

		$this->assertTrue( $this->get_store_context()['prices_include_tax'] );
	}

	public function test_store_context_reports_prices_exclude_tax(): void {
		// US-style tax-exclusive storefront — default in the
		// setUp stub.
		$this->assertFalse( $this->get_store_context()['prices_include_tax'] );
	}

	public function test_store_context_reports_shipping_disabled_for_digital_only(): void {
		Functions\when( 'wc_shipping_enabled' )->justReturn( false );

		$this->assertFalse( $this->get_store_context()['shipping_enabled'] );
	}

	public function test_store_context_country_is_null_when_wc_countries_unavailable(): void {
		// WC() not stubbed in this test environment; the code path
		// falls through to null. Asserting this locks in the
		// graceful-degradation branch: the manifest must not crash
		// or emit garbage when the WC global isn't ready.
		$this->assertNull( $this->get_store_context()['country'] );
	}

	public function test_store_context_fields_are_exactly_those_documented(): void {
		// Regression guard against field drift. If a future refactor
		// adds a new key to store_context without also updating
		// consumer documentation, this test fires. The fix is
		// deliberate: either update this test (conscious addition)
		// or remove the stray field.
		$this->assertSame(
			[ 'currency', 'locale', 'country', 'prices_include_tax', 'shipping_enabled' ],
			array_keys( $this->get_store_context() )
		);
	}

	// ------------------------------------------------------------------
	// agent_guide — operational guidance for clients that inject
	// the manifest into an LLM system prompt at session start.
	// UCPPlayground reads this field directly. Tests pin the field's
	// presence and content shape; the prose itself is intentionally
	// not pinned phrase-by-phrase since it can be tuned without
	// breaking the contract.
	// ------------------------------------------------------------------

	public function test_extension_config_includes_agent_guide(): void {
		// `agent_guide` lives at
		// `manifest.ucp.capabilities.com.woocommerce.ai_storefront[0].config.agent_guide`.
		// Same nesting level as `store_context`. Clients that inject
		// the manifest into an LLM context (e.g. UCPPlayground) read
		// from this field; structural drift would silently break that
		// contract.
		$manifest = $this->ucp->generate_manifest( [] );
		$config   = $manifest['ucp']['capabilities']['com.woocommerce.ai_storefront'][0]['config'];

		$this->assertArrayHasKey( 'agent_guide', $config );
	}

	public function test_agent_guide_is_non_empty_string(): void {
		// Whatever the prose, the field must be non-empty. An empty
		// string would inject blank context into the LLM and waste
		// the integration. Contract is "string with operational
		// guidance"; emptiness is a regression.
		$manifest = $this->ucp->generate_manifest( [] );
		$config   = $manifest['ucp']['capabilities']['com.woocommerce.ai_storefront'][0]['config'];

		$this->assertIsString( $config['agent_guide'] );
		$this->assertNotSame( '', $config['agent_guide'] );
	}

	public function test_agent_guide_mentions_required_concepts(): void {
		// Soft-pin the operational concepts that MUST be in the
		// guide for it to do its job. Phrasing can drift; these
		// concept anchors must not. If a future edit removes any
		// of these the test fires and forces a conscious revisit.
		//
		// 1. `requires_escalation` — the foundational "agents do not
		//    place orders directly here" signal.
		// 2. `continue_url` — how the agent hands off to merchant
		//    checkout.
		// 3. `/checkout-sessions` — the canonical entry-point path
		//    the agent should POST to.
		// 4. `UCP-Agent` — self-identification mechanism that drives
		//    attribution canonicalization.
		$manifest = $this->ucp->generate_manifest( [] );
		$guide    = $manifest['ucp']['capabilities']['com.woocommerce.ai_storefront'][0]['config']['agent_guide'];

		$this->assertStringContainsString( 'requires_escalation', $guide );
		$this->assertStringContainsString( 'continue_url', $guide );
		$this->assertStringContainsString( '/checkout-sessions', $guide );
		$this->assertStringContainsString( 'UCP-Agent', $guide );
	}

	public function test_spec_and_schema_urls_are_pinned_to_protocol_version(): void {
		// Iteration of spec-URL pinning through 1.4.5 → 1.6.4:
		//   - Pre-1.4.5: `/tree/main/` (moving target, wrong)
		//   - 1.4.5: `/tree/v{VERSION}/source/schemas/shopping` (pinned
		//           but at a GitHub directory listing, not a spec doc)
		//   - 1.6.4: `https://ucp.dev/{VERSION}/...` (pinned at the
		//           canonical docs site + OpenAPI schema)
		//   - Self-hosted extension (this PR): merchant-extension
		//           capability URLs are served from the merchant site
		//           (not ucp.dev) — they don't fit the version-pin
		//           rule because they always describe the CURRENT
		//           plugin version rather than a fixed UCP protocol
		//           revision.
		//
		// This test locks in the canonical-capability shape: all UCP
		// `dev.ucp.*` URLs use `ucp.dev/{PROTOCOL_VERSION}/`. Extension
		// capabilities (anything not under `dev.ucp.*`) are exempted
		// below and validated by a separate test.
		$manifest = $this->ucp->generate_manifest( [] );
		$service  = $manifest['ucp']['services']['dev.ucp.shopping'][0];
		$version  = WC_AI_Storefront_Ucp::PROTOCOL_VERSION;

		// Service-level URLs.
		$this->assertStringContainsString( "/{$version}/", $service['spec'] );
		$this->assertStringContainsString( "/{$version}/", $service['schema'] );

		// Capability-level URLs — canonical (`dev.ucp.*`) capabilities
		// point at ucp.dev and MUST be version-pinned. Extension
		// capabilities (our `com.woocommerce.ai_storefront`) are
		// self-hosted on the merchant site and exempted.
		$caps = $manifest['ucp']['capabilities'];
		foreach ( $caps as $name => $bindings ) {
			if ( 0 !== strpos( $name, 'dev.ucp.' ) ) {
				continue; // extension capability — skip
			}
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

	public function test_extension_capability_urls_are_self_hosted(): void {
		// The `com.woocommerce.ai_storefront` extension capability's
		// `spec` + `schema` URLs are served by the merchant site, not
		// by a third-party registry. This keeps docs always in sync
		// with the running plugin version, respects the site's own
		// access-control policy, and eliminates external dependencies.
		//
		// `spec` points at the llms.txt anchor; `schema` points at the
		// dedicated JSON Schema REST route. Both are on the merchant
		// host, never `ucp.dev` or `github.com`.
		$manifest = $this->ucp->generate_manifest( [] );
		$ext      = $manifest['ucp']['capabilities']['com.woocommerce.ai_storefront'][0];

		$this->assertArrayHasKey( 'spec', $ext );
		$this->assertArrayHasKey( 'schema', $ext );

		$this->assertStringContainsString( '/llms.txt#ucp-extension', $ext['spec'] );
		$this->assertStringContainsString( '/wc/ucp/v1/extension/schema', $ext['schema'] );

		// Never third-party hosted.
		$this->assertStringNotContainsString( 'ucp.dev', $ext['spec'] );
		$this->assertStringNotContainsString( 'ucp.dev', $ext['schema'] );
		$this->assertStringNotContainsString( 'github.com', $ext['spec'] );
		$this->assertStringNotContainsString( 'github.com', $ext['schema'] );
	}

	public function test_every_canonical_capability_has_spec_and_schema_urls(): void {
		// 1.6.4 added per-capability `spec` + `schema` URLs for
		// canonical UCP capabilities. Extension capabilities
		// (`com.woocommerce.*`) are vendor-specific and don't
		// carry canonical spec/schema URLs — they derive their
		// contract from the `extends` relationship back to the
		// parent. Only the canonical capabilities are required to
		// carry both URLs.
		$manifest       = $this->ucp->generate_manifest( [] );
		$canonical_caps = [
			'dev.ucp.shopping.catalog.search',
			'dev.ucp.shopping.catalog.lookup',
			'dev.ucp.shopping.checkout',
		];

		foreach ( $canonical_caps as $name ) {
			foreach ( $manifest['ucp']['capabilities'][ $name ] as $binding ) {
				$this->assertArrayHasKey( 'spec', $binding, "Canonical capability $name missing spec URL" );
				$this->assertArrayHasKey( 'schema', $binding, "Canonical capability $name missing schema URL" );
				$this->assertNotEmpty( $binding['spec'] );
				$this->assertNotEmpty( $binding['schema'] );
			}
		}
	}

	// ------------------------------------------------------------------
	// Layer 3: checkout capability + extension-config posture
	// ------------------------------------------------------------------
	//
	// Historical sweep:
	//   - Pre-1.6.5 the checkout capability carried a `config` block
	//     with purchase-URL templates + attribution guidance. Both
	//     were moved out in 1.6.5: URL templates went to llms.txt
	//     only (canonical flow is POST /checkout-sessions), and
	//     attribution moved into the com.woocommerce.ai_storefront
	//     extension as `config.attribution`.
	//   - A later sweep removed extension `config.attribution`
	//     entirely. The attribution contract is server-side: our
	//     `/checkout-sessions` endpoint injects utm_source +
	//     utm_medium into the `continue_url` based on the UCP-Agent
	//     header, so agents don't need to read UTM conventions
	//     off the manifest. llms.txt still carries the human-
	//     readable attribution narrative (hostname→brand table,
	//     fallback URL templates for non-UCP flows).
	//
	// The tests below guard the two resulting invariants:
	//   - `dev.ucp.shopping.checkout` has no `config` block at all.
	//   - `com.woocommerce.ai_storefront.config` only carries
	//      `store_context` — no `attribution`, no UTM schema,
	//      no purchase URL templates.

	public function test_checkout_capability_has_no_purchase_urls_after_1_6_5(): void {
		// Regression guard for the 1.6.5 removal. If any future
		// change re-adds `purchase_urls` to the checkout capability,
		// this test fires — forcing a conscious re-decision vs the
		// spec's SHOULD directive preferring business-provided
		// continue_url over platform-constructed templates.
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['capabilities']['dev.ucp.shopping.checkout'][0];

		$this->assertArrayNotHasKey( 'config', $binding );
	}

	public function test_extension_config_keys_are_exactly_documented_set(): void {
		// Lock the full set of config keys under the extension
		// capability. If a future refactor adds another machine-
		// readable config block here, this test fires and forces a
		// re-review: does it truly belong under a merchant-specific
		// extension, or should it go in llms.txt narrative (the usual
		// home for things UCP doesn't define)? Catches accidental
		// reintroduction of attribution or sibling blocks that
		// duplicate server-side contracts.
		//
		// Current set:
		//   - `store_context`: commerce-semantic hints (currency,
		//     locale, country, tax/shipping posture).
		//   - `agent_guide`:   operational LLM-prompt-friendly text
		//     describing checkout posture and self-identification.
		// `attribution` was deliberately removed in 1.6.5 — the
		// continue_url contract is server-side only.
		$manifest = $this->ucp->generate_manifest( [] );
		$config   = $manifest['ucp']['capabilities']['com.woocommerce.ai_storefront'][0]['config'];

		$this->assertSame( [ 'store_context', 'agent_guide' ], array_keys( $config ) );
		$this->assertArrayNotHasKey( 'attribution', $config );
	}

	// ------------------------------------------------------------------
	// Filter extensibility
	// ------------------------------------------------------------------

	public function test_manifest_is_filterable(): void {
		// The filter preserves the v1 signature: `apply_filters(
		// 'wc_ai_storefront_ucp_manifest', $manifest, $settings )`.
		// Third parties can extend the manifest (e.g., a plugin that
		// adds Payment Token Exchange would inject a payment handler
		// under ucp.payment_handlers).
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_ucp_manifest' === $hook ) {
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
