<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpNeutralCoresTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		// Reset the test-controllable settings so a `$test_settings`
		// override (the disabled-syndication 503 test below) can't leak
		// into other tests that assume syndication is enabled. Mirrors the
		// reset discipline in UcpCatalogSearchTest. Also clear the Logger's
		// cached is_enabled() so its apply_filters() stub doesn't bleed.
		WC_AI_Storefront::$test_settings = array();
		WC_AI_Storefront_Logger::reset_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_currency_from_context_extracts_valid_iso4217(): void {
		$this->assertSame(
			'EUR',
			WC_AI_Storefront_UCP_REST_Controller::get_currency_from_context( array( 'currency' => 'eur' ) )
		);
	}

	public function test_get_currency_from_context_returns_null_for_gibberish(): void {
		$this->assertNull(
			WC_AI_Storefront_UCP_REST_Controller::get_currency_from_context( array( 'currency' => 'gibberish' ) )
		);
		$this->assertNull( WC_AI_Storefront_UCP_REST_Controller::get_currency_from_context( null ) );
	}

	public function test_run_catalog_search_returns_503_body_when_syndication_disabled(): void {
		// The disabled-syndication gate short-circuits before any Store API
		// dispatch. Drive the gate via the stub's $test_settings (the same
		// lever UcpCatalogSearchTest uses) — production resolves it through
		// WC_AI_Storefront::get_settings(), not get_option(). apply_filters
		// is stubbed to return its 2nd arg so WC_AI_Storefront_Logger's
		// is_enabled() resolves to false and debug() short-circuits without
		// touching WordPress.
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$result     = $controller->run_catalog_search(
			array(
				'agent_data'       => array(
					'name'        => 'gibberish',
					'raw_host'    => '',
					'source_host' => '',
				),
				'ucp_agent_header' => '',
			)
		);

		$this->assertSame( 503, $result['status'] );
		$this->assertIsArray( $result['body'] );
	}

	public function test_run_catalog_lookup_returns_503_body_when_syndication_disabled(): void {
		// Parity with the search 503 test: the disabled-syndication gate
		// short-circuits before any ids validation or Store API fetch. Same
		// $test_settings + apply_filters stubbing rationale as above.
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$result     = $controller->run_catalog_lookup(
			array(
				'ids'              => array( 'prod_1' ),
				'agent_data'       => array(
					'name'        => 'gibberish',
					'raw_host'    => '',
					'source_host' => '',
				),
				'ucp_agent_header' => '',
			)
		);

		$this->assertSame( 503, $result['status'] );
		$this->assertIsArray( $result['body'] );
	}

	public function test_run_catalog_lookup_returns_ids_validation_error_for_non_array_ids(): void {
		// Syndication enabled (stub default), so the core passes the
		// disabled gate and reaches validate_lookup_ids_param(). A non-array
		// `ids` triggers the INVALID_INPUT error path; the core must surface
		// that helper response's exact status (the ucp_catalog_error_response
		// default, 400) — not the 200 success path. apply_filters is stubbed
		// so the Logger debug() calls on this path short-circuit.
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$result     = $controller->run_catalog_lookup(
			array(
				'ids'              => 'gibberish',
				'agent_data'       => array(
					'name'        => 'gibberish',
					'raw_host'    => '',
					'source_host' => '',
				),
				'ucp_agent_header' => '',
			)
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertIsArray( $result['body'] );
	}

	public function test_run_checkout_create_returns_503_when_syndication_disabled(): void {
		// Parity with the search/lookup 503 tests: the disabled-syndication
		// gate short-circuits before any line-item validation or Store API
		// fetch. The ucp_checkout_error_response body builds from the static
		// envelope + (function_exists-guarded) get_woocommerce_currency
		// fallback, so no WC stubbing is needed beyond apply_filters (which
		// keeps WC_AI_Storefront_Logger::debug() off the WordPress path).
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// ucp_checkout_error_response calls get_woocommerce_currency under a
		// function_exists guard. In the full suite another test mocks that
		// function, which flips function_exists() true here too — so stub it
		// to a deterministic value (mirrors UcpCheckoutSessionsTest).
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		WC_AI_Storefront_Logger::reset_cache();

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$result     = $controller->run_checkout_create(
			array(
				'line_items'       => array(
					array(
						'item'     => array( 'id' => 'prod_1' ),
						'quantity' => 1,
					),
				),
				'context'          => null,
				'agent_data'       => array(
					'name'        => 'gibberish',
					'raw_host'    => '',
					'source_host' => '',
				),
				'ucp_agent_header' => '',
			)
		);

		$this->assertSame( 503, $result['status'] );
		$this->assertIsArray( $result['body'] );
	}

	public function test_run_checkout_create_returns_400_for_empty_line_items(): void {
		// Syndication enabled (stub), so the core passes the disabled gate
		// and reaches the non-empty-line_items validation. An empty array
		// triggers the INVALID_INPUT error path; the core must surface that
		// helper response's exact status (ucp_checkout_error_response
		// default, 400) — not the 200/201 success path. This validation runs
		// before resolve_agent_host / process_line_item, so no WC stubbing is
		// needed beyond apply_filters (Logger short-circuit).
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// See the 503 test: stub get_woocommerce_currency so the error
		// helper's function_exists-guarded call resolves under the full suite.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		WC_AI_Storefront_Logger::reset_cache();

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$result     = $controller->run_checkout_create(
			array(
				'line_items'       => array(),
				'context'          => null,
				'agent_data'       => array(
					'name'        => 'gibberish',
					'raw_host'    => '',
					'source_host' => '',
				),
				'ucp_agent_header' => '',
			)
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertIsArray( $result['body'] );
	}

	public function test_run_catalog_lookup_returns_400_for_over_limit_ids_array(): void {
		// The over-limit check (>100 ids) is a neutral-core guard that runs
		// before any Store API dispatch. Pinned here so a refactor that moves
		// it into the REST-only handler would be caught immediately.
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$result     = $controller->run_catalog_lookup(
			array(
				'ids'              => array_fill( 0, 101, 'prod_gibberish' ),
				'agent_data'       => array(
					'name'        => 'gibberish',
					'raw_host'    => '',
					'source_host' => '',
				),
				'ucp_agent_header' => '',
			)
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertIsArray( $result['body'] );
	}

	public function test_run_checkout_create_returns_400_for_over_limit_line_items(): void {
		// Parity with the lookup over-limit test: the >100 line_items check is
		// also a neutral-core guard. Pinned here so it stays in both transports.
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		WC_AI_Storefront_Logger::reset_cache();

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$result     = $controller->run_checkout_create(
			array(
				'line_items'       => array_fill(
					0,
					101,
					array(
						'item'     => array( 'id' => 'prod_gibberish' ),
						'quantity' => 1,
					)
				),
				'context'          => null,
				'agent_data'       => array(
					'name'        => 'gibberish',
					'raw_host'    => '',
					'source_host' => '',
				),
				'ucp_agent_header' => '',
			)
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertIsArray( $result['body'] );
	}

	public function test_resolve_agent_data_from_name_known_product_token_resolves_hostname(): void {
		// A product token present in PRODUCT_TO_HOSTNAME must produce its
		// canonical brand `name` and the mapped lowercase `source_host`
		// (utm_source), matching what resolve_agent_host() yields for the
		// Product/Version header path. This is the MCP <-> REST attribution
		// parity the resolver exists to guarantee.
		$result = WC_AI_Storefront_UCP_REST_Controller::resolve_agent_data_from_name( 'UCP-Playground' );

		$this->assertSame( 'UCPPlayground', $result['name'] );
		$this->assertSame( 'UCP-Playground', $result['raw_host'] );
		$this->assertSame( 'ucpplayground.com', $result['source_host'] );
	}

	public function test_resolve_agent_data_from_name_blank_falls_back_to_unknown(): void {
		// A blank handshake name carries no attribution signal: name is the
		// FALLBACK_SOURCE sentinel and source_host is empty (build_continue_url
		// substitutes the sentinel downstream).
		$result = WC_AI_Storefront_UCP_REST_Controller::resolve_agent_data_from_name( '   ' );

		$this->assertSame(
			WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE,
			$result['name']
		);
		$this->assertSame( '', $result['raw_host'] );
		$this->assertSame( '', $result['source_host'] );
	}

	public function test_resolve_agent_data_from_name_unknown_name_buckets_as_other_ai(): void {
		// An unknown but non-blank name is a real signal we don't recognize:
		// name buckets under OTHER_AI_BUCKET while source_host falls back to
		// the name itself (non-empty) so the cohort stays observable.
		$result = WC_AI_Storefront_UCP_REST_Controller::resolve_agent_data_from_name( 'gibberish' );

		$this->assertSame(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			$result['name']
		);
		$this->assertSame( 'gibberish', $result['raw_host'] );
		$this->assertSame( 'gibberish', $result['source_host'] );
	}
}
