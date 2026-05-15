<?php
/**
 * Tests for WC_AI_Storefront_Multi_Currency.
 *
 * Covers the soft-dependency WooPayments multi-currency reader.
 *
 * @package WooCommerce_AI_Storefront
 */

namespace WCPay\MultiCurrency {
	if ( ! class_exists( __NAMESPACE__ . '\\MultiCurrency' ) ) {
		/**
		 * Test stand-in for the real WCPay class.
		 *
		 * Tests install a Mockery double onto `$test_double`;
		 * `instance()` returns it. When `$test_double` is null,
		 * `instance()` returns null and the helper falls through
		 * the `is_object()` guard.
		 *
		 * The instance methods (`is_multi_currency_enabled`,
		 * `get_enabled_currencies`) are declared with stub bodies
		 * so the helper's defensive `method_exists()` guards report
		 * true on Mockery doubles created via
		 * `\Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' )`.
		 * Mockery overrides them per-test via `shouldReceive`.
		 */
		class MultiCurrency {
			public static $test_double = null;

			public static function instance() {
				return self::$test_double;
			}

			public function is_multi_currency_enabled() {
				return false;
			}

			public function get_enabled_currencies() {
				return array();
			}
		}
	}
}

namespace {
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

	class MultiCurrencyTest extends \PHPUnit\Framework\TestCase {
		use MockeryPHPUnitIntegration;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			WC_AI_Storefront_Multi_Currency::reset_cache();
			\WCPay\MultiCurrency\MultiCurrency::$test_double = null;

			Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
			Functions\when( 'apply_filters' )->returnArg( 2 );
		}

		protected function tearDown(): void {
			\WCPay\MultiCurrency\MultiCurrency::$test_double = null;
			Monkey\tearDown();
			parent::tearDown();
		}

		public function test_get_accepted_currencies_no_wcpay_double_returns_base_only(): void {
			$this->assertSame(
				array( 'USD' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_wcpay_enabled_returns_full_list_with_base_first(): void {
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'is_multi_currency_enabled' )->andReturn( true );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'USD' => new \stdClass(),
					'EUR' => new \stdClass(),
					'GBP' => new \stdClass(),
				)
			);
			\WCPay\MultiCurrency\MultiCurrency::$test_double = $mc;

			$this->assertSame(
				array( 'USD', 'EUR', 'GBP' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_wcpay_disabled_returns_base_only(): void {
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'is_multi_currency_enabled' )->andReturn( false );
			$mc->shouldNotReceive( 'get_enabled_currencies' );
			\WCPay\MultiCurrency\MultiCurrency::$test_double = $mc;

			$this->assertSame(
				array( 'USD' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_base_missing_from_wcpay_list_is_prepended(): void {
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'is_multi_currency_enabled' )->andReturn( true );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'EUR' => new \stdClass(),
					'GBP' => new \stdClass(),
				)
			);
			\WCPay\MultiCurrency\MultiCurrency::$test_double = $mc;

			$this->assertSame(
				array( 'USD', 'EUR', 'GBP' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_duplicates_are_deduped_preserving_order(): void {
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'is_multi_currency_enabled' )->andReturn( true );
			// The 'eur' lowercase key is distinct from 'EUR' in the array
			// literal (so PHP doesn't collapse it at parse time), but
			// collapses to 'EUR' after the helper's strtoupper() — exercising
			// the dedup branch in normalize_codes().
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'USD' => new \stdClass(),
					'EUR' => new \stdClass(),
					'eur' => new \stdClass(),
					'GBP' => new \stdClass(),
				)
			);
			\WCPay\MultiCurrency\MultiCurrency::$test_double = $mc;

			$this->assertSame(
				array( 'USD', 'EUR', 'GBP' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_malformed_codes_are_dropped(): void {
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'is_multi_currency_enabled' )->andReturn( true );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'EUR'  => new \stdClass(),
					'eurr' => new \stdClass(),
					''     => new \stdClass(),
					'12'   => new \stdClass(),
					'GBP'  => new \stdClass(),
				)
			);
			\WCPay\MultiCurrency\MultiCurrency::$test_double = $mc;

			$this->assertSame(
				array( 'USD', 'EUR', 'GBP' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_filter_can_override_list(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value ) {
					if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
						return array( 'USD', 'CAD' );
					}
					return $value;
				}
			);

			$this->assertSame(
				array( 'USD', 'CAD' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_filter_returning_non_array_falls_back_to_base(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value ) {
					if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
						return 'not-an-array';
					}
					return $value;
				}
			);

			$this->assertSame(
				array( 'USD' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_filter_returning_empty_falls_back_to_base(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value ) {
					if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
						return array();
					}
					return $value;
				}
			);

			$this->assertSame(
				array( 'USD' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_filter_returning_all_invalid_codes_falls_back_to_base(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value ) {
					if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
						return array( '', 'xx', '1234', null );
					}
					return $value;
				}
			);

			$this->assertSame(
				array( 'USD' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_memoizes_within_request(): void {
			$call_count = 0;
			Functions\when( 'get_woocommerce_currency' )->alias(
				static function () use ( &$call_count ) {
					$call_count++;
					return 'USD';
				}
			);

			WC_AI_Storefront_Multi_Currency::get_accepted_currencies();
			WC_AI_Storefront_Multi_Currency::get_accepted_currencies();
			WC_AI_Storefront_Multi_Currency::get_accepted_currencies();

			$this->assertSame( 1, $call_count, 'get_woocommerce_currency should be called once per request' );
		}
	}
}
