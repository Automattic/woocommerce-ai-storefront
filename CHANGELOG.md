# Changelog

## [Unreleased]

---

## [0.3.1] – 2026-04-26

### Fixes
- **Merchant's `allowed_crawlers` setting was not enforced at the UCP REST endpoint.** Pre-fix: a merchant could disable a brand (e.g. ChatGPT) in the AI Crawlers list and that brand's `robots.txt` lines would update accordingly, but a UCP request from `openai.com` still hit `/wc/ucp/v1/catalog/search` and got commerce data. The endpoint trusted the merchant's intent only at the discovery layer, not at the access layer. New `check_agent_access` permission_callback wires the UCP-Agent header parse + canonical-brand lookup into the existing allowed_crawlers list, returning WP_Error 403 when every crawler ID mapped to that brand is absent. Three commerce routes are gated (`catalog/search`, `catalog/lookup`, `checkout-sessions`); `extension/schema` stays public so manifest discovery still works for any agent. Open-spec wedge preserved: agents that don't canonicalize to a known brand ("Other AI", You.com, Kagi) pass through unchanged. New `UCP_AGENT_CRAWLER_MAP` translation table bridges the canonical-brand-name namespace (used for attribution) to the crawler-ID namespace (used in robots.txt + the AI Crawlers UI).

### Tests
- 13 new tests covering the gate at three layers: pure-function `is_agent_allowed()` decision logic (9 cases — empty/unknown/known-allowed/known-blocked/strict-string-compare/structural map check/UCPPlayground self-reference), route-wiring (commerce routes gated, schema public), and `check_agent_access()` end-to-end (12 cases — header missing/empty/unparseable/Other-AI pass-through, blocked-with-403, blocked-message-includes-brand-name, settings-source fallbacks for missing-key + wrong-type).

---

## [0.3.0] – 2026-04-26

### Features
- **Per-product final-sale override on the WC product editor's Inventory tab.** Single "AI: Final sale" checkbox flips the product's JSON-LD `hasMerchantReturnPolicy` to `MerchantReturnNotPermitted` regardless of the store-wide policy mode. Variants inherit from their parent (resolved via `wp_get_post_parent_id()` at the JSON-LD layer). Override gate runs before store-wide mode logic, so flagged products emit the override even when store-wide is `unconfigured`. Reuses store-wide policy page link when configured. New `WC_AI_Storefront_Product_Meta_Box` class with strict `'yes' ===` POST validation and disambiguated `update_post_meta` failure logging.
- **Test crawlers category in the Discovery tab — UCPPlayground entry.** New `TEST_CRAWLERS` constant in `WC_AI_Storefront_Robots`. Discovery tab's "Training crawlers" group renamed to "Training and Test Crawlers"; UCP Playground (`ucpplayground.com`) is the first entry, default-off (merchant opts in for validation sessions). Group definition supports a `categories: [...]` override so one merchant-facing heading can cover multiple backend categories.

### Fixes
- **`inventoryLevel` JSON-LD emission targeted the wrong shape.** Pre-fix, the assignment `$markup['offers']['inventoryLevel'] = ...` mixed list + assoc shapes when the input was the production WC core shape (a list of Offer dicts). PHP serializes mixed-keys arrays as JSON objects (`{"0": {...}, "inventoryLevel": {...}}`), which Schema.org/Google validators reject as a malformed Offer list. Now targets `$markup['offers'][0]['inventoryLevel']` with the `isset() && is_array()` guard the priceCurrency / hasMerchantReturnPolicy / shippingDetails emissions also use.
- **Drop em-dash from plugin Description header.** Em-dash rendered inconsistently across WP plugin-listing surfaces (wp-cli CSV split on the dash, ASCII-rendering tools showed `?` or stripped). Replaced with a comma-joined ASCII-clean alternative. Same meaning, no rendering edge cases.
- **Policies tab: ToggleGroupControl rendered full-width with the WP-default flat-black filled pill instead of the elevated-pill treatment used on Product Visibility.** Two compounding causes: (1) `isBlock` prop was set, which stretches the segments to container width — the established pattern (documented in `product-selection.js`) explicitly omits `isBlock` so the segments read as a compact "pick one of N" strip rather than row headers spanning the panel; (2) the inline `<style>` block delivering the elevated-pill visual treatment lived only in `product-selection.js`, scoped to that one component, so it never reached the Policies tab. Removed `isBlock`. Extracted the style block into a shared `<ToggleGroupStyles />` component (`client/settings/ai-storefront/toggle-group-styles.js`) that both tabs render — future tabs using this control inherit the styling automatically.
- **Policies tab: "Return methods" checkboxes stacked flush against each other with no row breathing room.** The `__nextHasNoMarginBottom` prop on each `CheckboxControl` strips WP's default bottom margin, but the surrounding `<fieldset>` had no replacement gap. Wrapped the checkboxes in a `display: flex; flex-direction: column; gap: 6px` container so spacing is deterministic regardless of WP component-version changes to default margins.

### Refactors
- **Extract toggle-group visual treatment to a shared component.** The elevated-pill `<style>` block previously lived inline inside `product-selection.js`; new `<ToggleGroupStyles />` exports both the JSX style element + the `TOGGLE_GROUP_CLASSNAME` constant. Both `product-selection.js` and `policies-tab.js` import + render it. Future tabs adding a primary mode-selector inherit the visual treatment by importing the same module — no copy-paste, no drift between tabs.
- **Bucket unknown AI agents under "Other AI" instead of scattering raw hostnames.** Pre-this-release, when a UCP-Agent profile hostname wasn't in `KNOWN_AGENT_HOSTS`, attribution stamped the raw hostname into the order (`utm_source = "novel-ai.example.com"`), scattering the merchant's Origin column and Top Agent stats card with one-off vendor names. Unknown agents now bucket to `OTHER_AI_BUCKET` ("Other AI"). The raw hostname is preserved separately in the new `_wc_ai_storefront_agent_host_raw` order meta + `ai_agent_host_raw` URL param + a debug-log line — so merchants who drill into an "Other AI" order still see who actually sent it. Length-cap (RFC 1035 max 253 chars) + hostname-shape regex applied at capture time. New `canonicalize_host_idempotent()` sibling helper at the display layer prevents already-canonical brand names from being re-canonicalized into "Other AI" — protects the admin Recent Orders panel from mis-labelling every modern AI order.

### Observability
- **Admin order-edit screen surfaces the raw agent host alongside the canonical name.** When an order has both `_wc_ai_storefront_agent` (canonical, e.g. "Other AI") and `_wc_ai_storefront_agent_host_raw` (raw hostname) stamped, the existing AI Agent Attribution box in the order-edit screen shows both. Drill-in surface for "Other AI" bucketed orders so merchants can identify the actual hostname.

---

## [0.2.0] – 2026-04-26

### Features
- **Policies tab with store-wide return & refund policy section.** New "Policies" tab on the AI Storefront admin page lets merchants choose between "Returns accepted" / "No returns (final sale)" / "Don't expose" with optional days, fees, methods, and a policy page link. Drives the `hasMerchantReturnPolicy` JSON-LD emission at the Offer level — no more structurally invalid claims on every product.

### Fixes
- **UCP catalog & search: product-scope filter was registered against a fictitious WC hook, never ran.** The plugin registered `restrict_to_syndicated_products()` against `woocommerce_store_api_product_collection_query_args` — a hook that doesn't exist in WooCommerce core. WC's Store API delegates straight to `ProductQuery::get_objects()` → `WP_Query` with no such filter, so the product-scoping callback never ran in production. Tests passed because they invoked the callback directly without going through `apply_filters()` or `rest_do_request()`. Re-registered against `pre_get_posts` (a real WP-level hook) at `PHP_INT_MAX` priority, gated by (1) UCP-controller-initiated dispatch, (2) `post_type === 'product'`, (3) per-mode logic. Static-class sentinel ensures cross-instance idempotency. New `UcpStoreApiPreGetPostsTest` (11 tests, including a regression guard asserting registration against `pre_get_posts` and never against the fictitious hook).
- **Drop invalid `MerchantReturnFiniteReturnWindow` emission lacking `merchantReturnDays`; replace with smart-degrade structured emission.** Pre-this-release every product page emitted a `MerchantReturnFiniteReturnWindow` enum with no `merchantReturnDays`, no `merchantReturnLink`, no `returnFees`, no `returnMethod`. Google validators reject this combination. Default `unconfigured` mode now emits no policy block; `returns_accepted` smart-degrades to `MerchantReturnUnspecified` when days are unset.
- **Decode double-encoded `seller.name` HTML entities.** WC core sometimes feeds an already-encoded value into structured data (e.g. `Piero&amp;#039;s` for a name with an apostrophe), surfacing visible literal `&amp;` and `&#039;` in JSON-LD. We now decode twice through `html_entity_decode()` so AI agents JSON.parse-ing the markup get the literal merchant-typed string. Audit bug #3.
- **Normalize `weight` value to numeric form.** WC stores weight as a free-form string (often `.5` without the leading zero); casting through `(float)` produces a canonical `0.5` numeric so consumers parsing JSON-LD with strict number deserializers see a well-formed number. Audit bug #4.
- **Copy `priceCurrency` to top-level `Offer` for Google's preferred placement.** WC core writes the currency under nested `priceSpecification[0].priceCurrency`; we now also surface it at the outer Offer level (without overwriting an existing value). Audit bug #5.

### Refactors
- **Move `hasMerchantReturnPolicy` and `shippingDetails` from Product to Offer level (Schema.org/Google preferred placement).** Audit bug #6 fix folded into the same change.
- **Discovery tab: replaced "Store API" row with "UCP API".** The row used to surface `/wp-json/wc/store/v1/` as if it were the AI commerce surface, but AI agents actually call our UCP wrapper at `/wp-json/wc/ucp/v1/` (per the manifest at `/.well-known/ucp`). Store API is the underlying transport our UCP catalog/search/lookup/checkout-sessions handlers dispatch through; naming the row "Store API" forced merchants to reason about an implementation layer that has nothing to do with what AI agents see. The new "UCP API" row points at the correct endpoint and describes its purpose ("Structured commerce API for AI agents — catalog search, lookup, and checkout sessions"). Code-level vocabulary stays unchanged — `WC_AI_Storefront_UCP_Store_API_Filter` and the rate-limiter class are correctly named for their architectural roles.
- **Rate-limit section: dropped "Store API" framing in merchant-facing copy.** The limiter still lives at the Store API filter layer (correct architecturally — that's where the request lands), but it only throttles AI user-agents and only applies through the UCP path. Merchant copy now talks about throttling "AI agents" and "AI crawlers", which is what's actually observable to the merchant, not the internal layer where enforcement happens.

### Observability
- **UCP REST controller: log unknown query params + surface them via `X-WC-AI-Storefront-Unknown-Params` response header.** When agents send keys not declared in the route schema (e.g. `search` instead of `query`), we now log the unknown keys and echo them in a response header, bounded to 8 keys × 256 chars total with ASCII `...` truncation (RFC 9110 token-safe). Gated to associative-shape bodies (`! array_is_list($body)`) to skip well-formed list payloads. Helps agent authors diagnose silent param-name typos.

### Hardening
- **Declare WooCommerce as a hard plugin dependency via `Requires Plugins: woocommerce` header.** WP 6.5+ enforces this declaratively: the Plugins screen now shows "Cannot Activate" until WooCommerce is installed and active, blocks `activate_plugin()` from succeeding without WC, and surfaces an inline "Install WooCommerce" link. The legacy `WC requires at least: 9.9` header is informational only — it tells merchants what version they need but doesn't block activation. The existing runtime guards (`class_exists('WooCommerce')` checks in `wc_ai_storefront_init()` and `wc_ai_storefront_activate()`) are kept as defense-in-depth for the `--force` activation path and for the case where WC is deactivated after this plugin was already active. Plugin's `Requires at least: 6.7` already exceeds the 6.5 minimum for this header, so it's safe to add unconditionally.

---

## [0.1.14] – 2026-04-25

### Features
- **Product count pill on the By-taxonomy row.** The "Products by category, tag, or brand" row previously showed only the taxonomy-count summary (e.g. `1 category · 1 tag · 1 brand`), leaving merchants to switch to the Overview tab to see how many products their selection actually scoped to. Adds a second pill alongside the taxonomy summary showing the live scoped product count (e.g. `12 products`), pulled from the same `/admin/product-count` endpoint the Overview tab uses. Visual symmetry with the All-row's existing "35 products" pill — each row now tells the merchant both "what you selected" and "how many products that means" at a glance.

### Refactors
- New `ModeBadgeGroup` component renders one or more `ModeBadge` pills side-by-side. `ModeRow.badgeLabel` now accepts either a single `string` (one pill, unchanged) or an `Array<{key, label}>` of objects (multiple adjacent pills). Each entry's explicit `key` supplies React's reconciliation identity so the same logical pill keeps its DOM node across label updates (e.g. `'Loading…'` → `'12 products'`) — label-content or index-based keys would remount on every text change and produce a visible flash. Used so the by_taxonomy row can show its taxonomy summary AND scoped count without growing the `ModeRow` API surface.

---

## [0.1.13] – 2026-04-25

### Fixes
- **robots.txt: dropped bottom-of-section `Sitemap:` re-emission.** Pre-0.1.13 the AI Storefront section appended `Sitemap:` directives at the bottom, justified as "defense against parsers that process directives in document order." Two failure modes drove the deletion: (1) when the input had no `Sitemap:` directive at filter-time (because Jetpack et al. emit theirs via the `do_robotstxt` action, AFTER our `robots_txt` filter runs), the fallback to `get_sitemap_url('index')` fired and emitted a fictional `wp-sitemap.xml` URL — observed on `pierorocca.com` pointing crawlers at a 404 because Jetpack disables WP-core's sitemap; (2) RFC 9309 specifies `Sitemap:` as a top-level directive whose position is not order-sensitive, so the "ordering defense" was theoretical, not load-bearing. Top-level Sitemap directives (whoever emits them — WP core, Jetpack, Yoast, etc.) are authoritative and stand alone. The `extract_sitemap_urls()` helper that fed the bottom-of-section emission has been removed entirely as dead code.

### Tests
- Replaced `test_sitemap_directive_reemitted_at_end_of_section` and `test_sitemap_directive_falls_back_to_wp_core_when_no_sitemap_in_input` with two regression guards covering both shapes of the deletion: `test_no_bottom_of_section_sitemap_reemission` asserts top-level `Sitemap:` directives appear exactly once in the output (at their original location), not duplicated at the bottom; `test_no_sitemap_directive_emitted_when_input_has_none` covers the other failure mode — verifies no `Sitemap:` directive is emitted at all when the filtered input contains none (the case where Jetpack's `do_robotstxt`-emitted directives aren't visible to our filter).

---

## [0.1.12] – 2026-04-25

### Fixes
- **Stat cards no longer repeat the time-period suffix.** Pre-0.1.12 each Overview card label included a parenthetical period (e.g. "Total Orders (7d)", "AI Revenue (30d)"), but the period dropdown directly above the cards already conveys the same scope. Repeating it on every card was redundant noise that competed with the actual metric label for the merchant's attention. Dropped the suffix from all five Overview cards (Total Orders, AI Orders, AI Revenue, AOV, Top agent). The dropdown is the single source of truth — change it once, all cards refetch with the new period. Removes the now-unused `periodLabels` constant + 4 translatable strings (`24h`, `7d`, `30d`, `Year`) and 4 sprintf templates from the i18n surface.

---

## [0.1.11] – 2026-04-25

### Fixes
- **Option-selector: padding override now applies, breathing room visibly increased.** The 0.1.10 hotfix correctly retargeted the elevated-pill styling to `::before` and `[aria-checked="true"]`, but the padding override was still using `.components-button` — same Emotion-CSS-in-JS broken-class-name issue. The selected pill rendered cramped, with the label text touching the pill edges. 0.1.11 retargets the padding via `[role="radio"]` (the stable ARIA contract on the option buttons) and bumps the value from 14px to 18px so the pill carries ~6px of "halo" space on each side of the label, matching the elevation visual the rest of Option A is selling.

---

## [0.1.10] – 2026-04-25

### Fixes
- **Option-selector visual treatment now actually applies.** The 0.1.8 ToggleGroupControl restyle (elevated white pill on recessed neutral track) targeted hard-coded class names (`.components-toggle-group-control-backdrop`, etc.) that don't exist in the rendered DOM — `@wordpress/components` 28.x uses Emotion CSS-in-JS with dynamically-generated class names. The override rules silently didn't match anything, leaving the WP default (flat black `::before` pseudo-element pill, plus a vivid `:focus-within` border in the merchant's admin theme color). 0.1.10 retargets the actual rendered structure: `.ai-storefront-taxonomy-toggle::before` for the moving "selected" pill (it's a CSS pseudo-element on the wrapping div, not a separate `.backdrop` node), and `[aria-checked="true"]` for selected-state text styling (ARIA is the stable contract across WP component versions). Also tones down the `:focus-within` border so the admin theme color doesn't compete with the elevated-pill visual signal — keyboard focus indication is preserved on the selected button via `:focus-visible` + WP admin theme color outline.
- **llms.txt: `all` mode no longer enumerates taxonomies.** Pre-0.1.10 the `## Product Categories` / `## Product Tags` / `## Product Brands` sections rendered top-N (≤20) terms by count even when the merchant had `product_selection_mode = 'all'` (no scoping). That falsely implied a restriction the merchant hadn't configured AND under-reported (the truncated 20-term list could miss long-tail terms agents would want to navigate by). 0.1.10 suppresses these sections entirely in `all` mode — the merchant exposed the full catalog, so agents wanting enumeration use the Store API (the canonical source of truth). The sections still emit under `by_taxonomy` mode where they describe the actual scoping; `selected` mode continues to suppress them.

### Tests
- New `LlmsTxtTest::test_all_mode_suppresses_taxonomy_sections` locks the new behavior. Three category-rendering tests updated to seed `by_taxonomy` mode + `selected_categories` so they continue to exercise the now-restricted rendering path.

---

## [0.1.9] – 2026-04-25

### Fixes
- **robots.txt: dropped redundant per-bot sitemap `Allow:` rules.** Pre-0.1.9 the AI-bot section emitted `Allow:` directives for every entry in `COMMON_SITEMAP_PATHS` inside every per-bot block. With every bot in `LIVE_BROWSING_AGENTS` (17 entries) × 4 sitemap paths that produced ~68 redundant lines on a typical merchant's robots.txt (visible on `pierorocca.com` as the same 4-line `Allow:` block repeated ~17 times). The defense those rules were meant to provide didn't actually exist: `Allow:` only matters when there's a `Disallow:` that would otherwise block the path, and none of the per-bot `Disallow:` rules touch sitemap paths. Sitemap discovery still works via the top-level `Sitemap:` directives (emitted by WP core / Jetpack / SEO plugins above the AI section, plus re-emitted at the bottom of the AI section). The `COMMON_SITEMAP_PATHS` constant remains in place — it's still used by `WC_AI_Storefront_Llms_Txt::discover_sitemap_urls()` for HEAD-probing candidate sitemap locations to list in llms.txt (a legitimate use; that path is gated by 200 OK response, not by robots.txt mechanics).
- **robots.txt: dropped unsupported `Crawl-delay` directive.** Google explicitly doesn't support `Crawl-delay` (uses Search Console's crawl-rate setting); Bing's compliance is inconsistent in practice; major AI crawlers (OpenAI, Anthropic, Perplexity) don't publish their stance. The directive surfaced in Google Search Console's robots.txt tester as an "ignored" warning globally — flagged regardless of which `User-agent:` block it appeared in — creating merchant-facing noise without delivering enforceable rate-limiting. Hard rate enforcement remains via the plugin's Store API rate limiter (HTTP 429 + `Retry-After` at 25 req/min per bot by default), which every well-behaved crawler honors more reliably than the polite advisory ever did. Saves another 17 lines from the AI section per typical merchant. The `CRAWL_DELAY_SECONDS` constant has been removed.
- **UCP manifest: `extends` now uses the spec-compliant array form.** The `com.woocommerce.ai_storefront` extension capability previously declared `extends: "dev.ucp.shopping"` (a service ID). Per the UCP 2026-04-08 capability schema, the regex pattern accepts any matching identifier but the field's description constrains the meaning to capability IDs ("Parent capability(s) this extends. Use array for multi-parent extensions."), and `dev.ucp.shopping` is a service, not a capability. Switched to the array form listing all three canonical shopping capabilities the extension augments: `dev.ucp.shopping.catalog.search`, `dev.ucp.shopping.catalog.lookup`, `dev.ucp.shopping.checkout`. The array form is more honest about what `store_context` actually augments — it applies to all three operations, not to "the service" abstractly. **Consumer-side compat note:** any agent or downstream consumer reading `manifest.capabilities['com.woocommerce.ai_storefront'][0].extends` as a string must accept array per the UCP spec's `oneOf: [string, array<string>]` shape; spec-conformant consumers already handle both.

### Refactors
- **`CANONICAL_CAPABILITIES` constant** introduced on `WC_AI_Storefront_Ucp` as a single source of truth for the three canonical shopping capability suffixes. Both sides of the manifest's structural invariant now derive from it: (a) the canonical capability keys + bindings under `manifest.capabilities` are constructed by a new `build_canonical_capabilities()` helper that derives spec/schema URLs from each suffix (`catalog.search` → `/specification/catalog/search` + `/schemas/shopping/catalog_search.json`); (b) the `com.woocommerce.ai_storefront[0].extends` array is built by `array_map`'ing the constant. A future addition (e.g. `dev.ucp.shopping.subscription`) updates the constant and both sides reflect it.

### Tests
- `RobotsTest`: replaced the per-block sitemap-Allow assertions with a regression guard that locks the new behavior. Tightened the new not-contains test (`test_sitemap_paths_not_emitted_as_per_bot_allow_rules`) with a path-pattern regex assertion that catches reintroductions at non-canonical paths (e.g. `/custom-sitemap.xml`), not just the four canonical ones. New `test_sitemap_directive_falls_back_to_wp_core_when_no_sitemap_in_input` covers the WP-core `get_sitemap_url('index')` fallback branch (previously only exercised via the deleted per-block emission). Removed three obsolete tests covering `COMMON_SITEMAP_PATHS` per-block emission, dedupe with discovered paths, and WP-core fallback to per-block.
- `UcpTest`: updated the `extends` lock-in test to assert the array form. New `test_extends_entries_are_real_capabilities` adds a semantic invariant — every entry in `extends` must be a key in the manifest's declared capabilities — so a future PR that lists a fictional capability ID can't pass tests with a structurally invalid manifest.

---

## [0.1.8] – 2026-04-25

### Features
- **Two new Overview stat cards: AOV and Top agent.** Pre-0.1.8 the Overview tab showed 4 cards (Products Exposed, Total Orders, AI Orders, AI Revenue). 0.1.8 adds two more derived directly from data already in the `/stats` REST response — no new tables, no new queries. AOV is the weighted mean across AI-attributed orders (computed from totals, not averaged from per-agent AOVs to avoid the unweighted-mean-of-weighted-means trap). Top agent is sorted by `orders DESC, revenue DESC` so the card stays stable across daily snapshots when order counts tie; the subvalue reads `N orders | M% of AI orders` where the share denominator is AI orders, not all-store orders. The four agent-instrumented cards (visits, queries, products surfaced, top product) ship in a follow-up release once the counter-table schema lands.
- **Option-selector visual refresh.** The taxonomy `ToggleGroupControl` (Categories / Tags / Brands) now renders as a white pill with a soft contact shadow on a recessed neutral track, replacing the flat black `backdrop` that the WP default ships. Two depth cues — track inset + pill elevation — give the selected state a "lit thumb on a groove" affordance instead of a paint-fill rectangle. Hover-only on unselected, `:focus-visible` keyboard ring against the WP admin theme color, and a `forced-colors: active` rule for Windows High Contrast mode.

### Fixes
- **`/stats` no longer aggregates orders with empty `_wc_ai_storefront_agent` meta.** Both query branches (HPOS + legacy postmeta) now filter `meta_value <> ''` before `GROUP BY`, so a corrupt order with empty agent meta can't become a `by_agent` row that surfaces on the Top Agent card as a populated stat block with no name. Defense in depth: `derive_stats()` also skips empty-name rows it receives.
- **Currency rendering uses the symbol, not the ISO code.** Pre-0.1.8 the AI Revenue card rendered "USD 42.00" / "EUR 42.00" because the response only carried the currency code. The `/stats` response now also exposes `currency_symbol` (sourced from `get_woocommerce_currency_symbol()`); the AI Revenue and AOV cards prefer the symbol with a graceful fallback to the code, then to `$`.

### Refactors
- **`derive_stats()` extracted from `get_stats()`.** The post-query math (AOV, top-agent ranking, share-percent) now lives in a static helper that takes pre-aggregated totals + per-agent breakdown. The `$wpdb` query stays in `get_stats()`; the helper is unit-testable without mocking. No behavior change to existing fields — `get_stats()` returns the same shape it did before, with the two new `ai_aov` / `top_agent` / `currency_symbol` fields appended additively.
- **Defensive guards in `derive_stats()`.** Early-exit when `$total_orders <= 0`. Empty-string agent names skipped before ranking. Top-agent name capped at 64 chars (`TOP_AGENT_NAME_MAX_LENGTH`). Tertiary tie-break by agent name ASC for `usort` stability when both orders AND revenue tie.

### Tests
- New tests for `derive_stats()` covering AOV math, top-agent winner selection, tie-break semantics (revenue secondary, name tertiary, sub-dollar precision), share-percent denominator, defensive branches, and return-shape contract locks.

---

## [0.1.7] – 2026-04-25

### Fixes
- **Store API filter is now scoped to UCP-controller dispatches.** Pre-0.1.7 the `woocommerce_store_api_product_collection_query_args` filter was registered globally — every Store API consumer (front-end Cart, block-theme Checkout, themes, third-party plugins) silently saw the merchant's AI scoping applied to their own queries. The Products tab is labeled "Products available to AI crawlers"; applying the scope to non-AI Store API traffic violated that promise. New `enter_ucp_dispatch()` / `exit_ucp_dispatch()` markers wrap the UCP REST controller's `rest_do_request` calls; the filter self-gates and returns args unchanged outside that scope.
- **llms.txt now lists selected tags and brands, not just categories.** Pre-0.1.7 only the `## Product Categories` section emitted under `by_taxonomy` mode, so a merchant scoping by 3 categories + 1 tag + 1 brand saw only the categories. Now emits three independent sections (`## Product Categories`, `## Product Tags`, `## Product Brands`), each populated from its corresponding `selected_*` array.

### Tests
- Two new tests for the Store API filter UCP-dispatch gate (`test_filter_is_noop_outside_ucp_dispatch`, `test_dispatch_depth_counter_balances`).
- LlmsTxtTest's `categories_section_suppressed_when_only_tags_brands_selected` extended to verify the new tag and brand sections render with their own fixtures.

---

## [0.1.6] – 2026-04-25

### Fixes
- **Plugin Update Checker: branch + auth fixes.** Two issues caused merchants to see "Could not determine if updates are available" with GitHub API 403 errors:
  - PUC defaulted to fetching `/branches/master`, but this repo uses `main`. The 404 was masked as a 403 under GitHub's anonymous rate-limit throttle, producing a misleading error. Now sets `setBranch( 'main' )` explicitly.
  - Anonymous GitHub API requests are limited to 60/hour per IP. Stores on shared hosting or with frequent dashboard refreshes hit the limit. Added a `wc_ai_storefront_github_token` filter that, when populated with a GitHub personal access token, raises the limit to 5,000/hour via `setAuthentication()`.

---

## [0.1.5] – 2026-04-24

### Features
- **By-taxonomy scoping is now a UNION across categories, tags, and brands.** Pre-0.1.5, the `product_selection_mode` enum had separate `categories` / `tags` / `brands` values, with only one taxonomy's selection actually enforcing at a time — the other selections were inert on-disk data. A merchant picking 3 categories + 1 brand saw only one dimension enforce (whichever they toggled last), despite the multi-count badge ("3 categories · 1 brand") implying all dimensions were active. 0.1.5 consolidates the three taxonomy modes into a single `by_taxonomy` value that enforces `selected_categories ∪ selected_tags ∪ selected_brands` everywhere — `is_product_syndicated()`, the Store API filter, `llms.txt`, and the "Products Exposed" count. The UI's multi-count badge is now truthful.

### Fixes
- **"Products Exposed" card reflects the actual scoped product count.** Pre-0.1.5 the card could display `"3 categories"` (wrong unit for a card labeled "Products Exposed") or fall through to literal `"All"` for tag/brand modes (because the client-side switch had no branch for them). New `/admin/product-count` REST endpoint runs the same UNION query the Store API filter applies and returns the actual product count.
- **Empty-selection warning no longer fires when only unrelated taxonomies are empty.** Pre-0.1.5: merchant with `mode=tags, selected_tags=[], selected_categories=[3], selected_brands=[1]` saw "No tags selected, products hidden" — false, because under UNION the categories and brand still enforce. Now the warning fires only when all three `selected_*` arrays are empty (after accounting for the brand-downgrade exception) and mode is `by_taxonomy`.
- **llms.txt Product Categories section now matches what's actually syndicated.** For `by_taxonomy` mode: lists `selected_categories` when non-empty, suppresses when only tags or brands are selected (because emitting generic category counts would misrepresent the UNION'd product set).

### UI polish (rolled up from the closed 0.1.4 PR)
- **Taxonomy selector: breathing room around the selected pill** — `ToggleGroupControl` without `isBlock` renders options at content-exact width, leaving the selected black pill nearly flush with its label. Added a scoped CSS override widening the horizontal padding.
- **Remove redundant "new products not auto-included" Notice from Selected mode** — The yellow warning duplicated information already in the mode's header description. Hand-picked-products mode means hand-picking by definition.

### Migration
- **Silent upgrade on first settings read.** Any stored `product_selection_mode` value of `categories`, `tags`, or `brands` is rewritten to `by_taxonomy` in place. Non-destructive for `selected_*` arrays — all three stay intact, and the new UNION enforcement picks up whichever was populated. Also includes defensive fallbacks in `is_product_syndicated()`, the Store API filter, and `get_syndicated_categories()` so explicit callers passing a pre-0.1.5 `$settings` payload still get correct behavior.

### Tests
- Test suite extended with coverage for: UNION `tax_query` across multiple taxonomies; brands-skipped-when-unregistered UNION path; legacy-mode fallback compatibility; empty-all-three zero-match policy; llms.txt category section in UNION mode with and without selected categories; settings migration (legacy → `by_taxonomy`); per-product UNION enforcement against mixed selections; `update_settings()` sanitization across the new + legacy enum vocabulary; `/admin/product-count` endpoint contract.

---

## [0.1.3] – 2026-04-24

### Fixes
- **Products tab: taxonomy tab clicks now register** — Clicking Tags or Brands inside the By-taxonomy row only produced a screen flash before reverting to the prior selection. Introduced by the browse/commit decouple in 0.1.2: the sync effect keeping `activeTaxonomy` aligned to the server's normalized taxonomy was using a dependency-based guard that fired on every render while the two were out of sync, immediately reverting any local tab-change. Switched to a rising-edge ref pattern so the sync only fires when `normalizedServerTaxonomy` actually changes (e.g. after a Save completes), not whenever local view state drifts.

### UI polish
- **Taxonomy selector: content-sized instead of full-width** — Dropped `isBlock` from the `ToggleGroupControl`, restoring the compact segmented-pill appearance. Stretched full-width, it read like form fields; at content size it reads as "pick one of three" and matches Gutenberg's use of the same component.
- **"All published products" row collapses cleanly when selected** — Removed the empty panel that opened below the selected All row. The panel previously rendered a single gray auto-include line above a floating horizontal rule with whitespace above and below, contributing no information the header didn't already convey.
- **Inline warning Notices have proper padding + separation** — The two yellow `<Notice>` components (By-taxonomy empty-selection, Selected-mode new-products warning) were using WP's default admin-banner styling inside a card-embedded context. Added internal padding and a top margin so the text doesn't hug the yellow accent and the Notice separates from the content above.

---

## [0.1.2] – 2026-04-24

### Features
- **Products tab: decouple browse-state from scoping commit** — Clicking a taxonomy tab (Categories / Tags / Brands) inside the By-taxonomy row is now browse-only. The persisted scoping mode is no longer flipped on tab click, which removed a spurious "No tags selected, products hidden" warning that fired for merchants with a valid brand-scoped catalog who clicked the Tags tab to look around. The warning now tracks the *enforcing* mode, not the viewed tab. ([#71](https://github.com/Automattic/woocommerce-ai-storefront/pull/71))

### Fixes
- **llms.txt: hide category list when scoping by brand / tag / selected products** — Previously, the Product Categories section emitted the top-20 store categories in every mode, so a merchant scoping to a single brand saw their llms.txt advertise categories unrelated to the scoped catalog. Now only emitted for `all` and `categories` modes. ([#70](https://github.com/Automattic/woocommerce-ai-storefront/pull/70))
- **llms.txt: use prefixed UCP IDs in the Attribution request-body example** — Changed `"id": "123"` to `"id": "prod_123"` to match the wire format UCP catalog/search/lookup responses actually emit; agents copy-pasting the example would otherwise POST a raw numeric ID and be rejected by `parse_ucp_id()`. ([#70](https://github.com/Automattic/woocommerce-ai-storefront/pull/70))

### UI polish
- **Remove sample-product grid from the "All products" row** — The 6-tile preview rendered as half-empty placeholder boxes on fresh-install / staging stores, reading as "looks half-broken" rather than "here's your catalog." Count pill + deep link carry the information value. ([#70](https://github.com/Automattic/woocommerce-ai-storefront/pull/70))
- **By-taxonomy row: multi-count badge + conditional copy** — Badge now surfaces every non-zero taxonomy count (e.g. "3 categories · 1 brand") so a merchant flipping tabs doesn't lose sight of their full scope. Separator exposed as a translatable string for RTL locales. Badge is suppressed when the By-taxonomy row isn't selected and no counts apply. ([#71](https://github.com/Automattic/woocommerce-ai-storefront/pull/71))
- **llms.txt declutter** — Dropped stale post-v2.0.0 archaeology comments, URL-template patterns that contradicted the POST-first checkout posture, and the merchant-facing agent-mapping table. Collapsed programmatic-verification bullets into a single pointer. ([#70](https://github.com/Automattic/woocommerce-ai-storefront/pull/70))

---

## [0.1.1] – 2026-04-24

### Features
- **Products tab: tags, brands & taxonomy grouping** — Merchants can now restrict AI catalog syndication to specific product tags or WooCommerce Brands (requires WC ≥ 9.5). Taxonomy groups are displayed in a grouped selector. Per-product gate added to `llms.txt`, JSON-LD, and Store API output. ([#65](https://github.com/Automattic/woocommerce-ai-storefront/pull/65))

### Fixes
- **Overview: empty state for AI orders table** — Resolved a blank-panel regression when no AI-influenced orders exist yet. ([#67](https://github.com/Automattic/woocommerce-ai-storefront/pull/67))

### CI / Infra
- Add `.pot` freshness check — CI now fails if translatable strings are changed without regenerating the translation template. ([#66](https://github.com/Automattic/woocommerce-ai-storefront/pull/66))
- Expand PHP test matrix to 8.1 – 8.4 and align `composer.json` platform floor to tested reality. ([#68](https://github.com/Automattic/woocommerce-ai-storefront/pull/68))
- Add build-freshness and supply-chain audit jobs to CI. ([#69](https://github.com/Automattic/woocommerce-ai-storefront/pull/69))

---

## [0.1.0] – initial release
