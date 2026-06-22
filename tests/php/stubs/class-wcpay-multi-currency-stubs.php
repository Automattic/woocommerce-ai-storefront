<?php
/**
 * Shared WCPay multi-currency stubs for unit tests.
 *
 * Three symbols are stubbed here:
 *   1. `WC_Payments_Multi_Currency()`           — accessor function returning a
 *                                                 mock MultiCurrency singleton.
 *   2. `\WCPay\MultiCurrency\MultiCurrency`      — minimal class shape (via
 *                                                 class_alias to a local stub).
 *   3. `\WC_Payments_Features`                   — provides
 *                                                 `is_customer_multi_currency_enabled()`.
 *
 * Tests configure runtime behaviour via three globals (typically reset in
 * each test's setUp()):
 *   - `$GLOBALS['_mc_test_double']`     — Mockery double returned by
 *                                         `WC_Payments_Multi_Currency()`.
 *   - `$GLOBALS['_mc_throw']`           — when truthy, the accessor throws,
 *                                         simulating WCPay partial-boot.
 *   - `$GLOBALS['_mc_feature_enabled']` — boolean returned by
 *                                         `is_customer_multi_currency_enabled()`;
 *                                         set to the string `'throw'` to make
 *                                         the method throw.
 *
 * Loaded from `tests/php/bootstrap.php` so any test file can depend on these
 * symbols without coupling to test execution order. All definitions are
 * `if ( ! function_exists/class_exists )` guarded so individual test files
 * may redefine them inline without breaking.
 *
 * @package WooCommerce_AI_Storefront
 */

if ( ! function_exists( 'WC_Payments_Multi_Currency' ) ) {
	function WC_Payments_Multi_Currency() {
		if ( ! empty( $GLOBALS['_mc_throw'] ) ) {
			throw new \RuntimeException( 'WCPay partial-boot test exception' );
		}
		return $GLOBALS['_mc_test_double'] ?? null;
	}
}

if ( ! class_exists( '\WCPay\MultiCurrency\MultiCurrency' ) ) {
	class WCPay_MultiCurrency_MultiCurrency_Stub {
		public function get_enabled_currencies() {
			return array();
		}

		public static function instance() {
			return new self();
		}

		public function get_selected_currency() {
			$code = $GLOBALS['_mc_selected_currency'] ?? ( $GLOBALS['_mc_initial_selected'] ?? 'USD' );
			return new WCPay_MultiCurrency_Currency_Stub( $code );
		}

		public function update_selected_currency( $code, $persist = true ) {
			$GLOBALS['_mc_selected_currency'] = $code;
			if ( ! isset( $GLOBALS['_mc_update_calls'] ) || ! is_array( $GLOBALS['_mc_update_calls'] ) ) {
				$GLOBALS['_mc_update_calls'] = array();
			}
			$GLOBALS['_mc_update_calls'][] = array(
				'code'    => $code,
				'persist' => $persist,
			);
		}
	}
	class_alias( 'WCPay_MultiCurrency_MultiCurrency_Stub', '\WCPay\MultiCurrency\MultiCurrency' );
}

if ( ! class_exists( 'WCPay_MultiCurrency_Currency_Stub' ) ) {
	/**
	 * Minimal selected-currency object: only get_code() is read by the plugin.
	 */
	class WCPay_MultiCurrency_Currency_Stub {
		/** @var string */
		private $code;

		public function __construct( $code ) {
			$this->code = $code;
		}

		public function get_code() {
			return $this->code;
		}
	}
}

if ( ! class_exists( '\WC_Payments_Features' ) ) {
	class WC_Payments_Features {
		public static function is_customer_multi_currency_enabled() {
			if ( isset( $GLOBALS['_mc_feature_enabled'] ) && 'throw' === $GLOBALS['_mc_feature_enabled'] ) {
				throw new \RuntimeException( 'WCPay feature-flag option filter exploded' );
			}
			return ! empty( $GLOBALS['_mc_feature_enabled'] );
		}
	}
}
