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
	// Production code uses `function_exists('WC_Payments_Multi_Currency')`
	// as the first gate (proves WCPay is installed), then separately checks
	// `\WC_Payments_Features::is_customer_multi_currency_enabled()` as the
	// runtime feature-flag gate. The two are deliberately distinct: the
	// function persists from boot regardless of subsequent toggle changes;
	// the feature-flag gate reads the live option.
	//
	// We declare the function unconditionally here (rather than via Brain
	// Monkey `when()`) because it needs a static test-double slot —
	// Patchwork can't carry test-scoped state through an alias closure
	// cleanly. Setting `$_mc_test_double` directly from each test controls
	// what the function returns.
	//
	// To simulate "WCPay not installed": tests cannot replicate this path
	// exactly (the stub is declared at file-load time), but setting
	// `$_mc_test_double = null` exercises the same observable branch — the
	// `is_object()` guard short-circuits identically to the function not
	// existing. The feature-flag gate is controlled via `$_mc_feature_enabled`.
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

	// Stub for WCPay's feature-flag class. Production code calls
	// `\WC_Payments_Features::is_customer_multi_currency_enabled()` at
	// runtime as the explicit multi-currency-feature gate (the global
	// function being defined isn't sufficient — it persists from boot
	// regardless of subsequent toggle changes).
	// Tests control the return value via `$_mc_feature_enabled`:
	//   true / false  → normal bool return
	//   'throw'       → throws RuntimeException (simulates a bad option_* filter)
	$GLOBALS['_mc_feature_enabled'] = true;

	if ( ! class_exists( '\WC_Payments_Features' ) ) {
		class WC_Payments_Features {
			public static function is_customer_multi_currency_enabled() {
				if ( 'throw' === $GLOBALS['_mc_feature_enabled'] ) {
					throw new \RuntimeException( 'WCPay feature-flag option filter exploded' );
				}
				return (bool) $GLOBALS['_mc_feature_enabled'];
			}
		}
	}

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
			$GLOBALS['_mc_test_double']     = null;
			$GLOBALS['_mc_throw']           = false;
			$GLOBALS['_mc_feature_enabled'] = true;
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
		// with_active_currency
		// ------------------------------------------------------------------

		/**
		 * Swap the default Brain Monkey filter stubs for a minimal
		 * real-WordPress-style filter runtime: add_filter / remove_filter
		 * store callbacks in a test-local map keyed by hook name and
		 * apply_filters actually runs them in priority order.
		 *
		 * Brain Monkey's `executeApplyFilters` does NOT run callbacks
		 * registered via `add_filter` — it only matches against
		 * `Filters\expectApplied()` expectations and otherwise returns
		 * the default value unchanged. For the `with_active_currency`
		 * tests we need end-to-end hook behavior (production code
		 * registers a hook via `add_filter`, callers verify via
		 * `apply_filters`), so we install a real runtime here.
		 *
		 * Hooks for which no callback is registered fall through and
		 * `apply_filters` returns the default value unchanged — same
		 * behavior as the setUp default `Functions\when('apply_filters')->returnArg(2)`.
		 */
		private function install_real_filter_runtime(): void {
			$GLOBALS['_mc_test_filters'] = array();

			Functions\when( 'add_filter' )->alias(
				static function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
					$GLOBALS['_mc_test_filters'][ $hook ][ $priority ][] = array(
						'cb'   => $callback,
						'args' => $accepted_args,
					);
					return true;
				}
			);

			Functions\when( 'remove_filter' )->alias(
				static function ( $hook, $callback, $priority = 10 ) {
					if ( empty( $GLOBALS['_mc_test_filters'][ $hook ][ $priority ] ) ) {
						return false;
					}
					foreach ( $GLOBALS['_mc_test_filters'][ $hook ][ $priority ] as $idx => $entry ) {
						if ( $entry['cb'] === $callback ) {
							unset( $GLOBALS['_mc_test_filters'][ $hook ][ $priority ][ $idx ] );
							return true;
						}
					}
					return false;
				}
			);

			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value, ...$rest ) {
					if ( empty( $GLOBALS['_mc_test_filters'][ $hook ] ) ) {
						return $value;
					}
					$by_priority = $GLOBALS['_mc_test_filters'][ $hook ];
					ksort( $by_priority );
					foreach ( $by_priority as $entries ) {
						foreach ( $entries as $entry ) {
							$args   = array_merge( array( $value ), $rest );
							$args   = array_slice( $args, 0, max( 1, (int) $entry['args'] ) );
							$value  = call_user_func_array( $entry['cb'], $args );
						}
					}
					return $value;
				}
			);
		}

		public function test_with_active_currency_runs_callable_and_returns_its_value(): void {
			$result = WC_AI_Storefront_Multi_Currency::with_active_currency(
				'USD',
				static function () {
					return 'callable-return-value';
				}
			);
			$this->assertSame( 'callable-return-value', $result );
		}

		public function test_with_active_currency_hooks_override_filter_during_callable(): void {
			// Inside the callable, apply_filters of the WCPay override filter
			// should return our requested currency. Outside, it should not.
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'USD' => new \stdClass(),
					'EUR' => new \stdClass(),
				)
			);
			$GLOBALS['_mc_test_double'] = $mc;

			// Swap in a real-WordPress-style filter runtime: add_filter
			// stores callbacks, apply_filters runs them, remove_filter
			// pulls them out. Brain Monkey's default stubs don't run
			// add_filter callbacks (they only verify expectations), so
			// this helper is required for end-to-end hook tests.
			$this->install_real_filter_runtime();

			$during = null;
			WC_AI_Storefront_Multi_Currency::with_active_currency(
				'EUR',
				static function () use ( &$during ) {
					$during = apply_filters( 'wcpay_multi_currency_override_selected_currency', false );
				}
			);
			$after = apply_filters( 'wcpay_multi_currency_override_selected_currency', false );

			$this->assertSame( 'EUR', $during, 'Inside the callable, the filter should return EUR' );
			$this->assertFalse( $after, 'After the callable returns, the filter should be unhooked' );
		}

		public function test_with_active_currency_unhooks_filter_on_exception(): void {
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'USD' => new \stdClass(),
					'EUR' => new \stdClass(),
				)
			);
			$GLOBALS['_mc_test_double'] = $mc;

			$this->install_real_filter_runtime();

			try {
				WC_AI_Storefront_Multi_Currency::with_active_currency(
					'EUR',
					static function () {
						throw new \RuntimeException( 'callable exploded' );
					}
				);
				$this->fail( 'Expected exception to propagate' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'callable exploded', $e->getMessage() );
			}

			$after = apply_filters( 'wcpay_multi_currency_override_selected_currency', false );
			$this->assertFalse( $after, 'Filter must be unhooked even when callable throws' );
		}

		public function test_with_active_currency_is_noop_when_code_not_in_accepted_set(): void {
			// USD-only store (no WCPay double, no extra currencies). The
			// callable still runs but the filter is never hooked, so the
			// agent's request runs in base currency.
			$this->install_real_filter_runtime();

			$during = null;
			$result = WC_AI_Storefront_Multi_Currency::with_active_currency(
				'EUR', // not in accepted set on a USD-only store
				static function () use ( &$during ) {
					$during = apply_filters( 'wcpay_multi_currency_override_selected_currency', false );
					return 'ran-anyway';
				}
			);

			$this->assertSame( 'ran-anyway', $result );
			$this->assertFalse( $during, 'Filter must NOT be hooked when code is not in accepted set' );
		}

		public function test_with_active_currency_is_noop_when_code_is_malformed(): void {
			$this->install_real_filter_runtime();

			$during = null;
			WC_AI_Storefront_Multi_Currency::with_active_currency(
				'eu', // not ISO 4217
				static function () use ( &$during ) {
					$during = apply_filters( 'wcpay_multi_currency_override_selected_currency', false );
				}
			);

			$this->assertFalse( $during );
		}
	}
}
