# Jetpack Metadata Coexistence — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop our metadata layer from duplicating/conflicting with Jetpack's Open Graph + SEO meta description on commerce pages (GitHub #527), by first making our own OG output correct and complete, then suppressing Jetpack's overlapping tags page-scoped.

**Architecture:** All emission logic lives in `WC_AI_Storefront_Meta_Tags`. We add `og:locale`, an archive `og:image` fallback chain, and a front-page brand `og:title`, so our OG set is complete; then we page-scope-suppress Jetpack's `jetpack_og_tags` (via `remove_action` at `wp_head` priority 1) and its SEO `description` (via the `jetpack_seo_meta_tags` filter) only when `should_emit()` is true. The detector learns Jetpack but marks it auto-handled so the deactivate notice never nags about it.

**Tech Stack:** PHP 8.1+, WordPress/WooCommerce hooks, PHPUnit + Brain Monkey + Mockery (unit, WP functions stubbed).

## Global Constraints

- PHP floor 8.1; CI matrix runs 8.1–8.4. No syntax newer than 8.1.
- No em-dashes (`—`) in any merchant-facing copy / `__()` string; en-dashes (`–`) and hyphens are fine.
- The full local gate before "done": `composer test`, `npm run test:js` (n/a here, no JS), `vendor/bin/phpcs` on changed PHP, `php -d memory_limit=2G vendor/bin/phpstan analyse`, `./bin/make-pot.sh` (commit any `.pot` diff). Run `vendor/bin/phpcbf` before pushing.
- Ordering is a hard dependency: Tasks 1–3 (make our OG correct/complete) MUST land before Task 4 (suppress Jetpack), or Task 4 would regress the currently-correct Jetpack `og:title` and drop the homepage share image.
- Suppression is page-scoped: only when `WC_AI_Storefront_Meta_Tags::should_emit()` is true. Off commerce pages Jetpack must keep emitting (posts/pages we don't handle).
- New developer filter names use the `wc_ai_storefront_` prefix.

## File Structure

| File | Responsibility | Change |
|------|----------------|--------|
| `includes/ai-storefront/class-wc-ai-storefront-meta-tags.php` | All SERP/social emission | og:locale, archive og:image fallback, front-page og:title, Jetpack suppression hooks |
| `includes/ai-storefront/class-wc-ai-storefront-seo-plugin-detector.php` | Detect overlapping SEO plugins | add Jetpack (handled flag); `has_conflict()` counts only non-handled |
| `includes/admin/class-wc-ai-storefront-schema-conflict-notice.php` | Deactivate-nudge notice | list only non-handled plugins |
| `tests/php/unit/MetaTagsTest.php` | Meta-tags unit tests | add setUp stubs + new tests |
| `tests/php/unit/SeoPluginDetectorTest.php` | Detector unit tests | Jetpack detection + handled semantics |

### `MetaTagsTest::setUp()` additions (do this first, before Task 1's test run)

The new code calls `get_locale()`, `get_theme_mod()`, `get_site_icon_url()`, and `is_front_page()`. Brain Monkey throws on unstubbed WP functions, so add these defaults to `setUp()` (after the existing `is_search` stub, before `$this->meta = ...`). Falsy defaults keep every existing assertion intact:

```php
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_theme_mod' )->justReturn( 0 );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
```

---

## Task 1: Add `og:locale` to product and archive OG (issue scope item 3)

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-meta-tags.php` (`build_og_tags()` ~line 201, `build_archive_og_tags()` ~line 259, new helper)
- Test: `tests/php/unit/MetaTagsTest.php`

**Interfaces:**
- Produces: `private function og_locale(): string` returning a `language_TERRITORY` OG locale.

- [ ] **Step 1: Add the setUp stubs** listed in "File Structure" above (needed by every task from here on).

- [ ] **Step 2: Write failing tests**

```php
	public function test_og_tags_include_locale(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$product->shouldReceive( 'get_short_description' )->andReturn( 'A belt.' );
		$product->shouldReceive( 'get_description' )->andReturn( '' );
		$product->shouldReceive( 'get_id' )->andReturn( 10 );
		$product->shouldReceive( 'is_purchasable' )->andReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '' );
		$og = $this->meta->build_og_tags( $product );
		$this->assertSame( 'en_US', $og['og:locale'] );
	}

	public function test_archive_og_tags_include_locale(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'en_US', $og['og:locale'] );
	}

	public function test_og_locale_normalizes_wp_variant(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		Functions\when( 'get_locale' )->justReturn( 'de_DE_formal' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'de_DE', $og['og:locale'] );
	}
```

- [ ] **Step 3: Run to verify they fail**

Run: `vendor/bin/phpunit --filter 'test_og_tags_include_locale|test_archive_og_tags_include_locale|test_og_locale_normalizes_wp_variant' tests/php/unit/MetaTagsTest.php`
Expected: FAIL (undefined array key `og:locale`).

- [ ] **Step 4: Implement**

Add the helper (place it next to `attr_kind()`):

```php
	/**
	 * The current locale as an Open Graph `language_TERRITORY` value.
	 *
	 * WordPress locales like `de_DE_formal` carry a variant suffix OG does not
	 * accept, so we keep only the language and territory segments. Defaults to
	 * `en_US` when the locale is unavailable.
	 */
	private function og_locale(): string {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
		if ( '' === $locale ) {
			return 'en_US';
		}
		$parts = explode( '_', $locale );
		return isset( $parts[1] ) ? $parts[0] . '_' . $parts[1] : $parts[0];
	}
```

In `build_og_tags()`, add `og:locale` to the initial `$og` array (after `og:site_name`):

```php
			'og:site_name'   => get_bloginfo( 'name' ),
			'og:locale'      => $this->og_locale(),
```

In `build_archive_og_tags()`, add it to the initial `$og` array (after `og:url`):

```php
			'og:title'       => $site,
			'og:url'         => '',
			'og:locale'      => $this->og_locale(),
```

- [ ] **Step 5: Run to verify pass + no regressions**

Run: `vendor/bin/phpunit tests/php/unit/MetaTagsTest.php`
Expected: PASS (all, including pre-existing).

- [ ] **Step 6: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-meta-tags.php tests/php/unit/MetaTagsTest.php
git commit -m "feat(meta): emit og:locale on commerce pages (#527)"
```

---

## Task 2: Archive `og:image` fallback chain (issue scope item 2)

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-meta-tags.php` (`build_archive_og_tags()`, new helper)
- Test: `tests/php/unit/MetaTagsTest.php`

**Interfaces:**
- Produces: `private function archive_default_image(): string` — configured-default → site-logo → site-icon → `''`.
- New filter: `wc_ai_storefront_og_default_image` (string default `''`).

- [ ] **Step 1: Write failing tests**

```php
	public function test_archive_og_image_falls_back_to_site_logo(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		Functions\when( 'get_theme_mod' )->justReturn( 7 );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://shop.test/logo.png' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'https://shop.test/logo.png', $og['og:image'] );
	}

	public function test_archive_og_image_falls_back_to_site_icon(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		Functions\when( 'get_theme_mod' )->justReturn( 0 );
		Functions\when( 'get_site_icon_url' )->justReturn( 'https://shop.test/icon.png' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'https://shop.test/icon.png', $og['og:image'] );
	}

	public function test_archive_og_image_prefers_configured_default(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		// Override the pass-through apply_filters for the default-image hook only.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'wc_ai_storefront_og_default_image' === $hook ? 'https://shop.test/branded-og.png' : $value;
			}
		);
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'https://shop.test/branded-og.png', $og['og:image'] );
	}
```

(The existing `test_archive_og_tags_for_category` — no thumbnail — still expects no `og:image`; the setUp falsy stubs for `get_theme_mod`/`get_site_icon_url` keep `archive_default_image()` returning `''`, so that assertion holds.)

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter 'test_archive_og_image' tests/php/unit/MetaTagsTest.php`
Expected: FAIL (undefined array key `og:image`).

- [ ] **Step 3: Implement**

Add the helper:

```php
	/**
	 * Default social image for an archive that has no image of its own.
	 *
	 * Order: a merchant/dev-configured default (filter), then the site logo
	 * (Customizer custom_logo), then the site icon. Returns '' when none is
	 * available. Keeps the shop/home share card from going imageless when we
	 * suppress Jetpack's auto-generated Open Graph image.
	 */
	private function archive_default_image(): string {
		/**
		 * Filter the default Open Graph image URL for archive pages.
		 *
		 * @param string $url Default image URL. Empty string falls through to
		 *                    the site logo, then the site icon.
		 */
		$configured = (string) apply_filters( 'wc_ai_storefront_og_default_image', '' );
		if ( '' !== $configured ) {
			return $configured;
		}

		$logo_id = function_exists( 'get_theme_mod' ) ? (int) get_theme_mod( 'custom_logo' ) : 0;
		if ( $logo_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
			$url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		if ( function_exists( 'get_site_icon_url' ) ) {
			$icon = (string) get_site_icon_url( 512 );
			if ( '' !== $icon ) {
				return $icon;
			}
		}

		return '';
	}
```

In `build_archive_og_tags()`, immediately before the existing lazy `og:url` fallback (the `if ( '' === $og['og:url'] ...` block ~line 297), insert:

```php
		if ( empty( $og['og:image'] ) ) {
			$default_image = $this->archive_default_image();
			if ( '' !== $default_image ) {
				$og['og:image'] = $default_image;
			}
		}
```

- [ ] **Step 4: Run to verify pass + no regressions**

Run: `vendor/bin/phpunit tests/php/unit/MetaTagsTest.php`
Expected: PASS (all).

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-meta-tags.php tests/php/unit/MetaTagsTest.php
git commit -m "feat(meta): archive og:image fallback (configured/logo/icon) (#527)"
```

---

## Task 3: Front-page archive `og:title` is the brand (issue scope item 1)

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-meta-tags.php` (`build_archive_og_tags()` shop branch ~line 287)
- Test: `tests/php/unit/MetaTagsTest.php`

**Interfaces:** none new.

- [ ] **Step 1: Write the failing test**

```php
	public function test_archive_og_title_is_brand_when_shop_is_front_page(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'Saltwarp', $og['og:title'] );
	}
```

(The existing `test_archive_og_tags_for_shop` does not stub `is_front_page`; the setUp default `false` keeps its `og:title === 'Shop'` assertion valid.)

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter test_archive_og_title_is_brand_when_shop_is_front_page tests/php/unit/MetaTagsTest.php`
Expected: FAIL (got `Shop`, expected `Saltwarp`).

- [ ] **Step 3: Implement** — replace the shop branch (currently):

```php
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			if ( $shop_id > 0 ) {
				$og['og:title'] = get_the_title( $shop_id );
				$og['og:url']   = get_permalink( $shop_id );
			}
		}
```

with:

```php
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$is_front_page = function_exists( 'is_front_page' ) && is_front_page();
			$shop_id       = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			// When the shop archive is the site front page, the brand is the
			// correct share headline; the bare "Shop" archive title is not.
			if ( $is_front_page ) {
				$og['og:title'] = $site;
			} elseif ( $shop_id > 0 ) {
				$og['og:title'] = get_the_title( $shop_id );
			}
			if ( $shop_id > 0 ) {
				$og['og:url'] = get_permalink( $shop_id );
			}
		}
```

- [ ] **Step 4: Run to verify pass + no regressions**

Run: `vendor/bin/phpunit tests/php/unit/MetaTagsTest.php`
Expected: PASS (all).

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-meta-tags.php tests/php/unit/MetaTagsTest.php
git commit -m "fix(meta): brand og:title when shop archive is the front page (#527)"
```

---

## Task 4: Suppress Jetpack's overlapping OG + description, page-scoped (issue scope item 4)

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-meta-tags.php` (`init()` ~line 308, two new public methods)
- Test: `tests/php/unit/MetaTagsTest.php`

**Interfaces:**
- Produces: `public function suppress_jetpack_open_graph(): void` (hooked `wp_head` priority 1) and `public function suppress_jetpack_description( $meta )` (hooked `jetpack_seo_meta_tags` filter).

**Why these seams (verified against the installed Jetpack):** Jetpack registers `add_action( 'wp_head', 'jetpack_og_tags' )` (default priority 10) in `functions.opengraph.php`; removing that action at `wp_head` priority 1 is deterministic and avoids the `jetpack_enable_open_graph` filter-priority dance Jetpack does on WordPress.com. Jetpack's SEO description is built in `Jetpack_SEO::meta_tags()` and passed through `apply_filters( 'jetpack_seo_meta_tags', $meta )` (key `description`) right before output, so unsetting that key drops only the description and leaves any verification/robots entries intact.

- [ ] **Step 1: Write failing tests**

```php
	public function test_suppress_jetpack_description_drops_only_description_on_commerce(): void {
		Functions\when( 'is_product' )->justReturn( true );
		$out = $this->meta->suppress_jetpack_description(
			array( 'description' => 'dup', 'google-site-verification' => 'keep' )
		);
		$this->assertArrayNotHasKey( 'description', $out );
		$this->assertSame( 'keep', $out['google-site-verification'] );
	}

	public function test_suppress_jetpack_description_noop_off_commerce(): void {
		// All commerce conditionals default false in setUp().
		$in  = array( 'description' => 'keep' );
		$this->assertSame( $in, $this->meta->suppress_jetpack_description( $in ) );
	}

	public function test_suppress_jetpack_description_passes_non_array(): void {
		Functions\when( 'is_product' )->justReturn( true );
		$this->assertNull( $this->meta->suppress_jetpack_description( null ) );
	}

	public function test_suppress_jetpack_open_graph_removes_action_on_commerce(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'has_action' )->justReturn( 10 );
		Functions\expect( 'remove_action' )->once()->with( 'wp_head', 'jetpack_og_tags' );
		$this->meta->suppress_jetpack_open_graph();
	}

	public function test_suppress_jetpack_open_graph_noop_off_commerce(): void {
		// Not a commerce page (setUp defaults). remove_action must never fire.
		Functions\when( 'has_action' )->justReturn( 10 );
		Functions\expect( 'remove_action' )->never();
		$this->meta->suppress_jetpack_open_graph();
	}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter 'suppress_jetpack' tests/php/unit/MetaTagsTest.php`
Expected: FAIL (methods undefined).

- [ ] **Step 3: Implement** — in `init()`, add after the existing `wp_head` line:

```php
		// Avoid duplicate / conflicting tags from Jetpack on the commerce pages
		// where we emit our own. Page-scoped via should_emit(); off commerce
		// pages Jetpack keeps describing posts/pages we do not handle.
		add_action( 'wp_head', array( $this, 'suppress_jetpack_open_graph' ), 1 );
		add_filter( 'jetpack_seo_meta_tags', array( $this, 'suppress_jetpack_description' ) );
```

Add the two methods (after `render_head_tags()`):

```php
	/**
	 * Remove Jetpack's Open Graph tags on commerce pages where we emit our own.
	 *
	 * Runs at wp_head priority 1, before Jetpack's default-priority
	 * `jetpack_og_tags`, so the removal is deterministic regardless of the
	 * `jetpack_enable_open_graph` filter priorities Jetpack sets on
	 * WordPress.com. No-op off commerce pages and when Jetpack is absent.
	 */
	public function suppress_jetpack_open_graph(): void {
		if ( ! $this->should_emit() ) {
			return;
		}
		if ( false !== has_action( 'wp_head', 'jetpack_og_tags' ) ) {
			remove_action( 'wp_head', 'jetpack_og_tags' );
		}
	}

	/**
	 * Drop Jetpack SEO Tools' meta description on commerce pages where we emit
	 * our own, leaving any other Jetpack meta (verification, robots) intact.
	 *
	 * Filters `jetpack_seo_meta_tags`. No-op off commerce pages and for
	 * non-array input.
	 *
	 * @param mixed $meta Jetpack's name => content meta map.
	 * @return mixed Filtered map.
	 */
	public function suppress_jetpack_description( $meta ) {
		if ( is_array( $meta ) && $this->should_emit() ) {
			unset( $meta['description'] );
		}
		return $meta;
	}
```

- [ ] **Step 4: Run to verify pass + no regressions**

Run: `vendor/bin/phpunit tests/php/unit/MetaTagsTest.php`
Expected: PASS (all).

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-meta-tags.php tests/php/unit/MetaTagsTest.php
git commit -m "fix(meta): suppress Jetpack's overlapping OG + description on commerce pages (#527)"
```

---

## Task 5: Detector learns Jetpack as auto-handled (issue scope item 5)

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-seo-plugin-detector.php`
- Modify: `includes/admin/class-wc-ai-storefront-schema-conflict-notice.php` (`maybe_render()` ~line 60)
- Test: `tests/php/unit/SeoPluginDetectorTest.php`

**Interfaces:**
- `detect()` descriptors gain an optional `handled` bool. `has_conflict()` returns true only when a NON-handled (deactivatable) plugin is present.

**Rationale:** Jetpack is bundled-on for WordPress.com/Atomic and does much more than SEO; we must never tell merchants to deactivate it. Since Tasks 1–4 auto-suppress Jetpack's overlap, the deactivate notice must not fire for Jetpack. The detector still reports it (honest state) with `handled => true`.

- [ ] **Step 1: Write failing tests**

```php
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detect_reports_jetpack_as_handled(): void {
		define( 'JETPACK__VERSION', '13.0-test' );
		$found = WC_AI_Storefront_Seo_Plugin_Detector::detect();
		$jetpack = array_values( array_filter( $found, static fn( $p ) => 'jetpack' === $p['slug'] ) );
		$this->assertNotEmpty( $jetpack );
		$this->assertTrue( ! empty( $jetpack[0]['handled'] ) );
		// Jetpack alone is auto-handled, so it is NOT a deactivate-able conflict.
		$this->assertFalse( WC_AI_Storefront_Seo_Plugin_Detector::has_conflict() );
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter test_detect_reports_jetpack_as_handled tests/php/unit/SeoPluginDetectorTest.php`
Expected: FAIL (no jetpack entry; `has_conflict()` false is the only passing part).

- [ ] **Step 3: Implement detector** — add inside `detect()` after the AIOSEO block:

```php
		// Jetpack (incl. WordPress.com / Atomic) emits its own Open Graph +
		// SEO meta description. We auto-suppress the overlap on commerce pages
		// (see WC_AI_Storefront_Meta_Tags), so it is reported as handled — the
		// merchant is never asked to deactivate Jetpack.
		if ( defined( 'JETPACK__VERSION' ) ) {
			$found[] = array(
				'slug'    => 'jetpack',
				'label'   => __( 'Jetpack', 'woocommerce-ai-storefront' ),
				'handled' => true,
			);
		}
```

Replace `has_conflict()`:

```php
	/**
	 * Whether any deactivate-able (non auto-handled) overlapping SEO plugin is
	 * present. Auto-handled entries (e.g. Jetpack, whose overlap we suppress
	 * ourselves) do not count — we never nudge the merchant to remove them.
	 */
	public static function has_conflict(): bool {
		foreach ( self::detect() as $plugin ) {
			if ( empty( $plugin['handled'] ) ) {
				return true;
			}
		}
		return false;
	}
```

Update the `detect()` return-type docblock to `array<int,array{slug:string,label:string,handled?:bool}>`.

- [ ] **Step 4: Implement notice filter** — in `maybe_render()`, replace:

```php
		$plugins = WC_AI_Storefront_Seo_Plugin_Detector::detect();
		$labels  = implode( ', ', array_column( $plugins, 'label' ) );
```

with:

```php
		// Only deactivate-able plugins belong in the deactivate nudge; skip
		// auto-handled ones (e.g. Jetpack).
		$plugins = array_filter(
			WC_AI_Storefront_Seo_Plugin_Detector::detect(),
			static function ( $plugin ) {
				return empty( $plugin['handled'] );
			}
		);
		$labels  = implode( ', ', array_column( $plugins, 'label' ) );
```

- [ ] **Step 5: Run to verify pass + no regressions**

Run: `vendor/bin/phpunit tests/php/unit/SeoPluginDetectorTest.php tests/php/unit/MetaTagsTest.php`
Expected: PASS (all). The existing `test_detect_returns_empty_when_no_seo_plugin_present` still passes (no Jetpack constant in that process).

- [ ] **Step 6: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-seo-plugin-detector.php includes/admin/class-wc-ai-storefront-schema-conflict-notice.php tests/php/unit/SeoPluginDetectorTest.php
git commit -m "feat(seo): detect Jetpack as auto-handled; keep it out of the deactivate notice (#527)"
```

---

## Final verification (before opening the PR)

- [ ] `composer test` — full PHP suite green (call-graph touched shared classes; run the whole suite, not just the two files).
- [ ] `vendor/bin/phpcbf includes/ai-storefront/class-wc-ai-storefront-meta-tags.php includes/ai-storefront/class-wc-ai-storefront-seo-plugin-detector.php includes/admin/class-wc-ai-storefront-schema-conflict-notice.php` then `vendor/bin/phpcs` clean on those files.
- [ ] `php -d memory_limit=2G vendor/bin/phpstan analyse` clean.
- [ ] `./bin/make-pot.sh` — commit the `.pot` diff if any (new `__()` string "Jetpack" + comment-line shifts).
- [ ] Add a `CHANGELOG.md` `[Unreleased]` entry under `### Fixes` (deferred per the doc-timing convention if mid-iteration, but the `[Unreleased]` block must carry it before release).
- [ ] Manual smoke on the live Atomic store (saltwarp.shop) after deploy: exactly one `<meta name="description">` and one OG set on the homepage/shop; `og:title` = brand on the homepage; homepage has an `og:image` + `og:locale`; product + category pages unchanged; a non-commerce page (e.g. Privacy Policy) still shows Jetpack's description/OG.

## Self-Review

1. **Spec coverage:** item 1 → Task 3; item 2 → Task 2; item 3 → Task 1; item 4 → Task 4; item 5 → Task 5. Acceptance criteria (single description + single OG, front-page brand title, archive image+locale, no product/category regression, detector reports Jetpack, unit tests) all mapped. ✓
2. **Placeholder scan:** none — every code step has concrete code. ✓
3. **Type consistency:** `og_locale()`, `archive_default_image()`, `suppress_jetpack_open_graph()`, `suppress_jetpack_description()` names used consistently; descriptor `handled` key consistent across detector + notice + test. ✓
4. **Ordering:** Tasks 1–3 precede Task 4 (hard dependency stated in Global Constraints). ✓
