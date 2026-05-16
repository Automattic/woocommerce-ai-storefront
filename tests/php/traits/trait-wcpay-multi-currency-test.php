<?php
/**
 * Shared test scaffolding for tests that exercise
 * `WC_AI_Storefront_Multi_Currency::with_active_currency()` end-to-end —
 * i.e. tests that need real WordPress-style filter semantics
 * (add_filter / remove_filter / apply_filters running registered callbacks)
 * AND a Mockery double for the WCPay multi-currency singleton.
 *
 * Used by:
 *   - UcpCatalogSearchTest        (Task 3: Store API dispatch wrap)
 *   - UcpFilterConversionTest     (Task 4: filters.price conversion — TBD)
 *   - UcpCheckoutCurrencyTest     (Task 6: checkout-sessions wrap — TBD)
 *
 * The MultiCurrencyTest file declares the same WCPay stubs and helper
 * inline (file-level + private method). Centralising here so the Phase 2
 * call-site tests don't duplicate the scaffolding.
 *
 * @package WooCommerce_AI_Storefront
 */

namespace {
	use Brain\Monkey\Functions;

	// WCPay soft-dependency stubs.
	//
	// `WC_AI_Storefront_Multi_Currency::get_accepted_currencies()` probes the
	// merchant's WCPay configuration via:
	//   1. `function_exists( 'WC_Payments_Multi_Currency' )`
	//   2. `class_exists( '\WC_Payments_Features' )`
	//   3. `\WC_Payments_Features::is_customer_multi_currency_enabled()`
	//   4. `WC_Payments_Multi_Currency()->get_enabled_currencies()`
	//
	// Tests building a Mockery double of `\WCPay\MultiCurrency\MultiCurrency`
	// and assigning it to `$GLOBALS['_mc_test_double']` need all four to
	// resolve. We declare the stubs at file-load time (idempotent guards)
	// so that loading this trait file once is enough — tests then control
	// behavior via the three globals documented below.
	//
	// State control via globals (mirrors MultiCurrencyTest):
	//   $_mc_test_double     — null (default) or a Mockery double of
	//                          `\WCPay\MultiCurrency\MultiCurrency`.
	//                          Returned by the global function stub.
	//   $_mc_throw           — bool. When true, the global function stub
	//                          throws (simulates WCPay partial-boot).
	//   $_mc_feature_enabled — bool|'throw'. Drives the
	//                          `is_customer_multi_currency_enabled()` stub.
	//
	// The MultiCurrencyTest file declares identical stubs at its own
	// file-load time. PHP's `function_exists` / `class_exists` guards make
	// the duplicate declarations safe: whichever file loads first wins.

	// `wp_parse_str` is declared as a real function (not a Brain Monkey
	// stub) because Patchwork-based aliasing cannot proxy a pass-by-reference
	// second parameter — see https://github.com/Brain-WP/BrainMonkey/issues
	// for context. Declared here in the shared trait file so any test
	// that loads the trait (or uses the multi-currency helper end-to-end
	// via `stamp_currency_query`) gets a consistent shim. Guarded with
	// `function_exists` so re-declaration across multiple test files is
	// safe.
	if ( ! function_exists( 'wp_parse_str' ) ) {
		function wp_parse_str( $str, &$result ) {
			parse_str( (string) $str, $result );
		}
	}

	if ( ! isset( $GLOBALS['_mc_test_double'] ) ) {
		$GLOBALS['_mc_test_double'] = null;
	}
	if ( ! isset( $GLOBALS['_mc_throw'] ) ) {
		$GLOBALS['_mc_throw'] = false;
	}
	if ( ! isset( $GLOBALS['_mc_feature_enabled'] ) ) {
		$GLOBALS['_mc_feature_enabled'] = true;
	}

	if ( ! function_exists( 'WC_Payments_Multi_Currency' ) ) {
		function WC_Payments_Multi_Currency() {
			if ( $GLOBALS['_mc_throw'] ) {
				throw new \RuntimeException( 'WCPay partial-boot test exception' );
			}
			return $GLOBALS['_mc_test_double'];
		}
	}

	if ( ! class_exists( '\WCPay\MultiCurrency\MultiCurrency' ) ) {
		class WCPay_MultiCurrency_MultiCurrency_Stub {
			public function get_enabled_currencies() {
				return array();
			}
		}
		class_alias( 'WCPay_MultiCurrency_MultiCurrency_Stub', '\WCPay\MultiCurrency\MultiCurrency' );
	}

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

	/**
	 * Shared filter-runtime helper for tests that exercise
	 * `with_active_currency()` end-to-end.
	 *
	 * Brain Monkey's default `apply_filters` stub does NOT execute
	 * callbacks registered via `add_filter` — it only matches against
	 * `Filters\expectApplied()` expectations and otherwise returns the
	 * default value unchanged. For end-to-end hook tests (production
	 * code registers via `add_filter`, tests verify via `apply_filters`)
	 * we need a real runtime.
	 *
	 * `install_real_filter_runtime()` swaps in Brain Monkey aliases
	 * for `add_filter` / `remove_filter` / `apply_filters` that store
	 * callbacks in `$GLOBALS['_mc_test_filters']` and run them in
	 * priority order. Hooks with no registered callback fall through
	 * and `apply_filters` returns the default value — same observable
	 * behavior as the default `Functions\when('apply_filters')->returnArg(2)`.
	 *
	 * Tests using this trait should:
	 *   - Call `$this->install_real_filter_runtime()` inside the test
	 *     body (NOT in setUp) so other tests in the same class keep
	 *     the default Brain Monkey stubs.
	 *   - Reset `$GLOBALS['_mc_test_filters']` in tearDown (the trait's
	 *     `tear_down_filter_runtime()` is provided for that purpose).
	 */
	trait WCPayMultiCurrencyTestTrait {

		/**
		 * Install a minimal real-WordPress-style filter runtime that
		 * overrides Brain Monkey's default stubs for the duration of the
		 * current test.
		 */
		protected function install_real_filter_runtime(): void {
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
							$args  = array_merge( array( $value ), $rest );
							$args  = array_slice( $args, 0, max( 1, (int) $entry['args'] ) );
							$value = call_user_func_array( $entry['cb'], $args );
						}
					}
					return $value;
				}
			);
		}

		/**
		 * Tear down the filter-runtime state. Call from `tearDown()`
		 * to prevent test-local hooks from leaking into subsequent
		 * tests. Brain Monkey's own tearDown clears its alias map,
		 * but the `$GLOBALS['_mc_test_filters']` array persists
		 * between tests unless explicitly unset.
		 */
		protected function tear_down_filter_runtime(): void {
			unset( $GLOBALS['_mc_test_filters'] );
		}
	}
}
