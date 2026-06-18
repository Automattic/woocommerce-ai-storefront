# Base-Currency Shopify Feed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Shopify-compatible feed (`/products.json`, `/products/{handle}.json`, `/collections/{handle}/products.json`) emit the store's **base currency** regardless of WooPayments multi-currency geo-presentment, so its prices are stable, cacheable, and consistent with the UCP API and the storefront base.

**Architecture:** The feed's mapper reads prices via `$product->get_price()` (the `'view'` context), which fires the `woocommerce_product_get_price` filter that multi-currency uses to convert. WooCommerce stores prices in base currency and the `'edit'` context skips that display filter, so switching the mapper's price reads to `'edit'` yields base currency with no conversion logic and no global-state manipulation. A one-time feed-cache-version bump on upgrade abandons the existing currency-poisoned transients.

**Tech Stack:** PHP 7.4+ / WordPress / WooCommerce; PHPUnit + Brain Monkey + Mockery.

---

## Background (verified — do not re-derive)

- `includes/ai-storefront/class-wc-ai-storefront-products-feed.php`:
  - **Line 801** (simple-product / default variant): `'price' => self::money( $product->get_price() ),`
  - **Line 904** (variable-product variation, inside `build_variants()`): `'price' => self::money( $variation->get_price() ),`
  - **`compare_at()`** (≈ lines 819-830):
    ```php
    private static function compare_at( $product ): ?string {
        if ( method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() ) {
            $regular = $product->get_regular_price();
            if ( is_numeric( $regular ) ) {
                return self::money( $regular );
            }
        }
        return null;
    }
    ```
  - **`money()`** formats a numeric to a 2-decimal string (`'0.00'` for non-numeric).
  - **`const VERSION_OPTION = 'wc_ai_storefront_products_feed_version';`** (line 57); cache keys (`wc_ai_sf_pjson_/prod_/coll_/colls_`) embed this version integer; `CACHE_TTL = HOUR_IN_SECONDS` (line 63).
- `includes/ai-storefront/class-wc-ai-storefront-cache-invalidator.php`: `public function bump_products_feed_version(): void` (line 324) does `update_option( VERSION_OPTION, ( (int) get_option( VERSION_OPTION, 1 ) ) + 1, false )`; it is hooked to ~7 product/category/settings actions.
- `includes/class-wc-ai-storefront.php`: the upgrade branch (lines 310-347) — `if ( $needs_flush || $stored_version !== WC_AI_STOREFRONT_VERSION ) { … update_option( 'wc_ai_storefront_version', … ); … delete_transient( WC_AI_Storefront_Llms_Txt::host_cache_key() ); delete_transient( 'wc_ai_storefront_ucp' ); }` — busts content caches inline.
- Tests: `tests/php/unit/ProductsFeedMapperTest.php` (Brain Monkey + Mockery). Existing tests mock `get_price`/`get_regular_price` with `->andReturn(...)` and **no** `->with()`, so Mockery matches *any* argument — they stay green when the code starts calling `get_price('edit')`. Run a single file: `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php` (PHPUnit 10.5 — do NOT pass `-v`).

**Key Mockery fact this plan relies on:** `shouldReceive('get_price')->with('edit')->andReturn('X')` and `->with('view')->andReturn('Y')` and `->withNoArgs()->andReturn('Y')` can coexist on one mock; a call routes to the matching expectation. Before the fix the code calls `get_price()` (→ the `withNoArgs`/`'view'` value); after the fix it calls `get_price('edit')` (→ the `'edit'` value). That difference is what makes each test fail-then-pass.

## File map

- `includes/ai-storefront/class-wc-ai-storefront-products-feed.php` — new `base_price()` helper; `map_product()` (×2 reads) + `compare_at()` read `'edit'`; new static `bump_cache_version()`.
- `includes/ai-storefront/class-wc-ai-storefront-cache-invalidator.php` — `bump_products_feed_version()` delegates to the feed's static.
- `includes/class-wc-ai-storefront.php` — call the feed's static bump in the upgrade branch.
- `tests/php/unit/ProductsFeedMapperTest.php` — base-currency immunity tests.
- `tests/php/unit/CacheInvalidatorTest.php` (or the existing cache-invalidator test file) — bump test.

---

## Task 1: `base_price()` helper + simple-product base read

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-products-feed.php`
- Test: `tests/php/unit/ProductsFeedMapperTest.php`

- [ ] **Step 1: Write the failing test**

Append to `ProductsFeedMapperTest.php` (mirrors `test_map_simple_product_emits_single_default_variant`, but splits the price by context — `'view'` is the converted presentment, `'edit'` is the stored base; the feed must emit base):

```php
	public function test_map_simple_product_emits_base_currency_not_presentment(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );

		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( 26 );
		$p->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$p->shouldReceive( 'get_slug' )->andReturn( 'canvas-belt' );
		$p->shouldReceive( 'get_description' )->andReturn( '' );
		$p->shouldReceive( 'get_category_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_tag_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_gallery_image_ids' )->andReturn( [] );
		$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
		$p->shouldReceive( 'get_sku' )->andReturn( 'BELT' );
		// Multi-currency presentment: 'view' (default) returns the converted
		// CAD price; 'edit' returns the stored USD base. The feed must emit base.
		$p->shouldReceive( 'get_price' )->with( 'edit' )->andReturn( '45.99' );
		$p->shouldReceive( 'get_price' )->with( 'view' )->andReturn( '64.99' );
		$p->shouldReceive( 'get_price' )->withNoArgs()->andReturn( '64.99' );
		$p->shouldReceive( 'get_regular_price' )->andReturn( '45.99' );
		$p->shouldReceive( 'is_on_sale' )->andReturn( false );
		$p->shouldReceive( 'is_in_stock' )->andReturn( true );
		$p->shouldReceive( 'is_purchasable' )->andReturn( true );
		$p->shouldReceive( 'needs_shipping' )->andReturn( true );

		$out = WC_AI_Storefront_Products_Feed::map_product( $p );

		$this->assertSame( '45.99', $out['variants'][0]['price'] );
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php --filter 'base_currency_not_presentment'`
Expected: FAIL — got `'64.99'` (the code calls `get_price()` with no context → the `'view'`/`withNoArgs` value).

- [ ] **Step 3: Add the `base_price()` helper and use it at line 801**

In `class-wc-ai-storefront-products-feed.php`, add the helper next to `money()`:

```php
	/**
	 * A product or variation's price in the store's BASE currency, independent
	 * of any active multi-currency (e.g. WooPayments) presentment.
	 *
	 * The Shopify-compatible feed is a single-currency surface (base) and is
	 * cached, so it must not vary by request geolocation. `get_price('edit')`
	 * returns the raw stored value WITHOUT the `woocommerce_product_get_price`
	 * 'view'-context filter that multi-currency plugins use to convert — and
	 * WooCommerce stores prices in base currency, so 'edit' IS base.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string Base-currency price (may be '' when unset).
	 */
	private static function base_price( $product ): string {
		return (string) $product->get_price( 'edit' );
	}
```

Change the simple-product / default-variant read (line 801) from:
```php
			'price'             => self::money( $product->get_price() ),
```
to:
```php
			'price'             => self::money( self::base_price( $product ) ),
```

- [ ] **Step 4: Run to verify it passes (and nothing regressed)**

Run: `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php`
Expected: PASS — the new test plus every existing test (their argument-less `get_price` mocks still match `get_price('edit')`).

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-products-feed.php tests/php/unit/ProductsFeedMapperTest.php
git commit -m "fix(feed): emit base currency for simple-product price (base_price helper)"
```

---

## Task 2: variation base read

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-products-feed.php`
- Test: `tests/php/unit/ProductsFeedMapperTest.php`

- [ ] **Step 1: Write the failing test**

Append to `ProductsFeedMapperTest.php` (mirrors `test_map_variable_product_emits_options_and_positional_variants`, splitting the variation price by context):

```php
	public function test_map_variation_emits_base_currency_not_presentment(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );
		Functions\when( 'sanitize_title' )->alias(
			function ( $t ) {
				return strtolower( str_replace( ' ', '-', (string) $t ) );
			}
		);
		Functions\when( 'wc_attribute_label' )->alias(
			function ( $name ) {
				return ucfirst( str_replace( 'pa_', '', (string) $name ) );
			}
		);

		$variation = \Mockery::mock( 'WC_Product' );
		$variation->shouldReceive( 'get_id' )->andReturn( 3890 );
		$variation->shouldReceive( 'get_variation_attributes' )->andReturn(
			[ 'attribute_pa_size' => 'sm' ]
		);
		$variation->shouldReceive( 'get_sku' )->andReturn( 'BELT-SM' );
		// Multi-currency presentment vs stored base, same shape as the simple test.
		$variation->shouldReceive( 'get_price' )->with( 'edit' )->andReturn( '45.99' );
		$variation->shouldReceive( 'get_price' )->with( 'view' )->andReturn( '64.99' );
		$variation->shouldReceive( 'get_price' )->withNoArgs()->andReturn( '64.99' );
		$variation->shouldReceive( 'is_on_sale' )->andReturn( false );
		$variation->shouldReceive( 'get_regular_price' )->andReturn( '45.99' );
		$variation->shouldReceive( 'is_in_stock' )->andReturn( true );
		$variation->shouldReceive( 'is_purchasable' )->andReturn( true );
		$variation->shouldReceive( 'needs_shipping' )->andReturn( true );

		Functions\when( 'wc_get_product' )->justReturn( $variation );

		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( 26 );
		$p->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$p->shouldReceive( 'get_slug' )->andReturn( 'canvas-belt' );
		$p->shouldReceive( 'get_description' )->andReturn( '' );
		$p->shouldReceive( 'get_category_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_tag_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_gallery_image_ids' )->andReturn( [] );
		$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( true );
		$p->shouldReceive( 'get_variation_attributes' )->andReturn(
			[ 'pa_size' => [ 'sm', 'lxl' ] ]
		);
		$p->shouldReceive( 'get_children' )->andReturn( [ 3890 ] );

		$out = WC_AI_Storefront_Products_Feed::map_product( $p );

		$this->assertSame( '45.99', $out['variants'][0]['price'] );
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php --filter 'test_map_variation_emits_base_currency'`
Expected: FAIL — got `'64.99'`.

- [ ] **Step 3: Use `base_price()` at line 904**

Change the variation read (line 904, inside `build_variants()`) from:
```php
				'price'             => self::money( $variation->get_price() ),
```
to:
```php
				'price'             => self::money( self::base_price( $variation ) ),
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php`
Expected: PASS (new test + all existing, including `test_map_variable_product_emits_options_and_positional_variants`).

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-products-feed.php tests/php/unit/ProductsFeedMapperTest.php
git commit -m "fix(feed): emit base currency for variation price"
```

---

## Task 3: `compare_at()` reads base regular price

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-products-feed.php`
- Test: `tests/php/unit/ProductsFeedMapperTest.php`

- [ ] **Step 1: Write the failing test**

Append to `ProductsFeedMapperTest.php`:

```php
	public function test_compare_at_uses_base_regular_price_not_presentment(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );

		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( 26 );
		$p->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$p->shouldReceive( 'get_slug' )->andReturn( 'canvas-belt' );
		$p->shouldReceive( 'get_description' )->andReturn( '' );
		$p->shouldReceive( 'get_category_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_tag_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_gallery_image_ids' )->andReturn( [] );
		$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
		$p->shouldReceive( 'get_sku' )->andReturn( 'BELT' );
		$p->shouldReceive( 'get_price' )->with( 'edit' )->andReturn( '34.99' ); // base sale
		$p->shouldReceive( 'get_price' )->withNoArgs()->andReturn( '49.99' );
		$p->shouldReceive( 'is_on_sale' )->andReturn( true );
		// Multi-currency presentment vs stored base for the REGULAR price.
		$p->shouldReceive( 'get_regular_price' )->with( 'edit' )->andReturn( '45.99' );
		$p->shouldReceive( 'get_regular_price' )->withNoArgs()->andReturn( '64.99' );
		$p->shouldReceive( 'is_in_stock' )->andReturn( true );
		$p->shouldReceive( 'is_purchasable' )->andReturn( true );
		$p->shouldReceive( 'needs_shipping' )->andReturn( true );

		$out = WC_AI_Storefront_Products_Feed::map_product( $p );

		$this->assertSame( '34.99', $out['variants'][0]['price'] );            // base sale price
		$this->assertSame( '45.99', $out['variants'][0]['compare_at_price'] ); // base regular, not 64.99
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php --filter 'compare_at_uses_base_regular'`
Expected: FAIL — `compare_at_price` is `'64.99'` (code calls `get_regular_price()` with no context).

- [ ] **Step 3: Read the regular price in the `'edit'` context**

In `compare_at()`, change:
```php
			$regular = $product->get_regular_price();
```
to:
```php
			$regular = $product->get_regular_price( 'edit' );
```
(Leave `is_on_sale()` as-is — it is a sale-vs-regular ratio comparison, so it is currency-invariant.)

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php`
Expected: PASS (new test + the existing `test_map_simple_product_on_sale_sets_compare_at_price`, whose argument-less `get_regular_price` mock still matches).

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-products-feed.php tests/php/unit/ProductsFeedMapperTest.php
git commit -m "fix(feed): compare_at reads base regular price"
```

---

## Task 4: feed cache-version bump on upgrade

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-products-feed.php` (new static)
- Modify: `includes/ai-storefront/class-wc-ai-storefront-cache-invalidator.php` (delegate)
- Modify: `includes/class-wc-ai-storefront.php` (call in upgrade branch)
- Test: `tests/php/unit/CacheInvalidatorTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/php/unit/CacheInvalidatorTest.php` (Brain Monkey). If that file does not exist, create it with the standard `Monkey\setUp()/tearDown()` scaffold used by the other tests:

```php
	public function test_bump_cache_version_increments_feed_version_option(): void {
		Functions\when( 'get_option' )->justReturn( 3 );
		$captured = null;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) use ( &$captured ) {
				if ( WC_AI_Storefront_Products_Feed::VERSION_OPTION === $name ) {
					$captured = [ $value, $autoload ];
				}
				return true;
			}
		);

		WC_AI_Storefront_Products_Feed::bump_cache_version();

		$this->assertSame( [ 4, false ], $captured ); // incremented, autoload disabled
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/php/unit/CacheInvalidatorTest.php --filter 'bump_cache_version_increments'`
Expected: FAIL — `WC_AI_Storefront_Products_Feed::bump_cache_version()` does not exist.

- [ ] **Step 3: Add the static `bump_cache_version()` to the feed class**

In `class-wc-ai-storefront-products-feed.php`, add:

```php
	/**
	 * Increment the feed cache version, orphaning every cached page/endpoint
	 * at once (each key embeds this version). The feed class owns VERSION_OPTION,
	 * so it owns the bump; the cache invalidator and the upgrade path both call
	 * this. Autoload disabled — the value is read only inside the feed serve path.
	 */
	public static function bump_cache_version(): void {
		update_option( self::VERSION_OPTION, ( (int) get_option( self::VERSION_OPTION, 1 ) ) + 1, false );
	}
```

- [ ] **Step 4: Delegate the invalidator's instance method**

In `class-wc-ai-storefront-cache-invalidator.php`, change `bump_products_feed_version()`'s body to delegate (keeps all ~7 hooks working, single source of truth):

```php
	public function bump_products_feed_version(): void {
		WC_AI_Storefront_Products_Feed::bump_cache_version();
	}
```

- [ ] **Step 5: Call it in the upgrade branch**

In `includes/class-wc-ai-storefront.php`, inside the `if ( $needs_flush || $stored_version !== WC_AI_STOREFRONT_VERSION ) { … }` block, immediately after the two existing `delete_transient(...)` lines (after line 346, before the closing `}`), add:

```php
				// Orphan the Shopify-feed caches on a code update so a fix to the
				// mapper (e.g. the base-currency price fix) doesn't keep serving
				// stale/currency-poisoned entries for up to CACHE_TTL (1 hour).
				WC_AI_Storefront_Products_Feed::bump_cache_version();
```

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit tests/php/unit/CacheInvalidatorTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-products-feed.php includes/ai-storefront/class-wc-ai-storefront-cache-invalidator.php includes/class-wc-ai-storefront.php tests/php/unit/CacheInvalidatorTest.php
git commit -m "fix(feed): bump feed cache version on upgrade to drop poisoned entries"
```

---

## Task 5: Full local verification

**Files:** none (verification only)

- [ ] **Step 1: Full suite**

Run: `composer test`
Expected: all green (PHPUnit reports OK; the 3 pre-existing `UpdaterTest` deprecations are unrelated).

- [ ] **Step 2: Standards + static analysis**

Run: `vendor/bin/phpcbf includes/ai-storefront/class-wc-ai-storefront-products-feed.php includes/ai-storefront/class-wc-ai-storefront-cache-invalidator.php includes/class-wc-ai-storefront.php tests/php/unit/ProductsFeedMapperTest.php tests/php/unit/CacheInvalidatorTest.php`
Then: `vendor/bin/phpcs` on those same paths — expect clean.
Then: `vendor/bin/phpstan analyse --memory-limit=1G` — expect `[OK] No errors`.

- [ ] **Step 3: Docs-untouched guard**

Run: `git diff --name-only main...HEAD | grep -iE 'CHANGELOG|readme|USER-GUIDE' || echo "none ✓"`
Expected: `none ✓` (docs handled in the pre-release pass).

---

## Task 6: Post-deploy live verification + A/B gate

**Files:** none (verification only — runs after this fix ships to saltwarp.shop)

This is the gate that confirms Approach A (`'edit'` bypasses WooPayments) on the real store. `'edit'` skipping the `'view'` filter is standard WC-core behavior, so A is expected to hold; this step proves it and defines the fallback.

- [ ] **Step 1: Re-fetch every feed endpoint and assert base USD**

```bash
for u in \
  "https://saltwarp.shop/products/canvas-belt.json" \
  "https://saltwarp.shop/products.json" \
  "https://saltwarp.shop/collections/belts/products.json"; do
  echo "== $u =="
  curl -s --max-time 20 "$u" | grep -oE '"(price|compare_at_price)":"[0-9.]+"' | grep -i canvas -A0 2>/dev/null | head
  curl -s --max-time 20 "$u" | python3 -c "import sys,json
d=json.load(sys.stdin)
ps=d.get('products') or ([d['product']] if 'product' in d else [])
for p in ps:
    if p.get('handle')=='canvas-belt':
        print('  canvas-belt variants:', [(v['title'],v['price']) for v in p['variants']])"
done
```
Expected: **`45.99`** (base USD) on every endpoint, even from a Canada-geolocated request — the CAD `64.99` is gone.

- [ ] **Step 2: A/B decision**

- If Step 1 shows base USD everywhere → **Approach A confirmed. Done.**
- If any endpoint still shows the geo currency (`64.99`) → `get_price('edit')` did **not** bypass WooPayments on this install. **Switch to Approach B:** force the store base currency around the feed render — in the serve methods (`serve_products_json`/`serve_single_product`/`serve_collection_products`), before building the body, temporarily neutralize the multi-currency price conversion (remove the `woocommerce_product_get_price` / `woocommerce_product_variation_get_price` filters that `WC_Payments_Multi_Currency()` registered, capturing them so they can be re-added in a `finally`), build the feed, then restore. Spec Approach B as a follow-up task only if this branch is taken; do not pre-build it.

## Notes

- **Inert without multi-currency:** when no presentment filter is active, `get_price('edit') == get_price('view')`, so every endpoint's output is byte-identical to today.
- **Docs deferred** to the pre-release pass (this fix rides the next release).
- **Out of scope:** product-page JSON-LD (stays geo-presentment, per spec) and the checkout-anchor reachability finding (separate brainstorm).

## Self-review

- **Spec coverage:** base-currency reads on simple (Task 1) + variation (Task 2) + compare_at (Task 3); cache-poison cleanup via the upgrade bump (Task 4); local verification (Task 5); live A/B gate (Task 6). All spec sections covered. The "strictly Shopify-faithful / no currency field" and "no UCP/JSON-LD change" non-goals are honored by construction (no such code is added).
- **Placeholder scan:** every code step has complete, runnable content. The single contingency (Approach B in Task 6) is explicitly gated on a failing live check and labeled "do not pre-build" — it is a documented fallback, not a placeholder on the main path.
- **Type/name consistency:** `base_price()` (Task 1) is reused verbatim in Task 2; `bump_cache_version()` (Task 4, feed class) is called by both the invalidator delegate and the upgrade branch; `VERSION_OPTION`, `compare_at()`, `money()`, `build_variants()` all match the verified source signatures.
