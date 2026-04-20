<?php
/**
 * Tests for WC_AI_Syndication_UCP_REST_Controller route registration.
 *
 * Scope: the `register_routes()` contract only — namespace, paths,
 * methods, permission callback. The three routes must exist at their
 * canonical paths under `wc/ucp/v1`, accept POST, and be public.
 *
 * Handler behavior (request/response shapes, filter mapping,
 * line-item validation, the syndication-disabled gate) is tested in
 * the dedicated per-handler files:
 *   - UcpCatalogSearchTest
 *   - UcpCatalogLookupTest
 *   - UcpCheckoutSessionsTest
 *
 * @package WooCommerce_AI_Syndication
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
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$controller->register_routes();

		// Three commerce endpoints (catalog/search, catalog/lookup,
		// checkout-sessions) + one docs endpoint (extension/schema).
		// The commerce endpoints are the UCP 2026-04-08 surface; the
		// docs endpoint is our self-hosted JSON Schema for the
		// `com.woocommerce.ai_syndication` extension.
		$this->assertCount( 4, $this->registered_routes );
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
			WC_AI_Syndication_UCP_REST_Controller::NAMESPACE
		);
	}

	public function test_catalog_search_route_registered(): void {
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$controller->register_routes();

		$route = $this->route_for( '/catalog/search' );

		$this->assertNotNull( $route, 'catalog/search route should be registered' );
		$this->assertEquals( 'POST', $route['args']['methods'] );
	}

	public function test_catalog_lookup_route_registered(): void {
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$controller->register_routes();

		$route = $this->route_for( '/catalog/lookup' );

		$this->assertNotNull( $route, 'catalog/lookup route should be registered' );
		$this->assertEquals( 'POST', $route['args']['methods'] );
	}

	public function test_checkout_sessions_route_registered(): void {
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$controller->register_routes();

		$route = $this->route_for( '/checkout-sessions' );

		$this->assertNotNull( $route, 'checkout-sessions route should be registered' );
		$this->assertEquals( 'POST', $route['args']['methods'] );
	}

	public function test_extension_schema_route_registered(): void {
		// Self-hosted JSON Schema endpoint for the
		// com.woocommerce.ai_syndication merchant extension. GET,
		// not POST — it's read-only static documentation content.
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$controller->register_routes();

		$route = $this->route_for( '/extension/schema' );

		$this->assertNotNull( $route, 'extension/schema route should be registered' );
		$this->assertEquals( 'GET', $route['args']['methods'] );
	}

	public function test_all_routes_are_public(): void {
		// UCP routes are public by design — agent auth is via the
		// UCP-Agent header (used for attribution, not access control).
		// Merchants who want to deny access should pause syndication
		// rather than rely on route-level permissions.
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$controller->register_routes();

		foreach ( $this->registered_routes as $call ) {
			$this->assertEquals(
				'__return_true',
				$call['args']['permission_callback'],
				"Route {$call['route']} should have public permission_callback"
			);
		}
	}

	public function test_every_route_has_a_callable_handler(): void {
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$controller->register_routes();

		foreach ( $this->registered_routes as $call ) {
			$this->assertIsCallable(
				$call['args']['callback'],
				"Route {$call['route']} callback should be callable"
			);
		}
	}

	// ------------------------------------------------------------------
	// Extension schema handler (1.9.0)
	// ------------------------------------------------------------------

	public function test_extension_schema_handler_returns_valid_json_schema(): void {
		// The handler emits a JSON Schema document describing our
		// merchant-extension capability config. Required top-level
		// fields: `$schema` (draft identifier), `$id` (self URL for
		// re-serialization), `type`, `properties`.
		\Brain\Monkey\Functions\when( 'rest_url' )->alias(
			static fn( string $p ): string => 'https://example.com/wp-json/' . ltrim( $p, '/' )
		);

		$controller = new WC_AI_Syndication_UCP_REST_Controller();
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

		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$response   = $controller->handle_extension_schema();
		$data       = $response->get_data();

		$store_context = $data['properties']['config']['properties']['store_context']['properties'];
		$this->assertArrayHasKey( 'currency', $store_context );
		$this->assertArrayHasKey( 'locale', $store_context );
		$this->assertArrayHasKey( 'country', $store_context );
		$this->assertArrayHasKey( 'prices_include_tax', $store_context );
		$this->assertArrayHasKey( 'shipping_enabled', $store_context );
	}

	public function test_extension_schema_documents_ratings_payload(): void {
		// Products emit `ratings` under the extension namespace
		// (`extensions.com.woocommerce.ai_syndication.ratings`) — the
		// schema must document its shape so agents can validate.
		// Barcodes are NOT documented here: they're emitted in the
		// CORE `variant.barcodes` field per the UCP spec, sourced
		// from a Store API extension that's purely internal plumbing
		// and doesn't belong in the UCP-layer extension schema.
		\Brain\Monkey\Functions\when( 'rest_url' )->alias(
			static fn( string $p ): string => 'https://example.com/wp-json/' . ltrim( $p, '/' )
		);

		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$response   = $controller->handle_extension_schema();
		$data       = $response->get_data();

		$this->assertArrayHasKey( 'ratings', $data['properties'] );
		// Barcodes live on the core UCP variant shape — not here.
		$this->assertArrayNotHasKey( 'barcodes', $data['properties'] );
	}

	public function test_extension_schema_response_has_json_schema_content_type(): void {
		// RFC 7159-ish convention: JSON Schema documents use
		// `application/schema+json`. Agents and validators sniff the
		// content type to confirm what they fetched.
		\Brain\Monkey\Functions\when( 'rest_url' )->alias(
			static fn( string $p ): string => 'https://example.com/wp-json/' . ltrim( $p, '/' )
		);

		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$response   = $controller->handle_extension_schema();

		$this->assertStringContainsString(
			'application/schema+json',
			$response->get_headers()['Content-Type'] ?? ''
		);
	}

	// All three handlers are now implemented (tasks 10, 11, 12). Their
	// behavior tests live in UcpCatalogSearchTest, UcpCatalogLookupTest,
	// and UcpCheckoutSessionsTest respectively. This file retains only
	// the route-registration contract tests + the 1.9.0 extension
	// schema handler tests.
}
