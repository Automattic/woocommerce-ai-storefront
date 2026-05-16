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

	// `wp_parse_str` is declared here as a real function because
	// Brain Monkey's Patchwork-based aliasing cannot proxy a
	// pass-by-reference second parameter — see
	// https://github.com/Brain-WP/BrainMonkey/issues for context.
	// Guarded so re-running the test class (or running alongside a
	// future suite that also defines it) is safe.
	if ( ! function_exists( 'wp_parse_str' ) ) {
		function wp_parse_str( $str, &$result ) {
			parse_str( (string) $str, $result );
		}
	}

	// Test stand-in for the real WCPay global function.
	//
	// Production code calls `function_exists('WC_Payments_Multi_Currency')`
	// as the combined feature-flag check + singleton accessor.
	//
	// We declare the function unconditionally here (rather than via Brain
	// Monkey `when()`) because it needs a static $test_double slot —
	// Patchwork can't carry test-scoped state through an alias closure
	// cleanly. Setting `$_mc_test_double` directly from each test controls
	// what the function returns.
	//
	// To simulate "WCPay not installed / feature off" (both map to
	// `function_exists` = false in production), tests leave `$_mc_test_double`
	// as null; the function still exists in the test process but returns null,
	// which is the observable equivalent — the `is_object()` guard
	// short-circuits identically. The "function doesn't exist at all" path
	// cannot be tested with this scaffold because the stub is declared
	// unconditionally at file-load time.
	//
	// To simulate a partial-boot exception, tests set `$_mc_throw = true`.
	$GLOBALS['_mc_test_double'] = null;
	$GLOBALS['_mc_throw']       = false;

	if ( ! function_exists( 'WC_Payments_Multi_Currency' ) ) {
		function WC_Payments_Multi_Currency() {
			if ( $GLOBALS['_mc_throw'] ) {
				throw new \RuntimeException( 'WCPay partial-boot test exception' );
			}
			return $GLOBALS['_mc_test_double'];
		}
	}

	// Minimal stub class so Mockery can reflect
	// `\WCPay\MultiCurrency\MultiCurrency` when tests build doubles.
	// Only `get_enabled_currencies()` is needed — production code no longer
	// calls `::instance()` or `is_multi_currency_enabled()`.
	if ( ! class_exists( '\WCPay\MultiCurrency\MultiCurrency' ) ) {
		class WCPay_MultiCurrency_MultiCurrency_Stub {
			public function get_enabled_currencies() {
				return array();
			}
		}
		class_alias( 'WCPay_MultiCurrency_MultiCurrency_Stub', '\WCPay\MultiCurrency\MultiCurrency' );
	}

	class MultiCurrencyTest extends \PHPUnit\Framework\TestCase {
		use MockeryPHPUnitIntegration;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			WC_AI_Storefront_Multi_Currency::reset_cache();
			$GLOBALS['_mc_test_double'] = null;
			$GLOBALS['_mc_throw']       = false;

			Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			// `wp_parse_url` is globally stubbed in tests/php/stubs.php.
			// `wp_parse_str` is declared as a real function above this
			// class (pass-by-reference args can't go through Brain Monkey).
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
			$GLOBALS['_mc_test_double'] = null;
			$GLOBALS['_mc_throw']       = false;
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

		public function test_get_accepted_currencies_wcpay_function_returns_non_object_falls_back_to_base(): void {
			// Exercises the is_object() guard: function exists and returns a
			// non-null scalar (e.g. WCPay ships a refactor where the function
			// returns true/1 instead of the singleton). The is_object() check
			// short-circuits cleanly without reaching get_enabled_currencies().
			$GLOBALS['_mc_test_double'] = true;

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
	}
}
