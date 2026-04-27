<?php
/**
 * Tests for WC_AI_Storefront_UCP_REST_Controller route registration.
 *
 * Scope: the `register_routes()` contract — namespace, paths,
 * methods, permission_callback wiring. Four routes register under
 * `wc/ucp/v1`:
 *
 *   - `/catalog/search`     POST, gated by `check_agent_access`
 *   - `/catalog/lookup`     POST, gated by `check_agent_access`
 *   - `/checkout-sessions`  POST, gated by `check_agent_access`
 *   - `/extension/schema`   GET,  public (`__return_true`) so manifest
 *                                 discovery resolves regardless of
 *                                 per-brand merchant settings
 *
 * Commerce routes share the gate so the merchant's `allowed_crawlers`
 * setting is honored at the REST layer (not just in robots.txt).
 * `extension/schema` stays public because the manifest's `schema`
 * URL must resolve for any agent — gating it would break manifest
 * discovery for agents the merchant hasn't pre-approved.
 *
 * Handler behavior (request/response shapes, filter mapping,
 * line-item validation, the syndication-disabled gate that returns
 * a UCP-shaped 503 envelope) is tested in the dedicated per-handler
 * files:
 *   - UcpCatalogSearchTest
 *   - UcpCatalogLookupTest
 *   - UcpCheckoutSessionsTest
 *
 * The `check_agent_access()` permission callback itself has its own
 * dedicated test file (UcpAgentAccessGateTest) that covers the
 * brand-allow-list logic, syndication-disabled bypass, and WP_Error
 * envelope shape.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpRestControllerTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Captured register_rest_route() invocations. Tests populate this
	 * via a Brain\Monkey alias and then assert on the shape.
	 *
	 * @var array<int, array{namespace: string, route: string, args: array<string, mixed>}>
	 */
	private array $registered_routes = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registered_routes = [];

		// Capture every register_rest_route call made during the test.
		// Alias is test-instance-bound via `use (&$this->...)` through
		// this closure's $capture reference.
		$capture = &$this->registered_routes;
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$capture ): bool {
				$capture[] = [
					'namespace' => $namespace,
					'route'     => $route,
					'args'      => $args,
				];
				return true;
			}
		);

		// Route handlers use __( ... ) inside WP_Error messages; stub it
		// to identity so the assertions don't depend on i18n being loaded.
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoke the controller's route registration and find the captured
	 * call for a given path — returns `null` if that route wasn't
	 * registered at all.
	 *
	 * @return ?array{namespace: string, route: string, args: array<string, mixed>}
	 */
	private function route_for( string $path ): ?array {
		foreach ( $this->registered_routes as $call ) {
			if ( $call['route'] === $path ) {
				return $call;
			}
		}
		return null;
	}

	// ------------------------------------------------------------------
	// Registration contract
	// ------------------------------------------------------------------

	public function test_registers_expected_routes_under_wc_ucp_v1_namespace(): void {
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->register_routes();

		// Three commerce endpoints (catalog/search, catalog/lookup,
		// checkout-sessions POST) + one PATCH stub
		// (checkout-sessions/{id}, returns structured 405
		// `unsupported_operation` so agents that try to modify a
		// session don't see WP REST's generic 404) + one docs
		// endpoint (extension/schema). The commerce endpoints are
		// the UCP 2026-04-08 surface; the docs endpoint is our
		// self-hosted JSON Schema for the
		// `com.woocommerce.ai_storefront` extension.
		$this->assertCount( 5, $this->registered_routes );
		foreach ( $this->registered_routes as $call ) {
			$this->assertEquals( 'wc/ucp/v1', $call['namespace'] );
		}
	}

	public function test_namespace_const_matches_registered_value(): void {
		// Guards against the constant and the registration drifting apart —
		// everything external (robots.txt Allow line, manifest endpoint)
		// references the const, so a typo in either place would silently
		// produce a working route at the wrong path.
		$this->assertEquals(
			'wc/ucp/v1',
			WC_AI_Storefront_UCP_REST_Controller::NAMESPACE
		);
	}

	public function test_catalog_search_route_registered(): void {
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->register_routes();

		$route = $this->route_for( '/catalog/search' );

		$this->assertNotNull( $route, 'catalog/search route should be registered' );
		$this->assertEquals( 'POST', $route['args']['methods'] );
	}

	public function test_catalog_lookup_route_registered(): void {
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->register_routes();

		$route = $this->route_for( '/catalog/lookup' );

		$this->assertNotNull( $route, 'catalog/lookup route should be registered' );
		$this->assertEquals( 'POST', $route['args']['methods'] );
	}

	public function test_checkout_sessions_route_registered(): void {
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->register_routes();

		$route = $this->route_for( '/checkout-sessions' );

		$this->assertNotNull( $route, 'checkout-sessions route should be registered' );
		$this->assertEquals( 'POST', $route['args']['methods'] );
	}

	public function test_extension_schema_route_registered(): void {
		// Self-hosted JSON Schema endpoint for the
		// com.woocommerce.ai_storefront merchant extension. GET,
		// not POST — it's read-only static documentation content.
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->register_routes();

		$route = $this->route_for( '/extension/schema' );

		$this->assertNotNull( $route, 'extension/schema route should be registered' );
		$this->assertEquals( 'GET', $route['args']['methods'] );
	}

	public function test_commerce_routes_gated_by_check_agent_access(): void {
		// Commerce routes (catalog/search, catalog/lookup,
		// checkout-sessions) are gated by `check_agent_access` so
		// the merchant's `allowed_crawlers` setting is honored at the
		// REST endpoint — not just in robots.txt. Locking the wiring
		// here so a regression that flips back to `__return_true`
		// (the easy mistake during refactoring) fails loudly.
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->register_routes();

		$gated_paths = [
			'/catalog/search',
			'/catalog/lookup',
			'/checkout-sessions',
		];
		foreach ( $gated_paths as $path ) {
			$route = $this->route_for( $path );
			$this->assertNotNull( $route, "Route {$path} should be registered" );
			$this->assertSame(
				[ $controller, 'check_agent_access' ],
				$route['args']['permission_callback'],
				"Route {$path} must be gated by check_agent_access"
			);
		}
	}

	public function test_extension_schema_stays_public(): void {
		// `extension/schema` is JSON Schema metadata referenced by the
		// manifest's extension capability `schema` URL. Gating it
		// would break manifest discovery for any agent the merchant
		// has not pre-allowed. Schema content is not commerce data;
		// keeping it public is intentional.
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->register_routes();

		$route = $this->route_for( '/extension/schema' );
		$this->assertNotNull( $route );
		$this->assertSame(
			'__return_true',
			$route['args']['permission_callback'],
			'extension/schema must remain public for manifest discovery'
		);
	}

	public function test_every_route_has_a_callable_handler(): void {
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->register_routes();

		foreach ( $this->registered_routes as $call ) {
			$this->assertIsCallable(
				$call['args']['callback'],
				"Route {$call['route']} callback should be callable"
			);
		}
	}

	// ------------------------------------------------------------------
	// Extension schema handler
	// ------------------------------------------------------------------

	public function test_extension_schema_handler_returns_valid_json_schema(): void {
		// The handler emits a JSON Schema document describing our
		// merchant-extension capability config. Required top-level
		// fields: `$schema` (draft identifier), `$id` (self URL for
		// re-serialization), `type`, `properties`.
		\Brain\Monkey\Functions\when( 'rest_url' )->alias(
			static fn( string $p ): string => 'https://example.com/wp-json/' . ltrim( $p, '/' )
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_extension_schema();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );

		// JSON Schema meta.
		$this->assertStringContainsString( 'json-schema.org', $data['$schema'] );
		$this->assertStringContainsString( '/wc/ucp/v1/extension/schema', $data['$id'] );
		$this->assertSame( 'object', $data['type'] );
	}

	public function test_extension_schema_documents_store_context_fields(): void {
		// The schema must document the known config.store_context
		// fields (currency, locale, country, prices_include_tax,
		// shipping_enabled) so agents can validate without reading
		// the plugin source. A regression dropping one silently
		// would leave agents unable to interpret that field.
		\Brain\Monkey\Functions\when( 'rest_url' )->alias(
			static fn( string $p ): string => 'https://example.com/wp-json/' . ltrim( $p, '/' )
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_extension_schema();
		$data       = $response->get_data();

		$store_context = $data['properties']['config']['properties']['store_context']['properties'];
		$this->assertArrayHasKey( 'currency', $store_context );
		$this->assertArrayHasKey( 'locale', $store_context );
		$this->assertArrayHasKey( 'country', $store_context );
		$this->assertArrayHasKey( 'prices_include_tax', $store_context );
		$this->assertArrayHasKey( 'shipping_enabled', $store_context );
	}

	public function test_extension_schema_does_not_document_attribution(): void {
		// Attribution is handled server-side by the /checkout-sessions
		// endpoint (utm_source + utm_medium are injected into the
		// continue_url from the UCP-Agent header). Duplicating that
		// contract under a machine-readable `config.attribution` key
		// encouraged agents to construct checkout URLs client-side —
		// the exact path the UCP spec steers away from. If a future
		// change re-adds attribution here, this test fires and forces
		// a re-review: does the new data genuinely need a schema home,
		// or is it already covered by the runtime contract?
		\Brain\Monkey\Functions\when( 'rest_url' )->alias(
			static fn( string $p ): string => 'https://example.com/wp-json/' . ltrim( $p, '/' )
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_extension_schema();
		$data       = $response->get_data();

		$config_props = $data['properties']['config']['properties'];
		$this->assertArrayNotHasKey( 'attribution', $config_props );
		$this->assertSame( [ 'store_context' ], array_keys( $config_props ) );
	}

	public function test_extension_schema_has_no_response_level_payloads(): void {
		// 2.0.0+: no response-level payloads are emitted under this
		// extension. Rating moved to core `product.rating`. Barcodes
		// were never here (they live on `variants[].barcodes` per UCP
		// core). Both MUST stay absent — regression guard.
		//
		// Non-response top-level properties (e.g. `config` for
		// manifest-level config, `accepted_request_inputs` for
		// request-side extension documentation) are allowed — they
		// describe surfaces OTHER than product responses. The guard
		// specifically targets the response-payload keys.
		\Brain\Monkey\Functions\when( 'rest_url' )->alias(
			static fn( string $p ): string => 'https://example.com/wp-json/' . ltrim( $p, '/' )
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_extension_schema();
		$data       = $response->get_data();

		$this->assertArrayNotHasKey( 'ratings', $data['properties'] );
		$this->assertArrayNotHasKey( 'barcodes', $data['properties'] );
	}

	public function test_extension_schema_documents_accepted_request_inputs(): void {
		// PR J: we accept spec-standard `context` + `signals` objects
		// on catalog endpoints, plus a set of custom filters via
		// `additionalProperties`. Spec hints merchants MAY document
		// these; the extension JSON Schema is the canonical place.
		// Agents fetching the schema can discover the full
		// request-side extension surface without reading our source.
		\Brain\Monkey\Functions\when( 'rest_url' )->alias(
			static fn( string $p ): string => 'https://example.com/wp-json/' . ltrim( $p, '/' )
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_extension_schema();
		$data       = $response->get_data();

		$this->assertArrayHasKey( 'accepted_request_inputs', $data['properties'] );
		$inputs = $data['properties']['accepted_request_inputs']['properties'];

		// Spec-standard inputs we accept
		$this->assertArrayHasKey( 'context', $inputs );
		$this->assertArrayHasKey( 'signals', $inputs );

		// Custom filters block must exist AND carry a `properties` map
		// before we iterate — otherwise a missing or malformed shape
		// would silently skip the per-filter assertions and the test
		// would pass while documenting nothing.
		$this->assertArrayHasKey( 'custom_filters', $inputs );
		$this->assertArrayHasKey( 'properties', $inputs['custom_filters'] );
		$this->assertIsArray( $inputs['custom_filters']['properties'] );

		$custom = $inputs['custom_filters']['properties'];
		foreach ( [ 'brand', 'tags', 'in_stock', 'featured', 'min_rating', 'on_sale', 'attributes' ] as $filter_name ) {
			$this->assertArrayHasKey(
				$filter_name,
				$custom,
				"Custom filter \"{$filter_name}\" must be documented in the extension schema"
			);
		}
	}

	public function test_format_signal_keys_for_log_sanitizes_untrusted_input(): void {
		// Signal keys are request-supplied → untrusted. Defensive
		// helper caps size, strips control chars (log-injection guard),
		// drops non-string keys, and truncates individual keys.
		$reflection = new \ReflectionClass( WC_AI_Storefront_UCP_REST_Controller::class );
		$method     = $reflection->getMethod( 'format_signal_keys_for_log' );
		$method->setAccessible( true );

		// Newlines + control chars stripped — no log injection.
		// The sanitizer's job is to prevent malicious input from
		// starting a NEW log line (via \n) or terminal escape (via
		// \e / \x1b / \x07). Text content after stripped control
		// chars survives concatenated, which is fine — it stays on
		// the original line and can't impersonate a separate log
		// entry. Assert on the control-char removal, not on text.
		$out = $method->invoke( null, [ "dev.ucp.buyer_ip\nline2\r\x1b[31m" => 'x' ] );
		$this->assertStringNotContainsString( "\n", $out );
		$this->assertStringNotContainsString( "\r", $out );
		$this->assertStringNotContainsString( "\x1b", $out );

		// Each key is length-capped with ellipsis marker.
		$long_key = str_repeat( 'a', 500 );
		$out      = $method->invoke( null, [ $long_key => 'x' ] );
		$this->assertLessThan( 150, strlen( $out ), 'Over-long key must be truncated' );
		$this->assertStringContainsString( '…', $out );

		// Total-keys cap + overflow sigil.
		$many = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$many[ "key_{$i}" ] = true;
		}
		$out = $method->invoke( null, $many );
		$this->assertStringContainsString( '(+68 more)', $out, 'Overflow sigil should reflect truncated count' );

		// Non-string keys (numeric) dropped silently — they're
		// illegal under UCP's reverse-domain rule anyway.
		$out = $method->invoke( null, [ 0 => 'x', 'dev.ucp.buyer_ip' => 'y' ] );
		$this->assertSame( 'dev.ucp.buyer_ip', $out );

		// All keys filtered out (e.g. agent sent signals as a list
		// with purely numeric keys) → explicit `(none)` placeholder
		// instead of a confusing empty/overflow-only output.
		$out = $method->invoke( null, [ 0 => 'x', 1 => 'y', 2 => 'z' ] );
		$this->assertSame( '(none)', $out );

		// Overflow sigil derives from eligible string keys, not from
		// the full $signals count. A payload mixing 40 valid keys
		// with 28 numeric keys should show "(+8 more)" — based on
		// the 40 eligible, not 68 total.
		$mixed = [];
		for ( $i = 0; $i < 40; $i++ ) {
			$mixed[ "dev.ucp.key_{$i}" ] = true;
		}
		for ( $i = 0; $i < 28; $i++ ) {
			$mixed[] = 'noise'; // Appends with numeric keys.
		}
		$out = $method->invoke( null, $mixed );
		$this->assertStringContainsString( '(+8 more)', $out );
		$this->assertStringNotContainsString( '(+36 more)', $out, 'Overflow must not include non-string keys' );
	}

	public function test_extension_schema_response_has_json_schema_content_type(): void {
		// RFC 7159-ish convention: JSON Schema documents use
		// `application/schema+json`. Agents and validators sniff the
		// content type to confirm what they fetched.
		\Brain\Monkey\Functions\when( 'rest_url' )->alias(
			static fn( string $p ): string => 'https://example.com/wp-json/' . ltrim( $p, '/' )
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_extension_schema();

		$this->assertStringContainsString(
			'application/schema+json',
			$response->get_headers()['Content-Type'] ?? ''
		);
	}

	// All three commerce handlers are implemented (tasks 10, 11, 12).
	// Their behavior tests live in UcpCatalogSearchTest,
	// UcpCatalogLookupTest, and UcpCheckoutSessionsTest respectively.
	// This file retains only the route-registration contract tests +
	// the extension-schema handler tests.
}
