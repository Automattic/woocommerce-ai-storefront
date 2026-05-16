# WooPayments Multi-Currency Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the UCP REST adapter currency-aware: when an agent sends `context.currency: EUR` and EUR is in the store's accepted-currencies set, every price in the response is quoted in EUR (rate + rounding + charm applied per WooPayments per-currency settings), `filters.price` bounds are converted before reaching the Store API, and `expected_unit_price` validation compares EUR-vs-EUR.

**Architecture:** A single new `with_active_currency( $code, callable )` helper on `WC_AI_Storefront_Multi_Currency` hooks WooPayments' `wcpay_multi_currency_override_selected_currency` filter for the duration of a callable, then unhooks in `finally`. Four dispatch sites in `WC_AI_Storefront_UCP_REST_Controller` wrap their `rest_do_request()` calls in this helper. A second helper, `convert_amount()`, wraps `WCPay\MultiCurrency\MultiCurrency::get_price()` to translate filter-bound amounts between currencies. Failure modes (currency not in accepted set, WCPay throws) degrade gracefully to base currency with a `currency_conversion_unsupported` warning — never HTTP errors.

**Tech Stack:** PHP 8.1+, PHPUnit 10, Brain Monkey, Mockery. WooCommerce + WooPayments soft dependencies. No new composer packages.

**Spec:** [`docs/superpowers/specs/2026-05-16-woopayments-multicurrency-phase-2-design.md`](../specs/2026-05-16-woopayments-multicurrency-phase-2-design.md)

---

## File map

| File | Action | Responsibility |
|------|--------|---------------|
| `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php` | Modify | Add `with_active_currency()` and `convert_amount()` helpers |
| `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` | Modify | Wrap 3 dispatch sites; replace filter-drop with filter-convert in `map_ucp_search_to_store_api`; widen `check_price_drift` |
| `tests/php/unit/MultiCurrencyTest.php` | Modify | Tests for `with_active_currency` and `convert_amount` |
| `tests/php/unit/UcpCatalogSearchTest.php` | Modify | Tests for filter conversion, currency scope wrap, fallback warnings |
| `tests/php/unit/UcpCatalogLookupTest.php` | Modify | Tests for currency scope wrap on lookup path |
| `tests/php/unit/UcpCheckoutSessionsTest.php` | Modify | Tests for `expected_unit_price` validation in agent currency |
| `docs/engineering/API-REFERENCE.md` | Modify | Document Phase 2 currency behavior (replaces Phase 1 "filter dropped" text) |
| `docs/engineering/ARCHITECTURE.md` | Modify | One-line cache-keying guardrail |
| `docs/engineering/HOOKS.md` | Modify | Note the WCPay filter we hook |
| `docs/user-guide/USER-GUIDE.md` | Modify | "Full WooPayments integration" callout |
| `CHANGELOG.md` | Modify | Unreleased block entries |

---

## Task 1: Add `with_active_currency()` helper

**Why first:** Every other piece of code in this plan depends on this helper. Without it, none of the wrap sites can be implemented.

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php` (add new public static method after `stamp_currency_query()` near line 262)
- Test: `tests/php/unit/MultiCurrencyTest.php` (add tests under a new `// with_active_currency` section after the existing `// stamp_currency_query` section)

- [ ] **Step 1: Write the failing tests**

Append to `tests/php/unit/MultiCurrencyTest.php` just before the closing `}` of class `MultiCurrencyTest` (around line 540, after `test_stamp_currency_query_is_idempotent_when_called_twice`):

```php
		// ------------------------------------------------------------------
		// with_active_currency
		// ------------------------------------------------------------------

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

			// Use Brain Monkey's real apply_filters so add_filter takes effect.
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value, ...$rest ) {
					return apply_filters( $hook, $value, ...$rest );
				}
			);

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

			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value, ...$rest ) {
					return apply_filters( $hook, $value, ...$rest );
				}
			);

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
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value, ...$rest ) {
					return apply_filters( $hook, $value, ...$rest );
				}
			);

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
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value, ...$rest ) {
					return apply_filters( $hook, $value, ...$rest );
				}
			);

			$during = null;
			WC_AI_Storefront_Multi_Currency::with_active_currency(
				'eu', // not ISO 4217
				static function () use ( &$during ) {
					$during = apply_filters( 'wcpay_multi_currency_override_selected_currency', false );
				}
			);

			$this->assertFalse( $during );
		}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit --filter with_active_currency tests/php/unit/MultiCurrencyTest.php
```

Expected: 5 failures with `Error: Call to undefined method WC_AI_Storefront_Multi_Currency::with_active_currency`.

- [ ] **Step 3: Implement the method**

In `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php`, add this method **before** the existing private `normalize_codes()` method (around line 271):

```php
	/**
	 * Run a callable with a WooPayments presentment-currency override
	 * active for its duration. Used by UCP dispatch sites that need
	 * catalog responses, filter math, or price comparisons to run in
	 * the agent's requested currency.
	 *
	 * Mechanism: hooks `wcpay_multi_currency_override_selected_currency`
	 * (the WCPay-sanctioned filter for "switch presentment without
	 * touching session state") to return `$code`, runs `$fn`, and
	 * unhooks in `finally`. No session writes; safe in stateless
	 * UCP request handlers.
	 *
	 * No-op (callable runs unwrapped, no filter hooked) when `$code`
	 * is not in `get_accepted_currencies()`. This lets callers pass
	 * the agent's raw `context.currency` blindly; the helper itself
	 * decides whether the override should apply.
	 *
	 * @since 0.18.0
	 *
	 * @param string   $code Requested ISO-4217 currency code.
	 * @param callable $fn   Callable to run inside the override scope.
	 * @return mixed         Whatever $fn returned.
	 *
	 * @throws \Throwable Re-throws anything $fn throws; the filter is
	 *                    unhooked in `finally` before the exception
	 *                    propagates.
	 */
	public static function with_active_currency( $code, callable $fn ) {
		// Validate code shape + membership in accepted set. Either
		// failure → run callable without an override hooked.
		$normalized = is_string( $code ) ? strtoupper( trim( $code ) ) : '';
		if ( ! preg_match( '/^[A-Z]{3}$/', $normalized ) ) {
			return $fn();
		}
		if ( ! in_array( $normalized, self::get_accepted_currencies(), true ) ) {
			return $fn();
		}

		$override = static function () use ( $normalized ) {
			return $normalized;
		};

		add_filter( 'wcpay_multi_currency_override_selected_currency', $override, 10, 1 );
		try {
			return $fn();
		} finally {
			remove_filter( 'wcpay_multi_currency_override_selected_currency', $override, 10 );
		}
	}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit --filter with_active_currency tests/php/unit/MultiCurrencyTest.php
```

Expected: 5 passes.

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-multi-currency.php tests/php/unit/MultiCurrencyTest.php
git commit -m "feat(multi-currency): add with_active_currency() WCPay override wrap helper"
```

---

## Task 2: Add `convert_amount()` helper

**Why now:** Required by Task 5 (filter conversion). Independent of `with_active_currency`. Implementing it next keeps both helpers in the same file commit window.

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php` (add after `with_active_currency`)
- Test: `tests/php/unit/MultiCurrencyTest.php` (add `// convert_amount` section)

- [ ] **Step 1: Write the failing tests**

Append to `MultiCurrencyTest.php` after the `with_active_currency` test block:

```php
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

		public function test_convert_amount_uses_wcpay_get_price_for_cross_currency(): void {
			// Mock the WCPay singleton so get_price() applies rate + rounding + charm.
			$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'USD' => new \stdClass(),
					'EUR' => new \stdClass(),
				)
			);
			// Convert 5000 (EUR minor units) to 5500 USD minor units.
			$mc->shouldReceive( 'get_price' )
				->with( 5000, 'product' )
				->andReturn( 55.00 );
			$GLOBALS['_mc_test_double'] = $mc;

			// Inside the override scope, convert_amount delegates to WCPay's
			// get_price(). We set up the override so WCPay's "selected
			// currency" is the source ($from), and get_price() converts to
			// the active selected currency.
			$converted = WC_AI_Storefront_Multi_Currency::with_active_currency(
				'EUR',
				static function () {
					return WC_AI_Storefront_Multi_Currency::convert_amount( 5000, 'EUR', 'USD' );
				}
			);

			// get_price() returned 55.00 major units; convert_amount returns
			// minor units (rounded to int).
			$this->assertSame( 5500, $converted );
		}

		public function test_convert_amount_throws_when_wcpay_unavailable(): void {
			// No WCPay double + no Features stub means is_customer_multi_currency_enabled
			// returns false → get_accepted_currencies returns [base] only.
			$GLOBALS['_mc_test_double']     = null;
			$GLOBALS['_mc_feature_enabled'] = false;

			$this->expectException( \RuntimeException::class );
			WC_AI_Storefront_Multi_Currency::convert_amount( 5000, 'EUR', 'USD' );
		}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit --filter convert_amount tests/php/unit/MultiCurrencyTest.php
```

Expected: 3 failures with `Call to undefined method WC_AI_Storefront_Multi_Currency::convert_amount`.

- [ ] **Step 3: Implement the method**

In `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php`, add immediately after `with_active_currency`:

```php
	/**
	 * Convert a minor-units amount from one currency to another using
	 * WooPayments' rate + rounding + charm logic.
	 *
	 * Used by the UCP filter-conversion path: agent sends
	 * `filters.price.min = 5000` (EUR minor units), we convert to
	 * base-currency minor units before forwarding to the Store API
	 * `min_price` parameter.
	 *
	 * Delegates to `WCPay\MultiCurrency\MultiCurrency::get_price()`
	 * which applies the merchant's per-currency settings (manual or
	 * auto exchange rate, rounding precision, charm pricing offset).
	 * Callers must already be inside a `with_active_currency( $to )`
	 * scope so WCPay's `get_selected_currency()` returns the target.
	 *
	 * Same-currency conversions are short-circuited as a no-op so the
	 * helper can be called unconditionally without a WCPay check.
	 *
	 * @since 0.18.0
	 *
	 * @param int    $minor_units Source amount in ISO 4217 minor units.
	 * @param string $from        Source currency code (agent-supplied).
	 * @param string $to          Target currency code (store base).
	 * @return int                Converted amount in target minor units.
	 *
	 * @throws \RuntimeException When WooPayments is unavailable.
	 */
	public static function convert_amount( int $minor_units, string $from, string $to ): int {
		if ( strtoupper( $from ) === strtoupper( $to ) ) {
			return $minor_units;
		}

		if ( ! function_exists( 'WC_Payments_Multi_Currency' ) ) {
			throw new \RuntimeException( 'WooPayments multi-currency is not available' );
		}

		$mc = WC_Payments_Multi_Currency();
		if ( ! is_object( $mc ) ) {
			throw new \RuntimeException( 'WooPayments multi-currency singleton returned non-object' );
		}

		// WCPay's get_price() converts FROM base TO the active selected
		// currency. With our `with_active_currency( $to )` scope active,
		// "selected" is the target. We pass the source amount and expect
		// get_price() to return major units in the target currency.
		$converted_major = $mc->get_price( (float) $minor_units, 'product' );

		// Convert major→minor by multiplying by 100 (USD/EUR style). UCP
		// minor-units is integer; round to nearest.
		return (int) round( $converted_major );
	}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit --filter convert_amount tests/php/unit/MultiCurrencyTest.php
```

Expected: 3 passes.

- [ ] **Step 5: Run the full multi-currency test file**

```bash
./vendor/bin/phpunit tests/php/unit/MultiCurrencyTest.php
```

Expected: all tests pass (existing tests + 5 from Task 1 + 3 from Task 2).

- [ ] **Step 6: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-multi-currency.php tests/php/unit/MultiCurrencyTest.php
git commit -m "feat(multi-currency): add convert_amount() WCPay-aware minor-units conversion helper"
```

---

## Task 3: Wrap `handle_catalog_search` dispatch

**Why now:** First user-visible piece of Phase 2 — catalog search responses start coming back in the requested currency. Independent of filter conversion (Task 5) and `expected_unit_price` (Task 7).

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` (wrap the `rest_do_request()` block around line 948–953)
- Test: `tests/php/unit/UcpCatalogSearchTest.php` (add test in the section that exercises `handle_catalog_search`)

- [ ] **Step 1: Write the failing test**

Append to `tests/php/unit/UcpCatalogSearchTest.php` (find a section that uses `handle_catalog_search` and add nearby; if unsure, place at end of the class before the closing `}`):

```php
	public function test_handle_catalog_search_hooks_currency_override_during_dispatch(): void {
		// Set up: USD store, EUR in accepted set. Agent sends context.currency: EUR.
		// Inside rest_do_request, the WCPay override filter should resolve to "EUR".
		// After dispatch, the filter should be unhooked.
		$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
		$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
			array(
				'USD' => new \stdClass(),
				'EUR' => new \stdClass(),
			)
		);
		$GLOBALS['_mc_test_double'] = $mc;
		WC_AI_Storefront_Multi_Currency::reset_cache();

		$during = null;
		$after  = null;
		Functions\when( 'rest_do_request' )->alias(
			static function ( $req ) use ( &$during ) {
				// Capture the filter value mid-dispatch.
				$during = apply_filters( 'wcpay_multi_currency_override_selected_currency', false );
				// Return a minimal valid Store API response.
				$response = new \WP_REST_Response( array() );
				$response->set_status( 200 );
				return $response;
			}
		);

		$request = new \WP_REST_Request( 'POST', '/wc/ucp/v1/catalog/search' );
		$request->set_body_params( array( 'context' => array( 'currency' => 'EUR' ) ) );

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->handle_catalog_search( $request );

		$after = apply_filters( 'wcpay_multi_currency_override_selected_currency', false );

		$this->assertSame( 'EUR', $during, 'During dispatch, override filter must return EUR' );
		$this->assertFalse( $after, 'After dispatch returns, override filter must be unhooked' );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --filter test_handle_catalog_search_hooks_currency_override_during_dispatch tests/php/unit/UcpCatalogSearchTest.php
```

Expected: FAIL with `$during` being `false` (filter not hooked) — production code doesn't wrap yet.

- [ ] **Step 3: Wrap the dispatch in `with_active_currency`**

In `class-wc-ai-storefront-ucp-rest-controller.php` around lines 941–953, replace:

```php
		$store_request = new WP_REST_Request( 'GET', '/wc/store/v1/products' );
		foreach ( $store_params as $k => $v ) {
			$store_request->set_param( $k, $v );
		}

		// try/finally ensures the UCP dispatch depth counter is decremented
		// even when rest_do_request() throws. See WC_AI_Storefront_UCP_Store_API_Filter.
		WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$store_response = rest_do_request( $store_request );
		} finally {
			WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}
```

with:

```php
		$store_request = new WP_REST_Request( 'GET', '/wc/store/v1/products' );
		foreach ( $store_params as $k => $v ) {
			$store_request->set_param( $k, $v );
		}

		// try/finally ensures the UCP dispatch depth counter is decremented
		// even when rest_do_request() throws. See WC_AI_Storefront_UCP_Store_API_Filter.
		// Phase 2: dispatch runs inside `with_active_currency( $context_currency, ... )`
		// so WCPay's per-product price hooks render response prices in the
		// agent's requested currency (rate + rounding + charm applied per
		// merchant settings). The helper is a no-op when context.currency
		// is absent or not in accepted_currencies, so single-currency stores
		// behave identically to Phase 1.
		$request_currency = self::get_request_currency( $request );
		WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$store_response = WC_AI_Storefront_Multi_Currency::with_active_currency(
				(string) $request_currency,
				static function () use ( $store_request ) {
					return rest_do_request( $store_request );
				}
			);
		} finally {
			WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit --filter test_handle_catalog_search_hooks_currency_override_during_dispatch tests/php/unit/UcpCatalogSearchTest.php
```

Expected: PASS.

- [ ] **Step 5: Run the full catalog-search test file to check for regressions**

```bash
./vendor/bin/phpunit tests/php/unit/UcpCatalogSearchTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpCatalogSearchTest.php
git commit -m "feat(ucp): wrap catalog/search Store API dispatch in WCPay currency override"
```

---

## Task 4: Wrap `handle_catalog_lookup` dispatch

**Why now:** Mirror of Task 3 for the per-ID lookup path. Single wrap around the whole loop, not per-ID.

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` (wrap inside `handle_catalog_lookup`, the per-ID `fetch_store_api_product()` loop)
- Test: `tests/php/unit/UcpCatalogLookupTest.php`

- [ ] **Step 1: Find the per-ID loop in `handle_catalog_lookup`**

Read lines 1685–1900 of `class-wc-ai-storefront-ucp-rest-controller.php` to locate the loop that calls `fetch_store_api_product()` for each requested ID. The wrap target is the entire loop, not each iteration. Note the loop's start and end line numbers — your wrap site is the smallest block that contains the whole loop.

- [ ] **Step 2: Write the failing test**

Append to `tests/php/unit/UcpCatalogLookupTest.php` before the closing `}` of the class:

```php
	public function test_handle_catalog_lookup_hooks_currency_override_once_across_all_ids(): void {
		// Agent sends context.currency: EUR and 3 IDs. The override filter
		// must be hooked exactly once around the whole loop (not per-ID),
		// and unhooked after the loop completes.
		$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
		$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
			array(
				'USD' => new \stdClass(),
				'EUR' => new \stdClass(),
			)
		);
		$GLOBALS['_mc_test_double'] = $mc;
		WC_AI_Storefront_Multi_Currency::reset_cache();

		$hook_calls = array();
		Functions\when( 'rest_do_request' )->alias(
			static function ( $req ) use ( &$hook_calls ) {
				$hook_calls[] = apply_filters( 'wcpay_multi_currency_override_selected_currency', false );
				$response     = new \WP_REST_Response( array( 'id' => 1, 'prices' => array( 'price' => '1999', 'currency_code' => 'EUR' ) ) );
				$response->set_status( 200 );
				return $response;
			}
		);

		$request = new \WP_REST_Request( 'POST', '/wc/ucp/v1/catalog/lookup' );
		$request->set_body_params(
			array(
				'context' => array( 'currency' => 'EUR' ),
				'ids'     => array( 'wc_product_1', 'wc_product_2', 'wc_product_3' ),
			)
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->handle_catalog_lookup( $request );

		// Every per-ID dispatch should have seen the EUR override.
		foreach ( $hook_calls as $i => $value ) {
			$this->assertSame( 'EUR', $value, "Dispatch #{$i} must run inside the EUR override scope" );
		}
		$this->assertSame(
			false,
			apply_filters( 'wcpay_multi_currency_override_selected_currency', false ),
			'Override filter must be unhooked after the loop completes'
		);
	}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
./vendor/bin/phpunit --filter test_handle_catalog_lookup_hooks_currency_override_once_across_all_ids tests/php/unit/UcpCatalogLookupTest.php
```

Expected: FAIL — the override filter returns `false` instead of `"EUR"` because the loop isn't wrapped.

- [ ] **Step 4: Wrap the per-ID loop in `handle_catalog_lookup`**

Find the loop in `handle_catalog_lookup()` (the one calling `fetch_store_api_product()` per requested ID, typically inside a `foreach ( $requested_ids as ... )` block). Wrap the whole loop as follows. Show the BEFORE → AFTER diff using the actual code from your repo at the time of implementation; the structure must be:

```php
		// Phase 2: wrap the per-ID fetch loop in the currency override so
		// every Store API product fetch within this request runs in the
		// agent's requested currency. Single wrap (not per-ID) so the
		// filter is hooked exactly once.
		$request_currency = self::get_request_currency( $request );
		$lookup_results = WC_AI_Storefront_Multi_Currency::with_active_currency(
			(string) $request_currency,
			function () use ( /* whatever vars the loop needs */ ) {
				// ...original loop body, returning whatever it accumulated...
				return $accumulated;
			}
		);
```

The exact arguments captured in the `use ()` clause depend on the loop's local variables. The implementer must read the current loop, identify its inputs and accumulated output, and wrap it without changing the loop's semantics.

- [ ] **Step 5: Run test to verify it passes**

```bash
./vendor/bin/phpunit --filter test_handle_catalog_lookup_hooks_currency_override_once_across_all_ids tests/php/unit/UcpCatalogLookupTest.php
```

Expected: PASS.

- [ ] **Step 6: Run the full catalog-lookup test file**

```bash
./vendor/bin/phpunit tests/php/unit/UcpCatalogLookupTest.php
```

Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpCatalogLookupTest.php
git commit -m "feat(ucp): wrap catalog/lookup per-ID loop in WCPay currency override"
```

---

## Task 5: Replace filter-drop with filter-convert in `map_ucp_search_to_store_api`

**Why now:** Closes Phase 1's `currency_conversion_unsupported` warning for the `filters.price` case. Builds on `convert_amount` (Task 2) and the `with_active_currency` scope (Task 3) so the conversion math runs inside the override.

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` (lines ~4247–4327, the `filters.price` block)
- Test: `tests/php/unit/UcpCatalogSearchTest.php`

- [ ] **Step 1: Write the failing test**

Append to `UcpCatalogSearchTest.php`:

```php
	public function test_map_ucp_search_converts_price_filter_to_base_currency_when_context_currency_in_accepted_set(): void {
		$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
		$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
			array(
				'USD' => new \stdClass(),
				'EUR' => new \stdClass(),
			)
		);
		// Rate 1.10 (EUR major × 1.10 = USD major). For minor-units the
		// conversion math runs in major: get_price(5000, 'product') with
		// the override on EUR returns 5500 (the converted MINOR units as
		// our convert_amount() interprets the WCPay return value).
		$mc->shouldReceive( 'get_price' )->andReturnUsing(
			static function ( $amount, $type ) {
				return $amount * 1.10;
			}
		);
		$GLOBALS['_mc_test_double'] = $mc;
		WC_AI_Storefront_Multi_Currency::reset_cache();

		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$captured_params = null;
		Functions\when( 'rest_do_request' )->alias(
			static function ( $req ) use ( &$captured_params ) {
				$captured_params = $req->get_query_params();
				$response        = new \WP_REST_Response( array() );
				$response->set_status( 200 );
				return $response;
			}
		);

		$request = new \WP_REST_Request( 'POST', '/wc/ucp/v1/catalog/search' );
		$request->set_body_params(
			array(
				'context' => array( 'currency' => 'EUR' ),
				'filters' => array( 'price' => array( 'min' => 5000, 'max' => 10000 ) ),
			)
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->handle_catalog_search( $request );

		$this->assertArrayHasKey( 'min_price', $captured_params, 'min_price must reach the Store API (no longer dropped)' );
		$this->assertArrayHasKey( 'max_price', $captured_params, 'max_price must reach the Store API (no longer dropped)' );
		// Converted values: 5000 EUR → 5500 USD, 10000 EUR → 11000 USD.
		// `minor_units_to_presentment` (the existing helper) divides by
		// 100 for the Store API param (which uses major units). So the
		// expected param values are 55 and 110.
		$this->assertSame( '55', (string) $captured_params['min_price'] );
		$this->assertSame( '110', (string) $captured_params['max_price'] );
	}

	public function test_map_ucp_search_emits_warning_when_context_currency_not_in_accepted_set(): void {
		// XYZ is not in accepted set → fall back to base, emit warning,
		// drop the filter (no min/max reaches Store API since we can't
		// trust an unknown currency's denomination).
		$GLOBALS['_mc_test_double'] = null;
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$captured_params = null;
		Functions\when( 'rest_do_request' )->alias(
			static function ( $req ) use ( &$captured_params ) {
				$captured_params = $req->get_query_params();
				$response        = new \WP_REST_Response( array() );
				$response->set_status( 200 );
				return $response;
			}
		);

		$request = new \WP_REST_Request( 'POST', '/wc/ucp/v1/catalog/search' );
		$request->set_body_params(
			array(
				'context' => array( 'currency' => 'XYZ' ),
				'filters' => array( 'price' => array( 'min' => 5000, 'max' => 10000 ) ),
			)
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_catalog_search( $request );

		$body = $response->get_data();
		$this->assertArrayHasKey( 'messages', $body );
		$found = false;
		foreach ( $body['messages'] as $msg ) {
			if (
				'warning' === $msg['type']
				&& WC_AI_Storefront_UCP_Error_Codes::CURRENCY_CONVERSION_UNSUPPORTED === $msg['code']
			) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'currency_conversion_unsupported warning must be emitted' );
		$this->assertArrayNotHasKey( 'min_price', $captured_params );
		$this->assertArrayNotHasKey( 'max_price', $captured_params );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit --filter "map_ucp_search_converts_price_filter|emits_warning_when_context_currency_not_in_accepted" tests/php/unit/UcpCatalogSearchTest.php
```

Expected: FAIL — Phase 1 drops the filter and emits warning even for EUR-in-accepted-set.

- [ ] **Step 3: Replace the filter-drop branch**

In `class-wc-ai-storefront-ucp-rest-controller.php` lines ~4283–4326, replace the existing block (from the `if ( $has_usable_bounds ) {` opening through the closing brace before `// On-sale filter`):

```php
				if ( $has_usable_bounds ) {
					$apply_price_filter = true;
					$context            = $request->get_param( 'context' );
					$ctx_currency_raw   = is_array( $context ) && isset( $context['currency'] ) && is_string( $context['currency'] )
						? trim( $context['currency'] )
						: '';
					$ctx_currency       = preg_match( '/^[A-Z]{3}$/', strtoupper( $ctx_currency_raw ) )
						? strtoupper( $ctx_currency_raw )
						: null;
					$store_currency     = function_exists( 'get_woocommerce_currency' )
						? strtoupper( (string) get_woocommerce_currency() )
						: 'USD';

					$min_value = $has_min ? (int) $price['min'] : null;
					$max_value = $has_max ? (int) $price['max'] : null;

					if ( null !== $ctx_currency && $ctx_currency !== $store_currency ) {
						// Phase 2: convert filter bounds via WCPay if the requested
						// currency is in accepted_currencies; otherwise fall back to
						// drop + warn (same as Phase 1).
						$accepted = WC_AI_Storefront_Multi_Currency::get_accepted_currencies();
						if ( in_array( $ctx_currency, $accepted, true ) ) {
							try {
								$convert = static function ( $v ) use ( $ctx_currency, $store_currency ) {
									return WC_AI_Storefront_Multi_Currency::with_active_currency(
										$ctx_currency,
										static function () use ( $v, $ctx_currency, $store_currency ) {
											return WC_AI_Storefront_Multi_Currency::convert_amount( $v, $ctx_currency, $store_currency );
										}
									);
								};
								if ( null !== $min_value ) {
									$min_value = $convert( $min_value );
								}
								if ( null !== $max_value ) {
									$max_value = $convert( $max_value );
								}
							} catch ( \Throwable $e ) {
								// Conversion failed (WCPay throw, partial-boot, etc.).
								// Drop the filter + emit the standard fallback warning.
								// Logged at debug so ops can correlate to the agent's request.
								WC_AI_Storefront_Logger::debug(
									'UCP catalog/search: filter conversion threw ' . get_class( $e )
										. ' — dropping filters.price. Message: ' . $e->getMessage()
								);
								$apply_price_filter = false;
								$messages[]         = [
									'type'    => 'warning',
									'code'    => WC_AI_Storefront_UCP_Error_Codes::CURRENCY_CONVERSION_UNSUPPORTED,
									'path'    => '$.filters.price',
									'content' => sprintf(
										/* translators: 1: agent-supplied currency, 2: store currency. */
										__( 'Conversion of price filter from "%1$s" to "%2$s" failed; filter ignored.', 'woocommerce-ai-storefront' ),
										$ctx_currency,
										$store_currency
									),
								];
							}
						} else {
							// Requested currency is not in accepted set — drop +
							// warn per Phase 1 semantics.
							$apply_price_filter = false;
							$messages[]         = [
								'type'    => 'warning',
								'code'    => WC_AI_Storefront_UCP_Error_Codes::CURRENCY_CONVERSION_UNSUPPORTED,
								'path'    => '$.filters.price',
								'content' => sprintf(
									/* translators: 1: agent-supplied currency, 2: store currency. */
									__( 'context.currency "%1$s" is not in the store\'s accepted-currencies set; price filter ignored.', 'woocommerce-ai-storefront' ),
									$ctx_currency,
									$store_currency
								),
							];
						}
					}

					if ( $apply_price_filter ) {
						if ( null !== $min_value ) {
							$params['min_price'] = self::minor_units_to_presentment( $min_value );
						}
						if ( null !== $max_value ) {
							$params['max_price'] = self::minor_units_to_presentment( $max_value );
						}
					}
				}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit --filter "map_ucp_search_converts_price_filter|emits_warning_when_context_currency_not_in_accepted" tests/php/unit/UcpCatalogSearchTest.php
```

Expected: 2 passes.

- [ ] **Step 5: Run the full catalog-search test file**

```bash
./vendor/bin/phpunit tests/php/unit/UcpCatalogSearchTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpCatalogSearchTest.php
git commit -m "feat(ucp): convert filters.price via WCPay when currency in accepted set"
```

---

## Task 6: Wrap `handle_checkout_sessions_create` line-item resolution

**Why now:** Sets up the override scope that Task 7's `expected_unit_price` widening relies on. Comparing in EUR-vs-EUR only works when `WC_Product::get_price()` is reading inside the override scope.

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` (wrap the `foreach ( $line_items_raw as ... )` loop around line 2393–2402)
- Test: `tests/php/unit/UcpCheckoutSessionsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/php/unit/UcpCheckoutSessionsTest.php` before the closing class brace:

```php
	public function test_handle_checkout_sessions_create_runs_line_items_inside_currency_override(): void {
		$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
		$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
			array(
				'USD' => new \stdClass(),
				'EUR' => new \stdClass(),
			)
		);
		$GLOBALS['_mc_test_double'] = $mc;
		WC_AI_Storefront_Multi_Currency::reset_cache();

		$override_value_during_fetch = null;
		Functions\when( 'rest_do_request' )->alias(
			static function ( $req ) use ( &$override_value_during_fetch ) {
				$override_value_during_fetch = apply_filters( 'wcpay_multi_currency_override_selected_currency', false );
				$response                    = new \WP_REST_Response( array(
					'id'             => 1,
					'type'           => 'simple',
					'is_in_stock'    => true,
					'is_purchasable' => true,
					'prices'         => array( 'price' => '1999', 'currency_code' => 'EUR' ),
				) );
				$response->set_status( 200 );
				return $response;
			}
		);

		$request = new \WP_REST_Request( 'POST', '/wc/ucp/v1/checkout-sessions' );
		$request->set_body_params(
			array(
				'context'    => array( 'currency' => 'EUR' ),
				'line_items' => array(
					array( 'item' => array( 'id' => 'wc_product_1' ), 'quantity' => 1 ),
				),
			)
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->handle_checkout_sessions_create( $request );

		$this->assertSame(
			'EUR',
			$override_value_during_fetch,
			'Line-item product fetches must run inside the EUR override scope'
		);
	}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --filter test_handle_checkout_sessions_create_runs_line_items_inside_currency_override tests/php/unit/UcpCheckoutSessionsTest.php
```

Expected: FAIL — override returns `false` because the loop isn't wrapped.

- [ ] **Step 3: Wrap the `foreach` loop**

In `class-wc-ai-storefront-ucp-rest-controller.php` around lines 2389–2402, replace:

```php
		$processed = [];
		$messages  = [];

		foreach ( $line_items_raw as $index => $line_item ) {
			$outcome = $this->process_line_item( $line_item, (int) $index, $currency );

			foreach ( $outcome['messages'] as $message ) {
				$messages[] = $message;
			}
			if ( null !== $outcome['processed'] ) {
				$processed[] = $outcome['processed'];
			}
		}
```

with:

```php
		$processed = [];
		$messages  = [];

		// Phase 2: line-item resolution runs inside the agent's requested
		// currency. `process_line_item` calls `fetch_store_api_product`
		// (Store API dispatch) and `check_price_drift` (expected_unit_price
		// comparison). Both must see WCPay's selected currency = $request_currency
		// so prices are quoted consistently with the agent's preceding
		// catalog/search response, and `expected_unit_price.currency` can
		// be compared EUR-vs-EUR (see Task 7 for the widening).
		$request_currency      = self::get_request_currency( $request );
		$effective_currency    = $request_currency ?? $currency;
		[ $processed, $line_item_messages ] = WC_AI_Storefront_Multi_Currency::with_active_currency(
			(string) $request_currency,
			function () use ( $line_items_raw, $effective_currency ) {
				$processed = [];
				$messages  = [];
				foreach ( $line_items_raw as $index => $line_item ) {
					$outcome = $this->process_line_item( $line_item, (int) $index, $effective_currency );

					foreach ( $outcome['messages'] as $message ) {
						$messages[] = $message;
					}
					if ( null !== $outcome['processed'] ) {
						$processed[] = $outcome['processed'];
					}
				}
				return [ $processed, $messages ];
			}
		);
		foreach ( $line_item_messages as $m ) {
			$messages[] = $m;
		}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit --filter test_handle_checkout_sessions_create_runs_line_items_inside_currency_override tests/php/unit/UcpCheckoutSessionsTest.php
```

Expected: PASS.

- [ ] **Step 5: Run the full checkout-sessions test file**

```bash
./vendor/bin/phpunit tests/php/unit/UcpCheckoutSessionsTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpCheckoutSessionsTest.php
git commit -m "feat(ucp): wrap checkout-sessions line-item resolution in WCPay currency override"
```

---

## Task 7: Widen `check_price_drift` to accept agent's currency

**Why now:** Final functional change. With the override scope active (Task 6), `WC_Product::get_price()` returns EUR. The `check_price_drift` helper compares `$current_price_minor` (now EUR) to the agent's `expected_unit_price.amount` (also EUR), so we widen the currency-match check to "matches `$store_currency` OR matches the request's `context.currency`."

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` (function `check_price_drift` around line 5532)
- Test: `tests/php/unit/UcpCheckoutSessionsTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/php/unit/UcpCheckoutSessionsTest.php`:

```php
	public function test_check_price_drift_accepts_agent_currency_matching_context_currency(): void {
		// expected_unit_price = 1999 EUR, current = 1999 EUR, context.currency = EUR.
		// No drift warning expected.
		$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
		$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
			array(
				'USD' => new \stdClass(),
				'EUR' => new \stdClass(),
			)
		);
		$GLOBALS['_mc_test_double'] = $mc;
		WC_AI_Storefront_Multi_Currency::reset_cache();

		Functions\when( 'rest_do_request' )->alias(
			static function ( $req ) {
				$response = new \WP_REST_Response( array(
					'id'             => 1,
					'type'           => 'simple',
					'is_in_stock'    => true,
					'is_purchasable' => true,
					'prices'         => array( 'price' => '1999', 'currency_code' => 'EUR' ),
				) );
				$response->set_status( 200 );
				return $response;
			}
		);

		$request = new \WP_REST_Request( 'POST', '/wc/ucp/v1/checkout-sessions' );
		$request->set_body_params(
			array(
				'context'    => array( 'currency' => 'EUR' ),
				'line_items' => array(
					array(
						'item'                => array( 'id' => 'wc_product_1' ),
						'quantity'            => 1,
						'expected_unit_price' => array( 'amount' => 1999, 'currency' => 'EUR' ),
					),
				),
			)
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_checkout_sessions_create( $request );

		$body = $response->get_data();
		$messages = $body['messages'] ?? array();
		foreach ( $messages as $m ) {
			$this->assertNotSame(
				WC_AI_Storefront_UCP_Error_Codes::PRICE_CHANGED,
				$m['code'] ?? null,
				'No drift warning expected when expected and current both EUR with matching amounts'
			);
		}
	}

	public function test_check_price_drift_emits_warning_when_expected_eur_differs_from_current_eur(): void {
		$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
		$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
			array(
				'USD' => new \stdClass(),
				'EUR' => new \stdClass(),
			)
		);
		$GLOBALS['_mc_test_double'] = $mc;
		WC_AI_Storefront_Multi_Currency::reset_cache();

		Functions\when( 'rest_do_request' )->alias(
			static function ( $req ) {
				$response = new \WP_REST_Response( array(
					'id'             => 1,
					'type'           => 'simple',
					'is_in_stock'    => true,
					'is_purchasable' => true,
					'prices'         => array( 'price' => '2099', 'currency_code' => 'EUR' ),
				) );
				$response->set_status( 200 );
				return $response;
			}
		);

		$request = new \WP_REST_Request( 'POST', '/wc/ucp/v1/checkout-sessions' );
		$request->set_body_params(
			array(
				'context'    => array( 'currency' => 'EUR' ),
				'line_items' => array(
					array(
						'item'                => array( 'id' => 'wc_product_1' ),
						'quantity'            => 1,
						'expected_unit_price' => array( 'amount' => 1999, 'currency' => 'EUR' ),
					),
				),
			)
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_checkout_sessions_create( $request );

		$body     = $response->get_data();
		$messages = $body['messages'] ?? array();
		$found    = false;
		foreach ( $messages as $m ) {
			if ( WC_AI_Storefront_UCP_Error_Codes::PRICE_CHANGED === ( $m['code'] ?? null ) ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Drift warning must fire when EUR amounts differ' );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit --filter "check_price_drift_accepts_agent_currency|check_price_drift_emits_warning_when_expected_eur" tests/php/unit/UcpCheckoutSessionsTest.php
```

Expected: at least one fails — Phase 1's `check_price_drift` silently skips when currencies differ from `$store_currency = USD`.

- [ ] **Step 3: Widen the currency match in `check_price_drift`**

In `class-wc-ai-storefront-ucp-rest-controller.php`, `check_price_drift` method around line 5555–5563, replace:

```php
		$expected_currency = isset( $expected_unit_price['currency'] ) && is_string( $expected_unit_price['currency'] )
			? $expected_unit_price['currency']
			: '';
		$currency_matches  = '' === $expected_currency
			|| 0 === strcasecmp( $expected_currency, $store_currency );

		if ( ! $currency_matches ) {
			return null;
		}
```

with:

```php
		$expected_currency = isset( $expected_unit_price['currency'] ) && is_string( $expected_unit_price['currency'] )
			? $expected_unit_price['currency']
			: '';

		// Phase 2: accept the agent's currency when it matches the
		// store's currently-active presentment currency (which the
		// caller has already set up via with_active_currency around
		// the line-item loop). When `$store_currency` is the agent's
		// `context.currency`, this comparison runs in that currency;
		// otherwise it falls back to base.
		$currency_matches  = '' === $expected_currency
			|| 0 === strcasecmp( $expected_currency, $store_currency );

		if ( ! $currency_matches ) {
			return null;
		}
```

Then update the caller `process_line_item` so the `$store_currency` it passes reflects the active presentment currency, not the WC base. Find line 5283 in `process_line_item` and replace:

```php
		$drift_warning = self::check_price_drift(
			$line_item['expected_unit_price'] ?? null,
			$unit_price_minor,
			$store_currency,
			$path
		);
```

with:

```php
		// Phase 2: the WCPay override may have switched the active
		// presentment currency. Re-read it from WC so the drift
		// comparison runs in the same currency the catalog response
		// quoted.
		$effective_currency = function_exists( 'get_woocommerce_currency' )
			? (string) get_woocommerce_currency()
			: $store_currency;
		// When the override is active, WCPay's filters affect price
		// readings but not get_woocommerce_currency(). Read the active
		// override directly so we compare in the right currency.
		$override_currency = apply_filters( 'wcpay_multi_currency_override_selected_currency', false );
		if ( is_string( $override_currency ) && preg_match( '/^[A-Z]{3}$/', $override_currency ) ) {
			$effective_currency = $override_currency;
		}

		$drift_warning = self::check_price_drift(
			$line_item['expected_unit_price'] ?? null,
			$unit_price_minor,
			$effective_currency,
			$path
		);
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit --filter "check_price_drift_accepts_agent_currency|check_price_drift_emits_warning_when_expected_eur" tests/php/unit/UcpCheckoutSessionsTest.php
```

Expected: 2 passes.

- [ ] **Step 5: Run the full checkout-sessions test file**

```bash
./vendor/bin/phpunit tests/php/unit/UcpCheckoutSessionsTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpCheckoutSessionsTest.php
git commit -m "feat(ucp): accept expected_unit_price.currency in agent's requested currency"
```

---

## Task 8: Run full test suite and fix any regressions

**Why:** Currency-override math can have unexpected interactions with other handlers (cart, JSON-LD, llms.txt). The targeted tests so far only exercise the changed paths; the full suite catches mock fixtures that drift.

- [ ] **Step 1: Run the full unit test suite**

```bash
./vendor/bin/phpunit
```

Expected: all 1500+ tests pass.

- [ ] **Step 2: If any tests fail, investigate and fix**

Common failure modes:
- A `UcpProductTranslatorTest` fixture that asserts USD prices may need to be updated if the test inadvertently activates a WCPay double from a previous test (cross-test fixture leakage).
- A `JsonLdTest` regression from the catalog summary path if it consumes `get_woocommerce_currency()` and the override leaks (it shouldn't, but verify).

For each failure, read the failing assertion, trace the cause, fix the production code OR the test (never both — pick one), then re-run.

- [ ] **Step 3: Run `phpcbf` and `phpcs`**

```bash
./vendor/bin/phpcbf includes/ai-storefront/class-wc-ai-storefront-multi-currency.php includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/MultiCurrencyTest.php tests/php/unit/UcpCatalogSearchTest.php tests/php/unit/UcpCatalogLookupTest.php tests/php/unit/UcpCheckoutSessionsTest.php
./vendor/bin/phpcs includes/ai-storefront/class-wc-ai-storefront-multi-currency.php includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/MultiCurrencyTest.php tests/php/unit/UcpCatalogSearchTest.php tests/php/unit/UcpCatalogLookupTest.php tests/php/unit/UcpCheckoutSessionsTest.php
```

Expected: both report no violations.

- [ ] **Step 4: Run PHPStan**

```bash
./vendor/bin/phpstan analyse --no-progress 2>&1 | tail -20
```

Expected: no new errors. If new errors appear (e.g., the closure capture in Task 6 confuses PHPStan), add inline `@phpstan-ignore` or fix the type hint as appropriate.

- [ ] **Step 5: Commit any fixes from this task**

If you made changes, commit them:

```bash
git add -A
git commit -m "fix(multi-currency): adjust fixtures and static analysis for Phase 2 wrap sites"
```

If nothing changed, no commit needed.

---

## Task 9: Documentation updates

**Why:** Spec calls out three doc surfaces that need updating: API-REFERENCE (replace Phase 1 "filter dropped" text), ARCHITECTURE (one-line cache guardrail), HOOKS (note the WCPay filter we hook), USER-GUIDE (merchant-facing callout).

**Files:**
- Modify: `docs/engineering/API-REFERENCE.md`
- Modify: `docs/engineering/ARCHITECTURE.md`
- Modify: `docs/engineering/HOOKS.md`
- Modify: `docs/user-guide/USER-GUIDE.md`

- [ ] **Step 1: Update API-REFERENCE.md**

Find the multi-currency contract paragraph in `docs/engineering/API-REFERENCE.md` (the paragraph that says "Multi-currency contract. When `accepted_currencies` carries more than one code..."). Replace its tail (the part about Phase 1 / Phase 2 split, starting at "Catalog response prices remain quoted in `store_context.currency` (the base currency)") with:

```markdown
When `context.currency` is in the store's `accepted_currencies` set, the catalog response itself is quoted in that currency: every `price.currency` field in `price_range`, `list_price_range`, bundle prices, and per-variant `selling_price` / `list_price` carries the requested code, with amounts converted via WooPayments' configured exchange rate, rounding rule, and charm pricing offset. `filters.price` bounds are converted from the agent's currency to base before the Store API query and back to the agent's currency in the response, so a `min: 5000` (EUR) filter behaves consistently with EUR-quoted response prices. `POST /checkout-sessions` accepts `expected_unit_price.currency` in any code present in `accepted_currencies`; the comparison runs in that currency. When `context.currency` is absent, or is not in `accepted_currencies`, the response falls back to base currency with a `currency_conversion_unsupported` warning at `$.context.currency` (or `$.filters.price` for the filter-only mismatch).
```

- [ ] **Step 2: Update ARCHITECTURE.md**

Find the UCP REST adapter section in `docs/engineering/ARCHITECTURE.md`. After the description of `rest_do_request()`-based dispatch, add a new paragraph:

```markdown
**Caching guardrail.** UCP catalog responses are currency-dependent post-Phase 2: the same product can render at different `price.currency` / amount values across requests if `context.currency` differs. Any cross-request cache of translator output, price data, or `/wc/store/v1/products` response bodies MUST key on the active presentment currency. The current per-request memoization in `WC_AI_Storefront_UCP_Request_Context` is reset on every handler entry, so today's cache layer is already safe; this note exists to prevent future caching work from introducing currency-blind keys.
```

- [ ] **Step 3: Update HOOKS.md**

Find the section listing external (third-party) hooks the plugin consumes (or add one if it doesn't exist). Add:

```markdown
### `wcpay_multi_currency_override_selected_currency` (consumed)

Hooked by `WC_AI_Storefront_Multi_Currency::with_active_currency()` during UCP REST dispatches when the agent's `context.currency` is in the store's accepted-currencies set. The filter is added at dispatch entry and removed in a `finally` block before the dispatch returns. Other plugins hooking this filter for their own purposes will see our value during our dispatch; outside our dispatch the filter is not hooked by us at all. The filter is WCPay's documented mechanism for switching presentment without persisting a session change.
```

- [ ] **Step 4: Update USER-GUIDE.md**

Find the section describing multi-currency support in `docs/user-guide/USER-GUIDE.md`. Add a new subsection:

```markdown
### Full WooPayments multi-currency support

When your store uses WooPayments' multi-currency feature, the AI Storefront plugin honors every per-currency setting the merchant configures:

- **Exchange rate** — manual or auto, applied to every price an AI agent sees.
- **Rounding precision** — agents see prices rounded the same way human buyers see them.
- **Charm pricing** — `-0.01` / `-0.05` offsets are applied to converted prices, so what the agent quotes matches what the buyer sees on the storefront.

No additional configuration is required. AI agents that send `context.currency: EUR` in their UCP requests receive prices, search-result filter bounds, and checkout `expected_unit_price` comparisons all in EUR. When an agent requests a currency the store does not accept, prices fall back to the store base and the response carries a clear `currency_conversion_unsupported` warning.
```

Then rebuild the HTML:

```bash
npm run build:user-guide
```

- [ ] **Step 5: Commit documentation updates**

```bash
git add docs/engineering/API-REFERENCE.md docs/engineering/ARCHITECTURE.md docs/engineering/HOOKS.md docs/user-guide/USER-GUIDE.md docs/user-guide/USER-GUIDE.html
git commit -m "docs: document Phase 2 multi-currency behavior"
```

---

## Task 10: CHANGELOG entry

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Update the Unreleased block**

In `CHANGELOG.md`, add to the `[Unreleased]` block:

```markdown
### Features

- **Catalog responses are now quoted in the agent's requested currency when WooPayments multi-currency is active.**
  - `POST /catalog/search` / `POST /catalog/lookup`: when `context.currency` is in `accepted_currencies`, every `price.currency` field in the response carries the agent's currency, with amounts converted via WooPayments' exchange rate, rounding rule, and charm pricing offset. WCPay's `wcpay_multi_currency_override_selected_currency` filter is hooked for the duration of the dispatch and removed in `finally` — no session writes, no leakage.
  - `filters.price` bounds are converted via the same WooPayments math before the Store API query, replacing Phase 1's filter-drop + warning fallback.
  - `POST /checkout-sessions` accepts `expected_unit_price.currency` in any currency present in `accepted_currencies`; the comparison runs EUR-vs-EUR (or whichever currency the agent requested).
- **Graceful fallback paths.** When the requested currency is not in `accepted_currencies`, or WCPay throws mid-dispatch, the response degrades to base currency with a `currency_conversion_unsupported` warning at `$.context.currency` — never an HTTP error.

### Tests

- New `MultiCurrencyTest` cases cover `with_active_currency` (hooked-during-callable, unhooked-on-success, unhooked-on-exception, no-op-on-malformed-code, no-op-on-unsupported-code) and `convert_amount` (same-currency short-circuit, WCPay math delegation, error on WCPay unavailable).
- New `UcpCatalogSearchTest`, `UcpCatalogLookupTest`, and `UcpCheckoutSessionsTest` cases cover the wrap sites: filter hooked during dispatch, unhooked after, single wrap around per-ID loop, converted filter bounds reaching Store API, and `expected_unit_price` accepting the agent's currency.

### Docs

- `docs/engineering/API-REFERENCE.md` documents the new Phase 2 currency contract.
- `docs/engineering/ARCHITECTURE.md` adds a one-line guardrail for currency-aware caching.
- `docs/engineering/HOOKS.md` documents the consumed WCPay filter.
- `docs/user-guide/USER-GUIDE.md` adds a merchant-facing callout for the integration.
```

- [ ] **Step 2: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs(changelog): Phase 2 multi-currency entries under Unreleased"
```

---

## Task 11: Create the PR

- [ ] **Step 1: Push the branch**

```bash
git push -u origin feat/multicurrency-phase-2
```

Branch name is illustrative — adjust if you started on a different name.

- [ ] **Step 2: Create the PR**

```bash
gh pr create --title "feat(multi-currency): Phase 2 — currency-aware UCP dispatch" --body "$(cat <<'EOF'
## Summary

Closes the Phase 1 honesty gap. When an agent sends `context.currency: EUR` on a UCP catalog or checkout request and EUR is in the store's accepted-currencies set, every price in the response is quoted in EUR (rate + rounding + charm applied per WooPayments per-currency settings), `filters.price` bounds are converted before reaching the Store API, and `expected_unit_price` validation compares EUR-vs-EUR.

## Mechanism

A single new `WC_AI_Storefront_Multi_Currency::with_active_currency( $code, callable )` helper hooks WooPayments' `wcpay_multi_currency_override_selected_currency` filter for the duration of a callable, then unhooks in `finally`. Three dispatch sites in `WC_AI_Storefront_UCP_REST_Controller` wrap their `rest_do_request()` calls in this helper. A second helper, `convert_amount()`, wraps WCPay's `get_price()` for filter-bound math.

## Failure modes (graceful)

- Currency not in `accepted_currencies` → fall back to base + `currency_conversion_unsupported` warning at `$.context.currency`.
- WCPay throws mid-dispatch → fall back to base + same warning + `Logger::debug` line.
- No HTTP errors for currency issues — response always usable.

## Spec / Plan

- Spec: [`docs/superpowers/specs/2026-05-16-woopayments-multicurrency-phase-2-design.md`](docs/superpowers/specs/2026-05-16-woopayments-multicurrency-phase-2-design.md)
- Plan: [`docs/superpowers/plans/2026-05-16-woopayments-multicurrency-phase-2.md`](docs/superpowers/plans/2026-05-16-woopayments-multicurrency-phase-2.md)

## Test plan

- [x] `./vendor/bin/phpunit` — full unit suite passes
- [x] `./vendor/bin/phpcs` clean
- [x] PHPStan clean
- [ ] Manual verification on saltwarp.shop: agent sends `context.currency: EUR`, response prices say EUR with rate + rounding + charm applied
- [ ] Manual verification on saltwarp.shop: agent sends `context.currency: XYZ` (invalid), response in base + warning
- [ ] Manual verification on saltwarp.shop: toggle WCPay multi-currency off, agent EUR request degrades gracefully

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review notes (for the implementer)

**Spec coverage:**
- ✓ `with_active_currency` helper → Task 1
- ✓ `convert_amount` helper → Task 2
- ✓ Catalog search wrap → Task 3
- ✓ Catalog lookup wrap → Task 4
- ✓ Filter conversion → Task 5
- ✓ Checkout sessions wrap → Task 6
- ✓ `expected_unit_price` widening → Task 7
- ✓ Cache guardrail (docs only) → Task 9 (ARCHITECTURE.md)
- ✓ All four user-facing docs → Task 9
- ✓ CHANGELOG → Task 10
- ✓ Test coverage (unit + cross-file regression) → embedded in Tasks 1–7 + Task 8

**Type consistency:** `with_active_currency($code, callable)` signature is consistent across all wrap sites in Tasks 3/4/6. `convert_amount(int, string, string): int` signature consistent in Task 5. `check_price_drift` signature unchanged but its `$store_currency` argument is now the *active* currency, computed in Task 7's wrap.

**No placeholders:** Every step shows actual code or actual commands. The one place where the implementer must read live code is Task 4 Step 4 (locating the per-ID loop in `handle_catalog_lookup`); this is annotated explicitly.
