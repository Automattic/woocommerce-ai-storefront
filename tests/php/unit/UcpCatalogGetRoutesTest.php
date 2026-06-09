<?php
/**
 * Tests for GET /catalog/search and GET /catalog/lookup handlers.
 *
 * Verifies that handle_catalog_search_get() and handle_catalog_lookup_get()
 * correctly translate flat query-string params into the $params shape that
 * run_catalog_search() / run_catalog_lookup() expect, without exercising
 * the full Store API dispatch path.
 *
 * Strategy: a thin test subclass overrides the neutral-core methods to
 * capture their $params argument and return a fixed response, keeping the
 * test focused on the translation layer.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/**
 * Test subclass that captures run_catalog_search / run_catalog_lookup
 * params and returns a fixed dummy response without hitting Store API.
 */
class UcpCatalogGetRoutesTestController extends WC_AI_Storefront_UCP_REST_Controller {
	public ?array $last_search_params = null;
	public ?array $last_lookup_params = null;

	public function run_catalog_search( array $params ): array {
		$this->last_search_params = $params;
		return [
			'body'   => [ 'products' => [], 'ucp' => [] ],
			'status' => 200,
		];
	}

	public function run_catalog_lookup( array $params ): array {
		$this->last_lookup_params = $params;
		return [
			'body'   => [ 'products' => [], 'ucp' => [] ],
			'status' => 200,
		];
	}
}

class UcpCatalogGetRoutesTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private UcpCatalogGetRoutesTestController $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();

		$this->controller = new UcpCatalogGetRoutesTestController();

		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
		WC_AI_Storefront_Logger::reset_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Build a minimal WP_REST_Request stub with a flat params map.
	 *
	 * @param array<string, mixed> $params
	 * @return WP_REST_Request
	 */
	private function make_get_request( array $params = [] ): WP_REST_Request {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static fn( string $key ) => $params[ $key ] ?? null
		);
		return $request;
	}

	// ------------------------------------------------------------------
	// catalog/search GET — param mapping
	// ------------------------------------------------------------------

	public function test_get_search_q_maps_to_query_param(): void {
		$request = $this->make_get_request( [ 'q' => 'hoodie' ] );
		$this->controller->handle_catalog_search_get( $request );

		$this->assertSame( 'hoodie', $this->controller->last_search_params['query'] );
	}

	public function test_get_search_category_maps_to_filter(): void {
		$request = $this->make_get_request( [ 'category' => 'tops' ] );
		$this->controller->handle_catalog_search_get( $request );

		$this->assertSame(
			[ 'tops' ],
			$this->controller->last_search_params['filters']['categories']
		);
	}

	public function test_get_search_price_range_maps_to_filter(): void {
		$request = $this->make_get_request( [ 'min_price' => '20', 'max_price' => '60' ] );
		$this->controller->handle_catalog_search_get( $request );

		$price = $this->controller->last_search_params['filters']['price'];
		$this->assertSame( 20, $price['min'] );
		$this->assertSame( 60, $price['max'] );
	}

	public function test_get_search_in_stock_true_maps_to_filter(): void {
		$request = $this->make_get_request( [ 'in_stock' => '1' ] );
		$this->controller->handle_catalog_search_get( $request );

		$this->assertTrue( $this->controller->last_search_params['filters']['in_stock'] );
	}

	public function test_get_search_in_stock_false_maps_to_filter(): void {
		$request = $this->make_get_request( [ 'in_stock' => '0' ] );
		$this->controller->handle_catalog_search_get( $request );

		$this->assertFalse( $this->controller->last_search_params['filters']['in_stock'] );
	}

	public function test_get_search_in_stock_string_true_maps_to_filter(): void {
		$request = $this->make_get_request( [ 'in_stock' => 'true' ] );
		$this->controller->handle_catalog_search_get( $request );

		$this->assertTrue( $this->controller->last_search_params['filters']['in_stock'] );
	}

	public function test_get_search_only_min_price_maps_without_max(): void {
		$request = $this->make_get_request( [ 'min_price' => '20' ] );
		$this->controller->handle_catalog_search_get( $request );

		$price = $this->controller->last_search_params['filters']['price'];
		$this->assertSame( 20, $price['min'] );
		$this->assertArrayNotHasKey( 'max', $price );
	}

	public function test_get_search_attribute_bracket_param_maps_to_filters_attributes(): void {
		$request = $this->make_get_request( [
			'attribute' => [ 'color' => 'blue', 'size' => 'M' ],
		] );
		$this->controller->handle_catalog_search_get( $request );

		$attrs = $this->controller->last_search_params['filters']['attributes'];
		$this->assertSame( [ 'blue' ], $attrs['color'] );
		$this->assertSame( [ 'M' ], $attrs['size'] );
	}

	public function test_get_search_page_encodes_to_cursor(): void {
		$request = $this->make_get_request( [ 'page' => '3' ] );
		$this->controller->handle_catalog_search_get( $request );

		$pagination = $this->controller->last_search_params['pagination'];
		// encode_cursor(3) = base64_encode('p3') — pins the format so a future
		// encoding change is caught here rather than silently breaking next-page.
		$this->assertSame( base64_encode( 'p3' ), $pagination['cursor'] );
	}

	public function test_get_search_per_page_maps_to_pagination_limit(): void {
		$request = $this->make_get_request( [ 'per_page' => '20' ] );
		$this->controller->handle_catalog_search_get( $request );

		$this->assertSame( 20, $this->controller->last_search_params['pagination']['limit'] );
	}

	public function test_get_search_anonymous_agent_data(): void {
		$request = $this->make_get_request( [] );
		$this->controller->handle_catalog_search_get( $request );

		$agent = $this->controller->last_search_params['agent_data'];
		$this->assertSame( 'anonymous', $agent['name'] );
		$this->assertSame( '', $agent['raw_host'] );
		$this->assertSame( '', $agent['source_host'] );
	}

	public function test_get_search_ucp_agent_header_is_empty_string(): void {
		$request = $this->make_get_request( [] );
		$this->controller->handle_catalog_search_get( $request );

		$this->assertSame( '', $this->controller->last_search_params['ucp_agent_header'] );
	}

	public function test_get_search_no_filters_when_no_params(): void {
		$request = $this->make_get_request( [] );
		$this->controller->handle_catalog_search_get( $request );

		$this->assertSame( [], $this->controller->last_search_params['filters'] );
	}

	public function test_get_search_returns_503_when_syndication_disabled(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		// Use a real controller so the neutral core's syndication gate fires.
		$real    = new WC_AI_Storefront_UCP_REST_Controller();
		$request = $this->make_get_request( [ 'q' => 'hoodie' ] );
		$result  = $real->handle_catalog_search_get( $request );

		$this->assertSame( 503, $result->get_status() );
	}

	// ------------------------------------------------------------------
	// catalog/lookup GET — param mapping
	// ------------------------------------------------------------------

	public function test_get_lookup_by_numeric_id_wraps_as_ucp_id(): void {
		$request = $this->make_get_request( [ 'id' => '42' ] );
		$this->controller->handle_catalog_lookup_get( $request );

		$this->assertSame( [ 'prod_42' ], $this->controller->last_lookup_params['ids'] );
	}

	public function test_get_lookup_by_slug_resolves_via_get_page_by_path(): void {
		$post     = new WP_Post();
		$post->ID = 99;
		Functions\when( 'get_page_by_path' )->justReturn( $post );

		$request = $this->make_get_request( [ 'slug' => 'day-hoodie' ] );
		$this->controller->handle_catalog_lookup_get( $request );

		$this->assertSame( [ 'prod_99' ], $this->controller->last_lookup_params['ids'] );
	}

	public function test_get_lookup_unknown_slug_returns_404(): void {
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$real    = new WC_AI_Storefront_UCP_REST_Controller();
		$request = $this->make_get_request( [ 'slug' => 'gibberish-slug-xyz' ] );
		$result  = $real->handle_catalog_lookup_get( $request );

		$this->assertSame( 404, $result->get_status() );
	}

	public function test_get_lookup_non_numeric_id_returns_400(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$real    = new WC_AI_Storefront_UCP_REST_Controller();
		$request = $this->make_get_request( [ 'id' => 'abc' ] );
		$result  = $real->handle_catalog_lookup_get( $request );

		$this->assertSame( 400, $result->get_status() );
	}

	public function test_get_lookup_id_zero_returns_400(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$real    = new WC_AI_Storefront_UCP_REST_Controller();
		$request = $this->make_get_request( [ 'id' => '0' ] );
		$result  = $real->handle_catalog_lookup_get( $request );

		$this->assertSame( 400, $result->get_status() );
	}

	public function test_get_lookup_id_takes_precedence_over_slug(): void {
		$request = $this->make_get_request( [ 'id' => '42', 'slug' => 'some-product' ] );
		$this->controller->handle_catalog_lookup_get( $request );

		$this->assertSame( [ 'prod_42' ], $this->controller->last_lookup_params['ids'] );
	}

	public function test_get_lookup_missing_params_returns_400(): void {
		// Neither ?id nor ?slug → 400 before calling run_catalog_lookup.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$real    = new WC_AI_Storefront_UCP_REST_Controller();
		$request = $this->make_get_request( [] );
		$result  = $real->handle_catalog_lookup_get( $request );

		$this->assertSame( 400, $result->get_status() );
	}

	public function test_get_lookup_returns_503_when_syndication_disabled(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$real    = new WC_AI_Storefront_UCP_REST_Controller();
		$request = $this->make_get_request( [ 'id' => '42' ] );
		$result  = $real->handle_catalog_lookup_get( $request );

		$this->assertSame( 503, $result->get_status() );
	}
}
