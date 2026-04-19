=== WooCommerce AI Syndication ===
Contributors: woocommerce
Tags: woocommerce, ai, chatgpt, seo, llms-txt
Requires at least: 6.7
Tested up to: 6.8
Requires PHP: 8.0
WC requires at least: 9.9
WC tested up to: 9.9
Stable tag: 1.7.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Make your WooCommerce catalog discoverable by AI assistants (ChatGPT, Gemini, Claude, Perplexity) while keeping checkout on your store.

== Description ==

**Your store, your checkout, your data — visible to every AI assistant.**

AI assistants are becoming a primary product discovery channel. Shoppers ask ChatGPT, Gemini, Claude, Perplexity, and Copilot for recommendations — and the agents that return the best recommendations are the ones with machine-readable access to your catalog. This plugin gives you that access without giving up anything in return.

= What it does =

Publishes three discovery surfaces that AI agents consume:

* **llms.txt** — a Markdown store guide at `/llms.txt` with categories, featured products, and attribution instructions
* **UCP manifest** — a JSON declaration at `/.well-known/ucp` describing capabilities, checkout policy, and purchase URL templates
* **Enhanced JSON-LD** — augmented Schema.org Product markup on product pages with BuyAction, inventory levels, and attribute properties

Uses WordPress's `robots.txt` to declare which AI crawlers are allowed. Uses WooCommerce's built-in Store API rate limiter to control AI crawler traffic without affecting regular customers. Uses WooCommerce's standard Order Attribution to credit AI-referred sales back to the agent that sent them.

= What it doesn't do =

* **No delegated payments.** Checkout happens on your store, not in the AI chat. No platform fees, no middleman.
* **No authentication.** AI agents discover via open web standards. No API keys to manage, no bot registrations.
* **No custom rate limiter.** Uses WooCommerce's built-in Store API rate limiting with user-agent fingerprinting for AI bots. Regular customer traffic is unaffected.
* **No Stripe or other payment provider dependency.** Works with any WooCommerce-compatible gateway.

= How it works =

1. You install and enable the plugin.
2. Your `robots.txt` adds `Allow:` directives for the 12 commerce-relevant AI crawlers (GPTBot, ChatGPT-User, OAI-SearchBot, Google-Extended, Gemini, PerplexityBot, Perplexity-User, ClaudeBot, Claude-User, Meta-ExternalAgent, Amazonbot, Applebot-Extended).
3. A Markdown store guide is published at `/llms.txt` pointing AI agents at your product catalog, Store API, and UCP manifest.
4. A JSON manifest at `/.well-known/ucp` declares that checkout is web-redirect only (never delegated, never in-chat) and documents the purchase URL templates agents can use.
5. Product pages ship enhanced Schema.org JSON-LD with a BuyAction pointing back to your checkout with attribution placeholders.
6. When a customer clicks through from an AI agent and buys, WooCommerce's standard Order Attribution captures the agent name in order meta. The agent is displayed in WooCommerce's built-in "Origin" column on the orders list (as `Source: ChatGPT`, `Source: Gemini`, etc.), and the plugin's settings page displays AI-attributed revenue by agent and time period.

= Data sovereignty =

Every piece of this is designed around one principle: **the merchant owns the transaction.**

* Checkout happens on your domain. Customer data never flows through a third party.
* The agent is a referrer, not a storefront.
* You control which products are exposed (all / by category / individually selected).
* You control which crawlers can access the store (12 allow-list checkboxes in the Discovery tab).
* You can disable the plugin at any time; removing it is clean (no orphaned options or database rows).

== Installation ==

1. Upload the plugin to your `/wp-content/plugins/` directory, or install via the Plugins screen in WordPress.
2. Activate the plugin.
3. Go to **WooCommerce > AI Syndication** in the admin menu.
4. Click **Enable AI Syndication**.
5. (Optional) Review the **Product Visibility** tab to scope which products are exposed.
6. (Optional) Review the **Discovery** tab to adjust which AI crawlers are allowed and which rate limit applies.

Visiting `https://yourstore.example.com/llms.txt` in a browser should now return a Markdown document. Visiting `https://yourstore.example.com/.well-known/ucp` should return a JSON manifest.

== Frequently Asked Questions ==

= Do I need to register my store with OpenAI, Google, etc.? =

No. This plugin publishes discovery surfaces that AI crawlers fetch the same way they fetch any other site. No registration, no API keys, no agreements.

= Will AI agents respect the allowlist? =

Well-behaved AI crawlers respect `robots.txt` directives. Compliance is not universal — this is a cooperative protocol, not an enforcement mechanism. All 12 crawlers on the default allowlist are from organizations that have publicly committed to honoring robots.txt. Unchecking a crawler in the Discovery tab adds a `Disallow:` directive for that user agent; there's no stronger enforcement you can do at the WordPress layer.

= How does attribution work? =

When an AI agent links to a product page, it appends `utm_source={agent_name}&utm_medium=ai_agent&ai_session_id={session_id}` query parameters. WooCommerce's Order Attribution system (included in WooCommerce core since 8.5) captures these values into order meta and displays them in the built-in "Origin" column on the orders list — no custom column needed. The plugin surfaces per-agent revenue totals in the settings overview.

= What's the difference between llms.txt, robots.txt, and the UCP manifest? =

* **robots.txt** tells crawlers what they're allowed to fetch. It's a permissions document.
* **llms.txt** gives AI agents a machine-readable summary of the store — categories, featured products, API endpoints, attribution rules. It's a discovery document written in Markdown.
* **UCP manifest** is a JSON document declaring the store's commerce capabilities: checkout method (web-redirect only, never delegated), purchase URL templates, rate limits, attribution parameters. It's a protocol document for agent implementers.

The three work together. Agents fetch `robots.txt` to learn they're allowed, fetch `llms.txt` to learn what's available, and fetch the UCP manifest to learn how to generate purchase links.

= How does this compare to Stripe ACP / Google UCP? =

Those are protocols in which the AI agent collects payment details inside the chat and hands them to the merchant through a standardized API. This plugin does the opposite: checkout always happens on your store. The AI agent is a referrer, not a payment processor. You keep full control of the checkout experience, customer data, and payment relationships.

= Does this work with WooCommerce Subscriptions, Bookings, or other extensions? =

The discovery surfaces (llms.txt, UCP manifest, JSON-LD) describe the core WooCommerce product catalog. Extension-specific product types may or may not be accurately represented depending on how the extension implements `WC_Product`. Attribution works for any order WooCommerce creates, regardless of the product type.

= What happens to the llms.txt / UCP files when I deactivate the plugin? =

They return 404. The endpoints are virtual (served via rewrite rules); deactivating the plugin removes the rewrite rules. No cleanup required.

= Where are AI orders recorded? =

In the standard WooCommerce orders list. Every AI-referred order is a normal WC order with additional meta:

* `_wc_order_attribution_utm_medium` = `ai_agent`
* `_wc_order_attribution_utm_source` = agent name (e.g. `chatgpt`)
* `_wc_ai_syndication_agent` (denormalized for faster queries)
* `_wc_ai_syndication_session_id` (conversation identifier)

== Changelog ==

= 1.7.0 =
* Admin Overview tab now shows a **Recent AI Orders** table rendered with WordPress core's `@wordpress/dataviews` component — sortable columns, pagination, WooCommerce-native status pills, and links through to the order edit screen. Column order matches WC's native Orders list (Order, Date, Status, Agent, Total) so merchants can scan AI-attributed orders without leaving the dashboard. Built on a new `/wc/v3/ai-syndication/admin/recent-orders` REST endpoint that canonicalizes legacy agent hostnames in order meta at display time — so orders captured before 1.6.7 still show up as `Gemini` / `ChatGPT` / etc. rather than raw hostnames.
* **Pre-enable landing redesigned.** Three blocks: hero with single primary CTA and AI-assistant name chips (ChatGPT, Gemini, Claude, Perplexity, Copilot); three icon-led value cards using `@wordpress/icons` glyphs with benefit-first titles ("One setup, every AI assistant", "Checkout stays on your store", "See which AI drove each sale"); inline reassurance line under the CTA ("Read-only · Reversible anytime · No frontend changes"). Replaces the prior banner + 3 cards + 4-bullet bottom card layout that was text-heavy and had a duplicate CTA. The "No platform fees" line reads as "No AI-platform checkout fees" for clarity.
* **Discovery tab reorganization.** The Rate Limits card moved from the Overview tab to Discovery (same conceptual bucket as the crawler allow-list: "who gets in" + "how fast they can go"). A single page-level Save button replaces the two per-card "Save Changes" buttons, matching WP admin convention. Added a help tooltip bridging the crawler-ID naming (e.g. `ChatGPT-User`) with the brand-name display on the Orders list's Origin column (e.g. `Source: ChatGPT`).
* **Two new Microsoft crawlers** in the robots.txt allow-list: `AdIdxBot` (live — Microsoft Shopping / Bing Ads validation, feeds Copilot's shopping answers; parallel to Storebot-Google) and `Microsoft-BingBot-Extended` (training — AI opt-out parallel to Google-Extended / Applebot-Extended).
* **llms.txt is now self-contained for attribution.** Added an `### Attribution name mapping` subsection that publishes the full hostname → brand-name map (the single source of truth used at runtime), plus inline URL construction patterns for `/checkout-link/?products=...` and legacy `?add-to-cart=` URLs — one fetch per evaluating agent instead of two.
* **Infrastructure hardening:** cache-busting now tracks the CSS file's actual content hash (via `md5_file()` with `is_readable()` guard and `filemtime()`/asset-hash fallback chain); single-flight sentinel prevents concurrent llms.txt regenerations from both paying the 4-second HEAD-probe cost; order edit URLs are scheme-validated before rendering (defense against future REST response changes exposing non-http URLs); dropped the unused `@woocommerce/components` devDependency (~40+ transitive packages removed from node_modules).
* **Test coverage grew to 386 PHPUnit tests / 1110 assertions + 47 Jest tests** — including a structural contract suite for `/admin/recent-orders`, reducer coverage for `SET_RECENT_ORDERS`, Jest assertions for the refresh-on-save wiring, and `LlmsTxtTest` coverage for every entry in `KNOWN_AGENT_HOSTS` via data provider.

= 1.6.7 =
* Changed: `utm_source` on the `continue_url` returned by `POST /checkout-sessions` is now a short, human-readable brand name (`ChatGPT`, `Gemini`, `Claude`, `Perplexity`, `Copilot`, `Siri`, `Rufus`, `Klarna`, `You`, `Kagi`) instead of the raw UCP-Agent profile hostname. WooCommerce Order Attribution feeds this into `_wc_order_attribution_utm_source`, which WC's built-in "Origin" column on the Orders list displays as `Source: {name}` — so the previous `Source: Gemini.google.com` now reads `Source: Gemini`. Unknown / novel agents pass through verbatim so attribution is never lost, just refined for known vendors. The map lives in `WC_AI_Syndication_UCP_Agent_Header::KNOWN_AGENT_HOSTS`; adding a new vendor is a single constant entry plus a test-provider row.
* Removed: the custom "AI Agent" column and "Filter by AI agent" dropdown on the WooCommerce Orders list. Both duplicated WooCommerce core's built-in "Origin" column (which has been fed correctly by our `utm_source` / `utm_medium` pair all along). 186 lines removed from `class-wc-ai-syndication-attribution.php` including four hooks, four methods, and the `get_known_agents()` SQL helper. The order-edit meta box is unchanged — it still shows the AI session ID, which WC's native attribution UI doesn't surface.
* Added: regression guards in `AttributionTest.php` asserting that `init()` does not register any of the eight removed hooks and that none of the six removed methods exist on the class. Reintroducing the duplicate column without a conscious design review will fail CI. Also added an integration test in `UcpCheckoutSessionsTest.php` pinning the `gemini.google.com → Gemini` end-to-end wiring, plus unit tests for every entry in the `KNOWN_AGENT_HOSTS` map, case-insensitive matching, unknown-host passthrough, and the deliberate no-subdomain-match behavior.

= 1.6.6 =
* Added: explicit "merchant-only checkout" posture lock-in. Two complementary changes: (1) a new `## Checkout Policy` section in llms.txt declaring affirmatively that all purchases complete on the merchant site, and enumerating the five UCP surfaces we deliberately don't support (in-chat payment, embedded checkout, AP2 Mandates / agent-delegated authorization, persistent agent carts, payment handler tokens); (2) a dedicated regression test file `UcpCheckoutPostureTest.php` with 11 tests that lock every layer of the defense stack — empty `payment_handlers`, no `ap2_mandate` or `cart` capability, REST-only transport (no Embedded Protocol / MCP / A2A), exactly the 3 expected REST routes registered (no Complete Checkout endpoint), no `signing_keys` at profile root, no payment-related keys anywhere in the manifest. A future maintainer accidentally adding any of these weakens the posture; the tests fire and force a conscious posture review rather than silent capability drift.
* Added: llms.txt verification claims are programmatically checkable. The Checkout Policy section points readers at the UCP manifest with the expected values (`payment_handlers: {}`, specific capabilities list, `transport: "rest"`, `status: "requires_escalation"` in responses) so agent trust frameworks and merchant-review humans can verify the declarations without reading the plugin source. Redundant signaling vs. the manifest's own declaration-by-absence, but cheap insurance for trust frameworks that require explicit merchant commitments.

= 1.6.5 =
* Changed: UCP manifest restructured to match the spec's canonical-capabilities-plus-extension pattern (`capability.json` `base.extends`). Three structural changes: (1) removed `mode: "handoff"` from the `dev.ucp.shopping.checkout` capability — non-canonical (not defined in `capability.json`) and redundant with the runtime `status: requires_escalation` signal the endpoint already returns; (2) removed the `config` block containing `purchase_urls` + `attribution` from the checkout capability — the UCP checkout spec carries a SHOULD directive that businesses provide `continue_url` rather than platforms constructing permalinks, so advertising URL templates in the canonical capability was nudging agents toward the less-preferred path; (3) introduced the `com.woocommerce.ai_syndication` extension capability with `extends: "dev.ucp.shopping"` as the idiomatic UCP home for merchant-specific metadata — carries `store_context` (currency, locale, country, tax/shipping posture) and `attribution` (UTM convention documentation).
* Changed: root-level `store_context` field removed — relocated inside the extension capability at `capabilities["com.woocommerce.ai_syndication"][0].config.store_context`. The root-level placement from 1.4.5 was valid under `additionalProperties: true` but not spec-idiomatic; the extension-capability home is the spec's designed pattern for vendor-specific data.
* Changed: llms.txt is now the authoritative source for agent attribution guidance. Its `## Attribution` section leads with the canonical UCP flow (`POST /checkout-sessions` → server constructs `continue_url` with UTM pre-attached → response returns `status: "requires_escalation"` → agent redirects user), followed by fallback UTM-parameter guidance for agents that must construct URLs client-side. Pre-1.6.5 llms.txt emitted literal URL-template examples; those are now removed in favor of a pointer to WooCommerce's public `/checkout-link/` documentation for agents that need URL patterns.
* Removed: URL-template library (`purchase_urls.checkout_link.*` and `purchase_urls.add_to_cart.*`) from the UCP manifest entirely. Agents unable to use the `POST /checkout-sessions` canonical flow should consult the public WooCommerce shareable-checkout-URL docs referenced in llms.txt. Rationale: the UCP spec's SHOULD directive; with zero documented autonomous-agent usage of the templates (the one live test was a manually-prompted Gemini session, not in-the-wild behavior), keeping them was more signal-confusion than value.
* Added: regression tests locking in the new structure — `checkout_capability_has_no_mode_or_config`, `declared_capabilities_are_exactly_three_canonical_plus_one_extension`, `store_context_no_longer_lives_at_manifest_root`, `checkout_capability_has_no_purchase_urls_after_1_6_5`, and extension-specific attribution tests. Total: 345 tests / 980 assertions.

= 1.6.4 =
* Changed: UCP manifest structure reorganized to match April spec's `entity` semantic conventions. Three coordinated changes: (1) the `config` block carrying `purchase_urls` + `attribution` moved from service-level (`services["dev.ucp.shopping"][0].config`) to checkout-capability-level (`capabilities["dev.ucp.shopping.checkout"][0].config`) — both placements are syntactically valid per the UCP entity schema, but semantically these are checkout-specific concerns and belong with the checkout capability; (2) added `spec` + `schema` URLs to every capability binding (`catalog.search`, `catalog.lookup`, `checkout`) pointing at ucp.dev's canonical per-capability documentation and JSON Schema files; (3) added `schema` to the service binding pointing at the OpenAPI 3.1 spec for the UCP Shopping REST service. Agents wanting machine-readable contract validation now have authoritative URLs for both the service-level transport (OpenAPI) and the capability-level payloads (JSON Schema).
* Changed: all external spec/schema URLs migrated from GitHub `tree/v{VERSION}/source/...` paths to `https://ucp.dev/{VERSION}/...` paths. Single canonical host for every external reference in the manifest. Rendered docs at ucp.dev are the intended consumption path per the UCP project's publishing model; GitHub URLs were functional but aimed at source files rather than rendered specification pages.
* Changed: service-level `spec` URL now points at `https://ucp.dev/{VERSION}/specification/overview` (the canonical UCP overview doc) instead of the GitHub schema directory listing. The previous URL resolved to a file browser rather than a "human-readable specification document" per the entity schema's intent.
* Added: 4 regression tests locking in the structural changes — checkout capability owns the config (not service), every capability has `spec` + `schema` URLs, service has an OpenAPI `schema` URL, all external URLs are version-pinned via `ucp.dev/{PROTOCOL_VERSION}/`. Total: 354 tests / 989 assertions.

= 1.6.3 =
* Added: `## Sitemaps` section in llms.txt surfacing exhaustive URL enumeration paths for agents doing deep catalog discovery. Parallel to the sitemap `Allow:` entries in robots.txt (1.6.1/1.6.2) but in llms.txt's human+machine-readable narrative form. Unlike robots.txt where `Allow:` for non-existent paths is a harmless no-op, listing wrong URLs in llms.txt would be factually incorrect — so each candidate sitemap is HEAD-probed at generation time and only responding URLs make it into the document. Probes are synchronous with 1-second timeout but amortized by the 1-hour transient cache — one round per cache miss, not per request. Typical cache-miss latency: <500ms. Worst case (all 4 candidates time out): 4 seconds, once per hour.
* Changed: sitemap discovery sources in llms.txt match robots.txt's behavior — `get_sitemap_url( 'index' )` for WP core canonical + the four hardcoded paths from `WC_AI_Syndication_Robots::COMMON_SITEMAP_PATHS` (`/sitemap.xml`, `/sitemap_index.xml`, `/wp-sitemap.xml`, `/news-sitemap.xml`), covering WP core, Jetpack, Yoast, Rank Math, and AIOSEO between them.

= 1.6.2 =
* Fixed: 1.6.1's sitemap auto-discovery missed sites whose SEO/sitemap plugin (notably Jetpack's Sitemaps module, default on WordPress.com Atomic) emits `Sitemap:` directives via the `do_robotstxt` action with direct `echo` instead of through the `robots_txt` filter. Our filter runs in isolation from the echoed output, so the discovery regex saw nothing and fell back to WP core's `/wp-sitemap.xml` — which doesn't exist on Jetpack-powered sites. Merchants with Jetpack (i.e. every WP.com Atomic install) saw `Allow: /wp-sitemap.xml` in each AI-bot block instead of the actually-correct `Allow: /sitemap.xml`.
* Added: `COMMON_SITEMAP_PATHS` constant with hardcoded fallback paths (`/sitemap.xml`, `/sitemap_index.xml`, `/wp-sitemap.xml`, `/news-sitemap.xml`) emitted alongside any auto-discovered paths. Union + dedupe, so sites that DO emit via the filter correctly (Yoast SEO, Rank Math, AIOSEO in standard config) still see their specific URLs without duplication. Sites on Jetpack get their standard `/sitemap.xml` + `/news-sitemap.xml` paths inside every AI-bot block via the fallback. `Allow:` directives for non-existent paths are no-ops for crawlers, so this is zero-harm belt-and-suspenders.

= 1.6.1 =
* Fixed: Perplexity's browsing tool (and other Chromium-headless AI crawlers) couldn't read `/robots.txt` because the response had no CORS headers — same class of bug fixed for `/llms.txt` in 1.4.1, but robots.txt is served by WordPress core, not by our plugin, so the 1.4.1 fix didn't cover it. Added `do_robotstxt` action hook at priority 5 that injects `Access-Control-Allow-Origin: *`, `Access-Control-Allow-Methods: GET, OPTIONS`, and `X-Content-Type-Options: nosniff` before WP core flushes the body. Verified via curl with `PerplexityBot/1.0` UA: response now includes CORS headers.
* Added: sitemap `Allow:` directives inside every named AI-bot block in robots.txt. Auto-discovered from the top-level `Sitemap:` directives that WordPress core and SEO plugins (Yoast, Rank Math) emit, so any source of truth flows through without hardcoding. Per robots.txt spec the top-level `Sitemap:` is global and named crawlers see it regardless — but poorly-implemented parsers that over-scope their User-agent group were observed missing it. Cheap defensive redundancy.
* Added: explicit `Disallow: /` block for AI bots the merchant has unchecked. Pre-1.6.1 unchecked bots silently fell through to `User-agent: *` (which allows most of the site); post-1.6.1 they receive a specific per-bot block that matches merchant intent more honestly. Especially relevant for the training-default-off policy from 1.6.0 — fresh installs now explicitly tell GPTBot / Google-Extended / ClaudeBot / etc. that they're not welcome, rather than relying on "not listed" as the signal. Uses grouped `User-agent:` syntax per RFC 9309 §2.2.1 (~150 bytes saved vs. one block per bot).
* Added: `Sitemap:` directive re-emitted at the end of the plugin's robots.txt section. Industry convention + defense against parsers that process directives in document order and expect sitemap declarations at the bottom. Duplicates (not replaces) the top-level emission by WordPress core / SEO plugins — both locations are now covered.
* Changed: no version bump required for consumers of WC_AI_Syndication_Robots — all additions are additive. Existing callers and the merchant settings layer are unaffected.

= 1.6.0 =
* Added: cursor-based pagination on `POST /catalog/search` per the UCP v2026-04-08 `types/pagination.json` spec. Requests accept `pagination.cursor` (opaque) and `pagination.limit` (default 10, max 100). Responses emit `pagination.has_next_page` (always) and `pagination.cursor` (when more pages exist) and `pagination.total_count` (when Store API provides X-WP-Total). Closes a latent correctness issue: pre-1.6.0 search always returned the Store API default page (~10 products), silently losing 95% of data for any agent trying to enumerate a 200-product catalog. Cursor format is base64 of `p<page_number>` — opaque to agents, stateless, survives restarts. Malformed cursors fall back to page 1 without error (catalog mutation between calls can invalidate cursors; surfacing that as an error would make pagination brittle).
* Changed: UCP manifest now advertises `dev.ucp.shopping.catalog.search` and `dev.ucp.shopping.catalog.lookup` as separate sub-capabilities instead of the umbrella `dev.ucp.shopping.catalog`. The April UCP spec formalized these as distinct schemas; splitting the advertisement lets agents discover precisely which operations are available. Both sub-capabilities resolve to the same REST endpoints — only the discovery metadata shape changed.
* Changed: re-audited the global AI bot allow-list. Per vendor docs and Google's published recommendations: added `Applebot` (Siri/Spotlight search, since 2015 — was missing), `Claude-SearchBot` (Anthropic's search-feature crawler — was missing), `Bytespider` (ByteDance/TikTok training — dual-purpose for TikTok Shop), `CCBot` (CommonCrawl, feeds most open-source LLMs), `cohere-ai` (Cohere training). Moved `PerplexityBot` from training to live-browsing — vendor docs confirm it indexes for live answer retrieval, not training corpus construction. Removed the phantom `Gemini` entry that had been emitting a useless `User-agent: Gemini` directive since 1.0.0 (no Google crawler is literally named `Gemini`; the training bot is `Google-Extended`).
* Changed: training crawlers now default to DISABLED on fresh installs. New `allowed_crawlers` default is the `LIVE_BROWSING_AGENTS` list only (7 agents) instead of the full `AI_CRAWLERS` union (16 agents). Commerce merchants get the revenue-path live browsing surfaces turned on out of the box; training crawlers are a brand-strategy decision they can opt into from the admin page. Rationale: training crawls capture catalog snapshots that may surface in AI answers months later with stale prices and availability — real risk for commerce where quoted prices carry contractual weight. Existing installs with saved `allowed_crawlers` values are unaffected; only fresh installs see the new default.
* Added: `Crawl-delay: 2` directive emitted alongside every `User-agent:` block in robots.txt. Advisory rate hint that well-behaved crawlers respect by waiting 2 seconds between requests; dampens burst traffic from aggressive AI search bots that have been observed firing 10+ requests in a second during product-comparison queries. Paired with the existing Store API rate limiter (25 req/min hard enforcement per bot), this creates a two-tier defense: Crawl-delay is the polite signal, the rate limiter is the enforcer. Value defined in `WC_AI_Syndication_Robots::CRAWL_DELAY_SECONDS` for future tuning.
* Added: 10 regression tests for pagination behavior (default limit, clamping, cursor round-trip, malformed-cursor fallback, response shape invariants) + 2 for Crawl-delay emission + 5 for the re-audited crawler lists + 1 for the phantom-Gemini removal guard. Full suite now 321 tests / 874 assertions.

= 1.5.0 =
* Added: split the AI crawler allow-list into two semantic categories — `LIVE_BROWSING_AGENTS` (user-initiated fetches during active queries: ChatGPT-User, OAI-SearchBot, Perplexity-User, Claude-User) and `TRAINING_CRAWLERS` (static crawls feeding model training: GPTBot, Google-Extended, Gemini, PerplexityBot, ClaudeBot, Meta-ExternalAgent, Amazonbot, Applebot-Extended). The distinction matters for commerce: live browsing sees fresh inventory and routes revenue, while training crawls capture snapshots that may surface as stale answers months later (wrong prices, wrong availability). UCP's spec (v2026-04-08) is explicitly scoped to live agentic commerce and silent on training policy — this split reflects that design philosophy. The combined `AI_CRAWLERS` constant is preserved as the pre-1.5.0 canonical list for backward compatibility; existing installs' saved `allowed_crawlers` values are unaffected.
* Changed: the Allowed Crawlers admin UI now renders the two categories as separate visual groups with per-group descriptive text. "Live browsing" shows first with the label "User-initiated fetches during an active query. These agents see fresh inventory and route revenue — recommended on." "Training crawlers" shows second with "Static crawls that feed AI model training. Captured snapshots may surface as stale answers months later, with wrong prices or availability. Merchant discretion." Merchants can make an informed per-category decision instead of treating all twelve bots as interchangeable.
* Added: five regression tests for the category split — LIVE_BROWSING_AGENTS membership, TRAINING_CRAWLERS membership, AI_CRAWLERS equals the union (required for sanitize_allowed_crawlers backward compat), categories are disjoint (no double-rendering in UI), no duplicates overall (no redundant User-agent rules in robots.txt).

= 1.4.5 =
* Added: `store_context` top-level block in the UCP manifest declaring merchant-level commerce context — `currency` (ISO 4217), `locale` (BCP 47), `country` (ISO 3166-1 alpha-2), `prices_include_tax` (boolean), `shipping_enabled` (boolean). Pre-1.4.5 agents fetching only the UCP manifest couldn't tell what currency they'd be quoting in, whether catalog prices already included tax, or whether the store needed shipping addresses — they'd have to fall back to llms.txt or a Store API probe. Added in response to cross-agent review feedback from Claude that called out this as the dominant manifest-level gap: "for the CLDR project you're thinking about, this is exactly the gap — an agent has no machine-readable way to know what currency it'll be quoting in." The block is a sibling to `ucp` rather than nested inside it, because the facts are agnostic of the UCP spec — any AI-commerce tool (UCP-aware or not) reads them.
* Changed: UCP spec URL in the service binding now pins to the tagged spec revision matching `PROTOCOL_VERSION` (`tree/v2026-04-08/source/schemas/shopping`) instead of the moving `main` branch. A consumer verifying our manifest against the spec a year from now reads the exact revision we conformed to — not whatever HEAD happens to be. The UCP spec repo tags follow the same date-versioning as the protocol version, so bumping `PROTOCOL_VERSION` automatically tracks the URL; no extra coupling to maintain.
* Added: locale conversion from WordPress ICU form (`en_US`, underscore) to BCP 47 form (`en-US`, hyphen) for the manifest's `store_context.locale` field. Web-ecosystem consumers (HTTP `Accept-Language`, browser `navigator.language`, most APIs) expect hyphens; WordPress stores with underscores. The boundary conversion happens here so agents see the standards-compliant form.

= 1.4.4 =
* Fixed: llms.txt served an empty body on sites whose transient cache had been poisoned with an empty string during an earlier broken state (most likely during the 1.4.2 wiring-bug window before 1.4.3 shipped). The cache-hit check was `if ( false !== $cached )`, which passed for `''` because empty-string !== false — so the poisoned value was served verbatim for the full 1-hour TTL even after every upstream fix was in place. Production `curl -I` showed 200 with all the right headers (CORS, text/plain, no 301), but `curl` (GET without -I) returned nothing. Fix hardens the cache path on both halves: treat empty/falsy cached values as a miss (forcing regeneration + a fresh cache write that heals the poison), and refuse to write empty generate() output back into the cache (prevents future poisoning from the same root cause). New unit tests lock in both halves so a partial refactor can't re-introduce the bug.
* Changed: removed `X-Robots-Tag: noindex` from the llms.txt response. Earlier revisions set it defensively to keep the URL out of human-facing search results, but some AI browsing tools (Gemini reported this explicitly) use Google's search index as a discovery layer — they find URLs via search first, then fetch. A noindexed URL never enters the index, so those agents never try to fetch it. Since llms.txt exists specifically to be discovered by agents, nulling its search-indexability contradicts the plugin's own purpose. Agents that go direct to `/llms.txt` (the spec-canonical path) are unaffected; agents that search-first now work too. Merchants who want llms.txt kept out of search can reinstate the header via a `send_headers` filter in their own code.

= 1.4.3 =
* Fixed: 1.4.2's canonical-redirect fix didn't actually register, because the `redirect_canonical` filter was added inside the llms.txt/UCP classes' `init()` method — and those `init()` methods had been silently unused since an earlier refactor moved their hook registrations to the main plugin class. Production `curl -I` after the 1.4.2 deploy still showed `HTTP/2 301 location: .../llms.txt/` with `x-redirect-by: WordPress`, confirming the filter was never attached. Moved the filter registration to the main plugin class (`WC_AI_Syndication::register_rewrite_rules()`) alongside the other hooks for these two classes. Deleted the now-empty `init()` methods from both `WC_AI_Syndication_Llms_Txt` and `WC_AI_Syndication_Ucp` to prevent future changes to those methods from silently doing nothing.

= 1.4.2 =
* Fixed: llms.txt and UCP manifest were being 301-redirected by WordPress's `redirect_canonical()` function on sites with trailing-slash permalink structures (the default on WordPress.com and most self-hosted sites). `GET /llms.txt` would return `HTTP/2 301 location: /llms.txt/` with `content-type: text/html`, and then the trailing-slash URL didn't match the plugin's `^llms\.txt$` rewrite rule — the request fell through to a WordPress 404 HTML page. AI browsing tools either didn't follow the redirect or choked on the HTML response at the destination. Diagnosed via `curl -I` output from a production site on WordPress.com Atomic which showed the telltale `x-redirect-by: WordPress` header. Added a `redirect_canonical` filter on both the llms.txt and UCP manifest endpoints that returns `false` when the respective query var is present, short-circuiting WordPress's trailing-slash enforcement for exactly these two URLs while leaving canonical behavior on every other page of the site untouched.

= 1.4.1 =
* Fixed: llms.txt was unreachable from AI browsing tools running in Chromium-based headless contexts (Gemini's browsing tool, ChatGPT browse, Claude web-search), because the endpoint sent no CORS headers. The UCP manifest at `/.well-known/ucp` already set `Access-Control-Allow-Origin: *` and worked fine; llms.txt was the asymmetric missing piece. Reports from agents: "my browsing tool is still having trouble 'seeing' the raw text files despite the robots.txt update" — the tool's fetch was silently blocked by same-origin policy. Added `Access-Control-Allow-Origin: *`, `Access-Control-Allow-Methods: GET, OPTIONS`, and a 204 handler for CORS preflight OPTIONS requests so browsing tools that preflight (some do, some don't) don't interpret a non-2xx preflight as "resource unreachable."
* Changed: `Content-Type` on llms.txt is now `text/plain; charset=utf-8` instead of `text/markdown; charset=utf-8`. Both are spec-legal per the llms.txt convention (Jeremy Howard's original memo allows either), but several AI browsing tools have MIME allow-lists that don't include `text/markdown` and drop the response. `text/plain` is the universal fallback, still renders correctly in merchant browsers on direct visits, and matches what Anthropic's, Cursor's, and most well-known llms.txt serving scripts use in production.
* Added: `X-Content-Type-Options: nosniff` on llms.txt so content starting with `<` (rare but possible in merchant-supplied store descriptions) isn't MIME-sniffed as HTML by clients that do that.

= 1.4.0 =
* Added: in-place plugin updates from wp-admin. The plugin now registers itself with WordPress's native update UI via the Plugin Update Checker library (bundled at `includes/lib/plugin-update-checker/`) pointed at our GitHub release feed. Merchants see an "Update available" notice on the Plugins screen and click "Update Now" just like any WP.org-hosted plugin — no more manual zip uploads, no more duplicate plugin directories from source-code zips, no more stale installs coexisting with the new version. Update checks are admin-only (skipped on front-end requests to avoid loading the library on every pageview) and point at tagged release assets only, not branch HEAD, so merchants only ever see versions we have explicitly shipped.
* Added: `Update URI:` header pointing at the GitHub repo URL. WordPress 5.8+ uses this to route update checks through our updater rather than the WP.org directory, preventing name-collision hijacks if a plugin with the same slug ever appears on WP.org.
* Changed: canonical GitHub repo is now `woocommerce-ai-syndication` (renamed from `woo-ucp-syndicate-ai`). GitHub auto-redirects all old URLs, but the new name matches the plugin slug — so source-code zips from releases now extract to the same directory as the release-asset zip, eliminating the additive-install problem on manual uploads.

= 1.3.2 =
* Added: robots.txt row to the Discovery Endpoints table in the WooCommerce > AI Syndication admin panel. Merchants can now see the clickable URL, reachability status, and a plain-English description of what the plugin appends to robots.txt — completing the discovery picture alongside llms.txt, UCP manifest, and Store API.
* Added: `mode: "handoff"` hint on the `dev.ucp.shopping.checkout` capability binding in the UCP manifest. Agents reading the manifest now learn upfront that the checkout endpoint is redirect-only (no in-chat payment processing, no server-side cart lifecycle) without having to invoke it and parse the response. Additive per UCP schema — agents that don't understand the field ignore it gracefully.

= 1.3.1 =
* Fixed: UCP REST adapter handlers (`/catalog/search`, `/catalog/lookup`, `/checkout-sessions`) returned HTTP 500 "critical error" whenever they needed to translate a real product from WC Store API. Root cause: `rest_do_request()` returns Store API data with nested `stdClass` objects (prices, attributes, categories), not associative arrays — the translator's `$prices['currency_code']` style access fataled with "Cannot use object of type stdClass as array." The bug was production-only: external HTTP callers never saw it (JSON round-trip converted stdClass→array on their end), and unit tests never exercised it (the `rest_do_request` fake returned pre-shaped associative arrays). Fix: normalize Store API responses at the `rest_do_request` boundary via `json_decode(wp_json_encode(...), true)`, forcing all nested structures to pure associative arrays regardless of source type.
* Added: regression test seeding a product with `stdClass`-nested `prices` to lock in the normalization step, plus a direct test of the normalize helper's behavior.

= 1.3.0 =
* Added: UCP REST adapter at `/wp-json/wc/ucp/v1/*` implementing the `dev.ucp.shopping.catalog` and `dev.ucp.shopping.checkout` capabilities. AI agents can now invoke `POST /catalog/search` (full-text + category + price-range filtered product queries), `POST /catalog/lookup` (fetch specific products by ID), and `POST /checkout-sessions` (stateless one-shot checkout handoff) against a UCP-shaped API that translates WooCommerce Store API responses into the official UCP product / variant schemas. Pairs with the existing llms.txt and UCP manifest as an executable complement — agents go from discovery to operation through one plugin.
* Added: variable-product variant expansion. Catalog responses now include one UCP variant per WC variation with correct per-variation prices and attribute-derived titles (e.g. "Small / Blue"). Simple products still emit a single synthesized default variant to satisfy the UCP schema's `minItems: 1` constraint.
* Added: UCP-Agent header parsing for attribution. The profile hostname flows into `utm_source` on the checkout-sessions `continue_url` so merchants see agent-sourced revenue through WooCommerce's native Order Attribution system — no extra plumbing required.
* Added: stateless redirect-only checkout. Every `POST /checkout-sessions` response returns `status: requires_escalation` with a `continue_url` pointing at WooCommerce's native Shareable Checkout URL (`/checkout-link/?products=ID:QTY`). No cart persistence, no session storage, no UCP get/update/complete/cancel endpoints — merchants keep full ownership of payment, tax, and fulfillment, matching the plugin's longstanding web-redirect posture.
* Added: enforcement of the `product_selection_mode` setting on Store API product queries via the `woocommerce_store_api_product_collection_query_args` filter. Before 1.3.0 the setting silently applied only to llms.txt / JSON-LD; now "only these 10 products" genuinely restricts what AI agents (and block-theme Cart/Checkout blocks) can see. **Behavioral change worth highlighting**: merchants whose block-theme cart/checkout implicitly depended on showing all products may see unexpected filtering — review the Product Visibility setting after upgrade.
* Changed: `/.well-known/ucp` manifest now declares the two implemented capabilities (`dev.ucp.shopping.catalog`, `dev.ucp.shopping.checkout`) with the service endpoint pointing at `/wp-json/wc/ucp/v1/`. Pre-1.3.0 shape advertised `com.woocommerce.store_api` as a generic service with zero capabilities. Agents re-fetching the manifest will see the new capability declarations and service endpoint directly.
* Changed: UCP `PROTOCOL_VERSION` bumped from `2026-01-11` to `2026-04-08` to track the current UCP spec revision. The bump flows through to every catalog/checkout response envelope automatically.
* Added: `Allow: /wp-json/wc/ucp/` entry in robots.txt for every allowed crawler, so well-behaved AI bots know the new endpoint is crawlable even when site-wide `/wp-json/` disallows exist elsewhere.
* Added: defense-in-depth limits on UCP REST input. `POST /catalog/lookup` caps `ids[]` at 100 entries, `POST /checkout-sessions` caps `line_items[]` at 100 entries and `quantity` at 10,000 per item, and variable-product variation fan-out caps at 50 per product. All are documented as class constants and reject oversized input with `invalid_input` at HTTP 400.
* Added: enforcement of the syndication-enabled setting at the UCP REST layer. When a merchant pauses AI Syndication via the admin UI, UCP endpoints now return `ucp_disabled` with HTTP 503 instead of serving catalog/checkout data. Routes remain registered (rewrite-flush stability) but handlers gate access at the entry point.
* Added: `partial_variants` warning emitted when a variable product's variations can't be fully loaded (fetch failure or cap-truncated). Prevents agents from seeing price_range disagree silently with an incomplete variants[] list.
* Added: `category_not_found` warning when an agent's category filter can't be resolved. Before this, an unknown category was silently dropped and the agent received the unfiltered catalog — the opposite of what they asked for.
* Changed: validation errors (missing body fields, oversized input, disabled syndication) now return a UCP-envelope-shaped response instead of a bare `WP_Error`. Agents see the same response shape on success vs failure, making strict UCP parsers simpler.
* Changed: out-of-stock line items in `POST /checkout-sessions` are now rejected as `unrecoverable` errors rather than warnings. WC's `is_in_stock` already accounts for the merchant's backorder settings; when it's false, the item genuinely can't be purchased.
* Changed: subscription and `variable-subscription` product types are explicitly rejected from checkout-sessions (was: fell through the type gate as if simple). Matches the manifest's advertised `checkout_link.unsupported` list.
* Changed: description stripping now uses `wp_strip_all_tags()` instead of native PHP `strip_tags()` — handles `<script>`/`<style>` tag contents and trims whitespace.

= 1.2.1 =
* Fixed: content caches (llms.txt and UCP manifest) now actually regenerate after an in-place plugin upgrade. A latent bug since 1.0.0 caused the activation hook to pre-write the stored plugin version, which short-circuited the boot-time cache-bust branch — merchants saw stale cached content for up to an hour after every upgrade. 1.2.0 users upgrading to 1.2.1 will see the correct new UCP manifest on the first request after update.
* Added: `ActivationTest` enforces the structural invariant that only the boot-time cache-bust branch writes the `wc_ai_syndication_version` option, preventing this bug class from returning.

= 1.2.0 =
* Restructured the /.well-known/ucp manifest to conform to the official UCP business_profile schema at https://github.com/Universal-Commerce-Protocol/ucp. The previous shape used bespoke field names that did not match the spec; AI agents using official UCP libraries could not parse it. New shape declares one `service` (the public WooCommerce Store API) and zero UCP capabilities — a discovery-only posture consistent with the plugin's web-redirect checkout model (no delegated or in-chat payments).
* Purchase URL templates (checkout-link and add-to-cart for every supported product type) and WooCommerce Order Attribution parameters are retained inside the service's `config` block. The templates now also include all three add-to-cart redirect variants (add-only, redirect-to-cart, redirect-to-checkout) per the official WooCommerce documentation.
* Spec URL references added to `purchase_urls.spec`, `purchase_urls.add_to_cart_spec`, and `attribution.spec` so AI agents can follow pointers to the canonical WooCommerce documentation.
* Protocol version format changed from semver ("1.0") to UCP's required YYYY-MM-DD format (now "2026-01-11"). This is the UCP protocol revision the manifest targets, separate from the plugin version.

= 1.1.2 =
* Fixed: rewrite rules for `/llms.txt` and `/.well-known/ucp` now flush synchronously on plugin upgrade, so the endpoints work on the very first request after update. Previously the flush was deferred to `init` priority 99, which left the current request 404-ing — merchants had to visit Settings → Permalinks → Save to recover. No manual step is needed for future upgrades.

= 1.1.1 =
* Fixed: llms.txt no longer advertises the removed authenticated catalog API (`/wc/v3/ai-syndication/products` endpoints and the `X-AI-Agent-Key` header). AI agents now see an accurate pointer to WooCommerce's public Store API and the UCP manifest.
* Fixed: UCP manifest response no longer advertises `X-AI-Agent-Key` in its `Access-Control-Allow-Headers` CORS header.
* Cached llms.txt and UCP manifest are automatically regenerated on upgrade (via the existing version-based cache bust).

= 1.1.0 =
* Updated AI crawler list to focus on commerce-relevant bots. Added OAI-SearchBot, Perplexity-User, Claude-User, Meta-ExternalAgent. Removed Bytespider, CCBot, anthropic-ai, cohere-ai.
* Added AI Crawler Access allowlist UI in the Discovery tab.
* Added AI Agent column and filter to the WooCommerce orders list.
* Added AI share percentage to the Overview stats card.
* Fixed stale crawler IDs left over from previous versions being silently kept in `robots.txt`.

= 1.0.0 =
* Initial release.
