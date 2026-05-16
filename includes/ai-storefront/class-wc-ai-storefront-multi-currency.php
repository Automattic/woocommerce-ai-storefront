<?php
/**
 * WooPayments multi-currency reader.
 *
 * Soft-dependency helper that returns the list of currencies the
 * store accepts. When WooPayments' multi-currency feature is active,
 * the list mirrors WCPay's enabled set with the WooCommerce base
 * currency forced into the first position. When WooPayments is
 * absent or the multi-currency feature is currently disabled, the
 * list is `[ base_currency ]` — never empty, never null. The
 * runtime gate is `\WC_Payments_Features::is_customer_multi_currency_enabled()`,
 * not just `function_exists('WC_Payments_Multi_Currency')` — the
 * latter persists from `plugins_loaded:12` regardless of subsequent
 * toggle changes, and `get_enabled_currencies()` returns the
 * historical configured set whether the feature is on or off.
 *
 * All call sites get the same array shape regardless of detection
 * outcome, which keeps consumer code free of detection branches.
 *
 * Phase 1 ships two behaviours: (a) advertising accepted currencies on
 * all machine-readable surfaces (UCP manifest, JSON-LD, llms.txt) and
 * (b) stamping `?currency=XXX` on outbound buyer-facing URLs so the
 * WooPayments page handler switches the currency at render time.
 * Phase 2 (deferred) refers specifically to server-side price
 * computation inside UCP catalog responses — switching the WooCommerce
 * session currency before price lookup so the API returns prices in the
 * requested currency rather than the store base.
 *
 * @package WooCommerce_AI_Storefront
 * @since 0.17.0
 */

defined( 'ABSPATH' ) || exit;

class WC_AI_Storefront_Multi_Currency {

	/**
	 * Per-request memoized result. `null` means "not computed yet";
	 * after the first call, holds the resolved array (always at
	 * least one element).
	 *
	 * @var array<int, string>|null
	 */
	private static $cache = null;

	/**
	 * Reset the per-request memoization. Test-only — never called
	 * from production code, but a public no-op is safer than a
	 * test-only private state-reset via reflection.
	 *
	 * @since 0.17.0
	 * @return void
	 */
	public static function reset_cache() {
		self::$cache = null;
	}

	/**
	 * Return the ordered, deduplicated list of accepted currency
	 * codes (ISO 4217, uppercase). Always non-empty.
	 *
	 * Order: base currency first, then WooPayments-enabled
	 * currencies in WCPay's reported order, deduplicated. Malformed
	 * codes are dropped.
	 *
	 * Applies the `wc_ai_storefront_accepted_currencies` filter at
	 * the end. If the filter returns a non-array, an empty array,
	 * or an array whose entries all fail ISO-4217 validation, the
	 * helper falls back to `[ base ]`.
	 *
	 * @return array<int, string>
	 */
	public static function get_accepted_currencies() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$base = function_exists( 'get_woocommerce_currency' )
			? strtoupper( (string) get_woocommerce_currency() )
			: 'USD';

		// Defensive: if the base call returned empty or non-ISO
		// (e.g. WC not loaded), fall back to 'USD' to match the
		// existing convention in class-wc-ai-storefront-jsonld.php:435
		// and the UCP product translator.
		if ( '' === $base || ! preg_match( '/^[A-Z]{3}$/', $base ) ) {
			$base = 'USD';
		}

		$list = array( $base );

		// Soft-dependency probe with explicit runtime feature-flag check.
		//
		// `function_exists('WC_Payments_Multi_Currency')` is necessary but
		// NOT sufficient: the function is registered at boot time based on a
		// load-time option read, and `get_enabled_currencies()` returns the
		// historical configured set regardless of whether the feature is
		// currently toggled on. A merchant who configured 13 currencies and
		// then disabled the multi-currency feature would still see all 13
		// reported via the singleton — so we must explicitly re-check the
		// merchant-facing toggle at runtime via
		// `WC_Payments_Features::is_customer_multi_currency_enabled()`.
		//
		// The entire probe — including the feature-flag read — is wrapped in
		// try-catch. `is_customer_multi_currency_enabled()` reads a DB option
		// via `get_option()`, which fires the `option_*` filter chain; a
		// third-party hook on that filter can throw, so the call must be
		// inside the catch boundary.
		if ( function_exists( 'WC_Payments_Multi_Currency' )
			&& class_exists( '\WC_Payments_Features' ) ) {
			try {
				if ( \WC_Payments_Features::is_customer_multi_currency_enabled() ) {
					$mc = WC_Payments_Multi_Currency();
					if ( is_object( $mc ) ) {
						$enabled = $mc->get_enabled_currencies();
						if ( is_array( $enabled ) ) {
							// `get_enabled_currencies()` returns an associative
							// array keyed by ISO-4217 codes, with Currency
							// objects as values. Keys are typically uppercase
							// but we call strtoupper() unconditionally to stay
							// resilient to non-canonical casing from future
							// WCPay builds. We read the keys to stay resilient
							// to refactors of the Currency object's method
							// surface.
							foreach ( array_keys( $enabled ) as $code ) {
								$list[] = strtoupper( (string) $code );
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				// WCPay or a filter callback in partial-boot state;
				// fall through to base-only list.
				WC_AI_Storefront_Logger::debug(
					'multi-currency: WC_Payments_Multi_Currency() threw %s — falling back to base-only list. Message: %s',
					get_class( $e ),
					$e->getMessage()
				);
			}
		}

		$list = self::normalize_codes( $list );

		/**
		 * Filter the list of currencies the store advertises as accepted.
		 *
		 * Fires after auto-detection (WooPayments enabled list, with the
		 * store base currency forced first) and before the array is
		 * cached and returned to callers. Integrators populate this for
		 * non-WooPayments multi-currency plugins (CURCY, WC Currency
		 * Switcher, etc.), or to curate the WooPayments-reported list
		 * (e.g. hide a test-only currency).
		 *
		 * Contract: the filter MUST return an array of ISO-4217 codes
		 * (three uppercase ASCII letters). Malformed entries are silently
		 * dropped; the fallback to `[ base_currency ]` only activates
		 * when the result is a non-array, an empty array, or an array
		 * whose entries all fail ISO-4217 validation.
		 *
		 * @since 0.17.0
		 *
		 * @param array<int, string> $list Auto-detected list, base currency first.
		 */
		try {
			$filtered = apply_filters( 'wc_ai_storefront_accepted_currencies', $list );
		} catch ( \Throwable $e ) {
			// A third-party filter callback threw; fall back to auto-detected list.
			WC_AI_Storefront_Logger::debug(
				'multi-currency: wc_ai_storefront_accepted_currencies filter threw %s — using auto-detected list. Message: %s',
				get_class( $e ),
				$e->getMessage()
			);
			$filtered = $list;
		}

		if ( is_array( $filtered ) ) {
			$filtered = self::normalize_codes( $filtered );
			if ( ! empty( $filtered ) ) {
				$list = $filtered;
			}
		}

		self::$cache = $list;
		return self::$cache;
	}

	/**
	 * Stamp `?currency=XXX` onto an outbound buyer-facing URL when the
	 * agent's requested currency is in the accepted-currencies set.
	 *
	 * Designed for `continue_url` (UCP `POST /checkout-sessions`) and
	 * per-product `url` fields in `catalog/search` / `catalog/lookup`
	 * responses. Honors the agent's `context.currency` hint without
	 * changing the catalog data (Phase 1 honesty boundary): the buyer
	 * lands on the merchant's checkout / PDP in the requested currency,
	 * but the agent's recommendation was still computed against
	 * base-currency catalog data.
	 *
	 * Fail-closed paths (URL returned unchanged, except non-string $url
	 * which is coerced to '' so consumers always get a string back):
	 *   - $url is a non-string (null, int, array, etc.) → returns ''.
	 *   - $url is the empty string → returns ''.
	 *   - $requested_currency null or non-string → returns $url unchanged.
	 *   - $requested_currency fails the ISO-4217 pattern after
	 *     trim + uppercase → returns $url unchanged.
	 *   - $requested_currency is not in `get_accepted_currencies()` →
	 *     returns $url unchanged.
	 *   - $url already carries a lowercase `currency=` query param →
	 *     returns $url unchanged. Preserves any upstream override or
	 *     filter-injected value. Note: only the lowercase key `currency`
	 *     is detected; a capitalised variant (e.g. `Currency=`) will not
	 *     trigger this guard.
	 *
	 * Whitespace-padded currency codes (`' usd '`) are trimmed before
	 * validation per Postel's law — accepts what the network sends,
	 * emits a strict canonical form.
	 *
	 * The function is idempotent: calling it twice with the same args
	 * produces the same URL.
	 *
	 * @since 0.17.0
	 *
	 * @param string      $url                Outbound URL to stamp.
	 * @param string|null $requested_currency Candidate ISO-4217 code from
	 *                                        the agent's request. Canonical
	 *                                        source: `self::get_request_currency( $request )`,
	 *                                        which normalises the raw
	 *                                        `$request->get_param('context')['currency']`
	 *                                        value before it reaches this function.
	 * @return string The stamped URL, or the input URL unchanged.
	 */
	public static function stamp_currency_query( $url, $requested_currency ) {
		if ( ! is_string( $url ) ) {
			return '';
		}
		if ( '' === $url ) {
			return '';
		}

		if ( ! is_string( $requested_currency ) ) {
			return $url;
		}

		$normalized = strtoupper( trim( $requested_currency ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $normalized ) ) {
			return $url;
		}

		$accepted = self::get_accepted_currencies();
		if ( ! in_array( $normalized, $accepted, true ) ) {
			return $url;
		}

		// Idempotency / override-preservation guard. If the URL already
		// carries `currency=`, leave it alone — preserves any agent-set
		// value upstream or filter-injected override and makes double
		// stamping a no-op.
		$query_string = wp_parse_url( $url, PHP_URL_QUERY );
		if ( is_string( $query_string ) && '' !== $query_string ) {
			$params = array();
			wp_parse_str( $query_string, $params );
			if ( array_key_exists( 'currency', $params ) ) {
				return $url;
			}
		}

		return add_query_arg( 'currency', $normalized, $url );
	}

	/**
	 * Run a callable with a WooPayments presentment-currency override
	 * active for its duration. Used by UCP dispatch sites that need
	 * catalog responses, filter math, or price comparisons to run in
	 * the agent's requested currency.
	 *
	 * Mechanism: hooks `wcpay_multi_currency_override_selected_currency`
	 * (the WCPay-sanctioned filter for "switch presentment without
	 * touching session state") to return `$code`, runs `$callback`,
	 * and unhooks in `finally`. No session writes; safe in stateless
	 * UCP request handlers.
	 *
	 * No-op (callable runs unwrapped, no filter hooked) when `$code`
	 * is not in `get_accepted_currencies()`. This lets callers pass
	 * the agent's raw `context.currency` blindly; the helper itself
	 * decides whether the override should apply.
	 *
	 * @since 0.18.0
	 *
	 * @param string   $code     Requested ISO-4217 currency code.
	 * @param callable $callback Callable to run inside the override scope.
	 * @return mixed             Whatever $callback returned.
	 *
	 * @throws \Throwable Re-throws anything $callback throws; the filter
	 *                    is unhooked in `finally` before the exception
	 *                    propagates.
	 */
	public static function with_active_currency( $code, callable $callback ) {
		// Validate code shape + membership in accepted set. Either
		// failure → run callable without an override hooked.
		$normalized = is_string( $code ) ? strtoupper( trim( $code ) ) : '';
		if ( ! preg_match( '/^[A-Z]{3}$/', $normalized ) ) {
			return $callback();
		}
		if ( ! in_array( $normalized, self::get_accepted_currencies(), true ) ) {
			return $callback();
		}

		$override = static function () use ( $normalized ) {
			return $normalized;
		};

		add_filter( 'wcpay_multi_currency_override_selected_currency', $override, 10, 1 );
		try {
			return $callback();
		} finally {
			remove_filter( 'wcpay_multi_currency_override_selected_currency', $override, 10 );
		}
	}

	/**
	 * Normalize an array of currency codes: uppercase, drop entries
	 * that fail the ISO-4217 pattern, deduplicate preserving order.
	 *
	 * @param array<int, mixed> $codes Raw codes.
	 * @return array<int, string>
	 */
	private static function normalize_codes( array $codes ) {
		$seen = array();
		$out  = array();
		foreach ( $codes as $code ) {
			if ( ! is_string( $code ) ) {
				continue;
			}
			$normalized = strtoupper( trim( (string) $code ) );
			if ( ! preg_match( '/^[A-Z]{3}$/', $normalized ) ) {
				continue;
			}
			if ( isset( $seen[ $normalized ] ) ) {
				continue;
			}
			$seen[ $normalized ] = true;
			$out[]               = $normalized;
		}
		return $out;
	}
}
