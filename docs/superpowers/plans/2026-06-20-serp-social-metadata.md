# SERP/Social Metadata + Opinionated Noindex — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Self-emit auto-derived human-SERP `<head>` metadata (title, meta description, Open Graph/Twitter, opinionated robots noindex) on commerce pages, plus a dismissible admin nudge when an overlapping SEO plugin is detected — so a lean store can deactivate Yoast/RankMath/AIOSEO.

**Architecture:** Three units on one shared detection seam. `WC_AI_Storefront_Seo_Plugin_Detector` (static presence checks) is consumed only by the nudge. `WC_AI_Storefront_Meta_Tags` (front-end emitter) derives everything from WooCommerce core data and hooks `document_title_parts` + `wp_head`. `WC_AI_Storefront_Schema_Conflict_Notice` (admin) renders the migration nudge. No emission is gated on plugin detection — we always emit on commerce pages (assert + warn); the title wins via late filter priority, other tags tolerate transient duplication until the merchant deactivates the other plugin.

**Tech Stack:** PHP 8.1, WordPress 6.7+, WooCommerce 9.9+, PHPUnit 10 + Brain Monkey + Mockery for unit tests. No new Composer/npm dependencies; no build step.

## Global Constraints

- **PHP floor:** 8.1. **WP:** 6.7+. **WC:** 9.9+.
- **Dependency-free:** no new Composer/npm packages; no JS build step (notice dismiss is an inline footer script).
- **Source of truth = core:** read WooCommerce/WordPress core fields only; never read an SEO plugin's parallel meta.
- **Text domain:** `woocommerce-ai-storefront`. Every user-facing string is translatable; after adding/removing any `__()`/`esc_html__()` call, run `./bin/make-pot.sh` and commit the regenerated `languages/woocommerce-ai-storefront.pot` (the i18n freshness gate compares line refs).
- **Register new classes in TWO places:** `includes/autoload.php` (production classmap) AND `tests/php/bootstrap.php` (explicit `require_once`).
- **Escaping (PHPCS gate):** all `<head>` output goes through `esc_attr()` for attribute content and `esc_url()` for URLs. Run `phpcbf` before pushing (catches alignment warnings local `phpcs` misses).
- **No CHANGELOG/readme.txt edits in this feature PR** — changelog entries are written once at the 0.24.0 release, not during feature work.
- **Process:** create a tracking GitHub issue first; branch off `main` (use a worktree); the PR references the issue. No direct push to `main`; no self-merge.
- **Commits:** Conventional Commits; end each message with `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- **Run the FULL suite** (`composer test`) before pushing — filtered runs miss mock-fixture drift in sibling test files.

---

## File Structure

- Create `includes/ai-storefront/class-wc-ai-storefront-seo-plugin-detector.php` — static presence detector.
- Create `includes/ai-storefront/class-wc-ai-storefront-meta-tags.php` — front-end metadata emitter (builders + render).
- Create `includes/admin/class-wc-ai-storefront-schema-conflict-notice.php` — admin migration nudge.
- Create `docs/engineering/YOAST-COEXISTENCE.md` — comparison + two-audience framing + pre-flight checklist (nudge link target).
- Create `tests/php/unit/SeoPluginDetectorTest.php`, `tests/php/unit/MetaTagsTest.php`, `tests/php/unit/SchemaConflictNoticeTest.php`.
- Modify `includes/autoload.php` — add 3 classmap entries.
- Modify `tests/php/bootstrap.php` — `require_once` the 3 new class files.
- Modify `includes/class-wc-ai-storefront.php` — register the emitter + nudge inside the enabled block of `init_components()`.
- Modify `languages/woocommerce-ai-storefront.pot` — via `./bin/make-pot.sh`.

---

## Task 1: SEO-plugin detector

**Files:**
- Create: `includes/ai-storefront/class-wc-ai-storefront-seo-plugin-detector.php`
- Modify: `includes/autoload.php` (add classmap entry)
- Modify: `tests/php/bootstrap.php` (add require)
- Test: `tests/php/unit/SeoPluginDetectorTest.php`

**Interfaces:**
- Produces: `WC_AI_Storefront_Seo_Plugin_Detector::detect(): array` — list of `['slug' => string, 'label' => string]` for each detected plugin (`[]` when none). `WC_AI_Storefront_Seo_Plugin_Detector::has_conflict(): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Tests for WC_AI_Storefront_Seo_Plugin_Detector.
 *
 * @package WooCommerce_AI_Storefront
 */

class SeoPluginDetectorTest extends \PHPUnit\Framework\TestCase {

	public function test_detect_returns_empty_when_no_seo_plugin_present(): void {
		// CI environment has none of the three SEO plugins loaded.
		$this->assertSame( array(), WC_AI_Storefront_Seo_Plugin_Detector::detect() );
		$this->assertFalse( WC_AI_Storefront_Seo_Plugin_Detector::has_conflict() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detect_reports_rankmath_when_constant_defined(): void {
		define( 'RANK_MATH_VERSION', '1.0.0-test' );
		$found = WC_AI_Storefront_Seo_Plugin_Detector::detect();
		$slugs = array_column( $found, 'slug' );
		$this->assertContains( 'rankmath', $slugs );
		$this->assertTrue( WC_AI_Storefront_Seo_Plugin_Detector::has_conflict() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detect_reports_aioseo_when_constant_defined(): void {
		define( 'AIOSEO_VERSION', '4.0.0-test' );
		$slugs = array_column( WC_AI_Storefront_Seo_Plugin_Detector::detect(), 'slug' );
		$this->assertContains( 'aioseo', $slugs );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detect_reports_yoast_when_class_present(): void {
		// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- inline test double
		class_alias( '\stdClass', 'Yoast_WooCommerce_SEO' );
		$slugs = array_column( WC_AI_Storefront_Seo_Plugin_Detector::detect(), 'slug' );
		$this->assertContains( 'yoast', $slugs );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SeoPluginDetectorTest`
Expected: FAIL — `Error: Class "WC_AI_Storefront_Seo_Plugin_Detector" not found`.

- [ ] **Step 3: Create the detector class**

```php
<?php
/**
 * SEO-plugin conflict detector.
 *
 * Presence-based detection of the three SEO plugins that emit their own
 * WooCommerce Product schema and human-SERP <head> metadata. Used ONLY
 * by the migration nudge ({@see WC_AI_Storefront_Schema_Conflict_Notice})
 * to tell the merchant they can deactivate the other plugin — it does NOT
 * gate metadata emission (we always emit on commerce pages; see
 * {@see WC_AI_Storefront_Meta_Tags::should_emit()}).
 *
 * Presence-based (not option-reading) on purpose: reading each plugin's
 * own "emit schema" toggle would couple us to version-fragile option keys.
 * A false positive here is cheap — a dismissible notice, no output change.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects overlapping SEO plugins.
 */
class WC_AI_Storefront_Seo_Plugin_Detector {

	/**
	 * Return descriptors for every detected SEO plugin.
	 *
	 * @return array<int,array{slug:string,label:string}>
	 */
	public static function detect(): array {
		$found = array();

		// Yoast WooCommerce SEO addon (NOT free Yoast core) is what emits
		// the full WC Product node + product meta.
		if ( class_exists( 'Yoast_WooCommerce_SEO' ) ) {
			$found[] = array(
				'slug'  => 'yoast',
				'label' => __( 'Yoast WooCommerce SEO', 'woocommerce-ai-storefront' ),
			);
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$found[] = array(
				'slug'  => 'rankmath',
				'label' => __( 'Rank Math SEO', 'woocommerce-ai-storefront' ),
			);
		}

		if ( defined( 'AIOSEO_VERSION' ) ) {
			$found[] = array(
				'slug'  => 'aioseo',
				'label' => __( 'All in One SEO', 'woocommerce-ai-storefront' ),
			);
		}

		return $found;
	}

	/**
	 * Whether any overlapping SEO plugin is present.
	 */
	public static function has_conflict(): bool {
		return ! empty( self::detect() );
	}
}
```

- [ ] **Step 4: Register the class in the autoload classmap**

In `includes/autoload.php`, add this entry to the `$classmap` array (alphabetical-ish, near the other `ai-storefront/` entries):

```php
			'WC_AI_Storefront_Seo_Plugin_Detector'    => '/ai-storefront/class-wc-ai-storefront-seo-plugin-detector.php',
```

- [ ] **Step 5: Register the class in the test bootstrap**

In `tests/php/bootstrap.php`, add to the "Load plugin files" block (alongside the other `require_once $plugin_path . 'ai-storefront/...';` lines):

```php
require_once $plugin_path . 'ai-storefront/class-wc-ai-storefront-seo-plugin-detector.php';
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter SeoPluginDetectorTest`
Expected: PASS (4 tests). Stub `apply_filters`/`__` are not needed here — `__()` resolves to the WP stub in `tests/php/stubs.php`.

- [ ] **Step 7: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-seo-plugin-detector.php includes/autoload.php tests/php/bootstrap.php tests/php/unit/SeoPluginDetectorTest.php
git commit -m "feat(seo): add SEO-plugin presence detector

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Meta_Tags class + `should_emit()` gating

**Files:**
- Create: `includes/ai-storefront/class-wc-ai-storefront-meta-tags.php`
- Modify: `includes/autoload.php`
- Modify: `tests/php/bootstrap.php`
- Test: `tests/php/unit/MetaTagsTest.php`

**Interfaces:**
- Produces: `WC_AI_Storefront_Meta_Tags::should_emit(): bool` — true when the master filter is on, syndication is enabled, and the request is a commerce context (`is_product()`/`is_product_category()`/`is_shop()`).

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Tests for WC_AI_Storefront_Meta_Tags.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class MetaTagsTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Meta_Tags $meta;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		// apply_filters returns the value it was given (pass-through).
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Default all commerce conditionals to false; tests opt in.
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_product_category' )->justReturn( false );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		$this->meta = new WC_AI_Storefront_Meta_Tags();
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_should_emit_true_on_product_when_enabled(): void {
		Functions\when( 'is_product' )->justReturn( true );
		$this->assertTrue( $this->meta->should_emit() );
	}

	public function test_should_emit_false_when_syndication_disabled(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no' );
		Functions\when( 'is_product' )->justReturn( true );
		$this->assertFalse( $this->meta->should_emit() );
	}

	public function test_should_emit_false_on_non_commerce_page(): void {
		// All conditionals default false in setUp().
		$this->assertFalse( $this->meta->should_emit() );
	}

	public function test_should_emit_false_when_master_filter_off(): void {
		Functions\when( 'is_product' )->justReturn( true );
		// Override apply_filters so the master toggle returns false.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'wc_ai_storefront_emit_meta_tags' === $hook ? false : $value;
			}
		);
		$this->assertFalse( $this->meta->should_emit() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: FAIL — `Error: Class "WC_AI_Storefront_Meta_Tags" not found`.

- [ ] **Step 3: Create the class with `should_emit()`**

```php
<?php
/**
 * Human-SERP / social metadata emitter.
 *
 * Self-emits <title>, meta description, Open Graph / Twitter cards, and an
 * opinionated robots noindex on commerce pages, all auto-derived from
 * WooCommerce core data. Zero merchant configuration; developer filters are
 * the only override surface. See
 * docs/superpowers/specs/2026-06-20-serp-social-metadata-design.md.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emits human-facing SERP/social <head> metadata for commerce pages.
 */
class WC_AI_Storefront_Meta_Tags {

	/**
	 * Soft maximum length for the meta description, in characters.
	 */
	private const DESCRIPTION_MAX = 155;

	/**
	 * Whether to emit metadata for the current request.
	 *
	 * NOT gated on SEO-plugin presence — per the assert-and-warn design we
	 * always emit on commerce pages; {@see WC_AI_Storefront_Schema_Conflict_Notice}
	 * handles the migration nudge separately.
	 */
	public function should_emit(): bool {
		/**
		 * Master switch for the entire metadata layer.
		 *
		 * @param bool $emit Default true.
		 */
		if ( ! (bool) apply_filters( 'wc_ai_storefront_emit_meta_tags', true ) ) {
			return false;
		}

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return false;
		}

		return ( function_exists( 'is_product' ) && is_product() )
			|| ( function_exists( 'is_product_category' ) && is_product_category() )
			|| ( function_exists( 'is_shop' ) && is_shop() );
	}
}
```

- [ ] **Step 4: Register in autoload + test bootstrap**

In `includes/autoload.php` add:

```php
			'WC_AI_Storefront_Meta_Tags'              => '/ai-storefront/class-wc-ai-storefront-meta-tags.php',
```

In `tests/php/bootstrap.php` add:

```php
require_once $plugin_path . 'ai-storefront/class-wc-ai-storefront-meta-tags.php';
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-meta-tags.php includes/autoload.php tests/php/bootstrap.php tests/php/unit/MetaTagsTest.php
git commit -m "feat(meta): add Meta_Tags class with should_emit() gating

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Meta description builder

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-meta-tags.php`
- Test: `tests/php/unit/MetaTagsTest.php`

**Interfaces:**
- Produces: `WC_AI_Storefront_Meta_Tags::build_description( WC_Product $product ): string` — short description → long description, shortcode/HTML-stripped, whitespace-collapsed, truncated to ~155 chars on a word boundary with an ellipsis; `''` when both sources are blank. Result passes through the `wc_ai_storefront_meta_description` filter (second arg: the source `WC_Product`).
- Produces: `WC_AI_Storefront_Meta_Tags::build_archive_description(): string` — for the current category (term description) or shop page (shop-page content → store tagline), cleaned/truncated identically; passes through the same `wc_ai_storefront_meta_description` filter (second arg: the queried `WP_Term`/`WP_Post` or `null`). The shared `clean_text()`/`truncate()` helpers are reused.

**Note on the fallback chain:** the spec lists "short description → excerpt → long content". For a `WC_Product`, the post excerpt *is* the short description (`get_short_description()` returns `post_excerpt`), so the runtime chain is two tiers: `get_short_description()` → `get_description()`.

- [ ] **Step 1: Write the failing tests**

Add to `MetaTagsTest`:

```php
	private function make_product( array $overrides = array() ): \Mockery\MockInterface {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_short_description' )->andReturn( $overrides['short'] ?? '' );
		$product->shouldReceive( 'get_description' )->andReturn( $overrides['long'] ?? '' );
		return $product;
	}

	public function test_description_prefers_short_description(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		$p = $this->make_product( array( 'short' => 'A tight short blurb.', 'long' => 'Long body.' ) );
		$this->assertSame( 'A tight short blurb.', $this->meta->build_description( $p ) );
	}

	public function test_description_falls_back_to_long_when_short_blank(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		$p = $this->make_product( array( 'short' => '   ', 'long' => 'The long description.' ) );
		$this->assertSame( 'The long description.', $this->meta->build_description( $p ) );
	}

	public function test_description_empty_when_all_blank(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		$p = $this->make_product( array( 'short' => '', 'long' => '' ) );
		$this->assertSame( '', $this->meta->build_description( $p ) );
	}

	public function test_description_strips_html_and_shortcodes_and_collapses_whitespace(): void {
		Functions\when( 'strip_shortcodes' )->alias(
			static fn( $s ) => preg_replace( '/\[[^\]]*\]/', '', $s )
		);
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => wp_strip_all_tags_polyfill( $s ) );
		$p = $this->make_product( array( 'short' => "<p>Fine   leather</p>\n[sale] belt</p>" ) );
		$this->assertSame( 'Fine leather belt', $this->meta->build_description( $p ) );
	}

	public function test_description_truncates_on_word_boundary_with_ellipsis(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		$long = str_repeat( 'word ', 60 ); // 300 chars of "word "
		$p    = $this->make_product( array( 'short' => trim( $long ) ) );
		$out  = $this->meta->build_description( $p );
		$this->assertLessThanOrEqual( 156, mb_strlen( $out ) ); // 155 + ellipsis
		$this->assertStringEndsWith( '…', $out );
		$this->assertStringNotContainsString( 'wor…', $out ); // cut on a space, not mid-word
	}
```

Add this polyfill helper at the BOTTOM of the test file, after the class:

```php
// Minimal wp_strip_all_tags stand-in for the strip test (the real one is
// not loaded in unit tests). Strips tags and collapses runs of whitespace
// — matching what wp_strip_all_tags does for our inputs.
function wp_strip_all_tags_polyfill( string $s ): string {
	return trim( preg_replace( '/\s+/', ' ', strip_tags( $s ) ) );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: FAIL — `Error: Call to undefined method ...::build_description()`.

- [ ] **Step 3: Implement the builder + helpers**

Add to `WC_AI_Storefront_Meta_Tags`:

```php
	/**
	 * Build the meta description for a product, auto-derived from core fields.
	 *
	 * @param WC_Product $product Product to derive from.
	 * @return string Cleaned, truncated description; '' when no source text.
	 */
	public function build_description( $product ): string {
		$candidates = array(
			(string) $product->get_short_description(),
			(string) $product->get_description(),
		);

		$description = '';
		foreach ( $candidates as $raw ) {
			$text = $this->clean_text( $raw );
			if ( '' !== $text ) {
				$description = $this->truncate( $text, self::DESCRIPTION_MAX );
				break;
			}
		}

		/**
		 * Filter the auto-derived meta description.
		 *
		 * @param string     $description Derived description ('' when none).
		 * @param WC_Product $product     Source product.
		 */
		return (string) apply_filters( 'wc_ai_storefront_meta_description', $description, $product );
	}

	/**
	 * Strip shortcodes + HTML and collapse whitespace.
	 */
	private function clean_text( string $raw ): string {
		$raw = strip_shortcodes( $raw );
		$raw = wp_strip_all_tags( $raw );
		return trim( (string) preg_replace( '/\s+/', ' ', $raw ) );
	}

	/**
	 * Truncate to a soft max on a word boundary, appending an ellipsis.
	 */
	private function truncate( string $text, int $max ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > 0 ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut ) . '…';
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: PASS (8 tests total now).

- [ ] **Step 5: Write the failing archive-description tests**

Add to `MetaTagsTest`:

```php
	public function test_archive_description_from_category_term(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'is_product_category' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array( 'term_id' => 9, 'name' => 'Belts', 'description' => 'All our leather belts.' )
		);
		$this->assertSame( 'All our leather belts.', $this->meta->build_archive_description() );
	}

	public function test_archive_description_shop_uses_page_content_then_tagline(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' ); // empty shop page content
		Functions\when( 'get_bloginfo' )->justReturn( 'Fine leather goods, made to last.' );
		$this->assertSame( 'Fine leather goods, made to last.', $this->meta->build_archive_description() );
	}
```

- [ ] **Step 6: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: FAIL — `undefined method ...::build_archive_description()`.

- [ ] **Step 7: Implement the archive description builder**

Add to `WC_AI_Storefront_Meta_Tags`:

```php
	/**
	 * Build the meta description for the current archive (category or shop).
	 *
	 * Category → the term's description. Shop → the shop page content, falling
	 * back to the store tagline. Cleaned/truncated like the product path.
	 *
	 * @return string Cleaned, truncated description; '' when no source text.
	 */
	public function build_archive_description(): string {
		$raw    = '';
		$source = null;

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term   = get_queried_object();
			$source = $term;
			if ( is_object( $term ) && isset( $term->description ) ) {
				$raw = (string) $term->description;
			}
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			if ( $shop_id > 0 ) {
				$raw = (string) get_post_field( 'post_content', $shop_id );
			}
			if ( '' === trim( $raw ) ) {
				$raw = (string) get_bloginfo( 'description' ); // store tagline
			}
		}

		$text        = $this->clean_text( $raw );
		$description = '' !== $text ? $this->truncate( $text, self::DESCRIPTION_MAX ) : '';

		/** This filter is documented in build_description(). */
		return (string) apply_filters( 'wc_ai_storefront_meta_description', $description, $source );
	}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: PASS (10 tests total now).

- [ ] **Step 9: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-meta-tags.php tests/php/unit/MetaTagsTest.php
git commit -m "feat(meta): derive meta description for products and archives

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Title-parts builder

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-meta-tags.php`
- Test: `tests/php/unit/MetaTagsTest.php`

**Interfaces:**
- Produces: `WC_AI_Storefront_Meta_Tags::filter_title_parts( array $parts ): array` — a `document_title_parts` filter callback. On single-product pages it sets `$parts['title']` to `"<name> | <brand>"` (brand from the core `product_brand` taxonomy; dropped when absent). No-op on non-product pages (core already supplies sensible category/shop titles). Result passes through `wc_ai_storefront_meta_title_parts`.

- [ ] **Step 1: Write the failing tests**

Add to `MetaTagsTest`:

```php
	public function test_title_parts_appends_brand_on_product(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->justReturn(
			array( (object) array( 'name' => 'Leather Co' ) )
		);
		$parts = $this->meta->filter_title_parts( array( 'title' => 'Old', 'site' => 'Saltwarp' ) );
		$this->assertSame( 'Canvas Belt | Leather Co', $parts['title'] );
		$this->assertSame( 'Saltwarp', $parts['site'] );
	}

	public function test_title_parts_no_brand_when_absent(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->justReturn( false ); // no brand terms
		$parts = $this->meta->filter_title_parts( array( 'title' => 'Old' ) );
		$this->assertSame( 'Canvas Belt', $parts['title'] );
	}

	public function test_title_parts_untouched_on_non_product(): void {
		// is_product() false (default). Category/shop titles stay core's.
		Functions\when( 'is_product_category' )->justReturn( true );
		$parts = $this->meta->filter_title_parts( array( 'title' => 'Accessories' ) );
		$this->assertSame( 'Accessories', $parts['title'] );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: FAIL — `undefined method ...::filter_title_parts()`.

- [ ] **Step 3: Implement the filter**

Add to `WC_AI_Storefront_Meta_Tags`:

```php
	/**
	 * `document_title_parts` callback — enrich the product title with its brand.
	 *
	 * Hooked at a late priority so we win over an active SEO plugin (there is
	 * only one <title>, so this never duplicates). Non-product commerce pages
	 * keep core's title (it already supplies the term/shop name); we only add
	 * the brand on single products.
	 *
	 * @param array $parts Title parts (keys: title, page, tagline, site).
	 * @return array
	 */
	public function filter_title_parts( $parts ) {
		if ( ! is_array( $parts ) || ! $this->should_emit() ) {
			return $parts;
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;
			if ( $product ) {
				$title = $product->get_name();
				$brand = $this->get_brand_name( $product );
				if ( '' !== $brand ) {
					$title .= ' | ' . $brand;
				}
				$parts['title'] = $title;
			}
		}

		/**
		 * Filter the title parts after our enrichment.
		 *
		 * @param array $parts Title parts.
		 */
		return (array) apply_filters( 'wc_ai_storefront_meta_title_parts', $parts );
	}

	/**
	 * First brand name from the core `product_brand` taxonomy, or '' if none.
	 *
	 * @param WC_Product $product Product.
	 */
	private function get_brand_name( $product ): string {
		$terms = get_the_terms( $product->get_id(), 'product_brand' );
		if ( is_array( $terms ) && ! empty( $terms ) && isset( $terms[0]->name ) ) {
			return (string) $terms[0]->name;
		}
		return '';
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: PASS (11 tests total).

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-meta-tags.php tests/php/unit/MetaTagsTest.php
git commit -m "feat(meta): enrich product <title> with core brand taxonomy

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Open Graph + Twitter builders

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-meta-tags.php`
- Test: `tests/php/unit/MetaTagsTest.php`

**Interfaces:**
- Produces: `WC_AI_Storefront_Meta_Tags::build_og_tags( WC_Product $product ): array` — associative `property => content` map: `og:type=product`, `og:title`, `og:description`, `og:url`, `og:site_name`; plus `og:image` when a featured image exists; plus `product:price:amount` + `product:price:currency` when the product is purchasable. Passes through `wc_ai_storefront_og_tags`. And `WC_AI_Storefront_Meta_Tags::build_twitter_tags( array $og ): array` — `twitter:card=summary_large_image`, `twitter:title`/`twitter:description`/`twitter:image` derived from the OG map.
- Produces: `WC_AI_Storefront_Meta_Tags::build_archive_og_tags(): array` — `og:type=website`, `og:title` (term name / shop-page title / store name), `og:description` (from `build_archive_description()`), `og:url` (term link / shop permalink), `og:site_name`; plus `og:image` from the category thumbnail when present. No price. Passes through `wc_ai_storefront_og_tags` (second arg `null`).

- [ ] **Step 1: Write the failing tests**

Add to `MetaTagsTest`:

```php
	private function og_product( array $overrides = array() ): \Mockery\MockInterface {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$product->shouldReceive( 'get_name' )->andReturn( $overrides['name'] ?? 'Canvas Belt' );
		$product->shouldReceive( 'get_short_description' )->andReturn( $overrides['short'] ?? 'A belt.' );
		$product->shouldReceive( 'get_description' )->andReturn( '' );
		$product->shouldReceive( 'is_purchasable' )->andReturn( $overrides['purchasable'] ?? true );
		$product->shouldReceive( 'get_price' )->andReturn( $overrides['price'] ?? '48.00' );
		return $product;
	}

	public function test_og_tags_core_fields_and_price(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/canvas-belt/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( 'https://shop.test/img/belt.jpg' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$og = $this->meta->build_og_tags( $this->og_product() );
		$this->assertSame( 'product', $og['og:type'] );
		$this->assertSame( 'Canvas Belt', $og['og:title'] );
		$this->assertSame( 'A belt.', $og['og:description'] );
		$this->assertSame( 'https://shop.test/product/canvas-belt/', $og['og:url'] );
		$this->assertSame( 'Saltwarp', $og['og:site_name'] );
		$this->assertSame( 'https://shop.test/img/belt.jpg', $og['og:image'] );
		$this->assertSame( '48.00', $og['product:price:amount'] );
		$this->assertSame( 'USD', $og['product:price:currency'] );
	}

	public function test_og_tags_omit_image_when_absent_and_price_when_not_purchasable(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/x/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false ); // no image
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$og = $this->meta->build_og_tags( $this->og_product( array( 'purchasable' => false ) ) );
		$this->assertArrayNotHasKey( 'og:image', $og );
		$this->assertArrayNotHasKey( 'product:price:amount', $og );
	}

	public function test_twitter_tags_derive_from_og(): void {
		$tw = $this->meta->build_twitter_tags(
			array(
				'og:title'       => 'Canvas Belt',
				'og:description' => 'A belt.',
				'og:image'       => 'https://shop.test/img/belt.jpg',
			)
		);
		$this->assertSame( 'summary_large_image', $tw['twitter:card'] );
		$this->assertSame( 'Canvas Belt', $tw['twitter:title'] );
		$this->assertSame( 'A belt.', $tw['twitter:description'] );
		$this->assertSame( 'https://shop.test/img/belt.jpg', $tw['twitter:image'] );
	}

	public function test_twitter_tags_omit_image_when_og_image_absent(): void {
		$tw = $this->meta->build_twitter_tags(
			array( 'og:title' => 'X', 'og:description' => 'Y' )
		);
		$this->assertArrayNotHasKey( 'twitter:image', $tw );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: FAIL — `undefined method ...::build_og_tags()`.

- [ ] **Step 3: Implement the builders**

Add to `WC_AI_Storefront_Meta_Tags`:

```php
	/**
	 * Build the Open Graph tag map for a product page.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string,string> property => content.
	 */
	public function build_og_tags( $product ): array {
		$og = array(
			'og:type'        => 'product',
			'og:title'       => $product->get_name(),
			'og:description' => $this->build_description( $product ),
			'og:url'         => get_permalink( $product->get_id() ),
			'og:site_name'   => get_bloginfo( 'name' ),
		);

		$image = get_the_post_thumbnail_url( $product->get_id(), 'full' );
		if ( is_string( $image ) && '' !== $image ) {
			$og['og:image'] = $image;
		}

		if ( $product->is_purchasable() ) {
			$price = (string) $product->get_price();
			if ( '' !== $price ) {
				$og['product:price:amount']   = $price;
				$og['product:price:currency'] = get_woocommerce_currency();
			}
		}

		/**
		 * Filter the Open Graph tag map.
		 *
		 * @param array      $og      property => content.
		 * @param WC_Product $product Source product.
		 */
		return (array) apply_filters( 'wc_ai_storefront_og_tags', $og, $product );
	}

	/**
	 * Derive Twitter Card tags from an Open Graph map.
	 *
	 * @param array<string,string> $og Open Graph map.
	 * @return array<string,string> property => content.
	 */
	public function build_twitter_tags( array $og ): array {
		$tw = array(
			'twitter:card'        => 'summary_large_image',
			'twitter:title'       => $og['og:title'] ?? '',
			'twitter:description' => $og['og:description'] ?? '',
		);
		if ( ! empty( $og['og:image'] ) ) {
			$tw['twitter:image'] = $og['og:image'];
		}
		return $tw;
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: PASS (the four new OG/Twitter tests plus all prior MetaTagsTest cases).

- [ ] **Step 5: Write the failing archive-OG test**

Add to `MetaTagsTest`:

```php
	public function test_archive_og_tags_for_category(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'is_product_category' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array( 'term_id' => 9, 'name' => 'Belts', 'description' => 'Leather belts.' )
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_term_link' )->justReturn( 'https://shop.test/product-category/belts/' );
		Functions\when( 'get_term_meta' )->justReturn( 0 ); // no thumbnail
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'website', $og['og:type'] );
		$this->assertSame( 'Belts', $og['og:title'] );
		$this->assertSame( 'Leather belts.', $og['og:description'] );
		$this->assertSame( 'https://shop.test/product-category/belts/', $og['og:url'] );
		$this->assertSame( 'Saltwarp', $og['og:site_name'] );
		$this->assertArrayNotHasKey( 'og:image', $og );
		$this->assertArrayNotHasKey( 'product:price:amount', $og );
	}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: FAIL — `undefined method ...::build_archive_og_tags()`.

- [ ] **Step 7: Implement the archive OG builder**

Add to `WC_AI_Storefront_Meta_Tags`:

```php
	/**
	 * Build the Open Graph tag map for a category or shop archive.
	 *
	 * @return array<string,string> property => content.
	 */
	public function build_archive_og_tags(): array {
		$site = get_bloginfo( 'name' );
		$og   = array(
			'og:type'        => 'website',
			'og:description' => $this->build_archive_description(),
			'og:site_name'   => $site,
			'og:title'       => $site,
			'og:url'         => '',
		);

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			if ( is_object( $term ) ) {
				if ( isset( $term->name ) ) {
					$og['og:title'] = (string) $term->name;
				}
				$link = isset( $term->term_id ) ? get_term_link( $term ) : '';
				if ( is_string( $link ) && '' !== $link ) {
					$og['og:url'] = $link;
				}
				$thumb_id = isset( $term->term_id ) ? (int) get_term_meta( $term->term_id, 'thumbnail_id', true ) : 0;
				if ( $thumb_id > 0 ) {
					$img = wp_get_attachment_url( $thumb_id );
					if ( is_string( $img ) && '' !== $img ) {
						$og['og:image'] = $img;
					}
				}
			}
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			if ( $shop_id > 0 ) {
				$og['og:title'] = get_the_title( $shop_id );
				$og['og:url']   = get_permalink( $shop_id );
			}
		}

		// Fallback only when no branch set a URL — kept lazy so unit tests
		// exercising the category/shop branches need not stub home_url().
		if ( '' === $og['og:url'] && function_exists( 'home_url' ) ) {
			$og['og:url'] = home_url( '/' );
		}

		/** This filter is documented in build_og_tags(). */
		return (array) apply_filters( 'wc_ai_storefront_og_tags', $og, null );
	}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: PASS (archive-OG test plus all prior cases).

- [ ] **Step 9: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-meta-tags.php tests/php/unit/MetaTagsTest.php
git commit -m "feat(meta): build Open Graph + Twitter cards for products and archives

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Opinionated noindex decision

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-meta-tags.php`
- Test: `tests/php/unit/MetaTagsTest.php`

**Interfaces:**
- Produces: `WC_AI_Storefront_Meta_Tags::should_noindex(): bool` — true when the current single product has `catalog_visibility === 'hidden'`, OR the request is an internal shop search (`is_search()`). Passes through `wc_ai_storefront_robots_noindex`.

- [ ] **Step 1: Write the failing tests**

Add to `MetaTagsTest`:

```php
	public function test_noindex_true_for_hidden_product(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'hidden' );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$this->assertTrue( $this->meta->should_noindex() );
	}

	public function test_noindex_false_for_visible_product(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$this->assertFalse( $this->meta->should_noindex() );
	}

	public function test_noindex_true_for_search(): void {
		Functions\when( 'is_search' )->justReturn( true );
		$this->assertTrue( $this->meta->should_noindex() );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: FAIL — `undefined method ...::should_noindex()`.

- [ ] **Step 3: Implement the decision**

Add to `WC_AI_Storefront_Meta_Tags`:

```php
	/**
	 * Whether the current commerce page should carry robots noindex.
	 *
	 * Opinionated, zero-config: a product the merchant set to "Hidden" in
	 * WooCommerce (still reachable by URL) and internal shop search results
	 * (thin/duplicate content) are noindexed. Everything else is indexable.
	 */
	public function should_noindex(): bool {
		$noindex = false;

		if ( function_exists( 'is_product' ) && is_product()
			&& function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product && 'hidden' === $product->get_catalog_visibility() ) {
				$noindex = true;
			}
		}

		if ( function_exists( 'is_search' ) && is_search() ) {
			$noindex = true;
		}

		/**
		 * Filter the robots noindex decision for the current request.
		 *
		 * @param bool $noindex Whether to emit robots noindex.
		 */
		return (bool) apply_filters( 'wc_ai_storefront_robots_noindex', $noindex );
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: PASS (18 tests total).

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-meta-tags.php tests/php/unit/MetaTagsTest.php
git commit -m "feat(meta): opinionated noindex for hidden products + search

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Emission wiring (`init()` + `render_head_tags()` + registration)

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-meta-tags.php`
- Modify: `includes/class-wc-ai-storefront.php` (register in `init_components()`)
- Test: `tests/php/unit/MetaTagsTest.php`

**Interfaces:**
- Consumes: `should_emit()`, `build_description()`, `build_og_tags()`, `build_twitter_tags()`, `should_noindex()` (Tasks 2–6).
- Produces: `WC_AI_Storefront_Meta_Tags::init(): void` (hooks `document_title_parts` @ 99 and `wp_head` @ 5); `WC_AI_Storefront_Meta_Tags::render_head_tags(): void` (echoes escaped `<meta>` tags on product pages, no-ops otherwise).

- [ ] **Step 1: Write the failing tests**

Add to `MetaTagsTest`:

```php
	private function stub_escapers(): void {
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
	}

	public function test_render_noop_when_should_not_emit(): void {
		// Non-commerce page (all conditionals false in setUp).
		ob_start();
		$this->meta->render_head_tags();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_render_emits_description_og_and_twitter_for_product(): void {
		$this->stub_escapers();
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/belt/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( 'https://shop.test/i.jpg' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$product = $this->og_product();
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		ob_start();
		$this->meta->render_head_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta name="description" content="A belt."', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="Canvas Belt"', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image"', $html );
		$this->assertStringNotContainsString( 'noindex', $html );
	}

	public function test_render_emits_noindex_for_hidden_product(): void {
		$this->stub_escapers();
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/belt/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$product = $this->og_product();
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'hidden' );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		ob_start();
		$this->meta->render_head_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta name="robots" content="noindex,follow"', $html );
	}

	public function test_render_emits_archive_metadata_for_category(): void {
		$this->stub_escapers();
		Functions\when( 'is_product_category' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array( 'term_id' => 9, 'name' => 'Belts', 'description' => 'Leather belts.' )
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_term_link' )->justReturn( 'https://shop.test/product-category/belts/' );
		Functions\when( 'get_term_meta' )->justReturn( 0 );

		ob_start();
		$this->meta->render_head_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta name="description" content="Leather belts."', $html );
		$this->assertStringContainsString( '<meta property="og:type" content="website"', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="Belts"', $html );
		$this->assertStringNotContainsString( 'product:price:amount', $html );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: FAIL — `undefined method ...::render_head_tags()`.

- [ ] **Step 3: Implement `init()` + `render_head_tags()` + a printing helper**

Add to `WC_AI_Storefront_Meta_Tags`:

```php
	/**
	 * Register hooks.
	 */
	public function init(): void {
		// Late priority so our product-title enrichment wins over an active
		// SEO plugin (single <title>, never duplicated).
		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ), 99 );
		// Early in <head> so the description/OG/robots sit near the top.
		add_action( 'wp_head', array( $this, 'render_head_tags' ), 5 );
	}

	/**
	 * Echo the <head> metadata for the current commerce page.
	 */
	public function render_head_tags(): void {
		if ( ! $this->should_emit() ) {
			return;
		}

		if ( $this->should_noindex() ) {
			$this->print_meta( 'name', 'robots', 'noindex,follow' );
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;
			if ( ! $product ) {
				return;
			}
			$description = $this->build_description( $product );
			$og          = $this->build_og_tags( $product );
		} else {
			// Category or shop archive (should_emit() guarantees one of these).
			$description = $this->build_archive_description();
			$og          = $this->build_archive_og_tags();
		}

		if ( '' !== $description ) {
			$this->print_meta( 'name', 'description', $description );
		}
		$this->print_og_and_twitter( $og );
	}

	/**
	 * Print an Open Graph map followed by its derived Twitter cards.
	 *
	 * @param array<string,string> $og Open Graph map.
	 */
	private function print_og_and_twitter( array $og ): void {
		foreach ( $og as $property => $content ) {
			$this->print_meta( 'property', $property, $content, 'url' === $this->attr_kind( $property ) );
		}
		foreach ( $this->build_twitter_tags( $og ) as $name => $content ) {
			$this->print_meta( 'name', $name, $content, 'twitter:image' === $name );
		}
	}

	/**
	 * Whether an OG property carries a URL value (so it is esc_url'd).
	 */
	private function attr_kind( string $property ): string {
		return in_array( $property, array( 'og:url', 'og:image' ), true ) ? 'url' : 'text';
	}

	/**
	 * Print a single escaped <meta> tag.
	 *
	 * @param string $attr    'name' or 'property'.
	 * @param string $key     The attribute key (e.g. 'og:title').
	 * @param string $content The content value.
	 * @param bool   $is_url  Escape content as a URL instead of an attribute.
	 */
	private function print_meta( string $attr, string $key, string $content, bool $is_url = false ): void {
		$value = $is_url ? esc_url( $content ) : esc_attr( $content );
		printf(
			'<meta %1$s="%2$s" content="%3$s" />' . "\n",
			esc_attr( $attr ),
			esc_attr( $key ),
			// $value is already escaped by esc_url()/esc_attr() above.
			$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter MetaTagsTest`
Expected: PASS (21 tests total).

- [ ] **Step 5: Register the emitter in `init_components()`**

In `includes/class-wc-ai-storefront.php`, inside the enabled block of `init_components()` (after the `$jsonld->init();` lines, around line 194), add:

```php
		// Human-SERP / social metadata (title, description, OG/Twitter,
		// opinionated noindex) on commerce pages. Front-end only; the
		// emitter self-gates on commerce context per request.
		$meta_tags = new WC_AI_Storefront_Meta_Tags();
		$meta_tags->init();
```

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: PASS (all suites green, including the new 21 + detector 4).

- [ ] **Step 7: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-meta-tags.php includes/class-wc-ai-storefront.php tests/php/unit/MetaTagsTest.php
git commit -m "feat(meta): wire metadata emission on wp_head + document_title_parts

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Migration nudge (admin notice + dismiss)

**Files:**
- Create: `includes/admin/class-wc-ai-storefront-schema-conflict-notice.php`
- Modify: `includes/autoload.php`
- Modify: `tests/php/bootstrap.php`
- Modify: `includes/class-wc-ai-storefront.php` (register in `init_components()`, `is_admin()` block)
- Test: `tests/php/unit/SchemaConflictNoticeTest.php`

**Interfaces:**
- Consumes: `WC_AI_Storefront_Seo_Plugin_Detector::detect()` (Task 1).
- Produces: `WC_AI_Storefront_Schema_Conflict_Notice::init(): void`; `should_show(): bool` (syndication enabled AND a plugin detected AND `manage_woocommerce` AND not dismissed by this user); `maybe_render(): void`; `handle_dismiss(): void`. Constant `DISMISS_META = 'wc_ai_storefront_schema_notice_dismissed'`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Tests for WC_AI_Storefront_Schema_Conflict_Notice gating.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class SchemaConflictNoticeTest extends \PHPUnit\Framework\TestCase {

	private WC_AI_Storefront_Schema_Conflict_Notice $notice;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( '' ); // not dismissed
		$this->notice = new WC_AI_Storefront_Schema_Conflict_Notice();
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_no_show_when_no_conflict(): void {
		// CI env: detector finds nothing.
		$this->assertFalse( $this->notice->should_show() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_show_when_conflict_and_enabled_and_capable_and_not_dismissed(): void {
		define( 'RANK_MATH_VERSION', '1.0' );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$this->assertTrue( ( new WC_AI_Storefront_Schema_Conflict_Notice() )->should_show() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_show_when_dismissed(): void {
		define( 'RANK_MATH_VERSION', '1.0' );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( '1' ); // dismissed
		$this->assertFalse( ( new WC_AI_Storefront_Schema_Conflict_Notice() )->should_show() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_show_when_not_capable(): void {
		define( 'RANK_MATH_VERSION', '1.0' );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$this->assertFalse( ( new WC_AI_Storefront_Schema_Conflict_Notice() )->should_show() );
	}

	public function test_no_show_when_syndication_disabled(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no' );
		$this->assertFalse( $this->notice->should_show() );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter SchemaConflictNoticeTest`
Expected: FAIL — `Class "WC_AI_Storefront_Schema_Conflict_Notice" not found`.

- [ ] **Step 3: Create the notice class**

```php
<?php
/**
 * Migration nudge: detect an overlapping SEO plugin and invite the merchant
 * to deactivate it (this plugin now provides titles, descriptions, social
 * cards, and structured data). Informational + dismissible; changes no output.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the dismissible SEO-plugin conflict notice.
 */
class WC_AI_Storefront_Schema_Conflict_Notice {

	/**
	 * Per-user dismissal flag (user meta key).
	 */
	public const DISMISS_META = 'wc_ai_storefront_schema_notice_dismissed';

	/**
	 * AJAX action name for dismissal.
	 */
	private const AJAX_ACTION = 'wc_ai_storefront_dismiss_schema_notice';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Whether to render the notice for the current admin request/user.
	 */
	public function should_show(): bool {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return false;
		}
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return false;
		}
		return WC_AI_Storefront_Seo_Plugin_Detector::has_conflict();
	}

	/**
	 * Render the notice (and its inline dismiss script) when applicable.
	 */
	public function maybe_render(): void {
		if ( ! $this->should_show() ) {
			return;
		}

		$plugins = WC_AI_Storefront_Seo_Plugin_Detector::detect();
		$labels  = implode( ', ', array_column( $plugins, 'label' ) );
		$nonce   = wp_create_nonce( self::AJAX_ACTION );
		$doc_url = 'https://github.com/Automattic/woocommerce-ai-storefront/blob/main/docs/engineering/YOAST-COEXISTENCE.md';

		echo '<div class="notice notice-info is-dismissible" id="wc-ai-storefront-schema-notice" data-nonce="' . esc_attr( $nonce ) . '">';
		echo '<p>';
		printf(
			/* translators: %s: comma-separated list of detected SEO plugin names. */
			esc_html__( 'WooCommerce AI Storefront now provides your product titles, descriptions, social cards, and structured data. %s is also emitting these, which can produce duplicate tags. You can deactivate it — review the checklist first.', 'woocommerce-ai-storefront' ),
			'<strong>' . esc_html( $labels ) . '</strong>'
		);
		echo '</p>';
		echo '<p><strong>' . esc_html__( 'Before deactivating, check:', 'woocommerce-ai-storefront' ) . '</strong></p>';
		echo '<ul style="list-style:disc;margin-left:20px;">';
		echo '<li>' . esc_html__( 'Breadcrumbs: if your theme calls the SEO plugin\'s breadcrumb function, switch it to woocommerce_breadcrumb().', 'woocommerce-ai-storefront' ) . '</li>';
		echo '<li>' . esc_html__( 'Redirects: any redirects configured in the SEO plugin will stop working — keep a dedicated redirect plugin.', 'woocommerce-ai-storefront' ) . '</li>';
		echo '<li>' . esc_html__( 'Custom noindex rules: pages you manually noindexed will become indexable.', 'woocommerce-ai-storefront' ) . '</li>';
		echo '<li>' . esc_html__( 'Sitemap: WordPress core serves /wp-sitemap.xml — resubmit it in Google Search Console.', 'woocommerce-ai-storefront' ) . '</li>';
		echo '</ul>';
		echo '<p><a href="' . esc_url( $doc_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Read the full coexistence guide', 'woocommerce-ai-storefront' ) . '</a></p>';
		echo '</div>';

		$this->print_dismiss_script();
	}

	/**
	 * Inline footer script that POSTs the dismissal when the notice's
	 * built-in (×) dismiss button is clicked. No build step, no dependency.
	 */
	private function print_dismiss_script(): void {
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<script>
		( function () {
			var n = document.getElementById( 'wc-ai-storefront-schema-notice' );
			if ( ! n ) { return; }
			n.addEventListener( 'click', function ( e ) {
				if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
				var body = new URLSearchParams();
				body.append( 'action', '<?php echo esc_js( self::AJAX_ACTION ); ?>' );
				body.append( 'nonce', n.getAttribute( 'data-nonce' ) );
				fetch( '<?php echo esc_url( $ajax_url ); ?>', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Persist the per-user dismissal.
	 */
	public function handle_dismiss(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( '', 403 );
		}
		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		wp_send_json_success();
	}
}
```

- [ ] **Step 4: Register in autoload + test bootstrap**

In `includes/autoload.php` add:

```php
			'WC_AI_Storefront_Schema_Conflict_Notice' => '/admin/class-wc-ai-storefront-schema-conflict-notice.php',
```

In `tests/php/bootstrap.php` add (this class references the detector statically, so require the detector first if not already loaded — Task 1 added it):

```php
require_once dirname( __DIR__, 2 ) . '/includes/admin/class-wc-ai-storefront-schema-conflict-notice.php';
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter SchemaConflictNoticeTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Register the notice in `init_components()`**

In `includes/class-wc-ai-storefront.php`, inside the enabled block of `init_components()`, next to the existing `is_admin()` meta-box registration (around line 237), add:

```php
		if ( is_admin() ) {
			// Migration nudge: warn when an overlapping SEO plugin is active.
			$schema_notice = new WC_AI_Storefront_Schema_Conflict_Notice();
			$schema_notice->init();
		}
```

(If the existing `is_admin()` block already wraps the product meta box, add the two `$schema_notice` lines inside that same block instead of opening a second one.)

- [ ] **Step 7: Run the full suite**

Run: `composer test`
Expected: PASS (all green).

- [ ] **Step 8: Commit**

```bash
git add includes/admin/class-wc-ai-storefront-schema-conflict-notice.php includes/autoload.php includes/class-wc-ai-storefront.php tests/php/bootstrap.php tests/php/unit/SchemaConflictNoticeTest.php
git commit -m "feat(admin): migration nudge when an overlapping SEO plugin is active

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: Coexistence engineering doc

**Files:**
- Create: `docs/engineering/YOAST-COEXISTENCE.md`

**Interfaces:** none (documentation; the nudge links to this file's GitHub URL).

- [ ] **Step 1: Write the doc**

Create `docs/engineering/YOAST-COEXISTENCE.md` with these sections (port the content developed in the design discussion):

1. **Two audiences** — structured data serves Googlebot rich results AND AI agents; the meta layer serves the human-SERP/social audience. JSON-LD adds the stars/price row; title+description supply headline+snippet — complementary, not substitutes.
2. **Comparison table** — surface · plain-terms · audience · Yoast · this plugin · overlap, covering: Product JSON-LD (contention), reviews/aggregateRating (WC-core passthrough), WebSite/Org+sameAs, meta title/description, OG/Twitter, canonical (`rel=canonical` tag vs canonical permalink-as-data), robots-meta vs robots.txt, XML sitemaps (provider-agnostic deferral), redirects (never in scope), primary category, and the plugin-exclusive agentic surfaces.
3. **Governing principle** — core is the source of truth; never read an SEO plugin's parallel meta.
4. **Pre-flight checklist** — breadcrumbs (schema safe via WC core; visible trail is a theme edit → `woocommerce_breadcrumb()`), redirects (use a dedicated plugin), custom noindex rules, sitemap URL change (resubmit `/wp-sitemap.xml` to GSC).
5. **Litmus test** — "does it serve product discovery?" — what the plugin will/won't absorb.

- [ ] **Step 2: Verify the nudge's link target matches**

Confirm the URL printed in `WC_AI_Storefront_Schema_Conflict_Notice::maybe_render()` (`.../blob/main/docs/engineering/YOAST-COEXISTENCE.md`) matches the created file path. Fix either side if they differ.

- [ ] **Step 3: Commit**

```bash
git add docs/engineering/YOAST-COEXISTENCE.md
git commit -m "docs: add Yoast coexistence guide (nudge link target)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: i18n regen + final verification

**Files:**
- Modify: `languages/woocommerce-ai-storefront.pot`

**Interfaces:** none.

- [ ] **Step 1: Regenerate the .pot**

Run: `./bin/make-pot.sh`
Expected: new `__()`/`esc_html__()` strings from the notice appear in `languages/woocommerce-ai-storefront.pot`; the only other diff is the `POT-Creation-Date` header.

- [ ] **Step 2: Run phpcbf then phpcs**

Run: `vendor/bin/phpcbf includes/ai-storefront/class-wc-ai-storefront-meta-tags.php includes/ai-storefront/class-wc-ai-storefront-seo-plugin-detector.php includes/admin/class-wc-ai-storefront-schema-conflict-notice.php`
Then: `composer run phpcs` (or `vendor/bin/phpcs`)
Expected: no errors. Fix any alignment/escaping findings.

- [ ] **Step 3: Run the full suite + static analysis**

Run: `composer test`
Then: `vendor/bin/phpstan analyse` (if configured in CI)
Expected: all green.

- [ ] **Step 4: Commit**

```bash
git add languages/woocommerce-ai-storefront.pot
git commit -m "chore(i18n): regenerate .pot for metadata nudge strings

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 5: Open the PR**

Push the branch and open a PR referencing the tracking issue. Do NOT add CHANGELOG entries (those are written at the 0.24.0 release). Do NOT add the Copilot reviewer automatically — leave review timing to the maintainer.

---

## Self-Review

**Spec coverage:**
- Detector (presence-based, 3 plugins, descriptors) → Task 1. ✓
- Meta description — products (fallback chain, strip, truncate, omit-empty, filter) AND archives (category term description / shop content → tagline) → Task 3. ✓
- Title (branded, brand from core taxonomy, compose with theme, filter) → Task 4. ✓
- OG + Twitter — products (fields, image omit, price gated on purchasable) AND archives (website type, term/shop title+url, category thumbnail, no price) → Task 5. ✓
- Opinionated noindex (hidden product, internal search, filter) → Task 6. ✓
- `should_emit()` gating (master filter + enabled + commerce context, NOT plugin-gated) → Task 2. ✓
- Emission wiring (document_title_parts @99, wp_head @5, escaping, registration) → Task 7. ✓
- Migration nudge (gating truth table, dismiss via user meta + AJAX, inline script, checklist, doc link) → Task 8. ✓
- Coexistence doc + pre-flight checklist → Task 9. ✓
- i18n regen + phpcbf + full suite → Task 10. ✓
- Developer filters (`wc_ai_storefront_emit_meta_tags`, `_meta_description`, `_meta_title_parts`, `_og_tags`, `_robots_noindex`) → Tasks 2/3/4/5/6. ✓
- Non-goals (no per-product UI, no non-commerce pages, no Yoast suppression, no redirect manager) → not implemented by construction. ✓

**Reconciliations made explicit:** the spec's three-tier description chain collapses to two tiers for `WC_Product` (excerpt == short description) — documented in Task 3.

**Type consistency:** `detect()` returns `array<int,array{slug,label}>` consumed via `array_column(..., 'slug'|'label')` in Tasks 1 and 8. `build_og_tags()` returns a `property => content` map consumed by `build_twitter_tags()` and `render_head_tags()` in Tasks 5 and 7. `should_emit()`/`should_noindex()` bool signatures consistent across Tasks 2/6/7.

**Resolved (full archive coverage chosen):** `render_head_tags()` emits the full description + OG/Twitter set on product pages AND on category/shop archives (Task 7's archive branch, fed by `build_archive_description()` / `build_archive_og_tags()` from Tasks 3 and 5). Products use `og:type=product` with price; archives use `og:type=website` without price. Non-commerce pages remain out of scope (deferred roadmap item 1).
