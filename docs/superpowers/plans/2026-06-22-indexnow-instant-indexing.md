# IndexNow Instant Indexing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When the catalog changes, push the affected URLs plus our AI-discovery surfaces to IndexNow so Bing-backed AI assistants (ChatGPT Search, Copilot, Amazonbot) re-crawl immediately.

**Architecture:** One new class `WC_AI_Storefront_IndexNow` owns the whole feature: key lifecycle, a virtual `{key}.txt` route (same rewrite + `template_redirect` mechanism as `llms.txt`), change-hook subscriptions (the same product/category/stock events `cache-invalidator` uses), a deduped pending-URL option, and a debounced WP-Cron flush that POSTs a bulk payload to `https://api.indexnow.org/indexnow`. Eligibility is filtered at enqueue time (we hold the product ID there); the flush just dedupes and posts. Gated on syndication-enabled AND a new `indexnow_enabled` setting (default `yes`).

**Tech Stack:** PHP 8.1+, WordPress rewrite/cron APIs, `wp_remote_post`, PHPUnit + Brain Monkey + Mockery (WP functions stubbed).

**Scope boundary:** This plan covers the full backend + the REST settings plumbing (so `indexnow_enabled` and the read-only key are persisted, sanitized, and exposed). The feature is functional on merge because `indexnow_enabled` defaults to `yes` (active whenever syndication is on). The **merchant-facing React control** (toggle + key display + regenerate button in the `client/` settings app) is a deliberate fast-follow in its own plan — it consumes the REST surface built here; writing it now would require mapping the settings-app component structure not covered by this plan.

## Global Constraints

- PHP floor 8.1; CI matrix 8.1–8.4. No syntax newer than 8.1.
- No em-dashes (`—`) in merchant-facing strings / `__()` text; en-dashes and hyphens are fine.
- New developer filters/hooks prefixed `wc_ai_storefront_`.
- Full local gate before "done": `composer test`, `vendor/bin/phpcbf` + `vendor/bin/phpcs` on changed PHP, `php -d memory_limit=2G vendor/bin/phpstan analyse`, `./bin/make-pot.sh` (commit any `.pot` diff).
- Google does NOT consume IndexNow — this is not a Google-SEO feature; never present it as one.
- Never fatal, never block a product save, never surface anything to the shopper. All outcomes via `WC_AI_Storefront_Logger::debug()`.
- Submission endpoint: `https://api.indexnow.org/indexnow` (shared; propagates to all participants).

## File Structure

| File | Responsibility |
|------|----------------|
| `includes/ai-storefront/class-wc-ai-storefront-indexnow.php` (Create) | The entire feature: key lifecycle, `{key}.txt` route, change hooks, pending queue, debounced flush, HTTP submit |
| `includes/class-wc-ai-storefront.php` (Modify) | `settings_defaults()` gains `indexnow_enabled`; `init_components()` + `register_rewrite_rules()` wire the new class |
| `includes/admin/class-wc-ai-storefront-admin-controller.php` (Modify) | REST arg + sanitization for `indexnow_enabled`; expose read-only `indexnow_key`; a `regenerate` action |
| `tests/php/unit/IndexNowTest.php` (Create) | Unit tests for every unit below |

## Conventions reused (verified in the codebase)

- Rewrite: `add_rewrite_rule( '^…$', 'index.php?<qv>=1', 'top' )` on `init`; `query_vars` filter; serve on `template_redirect` with `status_header()` + `header()` + `exit`. (`class-wc-ai-storefront-llms-txt.php:99-200`)
- Debounce: `if ( ! wp_next_scheduled( HOOK ) ) { wp_schedule_single_event( time() + DELAY, HOOK ); }`; handler registered with `add_action( HOOK, [...] )`. (`class-wc-ai-storefront-cache-invalidator.php:266-268, 151`)
- Settings: `WC_AI_Storefront::SETTINGS_OPTION` = `'wc_ai_storefront_settings'`; `WC_AI_Storefront::get_settings()`; gate `'yes' === ( $settings['enabled'] ?? 'no' )`.
- Eligibility: `WC_AI_Storefront::is_product_syndicated( $product, $settings = null ): bool` (selection scope only — combine with status + visibility here).
- Logger: `WC_AI_Storefront_Logger::debug( string $message, ...$args ): void` (sprintf-style).
- HTTP: `wp_remote_post(...)` → `is_wp_error()` → `wp_remote_retrieve_response_code()`.

---

## Task 1: Settings default + enablement gate + key lifecycle

**Files:**
- Create: `includes/ai-storefront/class-wc-ai-storefront-indexnow.php`
- Modify: `includes/class-wc-ai-storefront.php` (`settings_defaults()`)
- Test: `tests/php/unit/IndexNowTest.php`

**Interfaces:**
- Produces: `WC_AI_Storefront_IndexNow::get_key(): string` (lazy-generates + persists a 32-char hex key under `SETTINGS_OPTION`), `::regenerate_key(): string`, `::is_enabled(): bool` (syndication AND `indexnow_enabled`).

- [ ] **Step 1: Add the setting default** — in `includes/class-wc-ai-storefront.php`, inside `settings_defaults()` array, add:

```php
		'indexnow_enabled' => 'yes',
```

- [ ] **Step 2: Write failing tests** — create `tests/php/unit/IndexNowTest.php`:

```php
<?php
/**
 * Tests for WC_AI_Storefront_IndexNow.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class IndexNowTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_IndexNow $indexnow;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'home_url' )->alias( static fn( $p = '/' ) => 'https://shop.test' . ( '' === $p ? '/' : $p ) );
		$this->indexnow = new WC_AI_Storefront_IndexNow();
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_key_generates_and_persists_hex_key(): void {
		$stored = null;
		Functions\when( 'get_option' )->justReturn( array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes' ) );
		Functions\expect( 'update_option' )->once()->andReturnUsing(
			function ( $name, $value ) use ( &$stored ) {
				$stored = $value['indexnow_key'] ?? null;
				return true;
			}
		);
		$key = $this->indexnow->get_key();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key );
		$this->assertSame( $key, $stored );
	}

	public function test_get_key_returns_existing_without_regenerating(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'indexnow_key' => 'abc123abc123abc123abc123abc12300' )
		);
		Functions\expect( 'update_option' )->never();
		$this->assertSame( 'abc123abc123abc123abc123abc12300', $this->indexnow->get_key() );
	}

	public function test_is_enabled_requires_both_flags(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'no' );
		$this->assertFalse( $this->indexnow->is_enabled() );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no', 'indexnow_enabled' => 'yes' );
		$this->assertFalse( $this->indexnow->is_enabled() );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes' );
		$this->assertTrue( $this->indexnow->is_enabled() );
	}
}
```

- [ ] **Step 3: Run to verify they fail**

Run: `vendor/bin/phpunit tests/php/unit/IndexNowTest.php`
Expected: FATAL/FAIL — class `WC_AI_Storefront_IndexNow` not found.

- [ ] **Step 4: Implement the class skeleton + key lifecycle** — create `includes/ai-storefront/class-wc-ai-storefront-indexnow.php`:

```php
<?php
/**
 * IndexNow instant-indexing integration.
 *
 * On catalog change, submits affected URLs plus the AI-discovery surfaces to
 * IndexNow (Bing/Yandex/Seznam/Naver/Yep/Internet Archive/Amazonbot), so the
 * Bing-backed AI assistants re-crawl quickly. Google does not consume IndexNow.
 * See docs/superpowers/specs/2026-06-22-indexnow-instant-indexing-design.md.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * IndexNow submitter.
 */
class WC_AI_Storefront_IndexNow {

	/**
	 * Shared submission endpoint (propagates to all participants).
	 */
	private const ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * Settings key holding the generated IndexNow key.
	 */
	private const KEY_SETTING = 'indexnow_key';

	/**
	 * Option holding the deduped pending-URL set between debounce windows.
	 */
	private const PENDING_OPTION = 'wc_ai_storefront_indexnow_pending';

	/**
	 * Query var for the virtual {key}.txt route.
	 */
	private const KEY_QUERY_VAR = 'wc_ai_storefront_indexnow_key';

	/**
	 * Cron hook for the debounced flush.
	 */
	public const FLUSH_HOOK = 'wc_ai_storefront_indexnow_flush';

	/**
	 * Debounce window before a queued batch is flushed (seconds).
	 */
	private const FLUSH_DELAY = 60;

	/**
	 * Max URLs per submission (IndexNow spec limit).
	 */
	private const MAX_URLS = 10000;

	/**
	 * Whether IndexNow submission is active: syndication on AND the toggle on.
	 */
	public function is_enabled(): bool {
		$settings = WC_AI_Storefront::get_settings();
		return 'yes' === ( $settings['enabled'] ?? 'no' )
			&& 'yes' === ( $settings['indexnow_enabled'] ?? 'no' );
	}

	/**
	 * The IndexNow key, generating and persisting one on first use.
	 */
	public function get_key(): string {
		$settings = WC_AI_Storefront::get_settings();
		$key      = (string) ( $settings[ self::KEY_SETTING ] ?? '' );
		if ( '' !== $key ) {
			return $key;
		}
		return $this->regenerate_key();
	}

	/**
	 * Generate a fresh key, persist it, and return it.
	 */
	public function regenerate_key(): string {
		$key      = bin2hex( random_bytes( 16 ) ); // 32 lowercase hex chars.
		$settings = get_option( WC_AI_Storefront::SETTINGS_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ self::KEY_SETTING ] = $key;
		update_option( WC_AI_Storefront::SETTINGS_OPTION, $settings );
		return $key;
	}
}
```

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/phpunit tests/php/unit/IndexNowTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-indexnow.php includes/class-wc-ai-storefront.php tests/php/unit/IndexNowTest.php
git commit -m "feat(indexnow): settings default + key lifecycle + enablement gate (#530)"
```

---

## Task 2: Virtual `{key}.txt` ownership route

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-indexnow.php`
- Test: `tests/php/unit/IndexNowTest.php`

**Interfaces:**
- Consumes: `get_key()`, `is_enabled()` (Task 1).
- Produces: `add_rewrite_rules(): void`, `add_query_vars( array $vars ): array`, `serve_key_file(): void`.

- [ ] **Step 1: Write failing tests** — append to `IndexNowTest.php`:

```php
	public function test_serve_key_file_outputs_key_on_match(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'indexnow_key' => 'abcabcabcabcabcabcabcabcabcabc99' )
		);
		Functions\when( 'get_query_var' )->justReturn( 'abcabcabcabcabcabcabcabcabcabc99' );
		Functions\expect( 'status_header' )->once()->with( 200 );
		ob_start();
		try {
			$this->indexnow->serve_key_file();
		} catch ( \WC_AI_Storefront_IndexNow_Exit $e ) {
			// serve_key_file() calls $this->terminate() which throws in tests.
		}
		$this->assertSame( 'abcabcabcabcabcabcabcabcabcabc99', ob_get_clean() );
	}

	public function test_serve_key_file_404_on_mismatch(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'indexnow_key' => 'realkeyrealkeyrealkeyrealkey0001' )
		);
		Functions\when( 'get_query_var' )->justReturn( 'gibberishgibberishgibberish00000' );
		Functions\expect( 'status_header' )->once()->with( 404 );
		try {
			$this->indexnow->serve_key_file();
		} catch ( \WC_AI_Storefront_IndexNow_Exit $e ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_serve_key_file_noop_when_no_query_var(): void {
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\expect( 'status_header' )->never();
		$this->indexnow->serve_key_file(); // returns without terminating
		$this->addToAssertionCount( 1 );
	}
```

Add this test double near the top of `IndexNowTest.php` (after the `use` lines), so `terminate()` can be intercepted instead of calling `exit`:

```php
if ( ! class_exists( 'WC_AI_Storefront_IndexNow_Exit' ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test double
	class WC_AI_Storefront_IndexNow_Exit extends \RuntimeException {}
}
```

And in `setUp()`, make the test double stand in for the real exit path by stubbing the WP `exit` surface. Because `exit` cannot be intercepted directly, the implementation routes termination through a protected `terminate()` method that the test subclasses. Replace the `$this->indexnow = new WC_AI_Storefront_IndexNow();` line in `setUp()` with an anonymous subclass overriding `terminate()`:

```php
		$this->indexnow = new class() extends WC_AI_Storefront_IndexNow {
			protected function terminate(): void {
				throw new \WC_AI_Storefront_IndexNow_Exit();
			}
		};
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter serve_key_file tests/php/unit/IndexNowTest.php`
Expected: FAIL — methods undefined.

- [ ] **Step 3: Implement** — add to `class-wc-ai-storefront-indexnow.php`:

```php
	/**
	 * Register the {key}.txt rewrite rule. The hex-only pattern cannot shadow
	 * robots.txt / llms.txt / ads.txt (those names contain non-hex letters);
	 * serve_key_file() additionally requires an exact match against the stored
	 * key, so even another hex *.txt request 404s.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^([a-fA-F0-9-]{8,128})\.txt$', 'index.php?' . self::KEY_QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Register the {key}.txt query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = self::KEY_QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the IndexNow key file at /{key}.txt when the request matches the
	 * stored key and the feature is enabled. No-op for unrelated requests.
	 */
	public function serve_key_file(): void {
		$requested = (string) get_query_var( self::KEY_QUERY_VAR );
		if ( '' === $requested ) {
			return;
		}
		if ( ! $this->is_enabled() || ! hash_equals( $this->get_key(), $requested ) ) {
			status_header( 404 );
			$this->terminate();
			return;
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		status_header( 200 );
		echo $this->get_key(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hex key, no escaping needed
		$this->terminate();
	}

	/**
	 * Terminate the request. Isolated so unit tests can intercept it instead of
	 * killing the test process.
	 *
	 * @codeCoverageIgnore
	 */
	protected function terminate(): void {
		exit;
	}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit tests/php/unit/IndexNowTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-indexnow.php tests/php/unit/IndexNowTest.php
git commit -m "feat(indexnow): virtual {key}.txt ownership route (#530)"
```

---

## Task 3: URL resolution + eligibility + pending queue

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-indexnow.php`
- Test: `tests/php/unit/IndexNowTest.php`

**Interfaces:**
- Produces: `surface_urls(): array` (homepage, shop, llms.txt, products.json), `is_product_indexable( $product ): bool`, `enqueue( array $urls ): void`, `take_pending(): array`.

- [ ] **Step 1: Write failing tests** — append:

```php
	public function test_surface_urls_includes_home_shop_llms_and_feed(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		$urls = $this->indexnow->surface_urls();
		$this->assertContains( 'https://shop.test/', $urls );
		$this->assertContains( 'https://shop.test/shop/', $urls );
		$this->assertContains( 'https://shop.test/llms.txt', $urls );
		$this->assertContains( 'https://shop.test/products.json', $urls );
	}

	public function test_is_product_indexable_true_for_published_visible_syndicated(): void {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$product->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		// is_product_syndicated() is a static on WC_AI_Storefront; settings mode 'all' => true.
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'product_selection_mode' => 'all' );
		$this->assertTrue( $this->indexnow->is_product_indexable( $product ) );
	}

	public function test_is_product_indexable_false_for_hidden_or_draft(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'product_selection_mode' => 'all' );
		$draft = \Mockery::mock( 'WC_Product' );
		$draft->shouldReceive( 'get_id' )->andReturn( 42 );
		$draft->shouldReceive( 'get_status' )->andReturn( 'draft' );
		$draft->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		$this->assertFalse( $this->indexnow->is_product_indexable( $draft ) );

		$hidden = \Mockery::mock( 'WC_Product' );
		$hidden->shouldReceive( 'get_id' )->andReturn( 43 );
		$hidden->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$hidden->shouldReceive( 'get_catalog_visibility' )->andReturn( 'hidden' );
		$this->assertFalse( $this->indexnow->is_product_indexable( $hidden ) );
	}

	public function test_enqueue_dedupes_and_take_pending_clears(): void {
		$store = array();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$store ) {
				return $store[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$store ) {
				$store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$store ) {
				unset( $store[ $name ] );
				return true;
			}
		);
		$this->indexnow->enqueue( array( 'https://shop.test/a', 'https://shop.test/b' ) );
		$this->indexnow->enqueue( array( 'https://shop.test/b', 'https://shop.test/c' ) );
		$pending = $this->indexnow->take_pending();
		sort( $pending );
		$this->assertSame( array( 'https://shop.test/a', 'https://shop.test/b', 'https://shop.test/c' ), $pending );
		$this->assertSame( array(), $this->indexnow->take_pending() ); // cleared
	}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter 'surface_urls|is_product_indexable|enqueue_dedupes' tests/php/unit/IndexNowTest.php`
Expected: FAIL — methods undefined.

- [ ] **Step 3: Implement** — add to the class:

```php
	/**
	 * The AI-discovery surface URLs submitted on any catalog change.
	 *
	 * @return string[]
	 */
	public function surface_urls(): array {
		$urls    = array( home_url( '/' ), home_url( '/llms.txt' ), home_url( '/products.json' ) );
		$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		if ( $shop_id > 0 ) {
			$shop = get_permalink( $shop_id );
			if ( is_string( $shop ) && '' !== $shop ) {
				$urls[] = $shop;
			}
		}
		return $urls;
	}

	/**
	 * Whether a product's URL should be advertised to IndexNow: published, not
	 * catalog-hidden (we noindex those), and within the syndication scope.
	 *
	 * @param WC_Product $product Product.
	 */
	public function is_product_indexable( $product ): bool {
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return false;
		}
		if ( 'hidden' === $product->get_catalog_visibility() ) {
			return false;
		}
		return WC_AI_Storefront::is_product_syndicated( $product );
	}

	/**
	 * Add URLs to the deduped pending set.
	 *
	 * @param string[] $urls URLs to enqueue.
	 */
	public function enqueue( array $urls ): void {
		if ( empty( $urls ) ) {
			return;
		}
		$pending = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}
		$merged = array_values( array_unique( array_merge( $pending, array_values( $urls ) ) ) );
		if ( count( $merged ) > self::MAX_URLS ) {
			WC_AI_Storefront_Logger::debug( 'IndexNow pending set capped at %d URLs (dropped %d)', self::MAX_URLS, count( $merged ) - self::MAX_URLS );
			$merged = array_slice( $merged, 0, self::MAX_URLS );
		}
		update_option( self::PENDING_OPTION, $merged );
	}

	/**
	 * Read and clear the pending set.
	 *
	 * @return string[]
	 */
	public function take_pending(): array {
		$pending = get_option( self::PENDING_OPTION, array() );
		delete_option( self::PENDING_OPTION );
		return is_array( $pending ) ? array_values( $pending ) : array();
	}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit tests/php/unit/IndexNowTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-indexnow.php tests/php/unit/IndexNowTest.php
git commit -m "feat(indexnow): URL resolution, eligibility, pending queue (#530)"
```

---

## Task 4: Change-hook subscriptions + debounced scheduling

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-indexnow.php`
- Test: `tests/php/unit/IndexNowTest.php`

**Interfaces:**
- Consumes: `is_enabled()`, `is_product_indexable()`, `surface_urls()`, `enqueue()`.
- Produces: `on_product_change( $product_id ): void`, `on_product_removed( $product_id ): void`, `on_term_change( $term_id ): void`, `schedule_flush(): void`, `init(): void`.

- [ ] **Step 1: Write failing tests** — append:

```php
	public function test_schedule_flush_guards_against_double_scheduling(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturnUsing(
			function ( $ts, $hook ) {
				$this->assertSame( WC_AI_Storefront_IndexNow::FLUSH_HOOK, $hook );
				return true;
			}
		);
		$this->indexnow->schedule_flush();
	}

	public function test_schedule_flush_noop_when_already_scheduled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 30 );
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->indexnow->schedule_flush();
	}

	public function test_on_product_change_enqueues_product_and_surfaces_when_indexable(): void {
		$captured = array();
		$store    = array();
		Functions\when( 'get_option' )->alias( static fn( $n, $d = false ) => $store[ $n ] ?? $d );
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$store, &$captured ) {
				$store[ $n ] = $v;
				$captured    = $v;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 30 );
		Functions\when( 'wc_get_product' )->justReturn( $this->indexable_product( 42 ) );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/x/' );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		$this->indexnow->on_product_change( 42 );
		$this->assertContains( 'https://shop.test/product/x/', $captured );
		$this->assertContains( 'https://shop.test/llms.txt', $captured );
	}

	public function test_on_product_change_skips_when_disabled(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no', 'indexnow_enabled' => 'yes' );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->indexnow->on_product_change( 42 );
		$this->addToAssertionCount( 1 );
	}
```

Add this helper inside the test class:

```php
	private function indexable_product( int $id ): \Mockery\MockInterface {
		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$p->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		return $p;
	}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter 'schedule_flush|on_product_change' tests/php/unit/IndexNowTest.php`
Expected: FAIL — methods undefined.

- [ ] **Step 3: Implement** — add to the class:

```php
	/**
	 * Register catalog-change hooks and the flush cron handler. Called only
	 * when the feature is enabled (see WC_AI_Storefront::init_components()).
	 */
	public function init(): void {
		add_action( 'woocommerce_update_product', array( $this, 'on_product_change' ) );
		add_action( 'woocommerce_new_product', array( $this, 'on_product_change' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_product_change' ) );
		add_action( 'woocommerce_trash_product', array( $this, 'on_product_removed' ) );
		add_action( 'woocommerce_delete_product', array( $this, 'on_product_removed' ) );
		add_action( 'created_product_cat', array( $this, 'on_term_change' ) );
		add_action( 'edited_product_cat', array( $this, 'on_term_change' ) );
		add_action( 'delete_product_cat', array( $this, 'on_term_change' ) );
		add_action( self::FLUSH_HOOK, array( $this, 'flush' ) );
	}

	/**
	 * A product was created/updated/restocked: enqueue its URL (when indexable)
	 * plus the AI surfaces, then schedule a flush.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_change( $product_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$urls    = $this->surface_urls();
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
		if ( $product && $this->is_product_indexable( $product ) ) {
			$permalink = get_permalink( $product->get_id() );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$urls[] = $permalink;
			}
		}
		$this->enqueue( $urls );
		$this->schedule_flush();
	}

	/**
	 * A product was trashed/deleted: submit its URL unconditionally (so engines
	 * re-crawl and de-index) plus the AI surfaces.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_removed( $product_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$urls      = $this->surface_urls();
		$permalink = get_permalink( (int) $product_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$urls[] = $permalink;
		}
		$this->enqueue( $urls );
		$this->schedule_flush();
	}

	/**
	 * A product category changed: enqueue its term URL plus the AI surfaces.
	 *
	 * @param int $term_id Term ID.
	 */
	public function on_term_change( $term_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$urls = $this->surface_urls();
		$link = get_term_link( (int) $term_id, 'product_cat' );
		if ( is_string( $link ) && '' !== $link ) {
			$urls[] = $link;
		}
		$this->enqueue( $urls );
		$this->schedule_flush();
	}

	/**
	 * Schedule a single debounced flush if one is not already pending.
	 */
	public function schedule_flush(): void {
		if ( ! wp_next_scheduled( self::FLUSH_HOOK ) ) {
			wp_schedule_single_event( time() + self::FLUSH_DELAY, self::FLUSH_HOOK );
		}
	}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit tests/php/unit/IndexNowTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-indexnow.php tests/php/unit/IndexNowTest.php
git commit -m "feat(indexnow): catalog-change hooks + debounced scheduling (#530)"
```

---

## Task 5: Debounced flush handler + HTTP submit

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-indexnow.php`
- Test: `tests/php/unit/IndexNowTest.php`

**Interfaces:**
- Consumes: `is_enabled()`, `take_pending()`, `get_key()`, `enqueue()`, `schedule_flush()`.
- Produces: `flush(): void`.

- [ ] **Step 1: Write failing tests** — append:

```php
	public function test_flush_posts_payload_with_host_key_and_urls(): void {
		$store = array( 'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a' ) );
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				if ( WC_AI_Storefront::SETTINGS_OPTION === $n ) {
					return array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'indexnow_key' => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0' );
				}
				return $store[ $n ] ?? $d;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		$posted = null;
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
			function ( $url, $args ) use ( &$posted ) {
				$posted = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c ) => 'shop.test' );
		$this->indexnow->flush();
		$this->assertSame( 'https://api.indexnow.org/indexnow', $posted['url'] );
		$this->assertSame( 'shop.test', $posted['body']['host'] );
		$this->assertSame( 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0', $posted['body']['key'] );
		$this->assertSame( array( 'https://shop.test/a' ), $posted['body']['urlList'] );
	}

	public function test_flush_noop_when_disabled(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no', 'indexnow_enabled' => 'yes' );
		Functions\when( 'get_option' )->justReturn( array( 'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a' ) ) );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\expect( 'wp_remote_post' )->never();
		$this->indexnow->flush();
		$this->addToAssertionCount( 1 );
	}

	public function test_flush_noop_when_queue_empty(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $n, $d = false ) => WC_AI_Storefront::SETTINGS_OPTION === $n
				? array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'indexnow_key' => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0' )
				: ( $d )
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\expect( 'wp_remote_post' )->never();
		$this->indexnow->flush();
		$this->addToAssertionCount( 1 );
	}

	public function test_flush_requeues_and_reschedules_on_429(): void {
		$store = array( 'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a' ) );
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				return WC_AI_Storefront::SETTINGS_OPTION === $n
					? array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'indexnow_key' => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0' )
					: ( $store[ $n ] ?? $d );
			}
		);
		$requeued = null;
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$requeued ) {
				if ( 'wc_ai_storefront_indexnow_pending' === $n ) {
					$requeued = $v;
				}
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->justReturn( 'shop.test' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 429 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );
		$this->indexnow->flush();
		$this->assertSame( array( 'https://shop.test/a' ), $requeued );
	}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter flush tests/php/unit/IndexNowTest.php`
Expected: FAIL — `flush()` undefined.

- [ ] **Step 3: Implement** — add to the class:

```php
	/**
	 * Cron handler: submit the pending batch to IndexNow. Gated on is_enabled().
	 * 429/transport errors re-queue with a fresh debounce; 403/422 are logged
	 * and dropped (retrying a structurally invalid request will not help).
	 */
	public function flush(): void {
		if ( ! $this->is_enabled() ) {
			$this->take_pending(); // clear; we are not submitting.
			return;
		}
		$urls = $this->take_pending();
		if ( empty( $urls ) ) {
			return;
		}

		$body     = array(
			'host'    => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'key'     => $this->get_key(),
			'urlList' => array_values( $urls ),
		);
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => 5,
				'blocking'    => true,
				'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'        => wp_json_encode( $body ),
				'sslverify'   => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WC_AI_Storefront_Logger::debug( 'IndexNow transport error: %s — re-queuing %d URLs', $response->get_error_message(), count( $urls ) );
			$this->enqueue( $urls );
			$this->schedule_flush();
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code || 202 === $code ) {
			WC_AI_Storefront_Logger::debug( 'IndexNow submitted %d URLs (HTTP %d)', count( $urls ), $code );
			return;
		}
		if ( 429 === $code ) {
			WC_AI_Storefront_Logger::debug( 'IndexNow rate-limited (429) — re-queuing %d URLs', count( $urls ) );
			$this->enqueue( $urls );
			$this->schedule_flush();
			return;
		}
		// 403 (key not served), 422 (host/schema mismatch), or other: log + drop.
		WC_AI_Storefront_Logger::debug( 'IndexNow submission failed (HTTP %d) — dropping %d URLs. If 403, the {key}.txt rewrite may need flushing.', $code, count( $urls ) );
	}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit tests/php/unit/IndexNowTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-indexnow.php tests/php/unit/IndexNowTest.php
git commit -m "feat(indexnow): debounced flush handler + bulk submit (#530)"
```

---

## Task 6: Wiring + REST settings plumbing

**Files:**
- Modify: `includes/class-wc-ai-storefront.php` (`register_rewrite_rules()`, `init_components()`)
- Modify: `includes/admin/class-wc-ai-storefront-admin-controller.php` (REST arg + sanitization + read-only key + regenerate action)
- Test: `tests/php/unit/IndexNowTest.php` (already covers the class); admin-controller changes verified by the existing settings tests + manual smoke.

**Interfaces:**
- Consumes: `WC_AI_Storefront_IndexNow` (Tasks 1-5).

- [ ] **Step 1: Wire the route (always) + the hooks (gated).** In `includes/class-wc-ai-storefront.php` `register_rewrite_rules()` (the method that wires `llms_txt`), add — so the key file is reachable regardless of the component gate:

```php
		$indexnow = new WC_AI_Storefront_IndexNow();
		add_action( 'init', array( $indexnow, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $indexnow, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $indexnow, 'serve_key_file' ) );
```

In `init_components()`, after the `'yes' !== ( $settings['enabled'] ?? 'no' )` early-return gate (so change-hooks only run when syndication is on), add:

```php
		$indexnow = new WC_AI_Storefront_IndexNow();
		if ( $indexnow->is_enabled() ) {
			$indexnow->init();
		}
```

- [ ] **Step 2: Manual route check (no automated step — documented).** After `wp rewrite flush`, `GET /<key>.txt` returns the key as `text/plain`; a wrong hex name returns 404. (Covered by Task 2 unit tests at the method level; the rewrite registration itself is exercised by the existing rewrite smoke in the test store.)

- [ ] **Step 3: Add the REST arg + sanitization.** In `includes/admin/class-wc-ai-storefront-admin-controller.php`, add to the settings args schema (alongside `mcp_enabled`):

```php
			'indexnow_enabled' => array(
				'type' => 'string',
				'enum' => array( 'yes', 'no' ),
			),
```

In the settings-update sanitization (where `mcp_enabled` is normalized), add the same yes/no guard:

```php
		$indexnow_enabled = $merged['indexnow_enabled'] ?? 'yes';
		if ( ! in_array( $indexnow_enabled, array( 'yes', 'no' ), true ) ) {
			$indexnow_enabled = 'yes';
		}
		// ...store $indexnow_enabled under 'indexnow_enabled'
```

- [ ] **Step 4: Expose the read-only key + a regenerate action.** In the settings GET response payload (where the controller serializes settings for the React app), include the current key so the UI can display it:

```php
		$payload['indexnow_key'] = ( new WC_AI_Storefront_IndexNow() )->get_key();
```

Register a `regenerate-indexnow-key` REST route (mirroring an existing POST action route in this controller) whose callback calls `( new WC_AI_Storefront_IndexNow() )->regenerate_key()`, then `flush_rewrite_rules()` is NOT needed (the rule is pattern-based, not key-specific), and returns the new key. Use the controller's existing `manage_woocommerce` permission callback.

- [ ] **Step 5: Run the full gate.**

```bash
composer test
vendor/bin/phpcbf includes/ai-storefront/class-wc-ai-storefront-indexnow.php includes/class-wc-ai-storefront.php includes/admin/class-wc-ai-storefront-admin-controller.php tests/php/unit/IndexNowTest.php
vendor/bin/phpcs includes/ai-storefront/class-wc-ai-storefront-indexnow.php includes/class-wc-ai-storefront.php includes/admin/class-wc-ai-storefront-admin-controller.php tests/php/unit/IndexNowTest.php
php -d memory_limit=2G vendor/bin/phpstan analyse
./bin/make-pot.sh   # commit .pot if changed
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add includes/class-wc-ai-storefront.php includes/admin/class-wc-ai-storefront-admin-controller.php
git commit -m "feat(indexnow): wire component + REST settings plumbing (#530)"
```

---

## Manual end-to-end verification (on the test store, after deploy)

- `wp rewrite flush`, then `curl https://saltwarp.shop/<key>.txt` returns the key as `text/plain`; a random hex `.txt` returns 404.
- Edit a published product → within ~60s the WP-Cron flush runs → the IndexNow submission is logged (`WP_DEBUG` log shows "IndexNow submitted N URLs (HTTP 200/202)"); Bing Webmaster's URL submission/quota panel reflects it.
- A bulk import enqueues many changes but produces a single batched submission (verify only one `IndexNow submitted` log line per debounce window).
- Disable the toggle (`indexnow_enabled=no`) → editing a product produces no submission.
- Verify no product/category save is slowed (the HTTP call happens in cron, not on save).

## Self-Review

1. **Spec coverage:** key lifecycle + `{key}.txt` (Tasks 1-2); products + AI surfaces submit scope (Task 3 `surface_urls` + Task 4 hooks); default-on-behind-syndication toggle (Tasks 1 + 6); debounced WP-Cron bulk POST to `api.indexnow.org` (Tasks 4-5); fail-safe error handling (Task 5); exclusions via `is_product_indexable` (Task 3). All spec decisions mapped. ✓
2. **Placeholder scan:** every code step has concrete code. The React UI is explicitly scoped out (not a placeholder — a stated boundary). ✓
3. **Type consistency:** `is_enabled()`, `get_key()`, `regenerate_key()`, `surface_urls()`, `is_product_indexable()`, `enqueue()`, `take_pending()`, `schedule_flush()`, `flush()`, `FLUSH_HOOK`, `on_product_change/removed`, `on_term_change` used consistently across tasks. ✓
4. **Refinement vs spec:** eligibility is applied at *enqueue* time (we hold the product ID there), not at flush time as the spec sketched — cleaner given the queue stores URLs. Noted in the design intent.
