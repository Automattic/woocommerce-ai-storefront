# Testing Strategy

How we test WooCommerce AI Storefront — what we test, where it lives, and how to add to it without breaking the suite.

## TL;DR

- **PHP** — PHPUnit 10 + Brain Monkey + Mockery. No WordPress install required (Brain Monkey mocks WP/WC functions). ~38 test files, 920+ test methods. Run via `composer test`.
- **JS** — `@wordpress/scripts test-unit-js` (Jest). Covers the `@wordpress/data` store (reducer, selectors, async thunks). React components are validated manually in PR review.
- **Static analysis** — PHPStan level 5, PHPCS WordPress-Extra.
- **CI** — GitHub Actions matrix on PHP 8.1/8.2/8.3/8.4. All gates fail closed.

## File layout

```
tests/php/
├── bootstrap.php                       # PHPUnit bootstrap; pulls in stubs and plugin classes
├── stubs.php                           # WP_Error, WP_REST_Request/Response, WC_Product/Order, etc.
├── stubs/
│   └── class-wc-ai-storefront-stub.php # Stub of the main plugin class (settings accessors)
└── unit/                               # All test classes — flat, no subdirectories
    ├── ActivationTest.php
    ├── AdminPolicyPagesTest.php
    ├── AdminProductCountTest.php
    ├── AdminRecentOrdersTest.php
    ├── AdminReturnPolicyTest.php
    ├── AdminSearchTaxonomyTest.php
    ├── AttributionDeriveStatsTest.php
    ├── AttributionTest.php
    ├── CacheInvalidatorTest.php
    ├── IsSyndicatedTagsBrandsTest.php
    ├── IsSyndicatedUnionTest.php
    ├── IsSyndicatedVariationTest.php
    ├── JsonLdNormalizationTest.php
    ├── JsonLdReturnPolicyTest.php
    ├── JsonLdTest.php
    ├── LlmsTxtTest.php
    ├── LoggerTest.php
    ├── ProductMetaBoxTest.php
    ├── RobotsTest.php
    ├── SettingsMigrationTest.php
    ├── StoreApiExtensionTest.php
    ├── StoreApiRateLimiterTest.php
    ├── UcpAgentAccessGateTest.php
    ├── UcpAgentHeaderTest.php
    ├── UcpCatalogLookupTest.php
    ├── UcpCatalogSearchTest.php
    ├── UcpCheckoutPostureTest.php
    ├── UcpCheckoutSessionsTest.php
    ├── UcpCheckoutSessionsUnsupportedMethodTest.php
    ├── UcpEnvelopeTest.php
    ├── UcpProductTranslatorTest.php
    ├── UcpRestControllerTest.php
    ├── UcpStoreApiFilterTest.php
    ├── UcpStoreApiPreGetPostsTest.php
    ├── UcpTest.php
    ├── UcpVariantTranslatorTest.php
    ├── UpdateSettingsSanitizationTest.php
    └── UpdaterTest.php

client/data/ai-storefront/__tests__/    # Jest tests for the @wordpress/data store
├── actions.test.js
├── reducer.test.js
└── selectors.test.js
```

Naming: `<UnitOfBehaviorBeingTested>Test.php`. PascalCase, no underscores. One file per production class is the default; split into multiple files when one class has clearly distinct behavior surfaces (`AttributionTest` vs `AttributionDeriveStatsTest`).

## Running tests

```bash
composer test                  # PHPUnit (~3 seconds)
npm run test:js                # Jest (<1 second)

vendor/bin/phpunit --filter AttributionTest             # single class
vendor/bin/phpunit --filter test_capture_detects_ai_medium_from_order_meta  # single method
vendor/bin/phpunit tests/php/unit/UcpRestControllerTest.php                 # single file
```

PHPUnit 10 doesn't ship watch mode. Use `entr`:

```bash
ls includes/**/*.php tests/php/**/*.php | entr -c vendor/bin/phpunit
```

## Test conventions

### Anatomy

```php
<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class AttributionTest extends \PHPUnit\Framework\TestCase {
    use MockeryPHPUnitIntegration;

    private WC_AI_Storefront_Attribution $attribution;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->attribution = new WC_AI_Storefront_Attribution();
        $_GET = [];
    }

    protected function tearDown(): void {
        $_GET = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_capture_detects_ai_medium_from_order_meta(): void {
        $order = new WC_Order();
        $order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
        $order->set_test_meta( '_wc_order_attribution_utm_source', 'chatgpt' );

        Functions\expect( 'do_action' )->once();

        $this->attribution->capture_ai_attribution( $order );

        $this->assertTrue( $order->was_saved() );
    }
}
```

Three things to notice:

1. **Brain Monkey is opt-in per test class.** `Monkey\setUp()` and `Monkey\tearDown()` bracket every test. Without them, function expectations leak between tests.
2. **`MockeryPHPUnitIntegration` trait.** Verifies Mockery expectations at the end of each test; unmet expectations fail loudly.
3. **`$_GET` is reset.** The plugin reads `$_GET` for attribution capture in some code paths. Reset in both `setUp` and `tearDown` so test order can't matter.

### Naming test methods

`test_<what>_<under_what_conditions>_<expected_outcome>` — snake_case, descriptive enough to read in a CI log without opening the file.

Good:
- `test_capture_detects_ai_medium_from_order_meta`
- `test_check_agent_access_blocks_unknown_host_when_flag_disabled`
- `test_get_settings_silent_migration_normalizes_legacy_categories_value`

Avoid:
- `test_attribution` — too vague
- `testCapture` — camelCase doesn't match the suite
- `test_it_works` — no info content

### When to add a new test class

New file: testing a new production class, OR existing test files for that class are over ~600 lines and a clear behavior boundary exists.

Same file: adding methods to an existing behavior surface, OR a bug fix is ≤ 3 new tests reinforcing existing coverage.

### Testing private methods

Don't. Test the public surface that exercises them. If a private method has logic complex enough to warrant direct testing, that's a signal to extract it into its own class with a public surface.

`Reflection`-based access is acceptable when the public surface genuinely can't reach a code path (e.g. a defensive guard requiring a specific WP execution context). Use sparingly — every reflection call is fragile against refactors.

### Testing REST handlers

Instantiate the controller, build a `WP_REST_Request` (the stub in `tests/php/stubs.php` has a working implementation), call the handler, assert on the `WP_REST_Response`.

```php
public function test_get_settings_returns_merchant_settings_payload(): void {
    Functions\when( 'get_option' )->justReturn( [ 'enabled' => 'yes' ] );

    $controller = new WC_AI_Storefront_Admin_Controller();
    $response   = $controller->get_settings();

    $this->assertSame( 200, $response->get_status() );
    $data = $response->get_data();
    $this->assertSame( 'yes', $data['enabled'] );
}
```

The stub `WP_REST_Request` supports `get_param`, `get_body_params`, and `get_header` — sufficient for every test in the suite.

### Mocking WordPress and WooCommerce

Brain Monkey gives you three primitives:

- `Functions\when( 'fn' )->justReturn( $value )` — stub returns a fixed value, no expectation.
- `Functions\expect( 'fn' )->once()->with( $arg )->andReturn( $value )` — stub plus expectation; fails if not called exactly once with the given arg.
- `Functions\stubs( [ 'fn1', 'fn2' ] )` — stub multiple functions to return their first argument.

Use `when` for setup, `expect` for the assertion you actually care about. Over-expecting (every WP call gets `expect`) makes tests brittle against refactors.

### Stubbing `WC_Product`, `WC_Order`, `WP_REST_Request`

Real PHP classes in `tests/php/stubs.php` — not Mockery mocks — because tests want to instantiate them, set state, and read it back. When WP or WC adds a method we depend on, add it to the stub. Reach for `Mockery::mock( WC_Order::class )` only after confirming the stub is missing the method.

### Anti-patterns

- **Don't `setUp()` your way into a fixture explosion.** > 30 lines = the test class is testing too many things. Split it.
- **Don't share state across tests via class properties.** Build fresh objects per test.
- **Don't assert on incidental state.** Test what the change is supposed to do, not adjacent observables.
- **Don't assert on log output.** Logs are observability, not contracts. Assert on the side effect.

## JS tests

We test:

- The Redux reducer (state transitions for known actions).
- Selectors (pure functions over state).
- Async thunks (resolvers/actions that hit the admin REST API).

We do **not** test React component rendering. Components are validated manually in PR review against a real plugin loaded into a WordPress dev install. Per-JSX test investment is high; regression-catching value is low for a UI that's a thin layer over a well-tested data store.

If you add a Jest test, keep it pure: stub `apiFetch`, assert on dispatched actions or selector return values. No `enzyme`, no `@testing-library`, no DOM mounting.

## Static analysis

```bash
vendor/bin/phpstan analyse --memory-limit=512M  # level 5
vendor/bin/phpcs                                # check
vendor/bin/phpcbf                               # auto-fix
npm run lint:js                                 # JS lint
npm run lint:js -- --fix                        # JS lint auto-fix
```

PHPStan won't see WooCommerce internals — when it complains about a WC function, add a narrow `ignoreErrors` entry in `phpstan.neon.dist`, matched by name pattern. **Never blanket-suppress.**

PHPCS uses `WordPress-Extra` + plugin-specific prefix declarations. When `$wpdb` interpolation triggers a sniff (e.g. `{$table}` for a hardcoded table name), wrap that specific query in `phpcs:disable` / `phpcs:enable` — not the whole method.

JS lint is `@wordpress/scripts` defaults.

## CI

Defined in [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml). Every push to `main` and every PR runs:

- PHPUnit (matrix: PHP 8.1, 8.2, 8.3, 8.4)
- Jest (JS tests)
- ESLint (`npm run lint:js`)
- PHPCS (with `cs2pr` for inline annotations)
- PHPStan (level 5, GitHub error format)
- i18n freshness — regenerates the `.pot` template and fails if the committed version is stale

All gates fail closed.

## Coverage

We don't track coverage as a metric. We track behavior coverage — every shipped behavior has at least one test that fails when it breaks.

Standard for new code:

1. Happy path tested.
2. At least one error/edge case tested.
3. Bug fixes include a regression test that fails without the fix.

Local coverage report (with PCOV or Xdebug):

```bash
vendor/bin/phpunit --coverage-html coverage/
```

Don't commit coverage reports.

## Adding a test — checklist

1. New file or extend existing?
2. Mirror the file's structure: imports, `MockeryPHPUnitIntegration`, `Monkey\setUp`/`tearDown`.
3. Snake_case test name that reads as a sentence in a CI log.
4. Smallest input that exercises the behavior; smallest observable outcome.
5. `vendor/bin/phpunit --filter <YourNewTestName>` until green.
6. `composer test` — make sure nothing else broke.
7. `vendor/bin/phpcs tests/php/unit/<YourFile>.php` — standards apply to tests too.

## Appendix: why no real WordPress install

The PHP suite uses **Brain Monkey** to mock WP/WC functions instead of bootstrapping `wp-phpunit`. Trade-off table:

| Approach | Pros | Cons |
|----------|------|------|
| Brain Monkey (what we use) | Sub-second per test class, no MySQL, deterministic. CI matrix is just PHP versions. | Tests can drift from real WP behavior if you mock wrong; stubs need maintenance. |
| `wp-phpunit` integration | Real WP, real DB, exercises the full hook graph. | 10–100× slower, needs MySQL in CI, harder to parallelize. |

For a plugin this size, Brain Monkey wins. The drift risk is mitigated by centralizing stubs in `tests/php/stubs.php`, treating them as production code, and smoke-testing in a real WordPress install before each release.

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — what each component does
- [`API-REFERENCE.md`](API-REFERENCE.md) — endpoint shapes that REST tests assert against
- [`DATA-MODEL.md`](DATA-MODEL.md) — option keys, meta keys, transients
- [`HOOKS.md`](HOOKS.md) — filters tests can stub or assert on
- [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md) — protocol flow exercised by several UCP tests
- [`../../CONTRIBUTING.md`](../../CONTRIBUTING.md) — full pre-PR quality gate
