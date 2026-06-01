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
}
