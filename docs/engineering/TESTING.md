# Testing Strategy

How we test WooCommerce AI Storefront — what we test, where it lives, and how to add to it without breaking the suite.

## TL;DR

- **PHP tests** — PHPUnit 10 + Brain Monkey + Mockery, no WordPress install required. ~38 test files, ~900+ test methods. Run via `composer test` or `vendor/bin/phpunit`.
- **JS tests** — `@wordpress/scripts test-unit-js` (Jest under the hood). Jest tests live next to the data store and exist primarily for reducer/selector logic, not React components.
- **Static analysis** — PHPStan level 5 (`vendor/bin/phpstan analyse`), PHPCS WordPress-Extra standard (`vendor/bin/phpcs`).
- **CI** — GitHub Actions, matrix on PHP 8.1/8.2/8.3/8.4, fails closed.

Every PR runs the full quality gate. If you need to ship without one of the gates passing, that's a decision a reviewer makes — not a default.

## Why no real WordPress install

The PHP suite uses **Brain Monkey** to mock WordPress functions instead of bootstrapping a real WordPress test environment (`wp-phpunit` / `WP_UnitTestCase`). The trade-offs:

| Approach | Pros | Cons |
|----------|------|------|
| Brain Monkey (what we use) | Fast (sub-second per test class), no MySQL, no WP install, deterministic. CI matrix is just PHP versions. | Tests can drift from real WP behavior if you mock wrong. Stubs need maintenance. |
| `wp-phpunit` integration | Real WP, real DB, exercises the full hook graph. | 10–100× slower, needs MySQL in CI, harder to parallelize. |

For a plugin this size and scope, Brain Monkey wins. Tests stay fast enough that running the full suite on save (~3–5 seconds) is the default workflow. The risk — that a function is mocked incorrectly and the test passes against wrong assumptions — is mitigated by:

1. Centralizing WordPress and WooCommerce stubs in `tests/php/stubs.php` (a single source of truth — when WP behavior shifts, one file changes).
2. Treating stubs as production code: anyone editing them needs to verify against actual WP source, not vibes.
3. Smoke-testing manually in a real WordPress install before each release.

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
    ├── AttributionTest.php
    ├── AttributionDeriveStatsTest.php
    ├── CacheInvalidatorTest.php
    ├── IsSyndicatedTagsBrandsTest.php
    ├── IsSyndicatedUnionTest.php
    ├── IsSyndicatedVariationTest.php
    ├── JsonLdTest.php
    ├── JsonLdNormalizationTest.php
    ├── JsonLdReturnPolicyTest.php
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

Naming: `<UnitOfBehaviorBeingTested>Test.php`. Singular, PascalCase, no underscores. One file per production class is the default; split into multiple files when one class has clearly distinct behavior surfaces (e.g. `AttributionTest` vs `AttributionDeriveStatsTest`, both exercising the same class but partitioning the test methods by concern).

## Running tests

### Full suite

```bash
composer test                  # PHPUnit (~900 tests, ~3 seconds)
npm run test:js                # Jest (data-store tests, <1 second)
```

### Filtering

```bash
# Single class
vendor/bin/phpunit --filter AttributionTest

# Single method
vendor/bin/phpunit --filter test_capture_detects_ai_medium_from_order_meta

# By group / dir
vendor/bin/phpunit tests/php/unit/UcpRestControllerTest.php
```

### Watch mode (PHP)

PHPUnit 10 doesn't ship watch mode natively. Common options:

```bash
# entr-based loop (Linux/macOS):
ls includes/**/*.php tests/php/**/*.php | entr -c vendor/bin/phpunit
```

If you find yourself running the suite by hand more than three times in a row, set up the watch loop — the round-trip is short enough that it stays useful.

## Test conventions

### Anatomy of a test

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

1. **Brain Monkey is opt-in per test class.** `Monkey\setUp()` and `Monkey\tearDown()` bracket every test. Without them, function expectations leak between tests and you'll spend an afternoon chasing flakes.
2. **Mockery integration via the trait.** `MockeryPHPUnitIntegration` ensures Mockery expectations are verified at the end of each test and that unmet expectations fail loudly.
3. **`$_GET` is reset.** The plugin reads `$_GET` for attribution capture in some code paths. Reset it explicitly in `setUp` and `tearDown` so test order can't matter.

### Naming test methods

`test_<what>_<under_what_conditions>_<expected_outcome>` — snake_case, descriptive enough to read in a CI log without needing the file open.

Good:
- `test_capture_detects_ai_medium_from_order_meta`
- `test_check_agent_access_blocks_unknown_host_when_flag_disabled`
- `test_get_settings_silent_migration_normalizes_legacy_categories_value`

Avoid:
- `test_attribution` (too vague)
- `testCapture` (camelCase doesn't match the suite convention)
- `test_it_works` (no info content)

### When to add a new test class

New file when:

- You're testing a new production class.
- Existing test files for the class are over ~600 lines and a clear behavior boundary exists (e.g. "REST handlers" vs "data validation").

Same file when:

- Adding methods to an existing behavior surface.
- The bug fix is ≤ 3 new tests reinforcing existing coverage.

### Testing private methods

Don't. Test the public surface that exercises the private method. If a private method has logic complex enough to warrant direct testing, that's a signal to extract it into its own class with a public surface.

Exception: `Reflection`-based access is acceptable when the public surface genuinely can't reach a code path (e.g. a defensive guard against a state that requires a specific WP execution context). Use sparingly — every reflection call is fragile against refactors.

### Testing REST handlers

REST handlers are testable as plain methods — instantiate the controller, build a `WP_REST_Request` (the stub in `tests/php/stubs.php` has a working implementation), call the handler, assert on the `WP_REST_Response`.

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

UCP REST tests follow the same pattern. The stub `WP_REST_Request` supports `get_param`, `get_body_params`, and `get_header` — sufficient for every test in the suite.

### Mocking WordPress and WooCommerce

Brain Monkey gives you three primitives:

- `Functions\when( 'fn' )->justReturn( $value )` — stub returns a fixed value, no expectation.
- `Functions\expect( 'fn' )->once()->with( $arg )->andReturn( $value )` — stub plus expectation; fails if not called exactly once with the given arg.
- `Functions\stubs( [ 'fn1', 'fn2' ] )` — stub multiple functions to return their first argument (default Brain Monkey behavior).

Use `when` for setup, `expect` for the assertion you actually care about. Over-expecting (every WP call gets `expect`) makes tests brittle against refactors that change the order of calls without changing the outcome.

### Stubbing `WC_Product`, `WC_Order`, `WP_REST_Request`

These live in `tests/php/stubs.php`. Each stub is a real PHP class — not a mock — because tests want to instantiate them directly, set state on them, and read it back. When WP or WC adds a method we depend on, add it to the stub. If you find yourself reaching for `Mockery::mock( WC_Order::class )`, ask first whether the stub is missing the method.

### Anti-patterns to avoid

- **Don't `setUp()` your way into a fixture explosion.** If `setUp` is more than 30 lines, the test class is testing too many things at once. Split it.
- **Don't share state across tests via class properties.** If `test_a` mutates `$this->order`, `test_b` runs in unpredictable order and may see the mutation. Build fresh objects per test.
- **Don't assert on incidental state.** If you're testing `capture_ai_attribution`, assert that the right meta was written. Don't also assert that `do_action` was fired with three other arguments unless that's the test.
- **Don't assert on log output.** Logs are observability, not contracts. Assert on the side effect, not the log line.

## JS tests

The Jest suite is small and intentional. We test:

- The Redux reducer (state transitions for known actions).
- Selectors (pure functions over state).
- Async thunks (resolvers/actions that hit the admin REST API).

We do **not** test:

- React component rendering with React Testing Library or similar. Components are exercised manually during PR review with real plugin loaded in a WordPress dev install. The investment per JSX test is high; the regression-catching value is low for a plugin where the UI is a thin layer over a well-tested data store.

If you add a Jest test, keep it pure: stub `apiFetch` and assert on dispatched actions or selector return values. Don't reach for `enzyme`, `@testing-library`, or DOM mounting.

## Static analysis

### PHPStan

```bash
vendor/bin/phpstan analyse --memory-limit=512M
```

Runs at level 5. Configuration: [`phpstan.neon.dist`](../../phpstan.neon.dist).

PHPStan won't see WooCommerce internals (we don't depend on WC source in dev) — when it complains about a WC function, the fix is to add a narrow ignore in `phpstan.neon.dist`, matched by name pattern. **Never blanket-suppress** — every ignore should name the specific symbol it's silencing.

### PHPCS

```bash
vendor/bin/phpcs              # check
vendor/bin/phpcbf             # auto-fix what's auto-fixable
```

Configuration: [`phpcs.xml.dist`](../../phpcs.xml.dist). Standard is **WordPress-Extra** plus plugin-specific prefix declarations.

When `$wpdb` interpolation triggers a sniff (e.g. `{$table}` for a hardcoded table name), wrap the specific query in `phpcs:disable` / `phpcs:enable` comments scoped to that query — not the whole method.

### JS lint

```bash
npm run lint:js               # check
npm run lint:js -- --fix      # auto-fix
```

Standard is `@wordpress/scripts` lint config — `@wordpress/eslint-plugin` defaults.

## CI

Defined in [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml). Every push to `main` and every PR runs:

- PHPUnit, matrix on PHP 8.1, 8.2, 8.3, 8.4
- Jest (JS tests)
- ESLint (`npm run lint:js`)
- PHPCS (with `cs2pr` for inline annotations)
- PHPStan (level 5, with GitHub error format)
- i18n freshness — regenerates the `.pot` template and fails if the committed version is stale

All gates fail closed. There is no "non-blocking" job in the matrix.

## Coverage

We don't track coverage as a metric. We track behavior coverage — every shipped behavior has at least one test that fails when the behavior breaks.

The standard for new features:

1. The happy path is tested.
2. At least one error/edge case is tested.
3. Bug fixes include a regression test that fails without the fix.

If you want a coverage report locally, PHPUnit will produce one with PCOV or Xdebug installed:

```bash
vendor/bin/phpunit --coverage-html coverage/
```

Don't commit coverage reports. They're noise in `git status` and they go stale immediately.

## Adding a test — checklist

1. Decide: new file or extend existing?
2. Mirror the file's existing structure: imports, `MockeryPHPUnitIntegration`, `Monkey\setUp`/`tearDown`.
3. Pick a snake_case test name that reads as a sentence in a CI log.
4. Build the smallest input that exercises the behavior; assert on the smallest observable outcome.
5. Run `vendor/bin/phpunit --filter <YourNewTestName>` until green.
6. Run the full suite (`composer test`). Make sure nothing else broke.
7. Run `vendor/bin/phpcs tests/php/unit/<YourFile>.php` — coding standards apply to tests too.

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — what each component does and where it lives
- [`API-REFERENCE.md`](API-REFERENCE.md) — endpoint shapes that REST tests assert against
- [`DATA-MODEL.md`](DATA-MODEL.md) — option keys, meta keys, and transients tests verify
- [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md) — the protocol-level flow that several UCP tests verify end to end
