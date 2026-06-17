# Agent-Reachable Discovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a cold markdown-extraction agent able to bootstrap discovery of saltwarp.shop's machine surfaces — by surfacing `/llms.txt` as a followable body link, replacing the `prod_1,prod_2` lookup example that traps allowlist fetchers, and listing the parameterless bulk `/products.json` in llms.txt's Read-only browsing.

**Architecture:** Three small changes, all to the llms.txt generator/serve class `WC_AI_Storefront_Llms_Txt` plus one hook registration in the main bootstrap. A new private helper sources a real syndicated product for the lookup examples. All output is gated on the existing `enabled` master setting. No theme edits; `/agents.md` inherits the content changes automatically (shared generator + cache).

**Tech Stack:** PHP 7.4+ / WordPress / WooCommerce; PHPUnit + Brain Monkey + Mockery for unit tests; phpcs (WordPress standard) + phpstan.

---

## Background (read once)

Verified facts the implementer must not re-derive:

- The generator is `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php`. `generate()` builds a `$lines[]` array of Markdown and returns `implode( "\n", $lines )` (filtered). Sections are emitted top-to-bottom.
- **The trap:** line ~744, in `## For agents`, renders `?ids=prod_1,prod_2,…`. A claude.ai-style fetch tool snaps to this literal example and returns `not_found`.
- **Read-only browsing:** lines ~758–771. Today it leads with UCP `catalog/search` + `catalog/lookup`, then (gated on `products_json_enabled === 'yes'`) lists scoped `products/{handle}.json`, `collections/{handle}/products.json`, `collections.json`. The bulk `/products.json` is deliberately **omitted** (the comment at ~749–757 says so).
- **UCP id format:** `WC_AI_Storefront_UCP_Product_Translator::PRODUCT_ID_PREFIX` is `'prod_'`; a product's UCP id is `'prod_' . $product->get_id()`.
- **Syndication gate:** `WC_AI_Storefront::is_product_syndicated( $product, $settings )` returns `true` under `product_selection_mode === 'all'` whenever `$product->get_id() > 0`.
- **Hook wiring:** `register_rewrite_rules()` in `includes/class-wc-ai-storefront.php` (~line 237) runs unconditionally and registers all serve callbacks + the `wp_head` UCP head-link (`add_action( 'wp_head', [ $ucp, 'inject_head_link' ] )` at ~line 267). Serve callbacks self-gate on `enabled`. This is where the new `wp_footer` hook goes.
- **Test harness:** `tests/php/unit/LlmsTxtTest.php`. `setUp()` sets `WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes', 'product_selection_mode' => 'all' ]`, stubs `home_url()` → `https://example.com{path}`, `rest_url()` → `https://example.com/wp-json/{path}`, and `wc_get_products()` → `[]`. Tests override stubs per scenario.
- **Docs are deferred.** Do NOT touch CHANGELOG.md / readme.txt / USER-GUIDE during this work — the project does one documentation pass just before release.

## File map

- `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` — add `get_example_catalog_refs()` helper + `render_discovery_link()` method; edit the batch-lookup line and the Read-only browsing section.
- `includes/class-wc-ai-storefront.php` — register the `wp_footer` hook (one line).
- `tests/php/unit/LlmsTxtTest.php` — new tests for the real lookup examples, the bulk-feed listing (inverting an existing assertion), and the footer link.

---

## Task 1: Real catalog refs + fix the `prod_1,prod_2` batch-lookup trap

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php`
- Test: `tests/php/unit/LlmsTxtTest.php`

- [ ] **Step 1: Write the failing test (real ids replace the placeholder)**

Add to `tests/php/unit/LlmsTxtTest.php` (anywhere among the `## For agents` tests, e.g. after `test_for_agents_section_lists_manifest_api_and_checkout`):

```php
	public function test_for_agents_batch_lookup_uses_real_ids_when_products_exist(): void {
		// Two syndicated products are available → the batch-lookup example
		// must use their real UCP ids (prod_<id>), never the prod_1,prod_2
		// placeholder that traps allowlist-based fetch tools into not_found.
		$p1 = \Mockery::mock( 'WC_Product' );
		$p1->shouldReceive( 'get_id' )->andReturn( 30 );
		$p1->shouldReceive( 'get_slug' )->andReturn( 'half-zip-hoodie' );
		$p1->shouldReceive( 'get_type' )->andReturn( 'simple' );
		$p1->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		$p2 = \Mockery::mock( 'WC_Product' );
		$p2->shouldReceive( 'get_id' )->andReturn( 22 );
		$p2->shouldReceive( 'get_slug' )->andReturn( 'day-hoodie' );
		$p2->shouldReceive( 'get_type' )->andReturn( 'simple' );
		$p2->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		Functions\when( 'wc_get_products' )->justReturn( [ $p1, $p2 ] );

		$output = $this->llms->generate();

		$this->assertStringContainsString( 'catalog/lookup?ids=prod_30,prod_22', $output );
		$this->assertStringNotContainsString( 'prod_1,prod_2', $output );
	}

	public function test_for_agents_batch_lookup_falls_back_to_placeholder_on_empty_catalog(): void {
		// No products (default stub returns []) → keep a syntactic placeholder
		// rather than emitting a broken/empty ?ids= example.
		$output = $this->llms->generate();

		$this->assertStringContainsString( 'catalog/lookup?ids=prod_1,prod_2', $output );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/php/unit/LlmsTxtTest.php --filter 'batch_lookup' -v`
Expected: `test_for_agents_batch_lookup_uses_real_ids_when_products_exist` FAILS (output still contains `prod_1,prod_2`); the fallback test PASSES already (placeholder is current behavior).

- [ ] **Step 3: Add the `get_example_catalog_refs()` helper**

In `class-wc-ai-storefront-llms-txt.php`, add this private method (place it near the other private helpers, e.g. just before `discover_sitemap_urls()`):

```php
	/**
	 * Source a real syndicated product for the llms.txt lookup examples.
	 *
	 * The catalog/lookup endpoint is the one a fetch tool is most likely to
	 * call from llms.txt, and allowlist-based tools (e.g. claude.ai web_fetch)
	 * snap to the literal example query string they have seen — so a
	 * placeholder like `?ids=prod_1,prod_2` resolves to a `not_found` stub.
	 * Emitting a REAL id / handle makes the documented example return real
	 * product data instead. Queries up to 10 published, catalog-visible
	 * products and returns the first two that pass the syndication gate.
	 *
	 * @param array $settings Plugin settings (for the syndication gate).
	 * @return array{ids: string[], slug: string} UCP ids (`prod_<id>`) and the
	 *               first product's slug; empty when no syndicated product exists.
	 */
	private function get_example_catalog_refs( array $settings ): array {
		$result = [ 'ids' => [], 'slug' => '' ];
		if ( ! function_exists( 'wc_get_products' ) ) {
			return $result;
		}

		$products = wc_get_products(
			[
				'status'     => 'publish',
				'visibility' => 'catalog',
				'limit'      => 10,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'objects',
			]
		);

		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			if ( ! WC_AI_Storefront::is_product_syndicated( $product, $settings ) ) {
				continue;
			}
			$result['ids'][] = WC_AI_Storefront_UCP_Product_Translator::PRODUCT_ID_PREFIX . $product->get_id();
			if ( '' === $result['slug'] ) {
				$result['slug'] = (string) $product->get_slug();
			}
			if ( count( $result['ids'] ) >= 2 ) {
				break;
			}
		}

		return $result;
	}
```

- [ ] **Step 4: Use the helper in the batch-lookup line**

In `generate()`, immediately after the UCP endpoint bases block (the lines that define `$ucp_api_base`, `$ucp_manifest`, `$ucp_checkout`, `$mcp_enabled` — around line 695–698), add:

```php
		// Real catalog refs for the lookup examples below — a real id/handle
		// keeps allowlist-based fetch tools (which snap to the literal example
		// query string) on a working endpoint instead of a not_found stub.
		$example_refs   = $this->get_example_catalog_refs( $settings );
		$example_ids    = ! empty( $example_refs['ids'] ) ? implode( ',', $example_refs['ids'] ) : 'prod_1,prod_2,…';
		$example_handle = '' !== $example_refs['slug'] ? $example_refs['slug'] : '{handle}';
```

Then change the batch-lookup line (currently `?ids=prod_1,prod_2,…`) to use `$example_ids`:

```php
		$lines[]       = "- **Batch lookup**: `GET {$ucp_api_base}/catalog/lookup?ids={$example_ids}` — fetch up to " . WC_AI_Storefront_UCP_REST_Controller::MAX_IDS_PER_LOOKUP . ' products in one request (or `POST /catalog/lookup`). Prefer this over many single lookups.';
```

(`$example_handle` is consumed in Task 2.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/php/unit/LlmsTxtTest.php --filter 'batch_lookup' -v`
Expected: both PASS.

- [ ] **Step 6: Run the full LlmsTxt suite (guard against regressions)**

Run: `vendor/bin/phpunit tests/php/unit/LlmsTxtTest.php -v`
Expected: all PASS (the default `wc_get_products` → `[]` stub keeps every existing test on the placeholder-fallback path).

- [ ] **Step 7: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-llms-txt.php tests/php/unit/LlmsTxtTest.php
git commit -m "fix(llms): real catalog-lookup example instead of prod_1,prod_2 trap"
```

---

## Task 2: Surface bulk `/products.json` + a real slug example in Read-only browsing

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php`
- Test: `tests/php/unit/LlmsTxtTest.php`

- [ ] **Step 1: Update the existing "never lists bulk" test to assert it IS listed (when the feed is on)**

In `tests/php/unit/LlmsTxtTest.php`, replace the whole `test_read_only_browsing_never_lists_bulk_products_json` method with:

```php
	public function test_read_only_browsing_lists_bulk_products_json_when_feed_on(): void {
		// Allowlist-based fetch tools cannot issue POST (catalog/search) and
		// snap to seen query strings, but CAN fetch one parameterless URL.
		// The bulk /products.json is the simplest whole-catalog surface for
		// them, so it IS listed in Read-only browsing when the feed is on.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'products_json_enabled'  => 'yes',
		];

		$output = $this->llms->generate();

		$this->assertStringContainsString( 'https://example.com/products.json', $output );
		// Scoped per-product path stays listed too.
		$this->assertStringContainsString( 'products/{handle}.json', $output );
		// The /collections/all alias is still NOT advertised (only the bare feed).
		$this->assertStringNotContainsString( 'collections/all/products.json', $output );
	}
```

- [ ] **Step 2: Extend the feed-toggle gating test to cover the bulk line**

In `tests/php/unit/LlmsTxtTest.php`, in `test_read_only_browsing_scoped_json_gated_on_feed_toggle`, add a bulk-feed absence assertion to the OFF block and a presence assertion to the ON block. After the existing `$off` assertions add:

```php
		$this->assertStringNotContainsString( 'https://example.com/products.json', $off );
```

and after the existing `$on` assertions add:

```php
		$this->assertStringContainsString( 'https://example.com/products.json', $on );
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/php/unit/LlmsTxtTest.php --filter 'read_only_browsing' -v`
Expected: `test_read_only_browsing_lists_bulk_products_json_when_feed_on` and the extended gating test FAIL (bulk `/products.json` is not emitted yet).

- [ ] **Step 4: Emit the bulk feed line + real slug example; update the section comment**

In `generate()`, in the `## Read-only browsing` section:

(a) Replace the section's leading comment block (the one stating the bulk feed is deliberately NOT listed) with:

```php
		// ============================================================
		// ## Read-only browsing
		// ============================================================
		// For agents that only need to READ the catalog without transacting.
		// Structured UCP catalog reads lead (currency-aware). The bulk
		// `/products.json` is listed for fetch-only agents that cannot issue
		// POST (catalog/search) and cannot reliably append query params
		// (allowlist fetch tools snap to seen query strings): one parameterless
		// URL returns the whole catalog. The Shopify-compatible `*.json` paths
		// (bulk + scoped) are emitted only when the products.json feed is on.
```

(b) Change the UCP **Look up** bullet to lead with a real, fetchable slug example (uses `$example_handle` from Task 1):

```php
		$lines[] = "- **Look up** — `GET {$ucp_api_base}/catalog/lookup?slug={$example_handle}` (UCP, structured, by product handle) or `?ids={ids}` for batch";
```

(c) Inside the existing `if ( 'yes' === ( $settings['products_json_enabled'] ?? 'no' ) ) {` block, add the bulk feed as the FIRST `.json` bullet, before the scoped `Product JSON` line:

```php
			$lines[] = "- **All products (one file)** — `GET {$site_url}products.json` (Shopify-compatible; whole catalog, no params, no POST — simplest for fetch-only agents)";
```

Leave the existing `Product JSON` / `Collection JSON` / `Collection list` bullets and the closing `Prefer the UCP catalog endpoints…` line unchanged.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/php/unit/LlmsTxtTest.php --filter 'read_only_browsing' -v`
Expected: all PASS.

- [ ] **Step 6: Run the full LlmsTxt suite**

Run: `vendor/bin/phpunit tests/php/unit/LlmsTxtTest.php -v`
Expected: all PASS. (`test_read_only_browsing_leads_with_structured_endpoints` still passes: `catalog/search?q=` and `catalog/lookup?ids=` both still appear — the latter via Task 1's batch-lookup line and the `?ids={ids}` batch clause here.)

- [ ] **Step 7: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-llms-txt.php tests/php/unit/LlmsTxtTest.php
git commit -m "feat(llms): list bulk /products.json + real slug example in Read-only browsing"
```

---

## Task 3: Surface `/llms.txt` as a followable body link (`wp_footer`)

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php`
- Modify: `includes/class-wc-ai-storefront.php`
- Test: `tests/php/unit/LlmsTxtTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/php/unit/LlmsTxtTest.php`:

```php
	public function test_render_discovery_link_outputs_followable_anchor_when_enabled(): void {
		// Markdown-extraction fetch tools strip <head> <link rel> and <script>
		// JSON-LD, but keep visible <a> anchors. A body anchor to /llms.txt
		// makes the whole discovery chain reachable on any page fetch.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes' ];

		ob_start();
		$this->llms->render_discovery_link();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<a ', $html );
		$this->assertStringContainsString( 'href="https://example.com/llms.txt"', $html );
		$this->assertStringContainsString( 'llms.txt', $html );
	}

	public function test_render_discovery_link_silent_when_disabled(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];

		ob_start();
		$this->llms->render_discovery_link();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/php/unit/LlmsTxtTest.php --filter 'render_discovery_link' -v`
Expected: FAIL with "Call to undefined method WC_AI_Storefront_Llms_Txt::render_discovery_link()".

- [ ] **Step 3: Add the `render_discovery_link()` method**

In `class-wc-ai-storefront-llms-txt.php`, add this public method (place it near `serve_llms_txt()`):

```php
	/**
	 * Print a single, visible body link to /llms.txt in the site footer.
	 *
	 * Markdown-extraction fetch tools (e.g. claude.ai web_fetch) strip
	 * `<head>` `<link rel>` tags and `<script>` JSON-LD, and only fetch URLs
	 * that have appeared as literal text in prior fetched content. A visible
	 * `<a>` anchor in the body survives extraction and makes /llms.txt
	 * reachable on any page fetch — and llms.txt enumerates every other
	 * endpoint, so one anchor bootstraps the whole discovery chain. The
	 * companion `<head>` link (UCP `inject_head_link`) stays for crawlers that
	 * read head links; this is its body-visible counterpart for the fetchers
	 * that don't. Gated on the master `enabled` setting, mirroring the serve
	 * handlers.
	 */
	public function render_discovery_link() {
		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return;
		}

		printf(
			'<p class="wc-ai-storefront-agent-discovery" style="text-align:center;font-size:12px;opacity:0.55;margin:1em 0;">Machine-readable store data for AI agents: <a href="%s" rel="alternate" type="text/markdown">llms.txt</a></p>',
			esc_url( home_url( '/llms.txt' ) )
		);
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/php/unit/LlmsTxtTest.php --filter 'render_discovery_link' -v`
Expected: both PASS.

- [ ] **Step 5: Register the `wp_footer` hook**

In `includes/class-wc-ai-storefront.php`, in `register_rewrite_rules()`, immediately after the existing `add_action( 'wp_head', [ $ucp, 'inject_head_link' ] );` line (~267), add:

```php
		// Visible body counterpart to the <head> UCP link: a followable
		// /llms.txt anchor in the footer, so markdown-extraction fetch tools
		// (which strip <head>/<script>) can reach llms.txt and bootstrap
		// discovery. Self-gates on the enabled setting.
		add_action( 'wp_footer', [ $llms_txt, 'render_discovery_link' ] );
```

- [ ] **Step 6: Run the full LlmsTxt suite**

Run: `vendor/bin/phpunit tests/php/unit/LlmsTxtTest.php -v`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-llms-txt.php includes/class-wc-ai-storefront.php tests/php/unit/LlmsTxtTest.php
git commit -m "feat(discovery): surface /llms.txt as a followable footer anchor"
```

---

## Task 4: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Run the complete unit suite**

Run: `composer test`
Expected: all green. (Per project history, always run the FULL suite after touching shared call paths — `generate()` gained a `wc_get_products` call; the global `wc_get_products` → `[]` stub keeps non-LlmsTxt tests unaffected, but confirm.)

- [ ] **Step 2: Static analysis + coding standards**

Run: `vendor/bin/phpcs includes/ai-storefront/class-wc-ai-storefront-llms-txt.php includes/class-wc-ai-storefront.php tests/php/unit/LlmsTxtTest.php`
Then: `vendor/bin/phpcbf <same paths>` if phpcs reports fixable issues, and re-run phpcs.
Run: `vendor/bin/phpstan analyse`
Expected: phpcs clean, phpstan clean.

- [ ] **Step 3: Manual local verification (wp-env, syndication enabled)**

```bash
# real lookup example (not prod_1,prod_2) and bulk products.json present:
curl -s http://localhost:8888/llms.txt | grep -nE 'catalog/lookup\?ids=|catalog/lookup\?slug=|/products\.json'
# footer anchor present in homepage body:
curl -s http://localhost:8888/ | grep -o 'href="[^"]*llms.txt"'
# agents.md stays byte-identical to llms.txt:
diff <(curl -s http://localhost:8888/llms.txt) <(curl -s http://localhost:8888/agents.md) && echo "agents.md == llms.txt"
```
Expected: the lookup example shows a real `prod_<id>` / `?slug=<handle>`; `/products.json` appears in Read-only browsing; the homepage body contains an `href=".../llms.txt"` anchor; `agents.md` matches llms.txt.

- [ ] **Step 4: Confirm docs are intentionally untouched**

Run: `git diff --name-only main...HEAD`
Expected: only the two source files, the test file, and the spec/plan docs under `docs/superpowers/` — NOT CHANGELOG.md / readme.txt / USER-GUIDE (those are handled in the pre-release doc pass).

---

## Notes

- **agents.md parity is automatic** — `serve_agents_md()` echoes the same `get_cached_content()`, so Tasks 1–2 reach `/agents.md` with no extra work; Step 3 of Task 4 confirms it.
- **Footer styling is adjustable** — the inline-styled muted line in Task 3 is a deliberately minimal, theme-agnostic default; the merchant/themer can restyle via the `.wc-ai-storefront-agent-discovery` class.
- **Deferred (separate effort):** homepage JSON-LD `ItemList` of featured products + Google Merchant Center feed.

## Self-review

- **Spec coverage:** Change 1 (followable llms.txt link) → Task 3. Change 2 (replace `prod_1,prod_2`) → Task 1. Change 3 (bulk `/products.json` in Read-only browsing) → Task 2. Test impact (invert bulk-absent assertion, update placeholder assertions, add footer + example tests) → Tasks 1–3. Verification → Task 4. All spec sections covered.
- **Placeholder scan:** every code/test step shows complete code; no TBD/"handle edge cases"/"similar to". The empty-catalog fallback (`prod_1,prod_2,…` / `{handle}`) is an explicit, tested branch, not a stub.
- **Type/name consistency:** `get_example_catalog_refs()` returns `['ids' => string[], 'slug' => string]`; `$example_ids` / `$example_handle` are derived in Task 1 and `$example_handle` is consumed in Task 2; `render_discovery_link()` name matches between the method (Task 3 Step 3) and the hook registration (Task 3 Step 5) and the tests; `WC_AI_Storefront_UCP_Product_Translator::PRODUCT_ID_PREFIX` and `is_product_syndicated()` match the verified source.
