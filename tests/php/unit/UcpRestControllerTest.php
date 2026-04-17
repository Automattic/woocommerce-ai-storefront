<?php
/**
 * Tests for WC_AI_Syndication_UCP_REST_Controller.
 *
 * Task 9 scope: route registration only. Stub handlers return a
 * `ucp_not_implemented` WP_Error with status 501 — these are
 * transient placeholders that tasks 10, 11, 12 replace with real
 * implementations. These tests lock in the registration contract:
 * the three routes must exist at the correct paths under the
 * `wc/ucp/v1` namespace, accept POST, and be public.
 *
 * Real request/response tests land alongside each route's handler
 * implementation (tasks 10-12).
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

	public function test_registers_three_routes_under_wc_ucp_v1_namespace(): void {
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$controller->register_routes();

		$this->assertCount( 3, $this->registered_routes );
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
	// Stub handler behavior (transient — tasks 10-12 replace these)
	// ------------------------------------------------------------------

	// catalog/search and catalog/lookup are implemented (tasks 10-11);
	// their behavior tests live in UcpCatalogSearchTest and
	// UcpCatalogLookupTest respectively. Once task 12 lands the
	// checkout-sessions implementation, the stub test below should
	// be deleted too.

	public function test_checkout_sessions_stub_returns_501_not_implemented(): void {
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$result     = $controller->handle_checkout_sessions_create( new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'ucp_not_implemented', $result->get_error_code() );
		$this->assertEquals( [ 'status' => 501 ], $result->get_error_data() );
	}
}
