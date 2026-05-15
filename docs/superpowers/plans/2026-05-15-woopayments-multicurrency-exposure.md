# WooPayments Multi-Currency Exposure (Phase 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two things in one PR. (1) Advertise: publish the full list of currencies a store accepts (via WooPayments multi-currency) on the UCP manifest, homepage JSON-LD, and llms.txt. (2) Honor: stamp `?currency=XXX` on outbound UCP `continue_url` and per-product `url` responses when the agent sends `context.currency` in the accepted set, activating WooPayments' page-level currency switcher on the destination. UCP catalog response prices stay base-currency-quoted (Phase 2).

**Architecture:** A new pure helper class `WC_AI_Storefront_Multi_Currency` owns currency detection. It is a soft-dependency reader — `class_exists`-guarded against WooPayments — and exposes two methods: `get_accepted_currencies()` (returns the ordered ISO-4217 list, base first) and `stamp_currency_query()` (stamps `?currency=XXX` on outbound URLs when the request currency is in the accepted set). Three existing classes (`WC_AI_Storefront_Ucp`, `WC_AI_Storefront_JsonLd`, `WC_AI_Storefront_Llms_Txt`) call `get_accepted_currencies()`. The UCP REST controller adds a private `get_request_currency()` helper to extract the agent's `context.currency` hint, threads it into `build_continue_url()`, and stamps the per-product `url` at the two existing post-translation sites. A new filter `wc_ai_storefront_accepted_currencies` lets integrators override the auto-detected list. Output shape is stable across single- and multi-currency states (always at least `[ base ]`); URLs are unstamped when the agent doesn't send `context.currency`.

**Tech Stack:** PHP 7.4+, WordPress + Automattic coding standards (tabs, Yoda, `array()`, `===`), PHPUnit + Brain Monkey + Mockery for tests. No real WordPress install required.

**Spec:** [`docs/superpowers/specs/2026-05-15-woopayments-multicurrency-exposure-design.md`](../specs/2026-05-15-woopayments-multicurrency-exposure-design.md). GitHub issue: [#404](https://github.com/Automattic/woocommerce-ai-storefront/issues/404).

---

## File Structure

**New files:**
- `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php` — the helper class. Two responsibilities: (1) detect WooPayments enabled currencies and return a normalized list; (2) stamp `?currency=XXX` on outbound URLs when the request currency is in the accepted set.
- `tests/php/unit/MultiCurrencyTest.php` — PHPUnit coverage for the helper.

**Modified files:**
- `includes/autoload.php` — register the new class in the classmap.
- `includes/ai-storefront/class-wc-ai-storefront-ucp.php` — `build_store_context()` adds `accepted_currencies`.
- `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` — `output_store_jsonld()` switches `currenciesAccepted` from a single code to a space-separated list.
- `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` — `generate()` appends the new `**Accepted currencies**` line conditionally.
- `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` — add private `get_request_currency()` helper; wire `stamp_currency_query()` into `build_continue_url()` (line ~5896 area) and into the two post-translation `with_woo_ucp_utm` call sites (lines ~1079 and ~1842).
- `tests/php/unit/UcpTest.php` — update the strict key-set guard at line 590 and add multi-currency coverage.
- `tests/php/unit/JsonLdTest.php` — add `currenciesAccepted` multi-currency assertions.
- `tests/php/unit/LlmsTxtTest.php` — add accepted-currencies line assertions.
- `tests/php/unit/UcpRestControllerTest.php` (or the closest existing checkout-sessions test file — verify during implementation) — assert `continue_url` carries `?currency=XXX` when `context.currency` is in `accepted_currencies` and is unchanged otherwise.
- `tests/php/unit/UcpCatalogSearchTest.php` and `tests/php/unit/UcpCatalogLookupTest.php` — assert per-product `url` carries `?currency=XXX` when `context.currency` is in the accepted set.
- `docs/engineering/HOOKS.md` — document the new filter.
- `docs/engineering/API-REFERENCE.md` — document `accepted_currencies` on `store_context` AND the new `?currency=` query param on `continue_url` and product `url` fields.
- `docs/engineering/UCP-BUY-FLOW.md` — document the `?currency=` stamping on `continue_url` so flow diagrams reflect the new param.
- `docs/engineering/JSON-LD-SCHEMA.md` — document the new `currenciesAccepted` shape AND the per-page `?currency=` reflection free-win.
- `docs/engineering/ARCHITECTURE.md` — register the new helper class.
- `AGENTS.md` — add the new code path to the path → doc map.
- `.github/workflows/docs-followup.yml` — mirror the AGENTS.md path map row.
- `woocommerce-ai-storefront.php` — bump plugin version header + `WC_AI_STOREFRONT_VERSION` constant.
- `package.json` — bump version.
- `readme.txt` — bump `Stable tag`.
- `CHANGELOG.md` — `[Unreleased]` entry (final wording lands in the pre-release pass, but the bullet is added now per `AGENTS.md`'s PR template convention).

---

## Task 1: Scaffold the helper class (failing test first)

**Files:**
- Create: `tests/php/unit/MultiCurrencyTest.php`
- Create (later in this task): `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php`
- Modify: `includes/autoload.php`

- [ ] **Step 1.1: Create the test file with the baseline "no WooPayments" assertion**

Create `tests/php/unit/MultiCurrencyTest.php`:

```php
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
```

- [ ] **Step 1.2: Run the test to verify it fails with "class not defined"**

Run: `vendor/bin/phpunit --filter=MultiCurrencyTest`
Expected: FATAL — `Class "WC_AI_Storefront_Multi_Currency" not found`.

- [ ] **Step 1.3: Create the minimal helper class to make the test pass**

Create `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php`:

```php
<?php
/**
 * WooPayments multi-currency reader.
 *
 * Soft-dependency helper that returns the list of currencies the
 * store accepts. When WooPayments' multi-currency feature is active,
 * the list mirrors WCPay's enabled set with the WooCommerce base
 * currency forced into the first position. When WooPayments is
 * absent or the multi-currency feature is disabled, the list is
 * `[ base_currency ]` — never empty, never null.
 *
 * All call sites get the same array shape regardless of detection
 * outcome, which keeps consumer code free of detection branches.
 *
 * Phase 1 of two: this class only ADVERTISES currencies. Live
 * currency switching on UCP product/checkout endpoints is Phase 2.
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

		// Soft-dependency probe. The class path is documented as
		// part of the public WCPay API; the `is_multi_currency_enabled()`
		// guard prevents us from reporting a multi-currency list when
		// the merchant has the WCPay plugin installed but the feature
		// turned off.
		if ( class_exists( '\WCPay\MultiCurrency\MultiCurrency' ) ) {
			$mc = \WCPay\MultiCurrency\MultiCurrency::instance();
			if ( is_object( $mc ) && method_exists( $mc, 'is_multi_currency_enabled' ) && $mc->is_multi_currency_enabled() ) {
				if ( method_exists( $mc, 'get_enabled_currencies' ) ) {
					$enabled = $mc->get_enabled_currencies();
					if ( is_array( $enabled ) ) {
						// `get_enabled_currencies()` returns an associative
						// array keyed by ISO-4217 code (uppercase), with
						// Currency objects as values. We read the keys to
						// stay resilient to refactors of the Currency
						// object's method surface.
						foreach ( array_keys( $enabled ) as $code ) {
							$list[] = strtoupper( (string) $code );
						}
					}
				}
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
		 * (three uppercase ASCII letters). Non-array returns, empty
		 * arrays, and arrays whose entries all fail validation fall
		 * back to `[ base_currency ]`.
		 *
		 * @since 0.17.0
		 *
		 * @param array<int, string> $list Auto-detected list, base currency first.
		 */
		$filtered = apply_filters( 'wc_ai_storefront_accepted_currencies', $list );

		if ( is_array( $filtered ) ) {
			$filtered = self::normalize_codes( $filtered );
			if ( ! empty( $filtered ) ) {
				$list = $filtered;
			}
		}

		// Ultimate fallback: even if normalize_codes() somehow yielded
		// an empty array (shouldn't, because base is prepended), guard
		// against returning [] to consumers.
		if ( empty( $list ) ) {
			$list = array( $base );
		}

		self::$cache = $list;
		return self::$cache;
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
			if ( ! is_string( $code ) && ! is_numeric( $code ) ) {
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
```

- [ ] **Step 1.4: Register the class in the standalone autoloader**

Edit `includes/autoload.php`. Find the line:

```php
'WC_AI_Storefront_Logger'                 => '/ai-storefront/class-wc-ai-storefront-logger.php',
```

Add this line directly after it (preserving WP-style aligned `=>`):

```php
'WC_AI_Storefront_Multi_Currency'         => '/ai-storefront/class-wc-ai-storefront-multi-currency.php',
```

The Composer classmap glob in `composer.json` already covers `includes/ai-storefront/` so no `composer.json` change is needed — but run `composer dump-autoload` so the optimized classmap picks up the file.

- [ ] **Step 1.5: Run the test again to verify it now passes**

Run: `composer dump-autoload && vendor/bin/phpunit --filter=MultiCurrencyTest`
Expected: PASS — 1 test, 1 assertion.

- [ ] **Step 1.6: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-multi-currency.php includes/autoload.php tests/php/unit/MultiCurrencyTest.php
git commit -m "feat(ucp): add WooPayments multi-currency reader helper

Soft-dependency reader for WooPayments' enabled currency list.
Returns base-currency-only when WCPay is absent or the
multi-currency feature is disabled. Output shape is stable across
all detection states.

Refs #404"
```

---

## Task 2: Cover the WooPayments-enabled detection path

**Files:**
- Modify: `tests/php/unit/MultiCurrencyTest.php`

The helper already has the detection logic from Task 1. This task locks in the behavior with tests.

- [ ] **Step 2.1: Replace the test file with the WCPay-stand-in version**

Brain Monkey can't easily mock a class that doesn't exist. We declare a minimal stand-in for `\WCPay\MultiCurrency\MultiCurrency` directly in the test file using namespace-bracketed syntax: tests install a Mockery double onto a static property, and the stand-in's `instance()` returns it.

Replace the entire contents of `tests/php/unit/MultiCurrencyTest.php` (the file written in Task 1.1) with:

```php
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
		 * Test stand-in for the real WCPay class. Tests install a
		 * Mockery double onto `$test_double`; `instance()` returns it.
		 * When `$test_double` is null, `instance()` returns null and
		 * the helper falls through the `is_object()` guard.
		 */
		class MultiCurrency {
			public static $test_double = null;
			public static function instance() {
				return self::$test_double;
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
	}
}
```

This replaces the file written in Task 1.1. The new test method name is the same logical assertion as before; it's renamed to clarify "no double installed" means "WCPay not active from the helper's POV."

- [ ] **Step 2.2: Run the renamed test to verify it still passes**

Run: `vendor/bin/phpunit --filter=MultiCurrencyTest`
Expected: PASS — 1 test, 1 assertion.

- [ ] **Step 2.3: Add the "WCPay enabled, full multi-currency list" test**

Inside the `class MultiCurrencyTest` body, add:

```php
		public function test_get_accepted_currencies_wcpay_enabled_returns_full_list_with_base_first(): void {
			$mc = \Mockery::mock();
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
```

- [ ] **Step 2.4: Run the new test**

Run: `vendor/bin/phpunit --filter=MultiCurrencyTest`
Expected: PASS — 2 tests, 2 assertions.

- [ ] **Step 2.5: Add the "WCPay installed but multi-currency disabled" test**

```php
		public function test_get_accepted_currencies_wcpay_disabled_returns_base_only(): void {
			$mc = \Mockery::mock();
			$mc->shouldReceive( 'is_multi_currency_enabled' )->andReturn( false );
			$mc->shouldNotReceive( 'get_enabled_currencies' );
			\WCPay\MultiCurrency\MultiCurrency::$test_double = $mc;

			$this->assertSame(
				array( 'USD' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}
```

- [ ] **Step 2.6: Add the "base missing from WCPay list → prepended" test**

```php
		public function test_get_accepted_currencies_base_missing_from_wcpay_list_is_prepended(): void {
			$mc = \Mockery::mock();
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
```

- [ ] **Step 2.7: Add the "duplicates deduped, order preserved" test**

```php
		public function test_get_accepted_currencies_duplicates_are_deduped_preserving_order(): void {
			$mc = \Mockery::mock();
			$mc->shouldReceive( 'is_multi_currency_enabled' )->andReturn( true );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'USD' => new \stdClass(),
					'EUR' => new \stdClass(),
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
```

- [ ] **Step 2.8: Add the "malformed codes dropped" test**

```php
		public function test_get_accepted_currencies_malformed_codes_are_dropped(): void {
			$mc = \Mockery::mock();
			$mc->shouldReceive( 'is_multi_currency_enabled' )->andReturn( true );
			$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
				array(
					'EUR'      => new \stdClass(),
					'eurr'     => new \stdClass(),
					''         => new \stdClass(),
					'12'       => new \stdClass(),
					'GBP'      => new \stdClass(),
				)
			);
			\WCPay\MultiCurrency\MultiCurrency::$test_double = $mc;

			$this->assertSame(
				array( 'USD', 'EUR', 'GBP' ),
				WC_AI_Storefront_Multi_Currency::get_accepted_currencies()
			);
		}
```

- [ ] **Step 2.9: Run all helper tests**

Run: `vendor/bin/phpunit --filter=MultiCurrencyTest`
Expected: PASS — 6 tests, 6 assertions.

- [ ] **Step 2.10: Commit**

```bash
git add tests/php/unit/MultiCurrencyTest.php
git commit -m "test(ucp): cover WooPayments multi-currency detection branches

Adds enabled-list, disabled-feature, base-prepend, dedupe, and
malformed-code-drop coverage for the helper.

Refs #404"
```

---

## Task 3: Cover the filter and memoization paths

**Files:**
- Modify: `tests/php/unit/MultiCurrencyTest.php`

- [ ] **Step 3.1: Add the "filter can override" test**

Brain Monkey's `Functions\when( 'apply_filters' )->returnArg( 2 )` is the baseline. To exercise the filter, swap it per-test using `Functions\expect()`.

Add to the test class:

```php
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
```

- [ ] **Step 3.2: Add the "filter returning non-array falls back" test**

```php
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
```

- [ ] **Step 3.3: Add the "filter returning empty falls back" test**

```php
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
```

- [ ] **Step 3.4: Add the "filter returning all-invalid codes falls back" test**

```php
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
```

- [ ] **Step 3.5: Add the memoization test**

```php
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
```

- [ ] **Step 3.6: Run all helper tests**

Run: `vendor/bin/phpunit --filter=MultiCurrencyTest`
Expected: PASS — 11 tests, 11 assertions.

- [ ] **Step 3.7: Commit**

```bash
git add tests/php/unit/MultiCurrencyTest.php
git commit -m "test(ucp): cover filter override and memoization in multi-currency helper

Refs #404"
```

---

## Task 4: Wire `accepted_currencies` into the UCP manifest

**Files:**
- Modify: `tests/php/unit/UcpTest.php`
- Modify: `includes/ai-storefront/class-wc-ai-storefront-ucp.php`

- [ ] **Step 4.1: Update the strict key-set guard test for the new field**

Edit `tests/php/unit/UcpTest.php`. Find `test_store_context_fields_are_exactly_those_documented` (around line 584). Replace the body:

```php
	public function test_store_context_fields_are_exactly_those_documented(): void {
		// Regression guard against field drift. If a future refactor
		// adds a new key to store_context without also updating
		// consumer documentation, this test fires. The fix is
		// deliberate: either update this test (conscious addition)
		// or remove the stray field.
		$this->assertSame(
			[ 'currency', 'accepted_currencies', 'locale', 'country', 'prices_include_tax', 'shipping_enabled' ],
			array_keys( $this->get_store_context() )
		);
	}
```

- [ ] **Step 4.2: Add a positive single-currency test for the new field**

Add this method anywhere in the store-context test section of `UcpTest.php` (e.g. directly above `test_store_context_fields_are_exactly_those_documented`):

```php
	public function test_store_context_accepted_currencies_includes_base_on_single_currency_store(): void {
		// On a stock WC install with no WooPayments, accepted_currencies
		// is a 1-element array containing the base currency. The shape
		// is stable — single-currency stores get the same field as
		// multi-currency stores.
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$ctx = $this->get_store_context();
		$this->assertSame( array( 'USD' ), $ctx['accepted_currencies'] );
	}
```

- [ ] **Step 4.3: Run the UCP tests to verify the guard now fails**

Run: `vendor/bin/phpunit --filter=UcpTest`
Expected: FAIL — `test_store_context_fields_are_exactly_those_documented` now expects six keys but the production code emits five. `test_store_context_accepted_currencies_includes_base_on_single_currency_store` fails with "undefined index `accepted_currencies`".

- [ ] **Step 4.4: Add `accepted_currencies` to `build_store_context()`**

Edit `includes/ai-storefront/class-wc-ai-storefront-ucp.php`. Find `build_store_context()` (around line 513). Locate the return array (around line 534):

```php
		return [
			'currency'           => get_woocommerce_currency(),
			'locale'             => $locale,
			'country'            => $country ? $country : null,
			'prices_include_tax' => (bool) wc_prices_include_tax(),
			'shipping_enabled'   => (bool) wc_shipping_enabled(),
		];
```

Replace with:

```php
		return [
			'currency'            => get_woocommerce_currency(),
			'accepted_currencies' => WC_AI_Storefront_Multi_Currency::get_accepted_currencies(),
			'locale'              => $locale,
			'country'             => $country ? $country : null,
			'prices_include_tax'  => (bool) wc_prices_include_tax(),
			'shipping_enabled'    => (bool) wc_shipping_enabled(),
		];
```

(Note: the WP-style aligned `=>` shift to accommodate `accepted_currencies` as the longest key.)

- [ ] **Step 4.5: Run the UCP tests to verify both new assertions pass**

Run: `vendor/bin/phpunit --filter=UcpTest`
Expected: PASS — full suite green.

- [ ] **Step 4.6: Add a positive multi-currency test using the WCPay stand-in**

`UcpTest.php` doesn't have the WCPay stand-in from `MultiCurrencyTest.php`. We don't need to duplicate it — `apply_filters` returnArg-2 is already in place via the setUp's `apply_filters` stub. Add the filter-driven version of the test:

```php
	public function test_store_context_accepted_currencies_reflects_filter_override(): void {
		// Cover the multi-currency case via the filter rather than
		// installing a WCPay stand-in here — keeps UcpTest free of
		// WCPay namespace gymnastics. Filter-driven coverage of the
		// detection path lives in MultiCurrencyTest.
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR', 'GBP' );
				}
				return $value;
			}
		);

		$ctx = $this->get_store_context();
		$this->assertSame( array( 'USD', 'EUR', 'GBP' ), $ctx['accepted_currencies'] );
	}
```

Note: also add a `tearDown` reset for the helper cache if not already present in `UcpTest.php`. Find the existing `tearDown` (or add one if absent):

```php
	protected function tearDown(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Monkey\tearDown();
		parent::tearDown();
	}
```

If `UcpTest.php` already has a tearDown, prepend `WC_AI_Storefront_Multi_Currency::reset_cache();` as the first line.

- [ ] **Step 4.7: Run the UCP tests**

Run: `vendor/bin/phpunit --filter=UcpTest`
Expected: PASS — all UCP tests green.

- [ ] **Step 4.8: Commit**

```bash
git add tests/php/unit/UcpTest.php includes/ai-storefront/class-wc-ai-storefront-ucp.php
git commit -m "feat(ucp): publish accepted_currencies in manifest store_context

Adds the base+enabled-currency list to the UCP manifest's
store_context block. Single-currency stores see a 1-element array;
multi-currency WooPayments stores see the full enabled list with
the base currency first.

Refs #404"
```

---

## Task 5: Wire the JSON-LD `currenciesAccepted` field

**Files:**
- Modify: `tests/php/unit/JsonLdTest.php`
- Modify: `includes/ai-storefront/class-wc-ai-storefront-jsonld.php`

- [ ] **Step 5.1: Add a single-currency JSON-LD test**

Append to `tests/php/unit/JsonLdTest.php` (inside the test class):

```php
	public function test_output_store_jsonld_currenciesaccepted_single_currency_emits_base(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );

		ob_start();
		( new WC_AI_Storefront_JsonLd() )->output_store_jsonld();
		$output = ob_get_clean();

		$this->assertStringContainsString( '"currenciesAccepted":"USD"', $output );
	}
```

If a similar test already exists, skip this step (note in commit if so).

- [ ] **Step 5.2: Add the multi-currency JSON-LD test (filter-driven)**

```php
	public function test_output_store_jsonld_currenciesaccepted_multi_currency_emits_space_separated_list(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR', 'GBP' );
				}
				return $value;
			}
		);

		ob_start();
		( new WC_AI_Storefront_JsonLd() )->output_store_jsonld();
		$output = ob_get_clean();

		$this->assertStringContainsString( '"currenciesAccepted":"USD EUR GBP"', $output );
	}
```

Add the helper-cache reset to `JsonLdTest.php`'s `tearDown` if not already present (same pattern as Task 4.6).

- [ ] **Step 5.3: Run JSON-LD tests to verify the multi-currency one fails**

Run: `vendor/bin/phpunit --filter=JsonLdTest`
Expected: FAIL — the multi-currency test fails because the production code still emits `"currenciesAccepted":"USD"`.

- [ ] **Step 5.4: Switch `currenciesAccepted` to use the helper**

Edit `includes/ai-storefront/class-wc-ai-storefront-jsonld.php`. Find line 1897:

```php
			'currenciesAccepted' => get_woocommerce_currency(),
```

Replace with:

```php
			'currenciesAccepted' => implode( ' ', WC_AI_Storefront_Multi_Currency::get_accepted_currencies() ),
```

The Schema.org `currenciesAccepted` property accepts a space-separated string of ISO-4217 codes — that's the canonical multi-currency format. The single-currency case continues to emit `"USD"` (a one-element list with no separator).

- [ ] **Step 5.5: Run JSON-LD tests**

Run: `vendor/bin/phpunit --filter=JsonLdTest`
Expected: PASS — both new tests green, no regressions in the existing suite.

- [ ] **Step 5.6: Commit**

```bash
git add tests/php/unit/JsonLdTest.php includes/ai-storefront/class-wc-ai-storefront-jsonld.php
git commit -m "feat(jsonld): emit space-separated currenciesAccepted for multi-currency stores

Homepage OnlineBusiness JSON-LD now lists the full accepted-currency
set when WooPayments multi-currency is active. Single-currency
stores remain unchanged (one code, no separator).

Refs #404"
```

---

## Task 6: Wire the llms.txt accepted-currencies line

**Files:**
- Modify: `tests/php/unit/LlmsTxtTest.php`
- Modify: `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php`

- [ ] **Step 6.1: Add a single-currency omission test**

Append to `tests/php/unit/LlmsTxtTest.php`:

```php
	public function test_generate_accepted_currencies_line_omitted_on_single_currency_store(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '**Accepted currencies**', $output );
	}
```

- [ ] **Step 6.2: Add a multi-currency presence test**

```php
	public function test_generate_accepted_currencies_line_present_on_multi_currency_store(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR', 'GBP' );
				}
				return $value;
			}
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString(
			"- **Accepted currencies**: USD, EUR, GBP (catalog prices quoted in USD; checkout converts at WooPayments' rates)",
			$output
		);
	}
```

Add the helper-cache reset to `LlmsTxtTest.php`'s `tearDown` if not already present.

- [ ] **Step 6.3: Run llms.txt tests to verify both new tests fail**

Run: `vendor/bin/phpunit --filter=LlmsTxtTest`
Expected: FAIL — the "present" test fails (line not emitted). The "omitted" test passes by accident (the line genuinely isn't there yet).

- [ ] **Step 6.4: Emit the conditional line in `generate()`**

Edit `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php`. Find line 343:

```php
		$lines[] = "- **Currency**: {$currency}";
```

Add immediately after it:

```php
		$accepted_currencies = WC_AI_Storefront_Multi_Currency::get_accepted_currencies();
		if ( count( $accepted_currencies ) > 1 ) {
			$lines[] = sprintf(
				/* translators: 1: comma-separated list of additional ISO-4217 currency codes, 2: base ISO-4217 currency code. */
				__( '- **Accepted currencies**: %1$s (catalog prices quoted in %2$s; checkout converts at WooPayments\' rates)', 'woocommerce-ai-storefront' ),
				implode( ', ', $accepted_currencies ),
				$currency
			);
		}
```

The `__()` call wraps the whole line (label + qualifier) so translators can restructure the parenthetical clause for languages that need different connective grammar. The base currency interpolates as `%2$s` so the qualifier remains accurate for non-USD base stores.

- [ ] **Step 6.5: Run llms.txt tests**

Run: `vendor/bin/phpunit --filter=LlmsTxtTest`
Expected: PASS — both new tests green, no regression in `test_store_section_always_carries_currency`.

- [ ] **Step 6.6: Commit**

```bash
git add tests/php/unit/LlmsTxtTest.php includes/ai-storefront/class-wc-ai-storefront-llms-txt.php
git commit -m "feat(llmstxt): add Accepted currencies line for multi-currency stores

Emits a sentence-case **Accepted currencies** line directly after
**Currency** when more than one currency is enabled. Includes a
parenthetical clarifying that catalog prices remain in the base
currency (Phase 1 honesty signal).

Refs #404"
```

---

## Task 6a: Add `stamp_currency_query()` to the helper

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php`
- Modify: `tests/php/unit/MultiCurrencyTest.php`

TDD: nine new tests covering every branch of the new method. The method is pure: given a URL and a candidate currency, return either the URL unchanged or the URL with `?currency=XXX` appended.

- [ ] **Step 6a.1: Add the test for "no request currency"**

Add inside the `class MultiCurrencyTest` body in `tests/php/unit/MultiCurrencyTest.php`:

```php
		public function test_stamp_currency_query_no_request_currency_returns_url_unchanged(): void {
			$url = 'https://example.com/checkout-link/?products=42:1';
			$this->assertSame(
				$url,
				WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, null )
			);
		}
```

- [ ] **Step 6a.2: Run the test to confirm it fails with "method not found"**

Run: `vendor/bin/phpunit --filter=MultiCurrencyTest`
Expected: FAIL — `Method WC_AI_Storefront_Multi_Currency::stamp_currency_query does not exist`.

- [ ] **Step 6a.3: Implement `stamp_currency_query()` in the helper**

Edit `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php`. Append a new public static method immediately after `get_accepted_currencies()` (before `normalize_codes()`):

```php
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
	 * Fail-closed paths (URL returned unchanged):
	 *   - $url empty, non-string, or null.
	 *   - $requested_currency null or non-string.
	 *   - $requested_currency fails the ISO-4217 pattern after uppercase.
	 *   - $requested_currency is not in `get_accepted_currencies()`.
	 *   - $url already carries a `currency=` query param (preserves any
	 *     upstream override or filter-injected value).
	 *
	 * The function is idempotent: calling it twice with the same args
	 * produces the same URL.
	 *
	 * @since 0.17.0
	 *
	 * @param string      $url               Outbound URL to stamp.
	 * @param string|null $requested_currency Candidate ISO-4217 code from
	 *                                        the agent's request (typically
	 *                                        `$request->get_param('context')['currency']`).
	 * @return string The stamped URL, or the input URL unchanged.
	 */
	public static function stamp_currency_query( $url, $requested_currency ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return is_string( $url ) ? $url : '';
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
```

- [ ] **Step 6a.4: Run the failing test to verify it now passes**

Run: `vendor/bin/phpunit --filter=MultiCurrencyTest`
Expected: PASS — 12 tests passing (11 previously + 1 new).

- [ ] **Step 6a.5: Add the remaining 8 `stamp_currency_query` tests**

Add inside the test class:

```php
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

		public function test_stamp_currency_query_empty_url_returns_empty_string(): void {
			$this->assertSame(
				'',
				WC_AI_Storefront_Multi_Currency::stamp_currency_query( '', 'USD' )
			);
		}

		public function test_stamp_currency_query_url_with_no_existing_query_appends_currency(): void {
			$url    = 'https://example.com/product/widget/';
			$result = WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, 'USD' );
			$this->assertSame( 'https://example.com/product/widget/?currency=USD', $result );
		}

		public function test_stamp_currency_query_is_idempotent_when_called_twice(): void {
			$url     = 'https://example.com/checkout-link/?products=42:1';
			$first   = WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, 'USD' );
			$second  = WC_AI_Storefront_Multi_Currency::stamp_currency_query( $first, 'USD' );
			$this->assertSame( $first, $second, 'Double-stamping should be a no-op' );
		}
```

Two Brain Monkey stubs need to exist for the `wp_parse_url` / `wp_parse_str` / `add_query_arg` calls. Check whether the test bootstrap already provides them:

```bash
grep -nE "wp_parse_url|wp_parse_str|add_query_arg" tests/php/stubs.php tests/php/bootstrap.php 2>/dev/null
```

If any of the three are absent, add aliases to `MultiCurrencyTest::setUp()` BEFORE the existing `Functions\when` calls. The drop-in stubs:

```php
		Functions\when( 'wp_parse_url' )->alias(
			static fn( $url, $component = -1 ) => -1 === $component ? parse_url( $url ) : parse_url( $url, $component )
		);
		Functions\when( 'wp_parse_str' )->alias(
			static function ( $str, &$result ) {
				parse_str( $str, $result );
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url ) {
				$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';
				return $url . $separator . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
			}
		);
		// Note: `add_query_arg()` has a 3-arg signature used here. WP also
		// supports a 2-arg form `add_query_arg( $args_array, $url )` but
		// this helper only uses the 3-arg form.
```

Only add stubs for the functions actually missing from `tests/php/stubs.php` — duplicate definitions error out.

- [ ] **Step 6a.6: Run all helper tests**

Run: `vendor/bin/phpunit --filter=MultiCurrencyTest`
Expected: PASS — 19 tests, all green (11 original + 8 new + the Task 6a.1 test = 20 actually; phpunit count varies because some new tests have multiple assertions).

Sanity-check the assertion count is consistent with the test count.

- [ ] **Step 6a.7: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-multi-currency.php tests/php/unit/MultiCurrencyTest.php
git commit -m "feat(ucp): add stamp_currency_query helper for outbound URL stamping

Stamps ?currency=XXX onto buyer-facing URLs when the agent's
context.currency is in the accepted-currencies set. Fail-closed
on every malformed input. Idempotent.

Used by build_continue_url() and post-translation product URL
stamping in catalog/search and catalog/lookup responses
(landing in subsequent tasks).

Refs #404"
```

---

## Task 6b: Stamp `?currency=` on `continue_url`

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php`
- Modify: `tests/php/unit/UcpRestControllerTest.php` (verify exact file name during implementation — there may be a more specific checkout-sessions test file)

The controller already reads `context.currency` inline in `map_ucp_search_to_store_api` (around line 4263). Lift it into a private helper, then call it in the checkout-sessions handler to thread through to `build_continue_url`.

- [ ] **Step 6b.1: Write the failing test for the `/checkout-link/?products=` path**

Find the relevant test file (likely `tests/php/unit/UcpCheckoutSessionsTest.php` per the convention). Add a test:

```php
	public function test_checkout_sessions_continue_url_carries_currency_when_context_currency_in_accepted_set(): void {
		// Multi-currency accepted set (USD+EUR+GBP) via filter override.
		// Agent sends context.currency=EUR, expects the continue_url
		// to carry `currency=EUR` ahead of the UTM block.
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR', 'GBP' );
				}
				if ( 'wc_ai_storefront_ucp_continue_url' === $hook ) {
					return $value;
				}
				return $value;
			}
		);

		// ... (rest of the test setup follows the established pattern in
		// UcpCheckoutSessionsTest — POST a simple product line_item with
		// context.currency = 'EUR' and assert response.continue_url
		// contains 'currency=EUR' as a query param).

		$response = $this->post_checkout_sessions( array(
			'line_items' => array(
				array( 'product_id' => 'wc:42', 'quantity' => 1, 'expected_unit_price' => array( 'amount' => 1000, 'currency' => 'EUR' ) ),
			),
			'context'    => array( 'currency' => 'EUR' ),
		) );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'continue_url', $data );
		$this->assertStringContainsString( 'currency=EUR', $data['continue_url'] );
	}
```

(The exact test scaffold — `post_checkout_sessions()` helper, fixture format — must match the surrounding test file's conventions. Read the existing checkout-sessions tests and follow their pattern. If the file doesn't yet have a helper for the "agent sends a line item, controller returns a continue_url" shape, look for the closest equivalent test and copy its structure verbatim. DO NOT invent new test infrastructure.)

- [ ] **Step 6b.2: Run the test to confirm it fails**

Run: `vendor/bin/phpunit --filter=test_checkout_sessions_continue_url_carries_currency_when_context_currency_in_accepted_set`
Expected: FAIL — `continue_url` does not contain `currency=EUR` (the controller doesn't stamp yet).

- [ ] **Step 6b.3: Add the private `get_request_currency()` helper to the controller**

Edit `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php`. Find a place near the other private static helpers (search for `private static function build_seller`). Add:

```php
	/**
	 * Extract and validate the agent's `context.currency` hint.
	 *
	 * Reads `context.currency` from the request body, trims it,
	 * uppercases it, and validates the ISO-4217 shape (^[A-Z]{3}$).
	 * Returns the normalized code on success, or null when the hint
	 * is absent, malformed, or non-string.
	 *
	 * This is the single source of truth for "what currency did the
	 * agent ask for?" — used by `build_continue_url()`, the per-product
	 * URL stamper in `translate_products_for_*`, and (in Phase 2) the
	 * WCPay currency-switch wrapper.
	 *
	 * Note: this helper does NOT check membership in
	 * `WC_AI_Storefront_Multi_Currency::get_accepted_currencies()` —
	 * that's `stamp_currency_query()`'s job. We pass the raw validated
	 * hint through; the stamper decides whether to use it.
	 *
	 * @since 0.17.0
	 *
	 * @param WP_REST_Request $request The incoming UCP request.
	 * @return string|null Normalized ISO-4217 code, or null when absent/malformed.
	 */
	private static function get_request_currency( WP_REST_Request $request ): ?string {
		$context = $request->get_param( 'context' );
		if ( ! is_array( $context ) ) {
			return null;
		}
		$raw = $context['currency'] ?? null;
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$normalized = strtoupper( trim( $raw ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $normalized ) ) {
			return null;
		}
		return $normalized;
	}
```

- [ ] **Step 6b.4: Thread the request currency into `build_continue_url`**

Find the `build_continue_url` signature (currently around line 5637):

```php
	private static function build_continue_url( array $processed, string $source_host, string $raw_host ): string {
```

Change to:

```php
	private static function build_continue_url( array $processed, string $source_host, string $raw_host, ?string $request_currency = null ): string {
```

Find the existing call site (around line 2836):

```php
		$continue_url = $should_redirect
			? self::build_continue_url( $processed, $agent_source_host, $agent_raw_host )
			: '';
```

Replace with:

```php
		$continue_url = $should_redirect
			? self::build_continue_url(
				$processed,
				$agent_source_host,
				$agent_raw_host,
				self::get_request_currency( $request )
			)
			: '';
```

(`$request` is the WP_REST_Request object in scope at that location. Verify by reading the surrounding method signature.)

- [ ] **Step 6b.5: Stamp inside `build_continue_url` BEFORE the UTM pass**

Find the existing UTM-stamping call inside `build_continue_url` (around line 5896):

```php
		$url = WC_AI_Storefront_Attribution::with_woo_ucp_utm(
			$url_with_products,
			$source_host,
			$raw_host
		);
```

Replace with:

```php
		// Stamp the agent's context.currency hint onto the URL before
		// UTM attribution stamps on top. The stamper is a no-op when
		// the request currency is null, malformed, or not in
		// `accepted_currencies` — so this single call covers
		// single-currency stores (no-op), multi-currency stores
		// where the agent didn't send a hint (no-op), and the live
		// case where the agent's hint is honored.
		$url_with_currency = WC_AI_Storefront_Multi_Currency::stamp_currency_query(
			$url_with_products,
			$request_currency
		);

		$url = WC_AI_Storefront_Attribution::with_woo_ucp_utm(
			$url_with_currency,
			$source_host,
			$raw_host
		);
```

- [ ] **Step 6b.6: Run the Task 6b.1 test to verify it now passes**

Run: `vendor/bin/phpunit --filter=test_checkout_sessions_continue_url_carries_currency_when_context_currency_in_accepted_set`
Expected: PASS.

- [ ] **Step 6b.7: Add three more checkout-sessions tests**

Add to the same test file:

```php
	public function test_checkout_sessions_continue_url_unchanged_when_context_currency_absent(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR', 'GBP' );
				}
				return $value;
			}
		);

		$response = $this->post_checkout_sessions( array(
			'line_items' => array(
				array( 'product_id' => 'wc:42', 'quantity' => 1 ),
			),
			// No 'context' key at all.
		) );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'continue_url', $data );
		$this->assertStringNotContainsString( 'currency=', $data['continue_url'] );
	}

	public function test_checkout_sessions_continue_url_unchanged_when_context_currency_not_in_accepted_set(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD' ); // single-currency store
				}
				return $value;
			}
		);

		$response = $this->post_checkout_sessions( array(
			'line_items' => array(
				array( 'product_id' => 'wc:42', 'quantity' => 1 ),
			),
			'context'    => array( 'currency' => 'JPY' ), // not in the set
		) );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'continue_url', $data );
		$this->assertStringNotContainsString( 'currency=', $data['continue_url'] );
	}

	public function test_checkout_sessions_continue_url_currency_precedes_utm_block(): void {
		// Param ordering matters for readability and for any downstream
		// filter that pattern-matches on the UTM tail. Verify that
		// currency= appears before utm_source= in the final query string.
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR' );
				}
				return $value;
			}
		);

		$response = $this->post_checkout_sessions( array(
			'line_items' => array(
				array( 'product_id' => 'wc:42', 'quantity' => 1 ),
			),
			'context'    => array( 'currency' => 'EUR' ),
		) );

		$data = $response->get_data();
		$url  = $data['continue_url'];

		$currency_pos = strpos( $url, 'currency=' );
		$utm_pos      = strpos( $url, 'utm_source=' );
		$this->assertNotFalse( $currency_pos );
		$this->assertNotFalse( $utm_pos );
		$this->assertLessThan( $utm_pos, $currency_pos, 'currency= should precede utm_source=' );
	}
```

- [ ] **Step 6b.8: Run all four checkout-sessions currency tests**

Run: `vendor/bin/phpunit --filter=UcpCheckoutSessionsTest`
Expected: PASS — all four new tests green, no regressions in the existing checkout-sessions suite.

- [ ] **Step 6b.9: Commit**

```bash
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpCheckoutSessionsTest.php
git commit -m "feat(ucp): stamp ?currency= on continue_url when context.currency in accepted set

Adds a private get_request_currency() helper that reads and
validates context.currency from the incoming UCP request. Threads
it into build_continue_url() so every continue_url shape
(/checkout-link/?products=, bundle, grouped, variable-parent)
carries ?currency=XXX ahead of the UTM block when the agent's
hint is in accepted_currencies.

Fail-closed: missing hint, malformed hint, or hint outside
accepted_currencies all return the URL unchanged.

Refs #404"
```

---

## Task 6c: Stamp `?currency=` on per-product `url` in catalog responses

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php`
- Modify: `tests/php/unit/UcpCatalogLookupTest.php`
- Modify: `tests/php/unit/UcpCatalogSearchTest.php`

The translator emits a bare `url`; the controller stamps UTM via `with_woo_ucp_utm` at two sites (lines ~1079 for catalog/lookup, ~1842 for catalog/search). Add `stamp_currency_query` immediately before the UTM call at both sites.

- [ ] **Step 6c.1: Write the failing test for catalog/lookup**

Add to `tests/php/unit/UcpCatalogLookupTest.php`:

```php
	public function test_catalog_lookup_product_url_carries_currency_when_context_currency_in_accepted_set(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR' );
				}
				return $value;
			}
		);

		// Follow the existing UcpCatalogLookupTest fixture pattern:
		// stub the Store API response for product id 42, post a UCP
		// catalog/lookup request with context.currency = 'EUR', and
		// assert the response's product.url contains `currency=EUR`.

		$response = $this->post_catalog_lookup( array(
			'product_ids' => array( 'wc:42' ),
			'context'     => array( 'currency' => 'EUR' ),
		) );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'products', $data );
		$this->assertNotEmpty( $data['products'] );
		$this->assertArrayHasKey( 'url', $data['products'][0] );
		$this->assertStringContainsString( 'currency=EUR', $data['products'][0]['url'] );
	}
```

(Match the existing test's helper invocations. Read 2–3 nearby tests in the same file before writing this one to copy the exact fixture/helper pattern. DO NOT invent new test infrastructure.)

- [ ] **Step 6c.2: Run the test to confirm it fails**

Run: `vendor/bin/phpunit --filter=test_catalog_lookup_product_url_carries_currency_when_context_currency_in_accepted_set`
Expected: FAIL — `url` does not contain `currency=EUR`.

- [ ] **Step 6c.3: Add the stamping call at the catalog/lookup site**

Find the existing post-translation stamping site (around line 1078):

```php
			if ( ! empty( $product['url'] ) ) {
				$product['url'] = WC_AI_Storefront_Attribution::with_woo_ucp_utm(
					$product['url'],
					$agent_source_host,
					$agent_raw_host
				);
			}
```

Replace with:

```php
			if ( ! empty( $product['url'] ) ) {
				$product['url'] = WC_AI_Storefront_Multi_Currency::stamp_currency_query(
					$product['url'],
					self::get_request_currency( $request )
				);
				$product['url'] = WC_AI_Storefront_Attribution::with_woo_ucp_utm(
					$product['url'],
					$agent_source_host,
					$agent_raw_host
				);
			}
```

`$request` is the WP_REST_Request in scope at this site. Verify by reading the enclosing method signature; if the variable name differs, use the local name.

- [ ] **Step 6c.4: Run the test to confirm it passes**

Run: `vendor/bin/phpunit --filter=test_catalog_lookup_product_url_carries_currency_when_context_currency_in_accepted_set`
Expected: PASS.

- [ ] **Step 6c.5: Repeat the test + implementation at the catalog/search site**

Add to `tests/php/unit/UcpCatalogSearchTest.php`:

```php
	public function test_catalog_search_product_url_carries_currency_when_context_currency_in_accepted_set(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR' );
				}
				return $value;
			}
		);

		// Match the existing search-test fixture pattern.
		$response = $this->post_catalog_search( array(
			'query'   => 'widget',
			'context' => array( 'currency' => 'EUR' ),
		) );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'products', $data );
		$this->assertNotEmpty( $data['products'] );
		foreach ( $data['products'] as $product ) {
			if ( ! empty( $product['url'] ) ) {
				$this->assertStringContainsString(
					'currency=EUR',
					$product['url'],
					'Every product URL in a catalog/search response should carry the request currency'
				);
			}
		}
	}
```

Run the test, confirm it fails, then update the catalog/search site (around line 1842) with the same pre-UTM `stamp_currency_query` call as Step 6c.3.

- [ ] **Step 6c.6: Add the "unchanged when absent / not in set" guards**

Add to both `UcpCatalogLookupTest.php` and `UcpCatalogSearchTest.php`:

```php
	public function test_catalog_lookup_product_url_unchanged_when_context_currency_absent(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR' );
				}
				return $value;
			}
		);

		$response = $this->post_catalog_lookup( array(
			'product_ids' => array( 'wc:42' ),
			// No 'context' key.
		) );

		$data = $response->get_data();
		$this->assertStringNotContainsString( 'currency=', $data['products'][0]['url'] );
	}
```

And the corresponding test in `UcpCatalogSearchTest.php`. Run all four currency-related tests in both files:

Run: `vendor/bin/phpunit --filter='UcpCatalog(Lookup|Search)Test'`
Expected: PASS.

- [ ] **Step 6c.7: Commit**

```bash
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpCatalogLookupTest.php tests/php/unit/UcpCatalogSearchTest.php
git commit -m "feat(ucp): stamp ?currency= on product url in catalog/lookup and catalog/search

Threads context.currency through the controller's two
post-translation URL-stamping sites (around lines 1079 and 1842)
so per-product url fields carry ?currency=XXX ahead of the
existing UTM block when the agent's hint is in accepted_currencies.

The translator stays a pure function — agent-context side-effects
remain in the controller per issue #176.

Refs #404"
```

---

## Task 7: Refresh the i18n .pot template

**Files:**
- Modify: `languages/woocommerce-ai-storefront.pot`

The new `__()` call in Task 6 introduces a new translatable string. The `.pot` file must be regenerated so translators see it.

- [ ] **Step 7.1: Run the make-pot script**

Run: `./bin/make-pot.sh`
Expected: `languages/woocommerce-ai-storefront.pot` updated with the new `Accepted currencies` msgid.

- [ ] **Step 7.2: Inspect the diff**

Run: `git diff languages/woocommerce-ai-storefront.pot`
Expected: a new `msgid` block referencing the line in `class-wc-ai-storefront-llms-txt.php`. No other unrelated changes.

- [ ] **Step 7.3: Commit**

```bash
git add languages/woocommerce-ai-storefront.pot
git commit -m "i18n: refresh .pot for new Accepted currencies string

Refs #404"
```

---

## Task 8: Documentation pass

**Files:**
- Modify: `docs/engineering/HOOKS.md`
- Modify: `docs/engineering/API-REFERENCE.md`
- Modify: `docs/engineering/UCP-BUY-FLOW.md`
- Modify: `docs/engineering/JSON-LD-SCHEMA.md`
- Modify: `docs/engineering/ARCHITECTURE.md`
- Modify: `AGENTS.md`
- Modify: `.github/workflows/docs-followup.yml`

Engineering docs land in the implementation PR (per the path → doc map). USER-GUIDE.md and CHANGELOG.md wait for the pre-release pass per project convention — but the `[Unreleased]` CHANGELOG bullet is added now.

- [ ] **Step 8.1: Document the new filter in HOOKS.md**

Open `docs/engineering/HOOKS.md` and find the filters table. Add a row matching the existing table format:

```markdown
| `wc_ai_storefront_accepted_currencies` | Filter | Override the auto-detected list of accepted ISO-4217 currency codes (base currency first). Falls back to `[ base_currency ]` if the returned value is not an array, is empty, or contains no valid codes. | `array<int, string> $list` |
```

(Match the column ordering used by the existing rows in that file.)

- [ ] **Step 8.2: Document `accepted_currencies` AND the `?currency=` query param in API-REFERENCE.md**

Find the section that describes `store_context` (search for `store_context` in `docs/engineering/API-REFERENCE.md`). Add an `accepted_currencies` row to the field table:

```markdown
| `accepted_currencies` | `array<string>` | Ordered, deduplicated list of ISO-4217 currency codes the store accepts. Always at least one element. Base currency is always first. Mirrors `store_context.currency` when the store is single-currency; reflects the WooPayments enabled set when multi-currency is active. |
```

Find the sections describing `continue_url` (on `POST /checkout-sessions`) and product `url` (on `catalog/search` / `catalog/lookup` responses). Add a paragraph to each noting the `?currency=` stamping behavior:

> When the request body carries `context.currency` and the value is a member of `store_context.accepted_currencies`, the returned URL carries `?currency=XXX` ahead of the UTM block. This activates WooPayments' built-in currency switcher on the destination page so the buyer sees the requested currency on the merchant's checkout / product page. When `context.currency` is absent, malformed, or outside the accepted set, the URL is unchanged.

- [ ] **Step 8.3: Document the `?currency=` flow in UCP-BUY-FLOW.md**

Open `docs/engineering/UCP-BUY-FLOW.md`. Find the section describing `continue_url` construction. Add a paragraph (or amend the existing one) noting that the query string carries `?currency=XXX&products=...&utm_source=...&utm_id=woo_ucp` when the agent's `context.currency` is in `accepted_currencies`. If the doc has a flow diagram, add `?currency=` as a labeled query-param.

- [ ] **Step 8.4: Document the JSON-LD shape change AND the per-page free-win in JSON-LD-SCHEMA.md**

Find the homepage `OnlineBusiness` / `OnlineStore` documentation (search for `currenciesAccepted`). Update the field description to note the shape change:

> `currenciesAccepted` — space-separated string of ISO-4217 currency codes (Schema.org convention). Single-currency stores emit one code; multi-currency stores emit the full accepted set with the base currency first.

Find the per-product `priceCurrency` section. Add a new subsection:

> **Per-page currency reflection (WooPayments multi-currency).** When a crawler fetches a single-product page with a `?currency=XXX` query parameter, WooPayments' multi-currency feature switches `get_woocommerce_currency()` for that request before the JSON-LD enricher runs. As a result, every `priceCurrency` field on the page's Product JSON-LD (including the variant-level Offer skeletons and the subscription `priceSpecification` entries) reflects `XXX`, and every `price` reflects the converted amount. This is a free behavior — no plugin code change required. Crawlers that need a multi-currency index can fetch each product URL once per code in `currenciesAccepted` to build the full matrix.
>
> This does NOT apply to the homepage `OnlineBusiness.currenciesAccepted` field (a store-wide list, not a per-quote currency), the UCP manifest (a discovery file served outside the storefront page render), or UCP REST responses (the `/wp-json/wc/ucp/v1/...` path does not traverse WooPayments' page-level `?currency=` handler — that's Phase 2).

- [ ] **Step 8.5: Register the helper class in ARCHITECTURE.md**

Find the component overview section. Add a row for `WC_AI_Storefront_Multi_Currency`:

```markdown
| `WC_AI_Storefront_Multi_Currency` | Pure helper. Two public methods: `get_accepted_currencies()` (returns ordered ISO-4217 list, base first) and `stamp_currency_query()` (stamps `?currency=XXX` on outbound URLs when the request currency is in the accepted set). Called by the UCP manifest, JSON-LD homepage emitter, llms.txt generator, UCP REST controller (`build_continue_url` + per-product URL stamping). Filter: `wc_ai_storefront_accepted_currencies`. |
```

(Match the format of nearby rows.)

- [ ] **Step 8.6: Add the new code paths to AGENTS.md's path → doc map**

In `AGENTS.md`, find the "Path → doc impact map" table (line 154). Add two rows directly under the last `includes/ai-storefront/class-wc-ai-storefront-*.php` row:

```markdown
| `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php` | HOOKS.md, ARCHITECTURE.md, API-REFERENCE.md, JSON-LD-SCHEMA.md |
| `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` (currency stamping) | API-REFERENCE.md, UCP-BUY-FLOW.md, ARCHITECTURE.md |
```

(If the controller row already exists in the path map, extend its docs cell rather than adding a duplicate.)

- [ ] **Step 8.7: Mirror the rows in the docs-followup workflow**

Open `.github/workflows/docs-followup.yml`. Find the path → doc map (it's a list of `path:` / `docs:` pairs). Add matching entries directly mirroring AGENTS.md.

(If the YAML uses a different structure, follow the existing format — the goal is for an automated docs-followup PR to know which docs to inspect when the new file changes.)

- [ ] **Step 8.8: Add a CHANGELOG `[Unreleased]` bullet**

Open `CHANGELOG.md`. Under `[Unreleased]`, add (or extend) the `### Added` subsection following the project's bold-headline + nested-bullet convention:

```markdown
### Added

- **WooPayments multi-currency exposure across UCP, JSON-LD, and llms.txt.**
  - UCP manifest `store_context` gains an `accepted_currencies` array (base currency first).
  - Homepage `OnlineBusiness` JSON-LD `currenciesAccepted` becomes a space-separated list on multi-currency stores.
  - llms.txt gains an `**Accepted currencies**` line when more than one currency is enabled.
  - UCP `continue_url` and per-product `url` fields carry `?currency=XXX` when the agent sends `context.currency` in the accepted set, activating WooPayments' page-level currency switcher on the destination.
  - Per-product page JSON-LD already reflects `?currency=XXX` automatically (WooPayments handles the switch before our enricher runs).
  - New filter `wc_ai_storefront_accepted_currencies` for integrators using non-WooPayments multi-currency plugins.
  - Catalog response prices remain quoted in the store's base currency (live currency switching of UCP responses is Phase 2).
```

(Per the user's memory rule, the wording will be polished in the pre-release pass. The skeleton bullet exists now to keep the `changelog-required` CI gate green.)

- [ ] **Step 8.9: Commit the docs pass**

```bash
git add docs/engineering/HOOKS.md docs/engineering/API-REFERENCE.md docs/engineering/UCP-BUY-FLOW.md docs/engineering/JSON-LD-SCHEMA.md docs/engineering/ARCHITECTURE.md AGENTS.md .github/workflows/docs-followup.yml CHANGELOG.md
git commit -m "docs: document accepted_currencies, ?currency= URL stamping, and filter

- API-REFERENCE.md: new store_context.accepted_currencies field;
  ?currency= note on continue_url + product url
- UCP-BUY-FLOW.md: ?currency= stamping on continue_url
- HOOKS.md: new wc_ai_storefront_accepted_currencies filter
- JSON-LD-SCHEMA.md: space-separated currenciesAccepted shape;
  per-page ?currency= reflection free-win
- ARCHITECTURE.md: register WC_AI_Storefront_Multi_Currency
- AGENTS.md + docs-followup.yml: path map rows
- CHANGELOG.md: [Unreleased] skeleton bullet

Refs #404"
```

---

## Task 9: Full quality gate

**Files:** none modified directly; this task runs the CI gate locally.

Per `AGENTS.md`, every PR must pass the full quality gate before push. Run each step in order. Stop on any failure and fix the underlying issue before continuing.

- [ ] **Step 9.1: PHPUnit (full suite)**

Run: `composer test`
Expected: PASS — every test green, no skipped tests beyond the existing skip set.

- [ ] **Step 9.2: PHPCS**

Run: `vendor/bin/phpcs`
Expected: PASS — no warnings, no errors. Per the user's memory rule on `phpcbf`, if alignment-warning-style nits surface, run `vendor/bin/phpcbf` once and re-run `phpcs` to confirm clean.

- [ ] **Step 9.3: PHPStan**

Run: `vendor/bin/phpstan analyse --memory-limit=512M`
Expected: PASS — no new errors. The WooPayments class is a soft dependency; PHPStan may flag the FQN. If so, add the appropriate `ignoreErrors` entry to `phpstan.neon.dist` referencing the specific message and file, with a comment explaining the soft-dependency rationale.

- [ ] **Step 9.4: JS lint (no JS changed, but the gate runs it)**

Run: `npm run lint:js`
Expected: PASS — no JS files in this PR, the lint pass is a no-op.

- [ ] **Step 9.5: Build (no JS changed; verifies the bundle doesn't regress)**

Run: `npm run build`
Expected: PASS — webpack produces the existing bundles unchanged.

- [ ] **Step 9.6: .pot freshness**

Run: `./bin/make-pot.sh && git diff --exit-code languages/`
Expected: `git diff --exit-code` exits 0 (no further diff after Task 7's commit). If the script emits new changes, commit them as a separate "i18n: refresh .pot" commit.

- [ ] **Step 9.7: Commit any quality-gate fixes**

If steps 9.1–9.6 surfaced fixes, commit them as `chore: <specific-fix>` commits — one fix per commit. Skip this step if the gate was already clean.

---

## Task 10: Version bump and open the PR

**Files:**
- Modify: `woocommerce-ai-storefront.php`
- Modify: `package.json`
- Modify: `readme.txt`

This is a MINOR bump per the design spec. Determine the next version by reading the current version from `package.json` (e.g. `0.16.1` → `0.17.0`).

- [ ] **Step 10.1: Read the current version**

Run: `grep -E '"version"' package.json`
Note the current value. Compute the next MINOR (e.g. `0.16.1` → `0.17.0`).

- [ ] **Step 10.2: Bump version in the three places that must agree**

Per `AGENTS.md`'s versioning section, three places update simultaneously:

1. `woocommerce-ai-storefront.php`:
   - The `Version:` line in the plugin header.
   - The `WC_AI_STOREFRONT_VERSION` constant.

2. `package.json`: the `"version"` key.

3. `readme.txt`: the `Stable tag` field.

The `sed` recipe in `docs/engineering/RELEASE.md` is the canonical way to do this. Follow it.

- [ ] **Step 10.3: Verify the three versions match**

Run:

```bash
grep -E "Version:|WC_AI_STOREFRONT_VERSION" woocommerce-ai-storefront.php
grep -E '"version"' package.json
grep -E "Stable tag" readme.txt
```

Expected: all three show the same new MINOR version.

- [ ] **Step 10.4: Commit the version bump**

```bash
git add woocommerce-ai-storefront.php package.json readme.txt
git commit -m "chore: bump version to <new-version>

MINOR bump for WooPayments multi-currency exposure: advertised
on UCP / JSON-LD / llms.txt and honored as ?currency= on outbound
continue_url and product url responses (backwards-compatible).

Refs #404"
```

- [ ] **Step 10.5: Push the branch**

Run:

```bash
git push -u origin HEAD
```

- [ ] **Step 10.6: Open the PR**

The user's memory rule says: don't auto-trigger Copilot review on new PRs. Use `gh pr create` WITHOUT `--add-reviewer copilot-pull-request-reviewer`. Per `AGENTS.md`, target `main`.

```bash
gh pr create --base main --title "feat(ucp): expose WooPayments accepted currencies and honor on outbound URLs" --body "$(cat <<'EOF'
## Summary

Phase 1 of WooPayments multi-currency exposure. The plugin now (1) advertises the full set of accepted currencies on three machine-readable surfaces — UCP manifest, homepage JSON-LD, llms.txt — when WooPayments multi-currency is active, and (2) honors the agent's `context.currency` hint by stamping `?currency=XXX` on outbound `continue_url` and per-product `url` responses, activating WooPayments' page-level currency switcher on the destination.

Spec: [`docs/superpowers/specs/2026-05-15-woopayments-multicurrency-exposure-design.md`](docs/superpowers/specs/2026-05-15-woopayments-multicurrency-exposure-design.md)
Plan: [`docs/superpowers/plans/2026-05-15-woopayments-multicurrency-exposure.md`](docs/superpowers/plans/2026-05-15-woopayments-multicurrency-exposure.md)

## What changed

- New helper class `WC_AI_Storefront_Multi_Currency` (soft-dependency reader for WCPay) with two methods: `get_accepted_currencies()` and `stamp_currency_query()`.
- UCP manifest `store_context.accepted_currencies` (always at least `[ base ]`).
- Homepage `OnlineBusiness` JSON-LD `currenciesAccepted` becomes a space-separated list on multi-currency stores.
- llms.txt `**Accepted currencies**` line emitted when more than one currency is enabled, with a qualifier noting catalog response prices remain in the base currency.
- UCP `continue_url` (every variant: `/checkout-link/?products=`, bundle, grouped, variable-parent) and per-product `url` in `catalog/search` / `catalog/lookup` carry `?currency=XXX` ahead of the UTM block when `context.currency` is in the accepted set.
- New private controller helper `get_request_currency()` — single source of truth for the agent's currency hint, reused across handlers.
- New filter `wc_ai_storefront_accepted_currencies` for integrator overrides.
- Free win documented: single-product page JSON-LD already reflects `?currency=XXX` when WooPayments-multicurrency processes a crawler's query param.

## Out of scope (Phase 2)

- Quoting UCP catalog response prices in non-base currencies (`catalog/search`, `catalog/lookup`, line-item `selling_price`, etc.).
- Loosening the `currency_conversion_unsupported` warning on `filters.price`.
- Aligning `process_line_item` `expected_unit_price.currency` validation.

## Test plan

- [ ] `composer test` — full PHPUnit suite green.
- [ ] `vendor/bin/phpcs` — no PHPCS errors.
- [ ] `vendor/bin/phpstan analyse --memory-limit=512M` — no new PHPStan errors.
- [ ] Manual: visit `/wp-json/wc/store/v1/.well-known/ucp` on the dev environment; confirm `store_context.accepted_currencies` is present with `[ "USD" ]` on a single-currency store.
- [ ] Manual (multi-currency): enable WooPayments multi-currency with USD + EUR + GBP; confirm the manifest, homepage JSON-LD (`currenciesAccepted`), and llms.txt all reflect the three currencies.
- [ ] Manual: POST a `/checkout-sessions` request with `context.currency=EUR`; confirm the returned `continue_url` carries `?currency=EUR&products=...&utm_source=...&utm_id=woo_ucp`, and that opening it lands on a EUR checkout page.
- [ ] Manual: POST a `catalog/search` request with `context.currency=EUR`; confirm each product `url` carries `?currency=EUR`.
- [ ] Manual: POST any UCP request without `context.currency`; confirm URLs are unstamped (back-compat).
- [ ] Manual: visit a single-product page with `?currency=EUR`; confirm the rendered JSON-LD `priceCurrency` reflects `EUR` (free win, no plugin change).
- [ ] Manual: confirm the llms.txt qualifier is honest about base-currency catalog quoting.

Closes #404
EOF
)"
```

- [ ] **Step 10.7: Report the PR URL**

The `gh pr create` command prints the PR URL. Surface it to the user.

---

## Notes for the executing engineer

- **Test runner**: `composer test` is the canonical PHPUnit entry point. For a single test class, prefer `vendor/bin/phpunit --filter=ClassName` over editing `phpunit.xml.dist`.
- **Brain Monkey gotchas**: `Functions\when()` overrides for `apply_filters` are scoped per-test; the baseline `returnArg( 2 )` stub in setUp() means filters are no-ops unless a test explicitly installs a routing alias.
- **WCPay class stand-in**: only `MultiCurrencyTest.php` declares the namespaced stand-in. `UcpTest.php`, `JsonLdTest.php`, and `LlmsTxtTest.php` cover the multi-currency case via the filter override path — that's cleaner and keeps the test files focused.
- **Helper cache**: every test class that touches a code path calling `WC_AI_Storefront_Multi_Currency::get_accepted_currencies()` must reset the cache in setUp() or tearDown(). Forgetting this leaks state across tests.
- **WP-style alignment**: PHPCS enforces aligned `=>` in array literals. When adding `accepted_currencies` to the `store_context` array, the longest key shifts — all aligned `=>` in the same block re-align. The Edit tool's exact-string matching handles this fine if you replace the full block in one operation.
- **No em-dashes in merchant-facing copy**: the llms.txt qualifier sticks to commas and parentheses per `AGENTS.md`. Don't reintroduce em-dashes during a copy-edit pass.
