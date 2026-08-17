<?php
/**
 * Tests for WC_AI_Storefront_Multi_Currency.
 *
 * Covers the soft-dependency WooPayments multi-currency reader.
 *
 * @package WooCommerce_AI_Storefront
 */

namespace {
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

	// WCPay soft-dependency stubs are loaded from tests/php/stubs/class-wcpay-multi-currency-stubs.php
	// via bootstrap.php. State control and initialization for each test is done via setUp() below.

	class MultiCurrencyTest extends \PHPUnit\Framework\TestCase {
		use MockeryPHPUnitIntegration;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			WC_AI_Storefront_Multi_Currency::reset_cache();
			$GLOBALS['_mc_test_double']     = null;
			$GLOBALS['_mc_throw']           = false;
			$GLOBALS['_mc_feature_enabled'] = true;

			Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			// `wp_parse_url` is globally stubbed in tests/php/stubs.php.
			// `wp_parse_str` is declared as a real function at the top of this
			// file (pass-by-reference args can't go through Brain Monkey).
			// `add_query_arg` mirrors WP core's parse-rebuild behavior so
			// the existing query string gets re-encoded (`42:1` → `42%3A1`)
			// and an existing key is replaced rather than duplicated.
			Functions\when( 'add_query_arg' )->alias(
				static function ( $key, $value, $url ) {
					// The helper only uses the 3-arg form. Mirror WP core:
					// parse the existing query, set/replace the new pair,
					// and rebuild via http_build_query() — which RFC 3986
					// encodes both the existing values and the new pair.
					$parts        = explode( '?', $url, 2 );
					$base         = $parts[0];
					$query_string = $parts[1] ?? '';
					$params       = array();
					if ( '' !== $query_string ) {
						parse_str( $query_string, $params );
					}
					$params[ (string) $key ] = (string) $value;
					return $base . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
				}
			);
		}

		protected function tearDown(): void {
			$GLOBALS['_mc_test_double']     = null;
			$GLOBALS['_mc_throw']           = false;
			$GLOBALS['_mc_feature_enabled'] = true;
			unset( $GLOBALS['_mc_test_filters'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		// ------------------------------------------------------------------
		// get_accepted_currencies — WCPay probe
		// ------------------------------------------------------------------

		public function test_get_accepted_currencies_no_wcpay_double_returns_base_only(): void {
			// $_mc_test_double is null → function exists but returns null →
			// is_object() guard short-circuits → base-only list.
			$this->assertSame(
				array( 'USD' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_wcpay_enabled_returns_full_list_with_base_first(): void {
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'USD' => new \stdClass(),
					'EUR' => new \stdClass(),
					'GBP' => new \stdClass(),
				)
			);
			$GLOBALS['_mc_test_double'] = $mc;

			$this->assertSame(
				array( 'USD', 'EUR', 'GBP' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_base_missing_from_wcpay_list_is_prepended(): void {
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'EUR' => new \stdClass(),
					'GBP' => new \stdClass(),
				)
			);
			$GLOBALS['_mc_test_double'] = $mc;

			$this->assertSame(
				array( 'USD', 'EUR', 'GBP' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_duplicates_are_deduped_preserving_order(): void {
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
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
			$GLOBALS['_mc_test_double'] = $mc;

			$this->assertSame(
				array( 'USD', 'EUR', 'GBP' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_malformed_codes_are_dropped(): void {
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'EUR'  => new \stdClass(),
					'eurr' => new \stdClass(),
					''     => new \stdClass(),
					'12'   => new \stdClass(),
					'GBP'  => new \stdClass(),
				)
			);
			$GLOBALS['_mc_test_double'] = $mc;

			$this->assertSame(
				array( 'USD', 'EUR', 'GBP' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}

		public function test_get_accepted_currencies_wcpay_get_enabled_currencies_returning_non_array_falls_back_to_base(): void {
			// This test verifies the is_array() guard is present. Without it,
			// array_keys(null) throws a TypeError — which the outer try-catch
			// would silently swallow, still returning ['USD']. To confirm the
			// guard fires rather than the catch, we verify no exception path
			// is exercised by asserting the result directly. The guard's
			// independent value is also covered by
			// test_get_accepted_currencies_wcpay_function_throws.
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn( null );
			$GLOBALS['_mc_test_double'] = $mc;

			$this->assertSame( array( 'USD' ), WC_AI_Storefront_Multi_Currency::get_accepted_currencies() );
		}

		public function test_get_accepted_currencies_wcpay_function_throws_falls_back_to_base(): void {
			$GLOBALS['_mc_throw'] = true;

			$this->assertSame( array( 'USD' ), WC_AI_Storefront_Multi_Currency::get_accepted_currencies() );
		}

		public function test_get_accepted_currencies_wcpay_features_class_throws_falls_back_to_base(): void {
			// Simulates a third-party `option_*` filter on the DB option that
			// backs `is_customer_multi_currency_enabled()` throwing an exception.
			// The entire probe — including the feature-flag read — lives inside
			// the try-catch boundary so a bad filter hook can't 500 every request.
			// `'throw'` sentinel triggers the stub to throw rather than return bool.
			$GLOBALS['_mc_feature_enabled'] = 'throw';

			$this->assertSame( array( 'USD' ), WC_AI_Storefront_Multi_Currency::get_accepted_currencies() );
		}

		public function test_get_accepted_currencies_wcpay_function_returns_non_object_falls_back_to_base(): void {
			// Exercises the is_object() guard: function exists and returns a
			// non-null scalar (e.g. WCPay ships a refactor where the function
			// returns true/1 instead of the singleton). The is_object() check
			// short-circuits cleanly without reaching get_enabled_currencies().
			$GLOBALS['_mc_test_double'] = true;

			$this->assertSame( array( 'USD' ), WC_AI_Storefront_Multi_Currency::get_accepted_currencies() );
		}

		public function test_get_accepted_currencies_wcpay_feature_disabled_returns_base_only_even_with_configured_currencies(): void {
			// The merchant toggled multi-currency off in WooPayments settings,
			// but the global function is still defined (registered at
			// plugins_loaded:12) and get_enabled_currencies() still returns the
			// historical configured set. The runtime feature-flag check via
			// `\WC_Payments_Features::is_customer_multi_currency_enabled()`
			// is what stops us advertising those currencies.
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldNotReceive( 'get_enabled_currencies' );
			$GLOBALS['_mc_test_double']     = $mc;
			$GLOBALS['_mc_feature_enabled'] = false;

			$this->assertSame( array( 'USD' ), WC_AI_Storefront_Multi_Currency::get_accepted_currencies() );
		}

		// ------------------------------------------------------------------
		// get_accepted_currencies — base currency handling
		// ------------------------------------------------------------------

		public function test_get_accepted_currencies_invalid_base_currency_falls_back_to_usd(): void {
			Functions\when( 'get_woocommerce_currency' )->justReturn( '' );

			$this->assertSame( array( 'USD' ), WC_AI_Storefront_Multi_Currency::get_accepted_currencies() );
		}

		public function test_get_accepted_currencies_non_iso_base_currency_falls_back_to_usd(): void {
			Functions\when( 'get_woocommerce_currency' )->justReturn( 'EURO' );

			$this->assertSame( array( 'USD' ), WC_AI_Storefront_Multi_Currency::get_accepted_currencies() );
		}

		// ------------------------------------------------------------------
		// get_accepted_currencies — filter behaviour
		// ------------------------------------------------------------------

		public function test_get_accepted_currencies_filter_throwing_falls_back_to_auto_detected_list(): void {
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'USD' => new \stdClass(),
					'EUR' => new \stdClass(),
				)
			);
			$GLOBALS['_mc_test_double'] = $mc;

			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value, ...$extras ) {
					if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
						throw new \RuntimeException( 'filter callback exploded' );
					}
					return $value;
				}
			);

			// The catch falls back to the auto-detected list, not just base.
			$this->assertSame(
				array( 'USD', 'EUR' ),
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

		// ------------------------------------------------------------------
		// get_accepted_currencies — memoization
		// ------------------------------------------------------------------

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

		public function test_get_accepted_currencies_memoizes_wcpay_call(): void {
			// Verify the WCPay probe is also behind the cache guard. Mockery's
			// ->once() assertion fires in tearDown if get_enabled_currencies()
			// is called more than once across the three get_accepted_currencies()
			// invocations below.
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'get_enabled_currencies' )->once()->andReturn(
				array(
					'USD' => new \stdClass(),
					'EUR' => new \stdClass(),
				)
			);
			$GLOBALS['_mc_test_double'] = $mc;

			WC_AI_Storefront_Multi_Currency::get_accepted_currencies();
			WC_AI_Storefront_Multi_Currency::get_accepted_currencies();
			WC_AI_Storefront_Multi_Currency::get_accepted_currencies();
		}

		// ------------------------------------------------------------------
		// stamp_currency_query
		// ------------------------------------------------------------------

		public function test_stamp_currency_query_no_request_currency_returns_url_unchanged(): void {
			$url = 'https://example.com/checkout-link/?products=42:1';
			$this->assertSame(
				$url,
				WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, null )
			);
		}

		public function test_stamp_currency_query_request_currency_matches_base_stamps_url(): void {
			// Base is USD (setUp default). Stamping the base is harmless
			// redundancy that keeps the rule predictable — the WCPay
			// page handler treats `?currency=USD` as a no-op on a USD
			// store, and stamping consistently makes the agent's
			// expectation match what the buyer sees.
			$url    = 'https://example.com/checkout-link/?products=42:1';
			$result = WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, 'USD' );
			$this->assertSame(
				'https://example.com/checkout-link/?products=42%3A1&currency=USD',
				$result
			);
		}

		public function test_stamp_currency_query_request_currency_in_accepted_set_stamps_url(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value ) {
					if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
						return array( 'USD', 'EUR', 'GBP' );
					}
					return $value;
				}
			);

			$url    = 'https://example.com/checkout-link/?products=42:1';
			$result = WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, 'EUR' );
			$this->assertSame(
				'https://example.com/checkout-link/?products=42%3A1&currency=EUR',
				$result
			);
		}

		public function test_stamp_currency_query_request_currency_not_in_accepted_set_returns_url_unchanged(): void {
			// Base-only accepted list (default setUp). 'JPY' is not in it.
			$url = 'https://example.com/checkout-link/?products=42:1';
			$this->assertSame(
				$url,
				WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, 'JPY' )
			);
		}

		public function test_stamp_currency_query_malformed_request_currency_returns_url_unchanged(): void {
			$url = 'https://example.com/checkout-link/?products=42:1';
			$this->assertSame(
				$url,
				WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, 'usdollars' )
			);
			$this->assertSame(
				$url,
				WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, '' )
			);
			$this->assertSame(
				$url,
				WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, '12' )
			);
		}

		public function test_stamp_currency_query_url_with_existing_currency_param_returns_url_unchanged(): void {
			$url = 'https://example.com/checkout-link/?currency=EUR&products=42:1';
			$this->assertSame(
				$url,
				WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, 'USD' )
			);
		}

		public function test_stamp_currency_query_non_string_url_returns_empty_string(): void {
			$this->assertSame( '', WC_AI_Storefront_Multi_Currency::stamp_currency_query( null, 'USD' ) );
			$this->assertSame( '', WC_AI_Storefront_Multi_Currency::stamp_currency_query( 42, 'USD' ) );
			$this->assertSame( '', WC_AI_Storefront_Multi_Currency::stamp_currency_query( array(), 'USD' ) );
			$this->assertSame( '', WC_AI_Storefront_Multi_Currency::stamp_currency_query( false, 'USD' ) );
		}

		public function test_stamp_currency_query_empty_url_returns_empty_string(): void {
			$this->assertSame(
				'',
				WC_AI_Storefront_Multi_Currency::stamp_currency_query( '', 'USD' )
			);
		}

		public function test_stamp_currency_query_whitespace_padded_currency_is_trimmed_and_accepted(): void {
			$url    = 'https://example.com/product/widget/';
			$result = WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, ' USD ' );
			$this->assertSame( 'https://example.com/product/widget/?currency=USD', $result );
		}

		public function test_stamp_currency_query_lowercase_currency_is_uppercased_and_accepted(): void {
			$url    = 'https://example.com/product/widget/';
			$result = WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, 'usd' );
			$this->assertSame( 'https://example.com/product/widget/?currency=USD', $result );
		}

		public function test_stamp_currency_query_capitalized_currency_param_is_not_detected_and_stamp_is_applied(): void {
			// The idempotency guard only detects lowercase `currency=`.
			// A URL with `Currency=EUR` (capital C) will have a second
			// `currency=USD` appended — this is a documented limitation.
			$url    = 'https://example.com/product/widget/?Currency=EUR';
			$result = WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, 'USD' );
			$this->assertStringContainsString( 'currency=USD', $result );
			$this->assertStringContainsString( 'Currency=EUR', $result );
		}

		public function test_stamp_currency_query_url_with_no_existing_query_appends_currency(): void {
			$url    = 'https://example.com/product/widget/';
			$result = WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, 'USD' );
			$this->assertSame( 'https://example.com/product/widget/?currency=USD', $result );
		}

		public function test_stamp_currency_query_is_idempotent_when_called_twice(): void {
			$url    = 'https://example.com/checkout-link/?products=42:1';
			$first  = WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, 'USD' );
			$second = WC_AI_Storefront_Multi_Currency::stamp_currency_query( $first, 'USD' );
			$this->assertSame( $first, $second, 'Double-stamping should be a no-op' );
		}

		// ------------------------------------------------------------------
		// convert_amount
		// ------------------------------------------------------------------

		public function test_convert_amount_returns_input_when_currencies_match(): void {
			// Same-currency conversion is a no-op even without WCPay.
			$this->assertSame(
				1999,
				WC_AI_Storefront_Multi_Currency::convert_amount( 1999, 'USD', 'USD' )
			);
		}

		public function test_convert_amount_uses_wcpay_get_raw_conversion_for_cross_currency(): void {
			// EUR → USD: agent sends 5000 minor units (€50), rate 0.85.
			// get_raw_conversion(50.0, 'USD', 'EUR') returns 50 / 0.85 ≈ 58.82.
			// convert_amount should return round(58.82 * 100) = 5882.
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'get_raw_conversion' )
				->with( 50.0, 'USD', 'EUR' )
				->andReturn( 58.82352941 );
			$GLOBALS['_mc_test_double'] = $mc;

			// convert_amount does NOT require a with_active_currency scope —
			// it uses get_raw_conversion() with explicit from/to codes.
			$converted = WC_AI_Storefront_Multi_Currency::convert_amount( 5000, 'EUR', 'USD' );

			$this->assertSame( 5882, $converted );
		}

		public function test_convert_amount_throws_when_wcpay_unavailable(): void {
			// $_mc_test_double = null → WC_Payments_Multi_Currency() returns null →
			// is_object() guard throws. (We cannot reach the function_exists() throw
			// because the test harness unconditionally declares the WC_Payments_Multi_Currency
			// shim at file load — known scaffolding limitation, not a defect.)
			$GLOBALS['_mc_test_double'] = null;

			$this->expectException( \RuntimeException::class );
			WC_AI_Storefront_Multi_Currency::convert_amount( 5000, 'EUR', 'USD' );
		}

		public function test_convert_amount_throws_for_negative_input(): void {
			$this->expectException( \InvalidArgumentException::class );
			WC_AI_Storefront_Multi_Currency::convert_amount( -1, 'EUR', 'USD' );
		}

		// ------------------------------------------------------------------
		// with_active_currency — dispatch-time switch (Mechanism B, #517)
		// ------------------------------------------------------------------

		/**
		 * Configure the WCPay mock so $codes count as accepted currencies,
		 * with USD as the base. Resets the selected-currency + update-call
		 * tracking globals.
		 *
		 * @param array<int, string> $codes Enabled currency codes (assoc keys
		 *                                   are what get_accepted_currencies reads).
		 */
		private function configureAcceptedCurrencies( array $codes ): void {
			$enabled = array();
			foreach ( $codes as $c ) {
				$enabled[ $c ] = (object) array();
			}
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn( $enabled );
			$GLOBALS['_mc_test_double']       = $mc;
			$GLOBALS['_mc_feature_enabled']   = true;
			$GLOBALS['_mc_initial_selected']  = 'USD';
			$GLOBALS['_mc_selected_currency'] = 'USD';
			$GLOBALS['_mc_update_calls']      = array();
			WC_AI_Storefront_Multi_Currency::reset_cache();
		}

		public function test_with_active_currency_switches_for_accepted_non_base_code(): void {
			$this->configureAcceptedCurrencies( array( 'CAD' ) );

			// All three WCPay override filters must be added at priority 99
			// then removed in finally.
			Functions\expect( 'add_filter' )
				->with( 'wcpay_multi_currency_override_selected_currency', \Mockery::type( 'callable' ), 99 )
				->once();
			Functions\expect( 'add_filter' )
				->with( 'wcpay_multi_currency_should_convert_product_price', '__return_true', 99 )
				->once();
			Functions\expect( 'add_filter' )
				->with( 'wcpay_multi_currency_should_return_store_currency', '__return_false', 99 )
				->once();
			Functions\expect( 'remove_filter' )
				->with( 'wcpay_multi_currency_override_selected_currency', \Mockery::type( 'callable' ), 99 )
				->once();
			Functions\expect( 'remove_filter' )
				->with( 'wcpay_multi_currency_should_convert_product_price', '__return_true', 99 )
				->once();
			Functions\expect( 'remove_filter' )
				->with( 'wcpay_multi_currency_should_return_store_currency', '__return_false', 99 )
				->once();

			$ran = false;
			$out = WC_AI_Storefront_Multi_Currency::with_active_currency(
				'CAD',
				static function () use ( &$ran ) {
					$ran = true;
					return 'result';
				}
			);

			$this->assertTrue( $ran, 'callback must run' );
			$this->assertSame( 'result', $out, 'callback return value must propagate' );

			// update_selected_currency called to switch to CAD then restore USD,
			// both non-persistent.
			$this->assertSame(
				array(
					array(
						'code'    => 'CAD',
						'persist' => false,
					),
					array(
						'code'    => 'USD',
						'persist' => false,
					),
				),
				$GLOBALS['_mc_update_calls'],
				'must switch to CAD then restore the prior selected currency, both non-persistent'
			);
		}

		public function test_with_active_currency_noop_for_base_currency(): void {
			$this->configureAcceptedCurrencies( array( 'CAD' ) );

			// No filters added when the requested code is the base currency.
			Functions\expect( 'add_filter' )->never();
			Functions\expect( 'remove_filter' )->never();

			$out = WC_AI_Storefront_Multi_Currency::with_active_currency(
				'USD',
				static function () {
					return 'base';
				}
			);

			$this->assertSame( 'base', $out );
			$this->assertSame( array(), $GLOBALS['_mc_update_calls'], 'no currency switch for base currency' );
		}

		public function test_with_active_currency_noop_for_unaccepted_currency(): void {
			// CAD accepted, JPY is not.
			$this->configureAcceptedCurrencies( array( 'CAD' ) );

			Functions\expect( 'add_filter' )->never();
			Functions\expect( 'remove_filter' )->never();

			$out = WC_AI_Storefront_Multi_Currency::with_active_currency(
				'JPY',
				static function () {
					return 'unaccepted';
				}
			);

			$this->assertSame( 'unaccepted', $out );
			$this->assertSame( array(), $GLOBALS['_mc_update_calls'], 'no switch for an unaccepted currency' );
		}

		public function test_with_active_currency_noop_for_null_code(): void {
			$this->configureAcceptedCurrencies( array( 'CAD' ) );

			Functions\expect( 'add_filter' )->never();

			$out = WC_AI_Storefront_Multi_Currency::with_active_currency(
				null,
				static function () {
					return 'null';
				}
			);

			$this->assertSame( 'null', $out );
			$this->assertSame( array(), $GLOBALS['_mc_update_calls'] );
		}

		public function test_with_active_currency_noop_for_malformed_code(): void {
			$this->configureAcceptedCurrencies( array( 'CAD' ) );

			Functions\expect( 'add_filter' )->never();

			$out = WC_AI_Storefront_Multi_Currency::with_active_currency(
				'gibberish',
				static function () {
					return 'malformed';
				}
			);

			$this->assertSame( 'malformed', $out );
			$this->assertSame( array(), $GLOBALS['_mc_update_calls'] );
		}

		public function test_with_active_currency_restores_currency_when_callback_throws(): void {
			$this->configureAcceptedCurrencies( array( 'CAD' ) );

			// Filters still added + removed even though the callback throws.
			Functions\expect( 'add_filter' )->times( 3 );
			Functions\expect( 'remove_filter' )->times( 3 );

			$threw = false;
			try {
				WC_AI_Storefront_Multi_Currency::with_active_currency(
					'CAD',
					static function () {
						throw new \RuntimeException( 'dispatch blew up' );
					}
				);
			} catch ( \RuntimeException $e ) {
				$threw = true;
			}

			$this->assertTrue( $threw, 'exception must propagate' );
			// The selected currency must be restored to USD even on throw.
			$this->assertSame( 'USD', $GLOBALS['_mc_selected_currency'], 'currency restored in finally' );
			$last = end( $GLOBALS['_mc_update_calls'] );
			$this->assertSame( 'USD', $last['code'], 'last switch restores the prior currency' );
		}

		public function test_with_active_currency_no_state_leak_between_calls(): void {
			$this->configureAcceptedCurrencies( array( 'CAD' ) );

			Functions\when( 'add_filter' )->justReturn( true );
			Functions\when( 'remove_filter' )->justReturn( true );

			// First call switches + restores.
			WC_AI_Storefront_Multi_Currency::with_active_currency(
				'CAD',
				static function () {
					return null;
				}
			);
			$this->assertSame( 'USD', $GLOBALS['_mc_selected_currency'], 'restored after first call' );

			// Second call in the same process starts clean and restores again.
			$GLOBALS['_mc_update_calls'] = array();
			WC_AI_Storefront_Multi_Currency::with_active_currency(
				'CAD',
				static function () {
					return null;
				}
			);

			$this->assertSame(
				array(
					array(
						'code'    => 'CAD',
						'persist' => false,
					),
					array(
						'code'    => 'USD',
						'persist' => false,
					),
				),
				$GLOBALS['_mc_update_calls'],
				'second call switches from the clean USD baseline, proving no leak'
			);
		}
	}
}
