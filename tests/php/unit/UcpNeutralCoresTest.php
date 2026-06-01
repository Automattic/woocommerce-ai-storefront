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
		WC_AI_Storefront::$test_settings = [];
		WC_AI_Storefront_Logger::reset_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_currency_from_context_extracts_valid_iso4217(): void {
		$this->assertSame(
			'EUR',
			WC_AI_Storefront_UCP_REST_Controller::get_currency_from_context( [ 'currency' => 'eur' ] )
		);
	}

	public function test_get_currency_from_context_returns_null_for_gibberish(): void {
		$this->assertNull(
			WC_AI_Storefront_UCP_REST_Controller::get_currency_from_context( [ 'currency' => 'gibberish' ] )
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
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$result     = $controller->run_catalog_search(
			[
				'agent_data'       => [ 'name' => 'gibberish', 'raw_host' => '', 'source_host' => '' ],
				'ucp_agent_header' => '',
				'json_body'        => [],
			]
		);

		$this->assertSame( 503, $result['status'] );
		$this->assertIsArray( $result['body'] );
	}
}
