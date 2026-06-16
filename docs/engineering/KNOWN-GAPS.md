# Known Gaps

This document tracks known limitations of the plugin that are explicit design choices or known measurement gaps — not bugs to fix imminently. The goal is to keep merchant-facing accuracy expectations honest and to give engineers a single place to find "yes we know about this" before re-investigating from scratch.

Each entry follows the same shape:
- **What** — the gap in one paragraph
- **Why it exists** — the structural reason
- **Impact** — what merchants and the plugin admin UI actually show
- **Mitigations available today** — what helps despite the gap
- **Future work** — the realistic options if/when this becomes a release priority

---

## Attribution: Chromium-based agentic browser shoppers

### What

A growing class of AI shoppers — ChatGPT Operator, ChatGPT Atlas, Perplexity Comet, Brave Leo, Google Project Mariner, Microsoft Copilot Vision, Arc/Dia, Manus, Multion, and the long tail of agents built on Browserbase / Anchor Browser / Steel.dev — drives a real Chromium or Edge browser on the user's behalf. Their HTTP `User-Agent` header is the parent browser's UA. There is no AI-specific token in the string for our [`detect_crawler_from_ua()`](../../includes/ai-storefront/class-wc-ai-storefront-robots.php) or the [`brand_names` map in `class-wc-ai-storefront-crawl-logger.php`](../../includes/ai-storefront/class-wc-ai-storefront-crawl-logger.php) to match.

When one of these agents hits our UCP REST endpoints (`catalog/search`, `catalog/lookup`, checkout), the attribution path resolves into one of two distinct buckets depending on what the request carries. The two paths are also asymmetric: the **crawl logger** (powering Discovery stats) and the **order attribution** (powering Recent AI Orders) treat the same cohort differently.

1. **No `UCP-Agent` header** (or unparseable header) → the canonical name becomes [`FALLBACK_SOURCE`](../../includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php) (`'ucp_unknown'`).
   - **Crawl logger / Discovery stats:** the `if ( FALLBACK_SOURCE !== $agent_data['name'] )` guards in `class-wc-ai-storefront-ucp-rest-controller.php` skip recording entirely, so these requests are **invisible in Discovery stats**.
   - **Order attribution:** the checkout-session continue URL still carries `utm_id=woo_ucp` and `utm_source=ucp_unknown`. When the customer completes checkout, [`WC_AI_Storefront_Attribution::capture_ai_attribution()`](../../includes/ai-storefront/class-wc-ai-storefront-attribution.php)'s strict gate fires on `utm_id=woo_ucp` and stamps the order with `_wc_ai_storefront_agent = "Other AI"` (because `utm_source` is non-empty but unknown). So **Recent AI Orders does show these orders, attributed as "Other AI" with no raw host**.

2. **Header parses, but the host isn't in `KNOWN_AGENT_HOSTS`** → the canonical name becomes [`OTHER_AI_BUCKET`](../../includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php) (`'Other AI'`).
   - **Crawl logger / Discovery stats:** the guard returns false (name is `Other AI`, not the fallback sentinel), so these rows ARE recorded and appear under the literal `Other AI` brand.
   - **Order attribution:** same outcome as cohort 1's order path (strict gate stamps `_wc_ai_storefront_agent = "Other AI"`). Indistinguishable from cohort 1 once the order is stamped.

The two cohorts represent very different states for *traffic visibility* but converge on the *order attribution* surface:

| Cohort | Discovery stats | Recent AI Orders |
|---|---|---|
| 1 — `ucp_unknown` (no/unparseable header) | Invisible | "Other AI" |
| 2 — `Other AI` (parseable, unknown host) | "Other AI" | "Other AI" |

Cohort 1 is "we know nothing about the traffic" — the request is invisible to crawler analytics — but **the order it generates is still flagged as AI-attributed** via the strict-gate path on `utm_id=woo_ucp`. Cohort 2 is "we know it's an AI but not which one" on both surfaces.

### Why it exists

Two structural reasons:

1. **Vendor convention is not yet uniform.** OpenAI, Anthropic, Perplexity, Microsoft, and Google publish UA tokens for their crawlers (`GPTBot`, `ChatGPT-User`, `ClaudeBot`, `Claude-User`, `PerplexityBot`, `Storebot-Google`, `AdIdxBot`) but explicitly do *not* set distinguishing UA tokens for their agentic-browser products — those drive a real browser and inherit its UA. A few products (Atlas, Comet, Manus) do append a custom suffix, but they're early and the suffixes haven't settled.

2. **The plugin's identification path is UA-based.** [`detect_crawler_from_ua()`](../../includes/ai-storefront/class-wc-ai-storefront-robots.php) does case-insensitive substring matching against the `AI_CRAWLERS` constant. If the UA contains no listed token, the plugin falls through to whatever signals the request itself carries — primarily the optional `UCP-Agent` header on UCP REST calls, and nothing on Store API calls.

The UCP-Agent header is the protocol's intended attribution channel: a well-behaved agentic shopper sends `UCP-Agent: vendor=openai.com; product=operator` and gets correctly attributed. The real-world gap is that not every agent sets it, especially during the current proliferation phase.

### Impact

| Surface | Cohort 1 (no/unparseable header → `ucp_unknown`) | Cohort 2 (parseable, unknown host → `Other AI`) |
|---|---|---|
| Discovery tab → "AI agent activity" → "By AI Agent" breakdown | Not present — crawl logger skips fallback-sentinel rows. | Appears under the literal `Other AI` brand. |
| Discovery tab → Top searches | Search query is not recorded in the raw log (the same `FALLBACK_SOURCE` guard fires before the record call). | Search query is recorded; `By AI Agent` column shows `Other AI`. |
| Recent AI Orders → Customer / Source columns | Order IS stamped as AI-attributed via the strict-gate path on `utm_id=woo_ucp` — appears as `_wc_ai_storefront_agent: Other AI` with no raw host. Distinguishable from an organic purchase, but indistinguishable from cohort 2. | Same as cohort 1 — order stamped `_wc_ai_storefront_agent: Other AI`. The two cohorts converge on this surface. |
| Crawler-stats charts | Volume is invisible — the gap is silent under-counting on the *traffic* side. | Volume rolls into `Other AI` totals; merchant sees AI activity but can't slice by vendor. |

The most common shape of this gap, in production: a merchant looking at the Discovery tab sees `4 catalog queries, "Other AI: 4"`. The 4 are real AI-driven queries from agents that sent a parseable but non-canonical `UCP-Agent` header (cohort 2). The harder-to-see version is cohort 1 — those agents' *traffic* is invisible to Discovery stats, but their *orders* still land in Recent AI Orders attributed as "Other AI" via the strict-gate path. So a merchant who checks both surfaces won't miss the conversions; they will miss the upstream activity that led to them.

### Mitigations available today

- **UCP-Agent header is the intended channel.** Agents that follow the UCP spec send it. We document it in the manifest at `/.well-known/ucp` and in [`API-REFERENCE.md`](API-REFERENCE.md). Each conforming agent gets correctly attributed without a UA-list update — and even agents that send a partial/unknown header land in cohort 2 (`Other AI`) rather than cohort 1 (silent), which preserves *some* signal.
- **`Other AI` is a real bucket, not a leak.** Orders placed via UCP with a parseable-but-unknown `UCP-Agent` header still get the `_wc_ai_storefront_agent: Other AI` order meta and appear in Recent AI Orders. The bucket distinguishes "AI-driven via UCP" from "human via Store API" — that distinction itself has value, even when the specific vendor is unknown.
- **Store API rate-limit logs (partial coverage only).** When agents whose UA matches an entry in `AI_CRAWLERS` trigger our Store API rate limiter, throttled requests are recorded under the matched bot token and contribute to the throttle counts on the Discovery tab. Important caveat: this **only** covers UA-identifiable agents. `WC_AI_Storefront_Crawl_Logger::record()` early-returns when `$agent === ''`, and `check_outer_rate_limit()` passes an empty `$matched_bot` for UAs with no `AI_CRAWLERS` token match, so throttled requests from cohort-1 agents (Chromium-based shoppers with no AI-token in their UA) are NOT logged. The throttle counts therefore under-count the actual throttling pressure on this class of agent — the rate limiter still enforces the limit, but the event is silent on the merchant's side.

### Future work

Three options in ascending complexity:

1. **Track new vendor UA suffixes as they appear.** When agentic products start carrying identifying tokens in their UA (e.g. `Comet/1.0`, `Atlas/1.0`, `Manus/1.0`), we add them to `AI_CRAWLERS` and the brand map. This catches up over time but cannot get ahead of vendors that choose not to identify.

2. **Treat `Other AI` as a first-class identity in the UI.** Surface it deliberately on the Discovery tab and in Recent AI Orders rather than implicitly. Today an `Other AI` order looks slightly different from a `ChatGPT` or `Claude` order; making the bucket explicit (with a tooltip explaining the limitation) is cheap and improves merchant trust.

3. **Behavioral fingerprinting.** Distinguish agentic browsers from humans via signals that don't depend on UA: webdriver/Playwright/Puppeteer flags, viewport heuristics, request patterns (no mouse movement, instant form fills, accessibility-tree fetches). This gets us a binary "agent vs human" signal but not vendor identity. Implementation is non-trivial and creates false-positive risk on accessibility tools — out of scope until it becomes load-bearing.

The realistic 2026 trajectory is option 1 plus option 2: keep the UA list current, and make the `Other AI` bucket honest in the merchant UI. Option 3 is a bigger product decision (do we want to identify, throttle, or block agentic shoppers differently from humans?) that crosses into broader strategic territory and is out of scope for an incremental measurement fix.

---

## Crawler stats: `ENDPOINT_PRODUCT_PAGE` is declared but unused

### What

The constant `ENDPOINT_PRODUCT_PAGE = 'product_page'` is declared in [`class-wc-ai-storefront-crawl-logger.php`](../../includes/ai-storefront/class-wc-ai-storefront-crawl-logger.php) but no call site uses it. Front-end product page hits by AI agents are not currently recorded under any endpoint.

### Impact

If an AI crawls product pages directly (reading the rendered HTML and the embedded JSON-LD Schema.org Product markup) instead of going through the UCP or Store API, the visit doesn't appear anywhere in the Discovery tab.

### Future work

Wire `ENDPOINT_PRODUCT_PAGE` recording into a `template_redirect` hook on single-product templates with the existing `detect_crawler_from_ua()` gate. Add a "Product page hits" tile on the Discovery tab. Worth doing alongside hit-logging for the Shopify `/products.json` feed (shipped, #449) — both surfaces represent "ingestion-shaped" traffic distinct from search/lookup, and the feed is currently edge-cached and not logged per request (the same reason the discovery surfaces aren't).

---

## Shopify-compatible feed: proprietary-format tracking

### What

The Shopify-compatible feed now ships all five probe paths AI agents reach for: `/products.json` and `/collections/all/products.json` (#449, the bulk feed and its alias) plus the v2 scoped endpoints `/products/{handle}.json`, `/collections/{handle}/products.json`, and `/collections.json`. All five sit behind the same `products_json_enabled` toggle and reuse the `WC_AI_Storefront_Products_Feed` mapper and cache machinery. See [`API-REFERENCE.md`](API-REFERENCE.md#shopify-compatible-products-feed). The remaining gap is not a missing endpoint but the format itself.

Shopify's `products.json` / `collections.json` shape is **stable but external** — a de-facto convention created by Shopify's scale, not a published standard we can pin a version to. We track it best-effort.

### Impact

If Shopify changes the shape in a way agents come to depend on, we'd need to follow — there's no contract forcing them to keep it stable, and no notification channel when they change it.

### Mitigations available today

The "pragmatic full" subset already shipped: we populate the fields a trained parser actually keys on (`variants[].price`, `available`, `images[].src`, `handle`, `body_html`, `vendor`, `option1/2/3`) and omit Shopify-internal fields with no WC meaning (`admin_graphql_api_id`, `template_suffix`, `published_scope`). This is an accepted risk, not a bug: the same data is available through the UCP surfaces (which *are* spec-pinned), so the feed degrading would not strand agents that also speak UCP.

---

## Shopify-compatible feed: `/collections.json` timestamps are always `null`

### What

The v2 `/collections.json` endpoint emits each collection's `published_at` and `updated_at` as `null`. The keys are present (Shopify always emits them) but never carry a value.

### Why it exists

A Shopify collection is a WooCommerce `product_cat` term, and the `wp_terms` table carries no created/modified timestamps — there is no source date to map. Fabricating one (e.g. the request time, or "now") would actively mislead an agent that diffs `updated_at` across crawls to decide what to re-sync, so we emit `null` rather than a plausible-but-wrong value. (The per-product feeds *do* carry real `published_at`/`created_at`/`updated_at` from the product's WC created/modified dates — only the collection list lacks a timestamp source.)

### Impact

An agent that wants to incrementally sync collections can't use `updated_at` to detect changes to a category's name, description, or membership; it has to re-read `/collections.json` in full. Low practical impact — the list is small, unpaginated, and edge-cached, and product-level diffing (which *does* have timestamps) covers the higher-churn surface.

### Future work

If a real consumer needs collection change-detection, the term's last-modified could be approximated from the most-recently-modified product in the category (a `MAX(post_modified)` over the category's products) — but that conflates "category changed" with "a product in it changed," so it's deferred until there's a concrete need.

---

## JSON-LD variants: shared-attribute `additionalProperty` + offer `priceSpecification` shape

### What

`add_inherited_variant_fields()` (#443) brought variation `Product` nodes to parity with simple products on `description`, `brand`, `category`, and offer `seller` / `priceValidUntil`. Two lower-value differences were deliberately deferred:

- **Shared non-varying attributes** are not re-emitted as `additionalProperty` on each variant. A simple product surfaces its custom attributes (Style, Heel Height, Logo) under `additionalProperty`; a variant carries only the *varying* typed axis (`color` / `size` / …). Attributes shared across all variations live on the parent `ProductGroup`, not duplicated onto each variant.
- The variant offer uses a flat `price`, whereas the simple-product path emits a `priceSpecification` (`UnitPriceSpecification`). Both are valid Schema.org / Google offer shapes, so this is a consistency nit, not a missing field.

### Impact

Neither is Google-flagged. An agent reading a variant gets the varying axis but not the shared attributes inline (they're one hop up on the parent `ProductGroup`), and sees a flat `price` rather than a `priceSpecification`. Low practical impact.

### Future work

If a real consumer needs shared attributes inline per variant, copy the parent's `additionalProperty` into each variant in `add_inherited_variant_fields()` (same guarded-copy pattern). Unifying the offer price shape would mean teaching `build_variant_offer_skeleton()` to emit `priceSpecification` — defer until there's a reason to converge the two paths.

---

## References (external)

- [OpenAI bot docs](https://platform.openai.com/docs/bots) — current GPTBot / ChatGPT-User / OAI-SearchBot UA tokens and behavior.
- [Anthropic crawler docs](https://support.anthropic.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler) — current ClaudeBot / Claude-User / Claude-SearchBot tokens.
- [Perplexity bot docs](https://docs.perplexity.ai/guides/bots) — PerplexityBot / Perplexity-User behavior, including the documented robots.txt non-compliance edge cases.
- [Dark Visitors registry](https://darkvisitors.com/) — third-party curated list of AI agents with robots.txt generators; useful when sanity-checking whether a new UA token has been seen elsewhere.
