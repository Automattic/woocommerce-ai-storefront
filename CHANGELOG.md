## [Unreleased]

### Features
### Fixes
### Refactors
### Tests
### Docs
### Chores

---

## [0.10.0] – 2026-05-06

### Features

- **Homepage `OnlineStore` JSON-LD with auto-sourced brand identity.** PR #311. Closes #308.
  - `output_store_jsonld()` now emits `@type: OnlineStore` (a Schema.org `Organization` subtype) instead of `@type: Store` (a `LocalBusiness` subtype). AI-readiness audits looking for an `Organization`-shaped entity now find one. The `OnlineStore` type is the most accurate fit — the plugin only loads inside an active WC install, so the site is definitionally an online store.
  - Three new identity sub-fields, all auto-sourced from existing WP/WC data — no new merchant settings, no new admin UI:
    - `logo` — custom-logo theme mod with `get_site_icon_url()` as fallback. Omitted entirely when neither is set (Schema.org's `logo` is for the merchant's primary brand mark; emitting a default WP favicon URL would mislead crawlers about brand identity).
    - `address` — Schema.org `PostalAddress` block built from `WC()->countries->get_base_*` (the same values WC's WooCommerce > Settings > General "Store Address" form populates). Each sub-key (`addressLocality`, `addressRegion`, `postalCode`) is omitted when WC has no value; the whole block is omitted when no base country is configured. **`streetAddress` is intentionally suppressed even when WC has it** — for an `OnlineStore` (vs. a `LocalBusiness`) the street address adds little verification value (buyers don't visit) but the privacy/safety risk is real: many small Woo merchants populate WooCommerce > Settings > General with their home address (the field is required at WC setup so tax calculations work) and don't realize it would be published in machine-readable form. City + region + postcode + country preserve every meaningful identity signal (jurisdiction, shipping origin, fraud-check disambiguation) without the residential-address leak.
    - `contactPoint.email` — two-stage resolution mirroring WC's own "where do customer replies land" logic: (1) `woocommerce_email_reply_to_address` when `woocommerce_email_reply_to_enabled === 'yes'`, then (2) `woocommerce_email_from_address` as a fallback, *but* rejected when its local-part matches a noreply pattern (`noreply@`, `no-reply@`, `donotreply@`, `do-not-reply@`, case-insensitive). Many merchants set From to a noreply address to avoid bounce-handling — publishing it as a customer-facing contact would route real questions into a black hole. Each candidate validated via `is_email`. Whole block omitted when neither stage produces a usable address. **Does NOT fall back to `admin_email`** — admin email is intentionally private (password resets, security notifications) and merchants do not expect it to be published in JSON-LD.
  - Phone (`contactPoint.telephone`) and social profiles (`sameAs`) are not emitted from this plugin. Neither has a canonical WP/WC source today; ecosystem plugins (Jetpack, Yoast, etc.) capture these via their own settings. The `wc_ai_storefront_jsonld_store` filter is the documented injection point — see the filter docblock at the call site for an example.
  - Backward compatibility: existing fields (`name`, `description`, `url`, `currenciesAccepted`, `potentialAction`, `hasOfferCatalog`) emit unchanged. The only schema-shape change is `@type: Store` → `@type: OnlineStore` plus optional new keys appended at the end of the JSON-LD body.

### Fixes

- **Manifest and llms.txt hits from UCP-aware clients (UCPScanner, UCPCheckerBot, etc.) recorded as zero.** PR #307. Closes #309.
  - `WC_AI_Storefront_Robots::detect_crawler_from_ua()` only matched substrings against the hardcoded `AI_CRAWLERS` allow-list (curated for the search-era AI training-bot ecosystem). Any UA outside that list — including legitimate UCP discovery scanners that identify themselves with well-formed product tokens like `UCPScanner/1.0 (+https://ucpscanner.com)` — returned `''`, and the call sites in `WC_AI_Storefront_UCP::serve_manifest()` and `WC_AI_Storefront_Llms_Txt::serve_llms_txt()` skipped `record()` entirely. Merchants saw zero hits in the analytics page even when UCP scanners actively crawled their stores.
  - Added a stage-2 fallback that extracts the leading RFC 7231 product token when stage 1 misses. `UCPScanner/1.0 (+...)` records as `UCPScanner`; `UCPCheckerBot/1.0 (+...)` records as `UCPCheckerBot`; version variants of the same scanner roll up under a single row. Real browser visits record as `Mozilla` — intentional, low frequency, useful forensic signal that a human is poking at the URL. Empty UAs continue to short-circuit so anonymous requests aren't recorded.
  - Stage 1 (known-crawler substring match, longest-first) is unchanged: realistic UAs like `Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)` still translate to `Claude` via the recorder's brand-name table, regardless of position within the UA string.

- **llms.txt hits always zero on CDN-fronted installs.** PR #307. Same shape as the 0.9.1 manifest fix (#283); llms.txt was missed in that hotfix.
  - `/llms.txt` was emitting `Cache-Control: public, max-age=3600`, causing Atomic/WordPress.com CDN edges (and any reverse proxy) to serve from cache — PHP never executed, so `WC_AI_Storefront_Crawl_Logger::record()` was never reached for crawler hits even when the UA was recognized.
  - Switched to `Cache-Control: no-store`. The llms.txt body is still cached internally via the existing transient (1-hour TTL, single-flight protected), so per-origin serving cost remains low while the edge is bypassed.

### Refactors
### Tests

- **`RobotsTest.php`** — 10 new unit tests for `detect_crawler_from_ua()` covering: missing UA, blank UA, known crawler in realistic Mozilla-preamble UA, longest-token-wins (`Microsoft-BingBot-Extended` vs. `Bingbot`), stage-2 product-token extraction for UCPScanner / UCPCheckerBot / curl / Mozilla browser UAs, unparseable leading characters, and version-stripping (UCPScanner/1.0 and UCPScanner/2.4.1-beta both → `UCPScanner`).
- **`JsonLdTest.php`** — 24 new unit tests for the homepage `OnlineStore` JSON-LD covering: `@type` switch, logo-precedence (custom-logo wins over site-icon), logo omit-when-empty, address auto-source from `WC()->countries`, `streetAddress` privacy guard (omitted even when WC has it), address omit-when-no-country, contactPoint reply-to-precedence, contactPoint fall-through-to-from when reply-to enabled but address blank, contactPoint omit-when-from-noreply, dataProvider locking 13 noreply patterns including plus-addressing variants, regression guards on `support@noreply.example.com` (domain-only, publishable) and `noreplies@store.com` (substring lookalike, publishable), and a no-`admin_email`-fallback regression guard.

### Docs

### Chores

- **Local development — project-root `docker-compose.yml`.** PR #312. Replaces the `wp-env`-based local dev workflow with a project-root compose file matching the house style of woopay, woocommerce-payments, and woocommerce-gateway-stripe. `docker compose up -d` from a fresh clone now produces correctly-named containers under project `woocommerce-ai-storefront`; a one-shot `bootstrap` service auto-installs WP, downloads + activates WooCommerce + this plugin, sets pretty permalinks, flushes rewrite rules, and enables plugin syndication. Idempotent — subsequent `up` calls are no-ops. The `.wp-env.json` config is intentionally retained for backward compat. See `AGENTS.md` "Local development" section for the full workflow.

---

## [0.9.1] – 2026-05-04

### Fixes

- **UCP manifest hits always zero on CDN-fronted installs.** Closes #283.
  - `/.well-known/ucp` was emitting `Cache-Control: public, max-age=3600`, causing Atomic/WordPress.com CDN edges (and any reverse proxy) to serve the manifest from cache — PHP never executed, so `WC_AI_Storefront_Crawl_Logger::record()` was never reached and the hit was never written to the database.
  - Switched to `Cache-Control: no-store`. The manifest is generated per-request (one settings read + JSON encode, no external calls), so per-origin serving has negligible cost and restores accurate hit recording.

---

## [0.9.0] – 2026-05-04

### Features

- **Policies tab — Shipping card with merchant-configurable handling time.** Closes #278.
  - New **Shipping** card on the Policies tab lets merchants declare their order handling time (minimum and maximum business days) via a pair of 0–365 stepper inputs.
  - When both values are > 0, the plugin emits `OfferShippingDetails.deliveryTime.handlingTime` as a Schema.org `ShippingDeliveryTime` + `QuantitativeValue` block in the product JSON-LD. AI agents that surface shipping timelines (e.g. "ships in 1–2 business days") can read this directly.
  - Clamping is symmetric with the PHP sanitizer: `max` is always raised to meet `min`, never the reverse. Inputs are clamped 0–365 on both client and server.
  - A live preview beneath the steppers shows the would-be structured-data block so merchants can verify the output before saving.
  - New `WC_AI_Storefront_Handling_Time` sanitizer class, exposed through the admin REST settings endpoint.

- **JSON-LD — emit `shippingRate: 0` for unconditional free shipping.** Closes #279.
  - When a WooCommerce shipping zone covers the store's base country and contains a free-shipping method with no minimum order requirement, the plugin adds `shippingRate: { "@type": "MonetaryAmount", "value": 0, "currency": "USD" }` to `OfferShippingDetails`.
  - AI agents that compare shipping costs across merchants can now read "free shipping" as a machine-readable fact rather than infer it from the absence of a rate.
  - Lookup is per-request cached to avoid repeated zone queries on catalog pages.

### Fixes

- **Orders table — missing comma before "+N more" in items column.** Closes #281.
  - The items column truncates long order line-item lists to "Product A, Product B +2 more". The separator before the overflow count was a plain space; now a comma+space, consistent with the comma-joined visible items.

### Tests

- **`HandlingTimeTest.php`** — 16 PHP unit tests for `WC_AI_Storefront_Handling_Time::sanitize()`: non-array input → zero pair, `null`/integer input, missing keys, negative values → 0, ceiling clamp at 365, `max < min` correction, string-number casting, and happy path.
- **`JsonLdTest.php`** — new PHP unit test asserting `handlingTime` block is omitted when a pre-stored `{min:5, max:2}` pair bypasses the sanitizer; existing free-shipping tests updated to use `WC_Shipping_Zones::$test_zones` stub property instead of overriding the protected method.
- **`policies-tab.test.js`** — 7 JS unit tests for `applyHandlingTimeMin` and `applyHandlingTimeMax` clamping helpers, including PHP-direction alignment cases and the `min > max` guard in `deriveHandlingTimePreview`.

---

## [0.8.8] – 2026-05-03

### Features

- **Discovery tab — boundary note for general-purpose SEO crawlers.** Closes #268.
  - The "Allowed AI agents" section now includes a one-line helper text clarifying that this list controls AI-specific crawlers (ChatGPT, Claude, Perplexity, Gemini, etc.).
  - Notes that general-purpose search engines (Google, Bing, Yandex, etc.) are managed by WordPress core and any installed SEO plugin — not by this plugin.
  - Resolves the "I don't see Googlebot here — does that mean I haven't allowed it?" confusion for merchants without an SEO plugin.

- **Expanded AI crawler allow-list.** Added four AI agents to the canonical crawler list:
  - `YouBot` (You.com) — live retrieval + training. Default-on, `LIVE_BROWSING_AGENTS` general subgroup. Brand: "You" (matches the `you.com` canonical entry in `WC_AI_Storefront_UCP_Agent_Header::KNOWN_AGENT_HOSTS`, so UA-token traffic and UCP-attributed traffic from You.com roll up under one brand).
  - `Mistralai-User` (Mistral) — live retrieval. Default-on, `LIVE_BROWSING_AGENTS` general subgroup, following the `-User` suffix convention used by OpenAI / Anthropic / Perplexity. Brand: "Mistral".
  - `anthropic-ai` — Anthropic's older crawler identifier still seen in real logs alongside the newer `ClaudeBot`. Default-off, `TRAINING_CRAWLERS`. Maps to the existing "Claude" brand so per-vendor stats consolidate cleanly.
  - `Diffbot` — Knowledge Graph builder licensed by several LLM vendors as training input. Default-off, `TRAINING_CRAWLERS`. Brand: "Diffbot".

  Token→brand map in `class-wc-ai-storefront-crawl-logger.php` updated; admin UI checkbox list in `endpoint-info.js` updated; existing `RobotsTest::test_ai_crawlers_is_union_of_live_training_and_test` continues to pass since the flat `AI_CRAWLERS` constant was updated in lockstep.

### Fixes

- **`/llms.txt` now announces the UCP API instead of the raw Store API.** Closes #271.
  - The "API Access" section was pointing AI agents at `/wp-json/wc/store/v1` (WooCommerce's raw Store API), which bypasses the UCP layer's agent fingerprinting, rate limiting, and access control.
  - Now announces `/wp-json/wc/ucp/v1` (the plugin's purpose-built UCP REST surface) as the AI-agent front door, paired with the existing `/.well-known/ucp` Commerce Protocol Manifest.
  - Stale comment in the generator that claimed the plugin "does NOT expose its own authenticated API" updated to describe the actual UCP-on-top-of-Store-API architecture.
  - Pinned test renamed from `test_api_access_section_points_to_store_api_and_ucp` to `test_api_access_section_points_to_ucp_api_and_manifest`; new regression guard asserts `wc/store/v1` does NOT appear in the output so a future change can't quietly re-announce it.

- **Discovery tab — "Products seen" now reflects search-result visibility, not just lookup hits.** Closes #273.
  - Previously `catalog/search` recorded a single row with `product_id = 0`, so the products an AI saw via search never counted toward "Products seen" (the `COUNT(DISTINCT product_id) WHERE product_id > 0` aggregate filtered them out).
  - The handler now emits a per-result impression row under a new `ENDPOINT_STORE_API_SEARCH_HIT` endpoint alongside the existing search-request row. Capped at 50 impressions per search by default to bound write volume; merchants can override via the new `wc_ai_storefront_search_impression_cap` filter (return 0 to disable impression recording entirely).
  - "Catalog queries" / "Top searches" / by-agent counts unchanged — the new endpoint is excluded from those aggregates so a search returning 4 hoodies doesn't inflate the request count from 1 → 4.
  - Existing installs will see "Products seen" jump from however much it under-counted before to the actual distinct products surfaced to AI agents. Not a data-migration; just newly-recorded data feeding the existing query.

- **Discovery tab — relabel "UCP API hits" to "UCP manifest hits".** The card was summing `ENDPOINT_UCP` events, which only fire when an agent reads the static `/.well-known/ucp` manifest — not when it calls the UCP REST surface (`catalog/search`, `catalog/lookup`). Those calls are recorded under `ENDPOINT_STORE_API_*` and roll up into the "Catalog queries" card. The previous label suggested API traffic was being counted, which led merchants to read a 0 there as "no UCP API activity" when it really meant "no fresh manifest fetches". The label now matches what the field counts, parallel to the adjacent "llms.txt hits" card. Underlying API field name (`ucp_hits`) and recording behavior are unchanged.

### Refactors

- **`robots.txt` opt-in block now uses a single grouped rule (RFC 9309 §2.2.1).** Pre-0.8.8 the plugin emitted a separate `User-agent:` block with a duplicated 9-line Allow/Disallow body per allowed bot, producing ~200 lines on a default install with ~20 default-on crawlers. The opt-in path now emits all allowed `User-agent:` lines first, followed by a single shared rule body — same shape the opt-out block (`Disallow: /` for unchecked bots) has used since 1.6.1. Output drops to ~30 lines without changing the rules any crawler sees. Closes #267.

### Tests

- New `test_opt_in_block_uses_grouped_user_agent_form` pinning the consolidated shape: each allowed bot appears as its own `User-agent:` line, and the Allow rules appear exactly once for the whole group. The pre-existing `test_allows_ucp_rest_endpoint_for_every_crawler` was renamed to `test_allows_ucp_rest_endpoint_in_consolidated_block` and its assertion updated from "once per crawler" to "exactly once for the group".
