# WooPayments Multi-Currency Exposure (Phase 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish the full list of currencies a store accepts (via WooPayments multi-currency) on the UCP manifest, homepage JSON-LD, and llms.txt. Phase 1 advertises only; catalog prices remain base-currency-quoted.

**Architecture:** A new pure helper class `WC_AI_Storefront_Multi_Currency` owns currency detection. It is a soft-dependency reader — `class_exists`-guarded against WooPayments — and exposes one method, `get_accepted_currencies()`. Three existing classes (`WC_AI_Storefront_Ucp`, `WC_AI_Storefront_JsonLd`, `WC_AI_Storefront_Llms_Txt`) call into it. A new filter `wc_ai_storefront_accepted_currencies` lets integrators override the auto-detected list. Output shape is stable across single- and multi-currency states (always at least `[ base ]`).

**Tech Stack:** PHP 7.4+, WordPress + Automattic coding standards (tabs, Yoda, `array()`, `===`), PHPUnit + Brain Monkey + Mockery for tests. No real WordPress install required.

**Spec:** [`docs/superpowers/specs/2026-05-15-woopayments-multicurrency-exposure-design.md`](../specs/2026-05-15-woopayments-multicurrency-exposure-design.md). GitHub issue: [#404](https://github.com/Automattic/woocommerce-ai-storefront/issues/404).

---

## File Structure

**New files:**
- `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php` — the helper class. Single responsibility: detect WooPayments enabled currencies and return a normalized list.
- `tests/php/unit/MultiCurrencyTest.php` — PHPUnit coverage for the helper.

**Modified files:**
- `includes/autoload.php` — register the new class in the classmap.
- `includes/ai-storefront/class-wc-ai-storefront-ucp.php` — `build_store_context()` adds `accepted_currencies`.
- `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` — `output_store_jsonld()` switches `currenciesAccepted` from a single code to a space-separated list.
- `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` — `generate()` appends the new `**Accepted currencies**` line conditionally.
- `tests/php/unit/UcpTest.php` — update the strict key-set guard at line 590 and add multi-currency coverage.
- `tests/php/unit/JsonLdTest.php` — add `currenciesAccepted` multi-currency assertions.
- `tests/php/unit/LlmsTxtTest.php` — add accepted-currencies line assertions.
- `docs/engineering/HOOKS.md` — document the new filter.
- `docs/engineering/API-REFERENCE.md` — document `accepted_currencies` on `store_context`.
- `docs/engineering/JSON-LD-SCHEMA.md` — document the new `currenciesAccepted` shape.
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

- [ ] **Step 8.2: Document `accepted_currencies` in API-REFERENCE.md**

Find the section that describes `store_context` (search for `store_context` in `docs/engineering/API-REFERENCE.md`). Add an `accepted_currencies` row to the field table:

```markdown
| `accepted_currencies` | `array<string>` | Ordered, deduplicated list of ISO-4217 currency codes the store accepts. Always at least one element. Base currency is always first. Mirrors `store_context.currency` when the store is single-currency; reflects the WooPayments enabled set when multi-currency is active. |
```

- [ ] **Step 8.3: Document the JSON-LD shape change in JSON-LD-SCHEMA.md**

Find the homepage `OnlineBusiness` / `OnlineStore` documentation (search for `currenciesAccepted`). Update the field description to note the shape change:

> `currenciesAccepted` — space-separated string of ISO-4217 currency codes (Schema.org convention). Single-currency stores emit one code; multi-currency stores emit the full accepted set with the base currency first.

- [ ] **Step 8.4: Register the helper class in ARCHITECTURE.md**

Find the component overview section. Add a row for `WC_AI_Storefront_Multi_Currency`:

```markdown
| `WC_AI_Storefront_Multi_Currency` | Pure helper. Detects WooPayments multi-currency and returns the ordered list of accepted ISO-4217 codes. Called by the UCP manifest, JSON-LD homepage emitter, and llms.txt generator. Filter: `wc_ai_storefront_accepted_currencies`. |
```

(Match the format of nearby rows.)

- [ ] **Step 8.5: Add the new code path to AGENTS.md's path → doc map**

In `AGENTS.md`, find the "Path → doc impact map" table (line 154). Add a row directly under the last `includes/ai-storefront/class-wc-ai-storefront-*.php` row:

```markdown
| `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php` | HOOKS.md, ARCHITECTURE.md, API-REFERENCE.md, JSON-LD-SCHEMA.md |
```

- [ ] **Step 8.6: Mirror the row in the docs-followup workflow**

Open `.github/workflows/docs-followup.yml`. Find the path → doc map (it's a list of `path:` / `docs:` pairs). Add a matching entry directly mirroring AGENTS.md.

(If the YAML uses a different structure, follow the existing format — the goal is for an automated docs-followup PR to know which docs to inspect when the new file changes.)

- [ ] **Step 8.7: Add a CHANGELOG `[Unreleased]` bullet**

Open `CHANGELOG.md`. Under `[Unreleased]`, add (or extend) the `### Added` subsection following the project's bold-headline + nested-bullet convention:

```markdown
### Added

- **WooPayments multi-currency exposure on machine-readable surfaces.**
  - UCP manifest `store_context` gains an `accepted_currencies` array (base currency first).
  - Homepage `OnlineBusiness` JSON-LD `currenciesAccepted` becomes a space-separated list on multi-currency stores.
  - llms.txt gains an `**Accepted currencies**` line when more than one currency is enabled.
  - New filter `wc_ai_storefront_accepted_currencies` for integrators using non-WooPayments multi-currency plugins.
  - Catalog prices remain quoted in the store's base currency (live currency switching is Phase 2).
```

(Per the user's memory rule, the wording will be polished in the pre-release pass. The skeleton bullet exists now to keep the `changelog-required` CI gate green.)

- [ ] **Step 8.8: Commit the docs pass**

```bash
git add docs/engineering/HOOKS.md docs/engineering/API-REFERENCE.md docs/engineering/JSON-LD-SCHEMA.md docs/engineering/ARCHITECTURE.md AGENTS.md .github/workflows/docs-followup.yml CHANGELOG.md
git commit -m "docs: document accepted_currencies + wc_ai_storefront_accepted_currencies filter

- API-REFERENCE.md: new store_context.accepted_currencies field
- HOOKS.md: new wc_ai_storefront_accepted_currencies filter
- JSON-LD-SCHEMA.md: space-separated currenciesAccepted shape note
- ARCHITECTURE.md: register WC_AI_Storefront_Multi_Currency
- AGENTS.md + docs-followup.yml: path map row for the new file
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

MINOR bump for WooPayments multi-currency exposure on UCP /
JSON-LD / llms.txt (backwards-compatible).

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
gh pr create --base main --title "feat(ucp): expose WooPayments accepted currencies on UCP, JSON-LD, llms.txt" --body "$(cat <<'EOF'
## Summary

Phase 1 of WooPayments multi-currency exposure. The plugin now advertises the full set of accepted currencies on three machine-readable surfaces — UCP manifest, homepage JSON-LD, and llms.txt — when WooPayments multi-currency is active.

Spec: [`docs/superpowers/specs/2026-05-15-woopayments-multicurrency-exposure-design.md`](docs/superpowers/specs/2026-05-15-woopayments-multicurrency-exposure-design.md)
Plan: [`docs/superpowers/plans/2026-05-15-woopayments-multicurrency-exposure.md`](docs/superpowers/plans/2026-05-15-woopayments-multicurrency-exposure.md)

## What changed

- New helper class `WC_AI_Storefront_Multi_Currency` (soft-dependency reader for WCPay).
- UCP manifest `store_context.accepted_currencies` (always at least `[ base ]`).
- Homepage `OnlineBusiness` JSON-LD `currenciesAccepted` becomes a space-separated list on multi-currency stores.
- llms.txt `**Accepted currencies**` line emitted when more than one currency is enabled, with a qualifier noting catalog prices remain in the base currency.
- New filter `wc_ai_storefront_accepted_currencies` for integrator overrides.

## Out of scope (Phase 2)

- Live currency switching on UCP product/checkout endpoints.
- Quoting catalog prices in non-base currencies.

## Test plan

- [ ] `composer test` — full PHPUnit suite green.
- [ ] `vendor/bin/phpcs` — no PHPCS errors.
- [ ] `vendor/bin/phpstan analyse --memory-limit=512M` — no new PHPStan errors.
- [ ] Manual: visit `/wp-json/wc/store/v1/.well-known/ucp` on the dev environment; confirm `store_context.accepted_currencies` is present with `[ "USD" ]` on a single-currency store.
- [ ] Manual (multi-currency): enable WooPayments multi-currency with USD + EUR + GBP; confirm the manifest, homepage JSON-LD (`currenciesAccepted`), and llms.txt all reflect the three currencies.
- [ ] Manual: confirm the llms.txt qualifier is honest about base-currency quoting.

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
