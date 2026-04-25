# Changelog

## [0.1.9] – 2026-04-25

### Fixes
- **robots.txt: dropped redundant per-bot sitemap `Allow:` rules.** Pre-0.1.9 the AI-bot section emitted `Allow:` directives for every entry in `COMMON_SITEMAP_PATHS` inside every per-bot block. With every bot in `LIVE_BROWSING_AGENTS` (17 entries) × 4 sitemap paths that produced ~68 redundant lines on a typical merchant's robots.txt (visible on `pierorocca.com` as the same 4-line `Allow:` block repeated ~17 times). The defense those rules were meant to provide didn't actually exist: `Allow:` only matters when there's a `Disallow:` that would otherwise block the path, and none of the per-bot `Disallow:` rules touch sitemap paths. Sitemap discovery still works via the top-level `Sitemap:` directives (emitted by WP core / Jetpack / SEO plugins above the AI section, plus re-emitted at the bottom of the AI section). The `COMMON_SITEMAP_PATHS` constant remains in place — it's still used by `WC_AI_Storefront_Llms_Txt::discover_sitemap_urls()` for HEAD-probing candidate sitemap locations to list in llms.txt (a legitimate use; that path is gated by 200 OK response, not by robots.txt mechanics).
- **UCP manifest: `extends` now uses the spec-compliant array form.** The `com.woocommerce.ai_storefront` extension capability previously declared `extends: "dev.ucp.shopping"` (a service ID). Per the UCP 2026-04-08 capability schema, the regex pattern accepts any matching identifier but the field's description constrains the meaning to capability IDs ("Parent capability(s) this extends. Use array for multi-parent extensions."), and `dev.ucp.shopping` is a service, not a capability. Switched to the array form listing all three canonical shopping capabilities the extension augments: `dev.ucp.shopping.catalog.search`, `dev.ucp.shopping.catalog.lookup`, `dev.ucp.shopping.checkout`. The array form is more honest about what `store_context` actually augments — it applies to all three operations, not to "the service" abstractly. **Consumer-side compat note:** any agent or downstream consumer reading `manifest.capabilities['com.woocommerce.ai_storefront'][0].extends` as a string must accept array per the UCP spec's `oneOf: [string, array<string>]` shape; spec-conformant consumers already handle both.

### Refactors
- **`CANONICAL_CAPABILITIES` constant** introduced on `WC_AI_Storefront_Ucp` as a single source of truth for the three canonical shopping capability suffixes. Used by both the canonical-capability declarations and the `com.woocommerce.ai_storefront[0].extends` array, so a future addition (e.g. `dev.ucp.shopping.subscription`) updates one place and both sides stay in sync.

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
