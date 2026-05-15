<?php
/**
 * Tests for WC_AI_Storefront_Multi_Currency.
 *
 * Covers the soft-dependency WooPayments multi-currency reader.
 * The class is a pure helper — no state besides per-request
 * memoization — so all tests stub the underlying WP/WC/WCPay
 * surface via Brain Monkey.
 *
 * Naming: `test_<method>_<conditions>_<outcome>` per TESTING.md.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class MultiCurrencyTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset the helper's static memoization between tests so
		// each test sees a fresh detection cycle. The helper exposes
		// a public `reset_cache()` static for this purpose.
		WC_AI_Storefront_Multi_Currency::reset_cache();

		// Default base currency. Individual tests override.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		// `apply_filters` returns the second arg verbatim unless a
		// test installs a specific filter expectation.
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_accepted_currencies_no_wcpay_returns_base_only(): void {
		// WCPay class absent → return [ base ] only.
		// `class_exists` defaults to false for unknown classes under PHPUnit,
		// so no explicit stub is needed.
		$this->assertSame(
			array( 'USD' ),
			WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
		);
	}
}
