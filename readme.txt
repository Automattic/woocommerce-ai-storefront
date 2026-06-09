=== WooCommerce AI Storefront ===
Contributors: woocommerce
Tags: woocommerce, ai, chatgpt, seo, llms-txt
Requires at least: 6.7
Tested up to: 6.8
Requires PHP: 8.1
WC requires at least: 9.9
WC tested up to: 9.9
Stable tag: 0.19.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Make your WooCommerce catalog discoverable by AI assistants (ChatGPT, Gemini, Claude, Perplexity) while keeping checkout on your store.

== Description ==

**Status: Beta.** This plugin is in active development. Features and shape may change between releases. Production use is supported; your feedback shapes what ships in 1.0.

**Your store, your checkout, your data, visible to every AI assistant.**

AI assistants are becoming a primary product discovery channel. Shoppers ask ChatGPT, Gemini, Claude, Perplexity, and Copilot for recommendations. The agents that return the best recommendations are the ones with machine-readable access to your catalog. This plugin gives you that access without giving up anything in return.

= What it does =

Publishes three discovery surfaces that AI agents consume:

* **llms.txt**: a Markdown store guide at `/llms.txt` with store identity, top categories, browse and search URLs, shipping and returns policy, and pointers at the UCP manifest and REST endpoints
* **UCP manifest**: a JSON declaration at `/.well-known/ucp` describing capabilities, checkout policy, and purchase URL templates
* **Enhanced JSON-LD**: augmented Schema.org Product markup on product pages with BuyAction, inventory levels, and attribute properties

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
* AI-referred orders store the agent name (e.g. `ChatGPT`) and an opaque AI session token in order meta for your reference. This data never leaves your server and is not personal data under GDPR.

== Installation ==

1. Upload the plugin to your `/wp-content/plugins/` directory, or install via the Plugins screen in WordPress.
2. Activate the plugin.
3. Go to **WooCommerce > AI Storefront** in the admin menu.
4. Click **Enable AI Storefront**.
5. (Optional) Review the **Product Visibility** tab to scope which products are exposed.
6. (Optional) Review the **Discovery** tab to adjust which AI crawlers are allowed and which rate limit applies.

Visiting `https://yourstore.example.com/llms.txt` in a browser should now return a Markdown document. Visiting `https://yourstore.example.com/.well-known/ucp` should return a JSON manifest.

== Frequently Asked Questions ==

= Do I need to register my store with OpenAI, Google, etc.? =

No. This plugin publishes discovery surfaces that AI crawlers fetch the same way they fetch any other site. No registration, no API keys, no agreements.

= Will AI agents respect the allowlist? =

Well-behaved AI crawlers respect `robots.txt` directives. Compliance is not universal; this is a cooperative protocol, not an enforcement mechanism. All 12 crawlers on the default allowlist are from organizations that have publicly committed to honoring robots.txt. Unchecking a crawler in the Discovery tab adds a `Disallow:` directive for that user agent; there's no stronger enforcement you can do at the WordPress layer.

= How does attribution work? =

When an AI agent links to a product page, it appends `utm_source={agent_name}&utm_medium=ai_agent&ai_session_id={session_id}` query parameters. WooCommerce's Order Attribution system (included in WooCommerce core since 8.5) captures these values into order meta and displays them in the built-in "Origin" column on the orders list. No custom column needed. The plugin surfaces per-agent revenue totals in the settings overview.

= What's the difference between llms.txt, robots.txt, and the UCP manifest? =

* **robots.txt** tells crawlers what they're allowed to fetch. It's a permissions document.
* **llms.txt** gives AI agents a machine-readable summary of the store: identity, top categories, browse/search URLs, shipping and returns policy, and pointers at the UCP manifest and REST endpoints. It's a discovery document written in Markdown.
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
* `_wc_ai_storefront_agent` (denormalized for faster queries)
* `_wc_ai_storefront_session_id` (conversation identifier)

== Frequently Asked Questions ==

= Does this support MCP (Model Context Protocol)? =

Not currently. MCP's tool and resource exposure pattern requires running a server surface reachable by external, non-admin clients, which neither WordPress core nor WooCommerce scaffold today. There is no first-class MCP entry point for a plugin to hook into, and running one alongside the WP stack would require auth, transport, and capability-routing infrastructure outside this plugin's scope.

AI Storefront targets the Universal Commerce Protocol (UCP) instead, which works with the HTTP/REST surfaces WordPress and WooCommerce already expose to public clients. UCP gives AI shopping agents a stable, spec-conforming way to discover and transact against your catalog. MCP support will be evaluated if and when WP/WC grow native MCP-server primitives.

= Will my customer data be shared with AI companies? =

No. Customer data stays on your store. AI agents see the public catalog (the same products a shopper browsing your storefront sees) via the discovery endpoints; checkout happens on your own site via a continue URL, using WooCommerce's native checkout flow. No PII is transmitted to AI providers by this plugin.

= What happens if I disable the plugin? =

Discovery endpoints (`/llms.txt`, `/.well-known/ucp`, JSON-LD markup) stop being served. The `robots.txt` additions are removed. Order attribution already captured on completed orders remains in the database; new orders stop getting AI attribution stamps. No product data is deleted.

== Changelog ==

= 0.17.3 - 2026-05-18 =
**Improved**
* User guide: new §5b "Shape your catalog for AI discoverability" with five principles, before/after worked example, and a step-by-step reshape recipe. Includes an optional subsection on using WooCommerce's native MCP (WC 10.3+, developer preview) to let an AI assistant help with the reshape.
* Documentation: MCP positioning updated to reflect WooCommerce core's native MCP support in WC 10.3.0+. Clarifies that MCP is admin-side (merchants' own AI assistants) while UCP is shopper-side (external buyers' shopping agents); the two are orthogonal audiences. AI Storefront does not register its own MCP abilities yet but the path is open via the WordPress Abilities API.
* Engineering docs: JSON-LD-SCHEMA `Product.category` reference expanded with sourcing rules, design rationale for not normalizing to Google Product Taxonomy in the plugin (links to POC #412), and the deepest-leaf breadcrumb selection algorithm.

= 0.17.2 - 2026-05-15 =
**Fixed**
* Multi-currency support is now correctly hidden when WooPayments' multi-currency feature is toggled off. The probe checks `\WC_Payments_Features::is_customer_multi_currency_enabled()` at runtime in addition to the function-exists check, so merchants who turn the feature off no longer see previously-configured currencies advertised.
* UCP manifest `store_context.accepted_currencies` is now omitted entirely on single-currency stores (instead of emitting a 1-element `[base]` list that falsely signals multi-currency support). Matches the existing llms.txt behavior.

= 0.17.1 - 2026-05-16 =
**Fixed**
* WooPayments multi-currency detection corrected for WCPay 10.x. Replaced the `\WCPay\MultiCurrency\MultiCurrency::instance()` probe (which returned `null` until WCPay's bootstrap completed) with the `WC_Payments_Multi_Currency()` global function. Also removed the `is_multi_currency_enabled()` guard, which does not exist on WCPay 10.x and caused `accepted_currencies` to always fall back to the store base currency on multi-currency-enabled stores.
* Observability: both `try`/`catch` blocks in the multi-currency probe now log diagnostic messages, making silent fallback-to-base-currency detectable in debug logs.

= 0.17.0 - 2026-05-15 =
**New**
* WooPayments multi-currency exposure across UCP, JSON-LD, and llms.txt: UCP manifest `store_context` gains an `accepted_currencies` array (base currency first); homepage `OnlineBusiness` JSON-LD `currenciesAccepted` becomes a space-separated list on multi-currency stores; llms.txt gains an `**Accepted currencies**` line when more than one currency is enabled.
* UCP `continue_url` and per-product `url` fields now carry `?currency=XXX` when the agent sends `context.currency` in the accepted set, activating WooPayments' page-level currency switcher on the destination. Per-product page JSON-LD already reflects the switched currency automatically.
* New filter `wc_ai_storefront_accepted_currencies` for integrators using non-WooPayments multi-currency plugins.

**Tweaked**
* Catalog response prices remain quoted in the store's base currency in this release; live currency switching of UCP catalog responses ships in Phase 2 (0.18+).

= 0.16.1 - 2026-05-14 =
**Improved**
* User guide refreshed: GMC compatibility (§1.2) reframed as "stacks with it" to reflect the January 2026 Google + Shopify UCP launch (Shopping Graph for retrieval, UCP for checkout handoff are now two layers of the same Gemini agentic flow). Smoke test (§4) restructured into three verification layers (direct endpoint check → UCPPlayground/UCPChecker → live AI assistant query) with per-engine fetch-behavior expectations stated explicitly. Agent/Referral stat card description (§3) picks up a diagnostic-use sentence for spotting failed UCP-manifest discovery. New troubleshooting entry (§10) for the 24h sitemap-discovery cache and how to bust it via plugin-settings save.

= 0.16.0 - 2026-05-14 =
**New**
* `/llms.txt` restructured into seven catalog-discovery sections (Store, Browse, Catalog, Shipping & Returns, Structured data, For agents, Extension schema) sourced from the same JsonLd helpers feeding the homepage Schema.org block. Browse URLs carry the new `utm_id=woo_llms` channel marker. (#398)
* Admin: HelpMenu hairline separator between Documentation/Support actions and the Version metadata row, matching Gutenberg's More-options popover convention.

**Improved**
* Attribution: new `WOO_LLMS_ID = 'woo_llms'` channel marker added to the STRICT recognition gate and `by_channel` SQL aggregation, slotting llms.txt-routed orders into the Referral channel alongside `woo_jsonld`.
* User guide and engineering docs (`USER-GUIDE.md`, `ARCHITECTURE.md`, `UCP-BUY-FLOW.md`, `JSON-LD-SCHEMA.md`) refreshed to describe the new `/llms.txt` structure.

**Tweaked**
* CI: retired `release-drafter` workflow; release notes now extracted directly from `CHANGELOG.md` at tag-push time via awk inside `release.yml`. Single source of truth, no drift between hand-curated CHANGELOG and the GitHub Release body.
* CI: dropped the GitHub "Pre-release" flag from `release.yml`. The Beta lifecycle status is already signaled through in-product surfaces (PageHeader, HelpMenu, readme Description, USER-GUIDE intro), and the pre-release flag was preventing v0.15.0 from claiming the "Latest" badge on the Releases page.

= 0.15.0 - 2026-05-13 =
**New**
* Attribution: AI orders split into Agent (live UCP shopping sessions, `utm_id=woo_ucp`) vs Referral (JSON-LD-surfaced traffic, `utm_id=woo_jsonld`) channels. New Overview stat card shows the comparison (e.g. "60% / 40%") so merchants can tell convertible AI traffic apart from exposure AI traffic. Stats payload extended with `by_channel` (per-channel orders + revenue + self-normalized share_percent) and `top_channel`. (#387)
* Admin: help menu in the PageHeader top-right with Documentation (in-product user guide), Support (woocommerce.com contact form), and Version. Built on `@wordpress/components` `DropdownMenu` for keyboard accessibility and ARIA semantics. (#388)
* Admin: Beta status markers across PageHeader, HelpMenu version line, readme Description, and user guide intro. Single grep+delete removes them at 1.0.

**Fixed**
* HelpMenu tooltip position: tooltip on the help icon was clipping against the WP admin chrome edge. Switched to `tooltipPosition: 'middle left'` so it extends leftward and stays in the viewport.

**Improved**
* User-guide footer self-updates via build-time version injection. The footer literal ("Covers AI Storefront X.Y.Z") now reads from `package.json` at build time instead of being hand-edited (the previous literal was four releases stale).
* User guide: new section pointing merchants at three widely-used third-party tools for verifying their UCP setup (UCPPlayground.com, UCPChecker.com, UCPRegistry.com), with a fallback to https://ucp.dev/ if any link goes stale.
* User guide: broadened the AI shopping assistant framing to lead with shopper-present co-shopping (the dominant 2026 case where the buyer stays in chat and is handed off to your checkout), with on-behalf-of agents as the secondary scenario.
* Em-dash cleanup across merchant-facing copy (5 in user guide, 28 in readme.txt) per the AGENTS.md convention.

= 0.14.2 - 2026-05-11 =
**Fixed**
* Unpurchasable variations no longer leak to agents. A misconfigured variation (typically missing a price, where `is_in_stock: true` but `is_purchasable: false` in WC) was being emitted with a usable-looking variant ID and a checkout URL that WC then refused at cart-add. Three coordinated guards: UCP `/catalog/{search,lookup}` filter the bad variations out before the product translator sees them; `/checkout-sessions` rejects a stale or guessed unpurchasable variant ID with a new `item_unpurchasable` error code (distinct from `out_of_stock` so agents can route remediation correctly); JSON-LD `hasVariant[]` and the parent-product BuyAction drop their checkout URLs while keeping descriptive fields so SEO crawlers don't get handed a URL WC would 4xx. (#373)
* Synthesized variant entries now carry the parent's `short_description` instead of an empty string. For simple / bundle / grouped products (and the synthesize-default fallback for malformed-variable parents), agents that drill into a variant ID directly now see useful descriptive copy on the variant entity. (#375)

**New**
* UCP `product.{status, published_at, updated_at}` relocated under `metadata.lifecycle.status` and `metadata.timestamps.{published_at, updated_at}` to match the UCP spec's expected shape. These are business-defined extension fields, not first-class properties of `product.json`. Strict validators that tighten `additionalProperties` in a future spec revision now read our manifest cleanly. (#374)

= 0.14.0 - 2026-05-11 =
**New**
* UCP: variable + variable-subscription products become first-class. Three fixes ride together: variant enumeration unlocked for `variable-subscription` (previously collapsed to a single placeholder), subscription line items accepted at `/checkout-sessions` (previously rejected with `product_type_unsupported`), and featured-variant precision driven by merchant `_default_attributes` with `variants[0]` reordering to match Schema.org's "first item is featured" convention.
* UCP: parent-only variable + variable-subscription line items at `/checkout-sessions` now return a permalink fallback `continue_url` plus a `field_required` / `requires_buyer_input` message, the same configurable pattern as bundle/grouped. Merchant `_default_attributes` pre-fills the PDP dropdown but the buyer retains the final choice (no server-side auto-resolution).
* JSON-LD: WC Subscriptions products now emit Schema.org recurring-pricing signals on the Offer: `priceSpecification` with `UnitPriceSpecification.billingDuration` for recurring price, two-element array for trial-then-paid, inline `priceComponentType: ActivationFee` plus `Offer.addOn` for sign-up fees, and `Offer.eligibleDuration` for finite-length subscriptions. Variable-subscription parents emit per-variation `priceSpecification` blocks under `hasVariant[i].offers[0]` so each subscription term (monthly, yearly, etc.) carries its own metadata.

= 0.13.2 - 2026-05-10 =
**Fixed**
* Fix bundle and grouped products surfacing broken AI-checkout links to crawlers and AI agents. The JSON-LD `BuyAction.target.urlTemplate` and `Offer.checkoutPageURLTemplate` were emitting `/checkout-link/?products=ID:1` for every product type: a shortcut bundle parents can't satisfy (no per-bundled-item config) and grouped parents have no SKU for (only their children do). Now branches on product type: bundle and grouped emit the product permalink with UTM placeholders; simple, variable, and per-variation entries keep the existing Shareable Checkout shape.

= 0.13.1 - 2026-05-10 =
**Fixed**
* Fix fatal "Call to a member function add_rule() on null" on plugin upgrade. The version-mismatch detection branch was calling `add_rewrite_rule()` synchronously on `plugins_loaded`, but WordPress core instantiates `$wp_rewrite` AFTER `plugins_loaded`. The deferred init:99 flush (already present in the same block) handles this at the correct lifecycle point.

= 0.13.0 - 2026-05-10 =
**New**
* UCP: WooCommerce Product Bundles plugin support. Bundles emit accurate `price_range`/`list_price_range` and `metadata.bundle` structure; deterministic bundles get `/checkout/?add-to-cart=` direct-checkout URLs, configurable bundles fall back to the PDP permalink with spec-defined error messaging.
* UCP: WooCommerce Grouped product support. Grouped parents emit `metadata.grouped.children[]`; deterministic grouped products (all-simple children) get `/checkout/?add-to-cart=PARENT&quantity[CHILD]=N` URLs, configurable grouped fall back to the PDP.

**Fixed**
* UCP: simple, bundle, and grouped products with Color/Size/Pattern/Material attributes now emit those attributes in `metadata.attributes` instead of `product.options[]`. Fixes a bug where multi-value attributes were silently truncated to first-value-only when emitted as `options[]`.

= 0.12.0 - 2026-05-08 =
**New**
* UCP enrichment: `option_value.id` and `selected_option.id` now emit as stable `<taxonomy>:<slug>` identifiers for taxonomy-backed variant attributes, enabling cross-locale variant matching by agents.
* UCP enrichment: category `value` fields now emit as `>`-delimited hierarchy strings (e.g. `"Clothing > Tshirts"`) per the UCP `category.json` spec.
* UCP: simple products with schema.org reserved variant attributes (Color, Size, Pattern, Material) now emit in product-group shape with `options[]` and a synthesized default variant.

**Fixed**
* UCP wire-format compliance with `release/2026-04-08`. Variant `price`/`list_price` renamed, `compare_at_price` removed (use `list_price`), `selected_option`/`option_value`/`rating` reshaped, seller moved from product to variant, lookup adds per-variant `inputs[]` correlation, message shapes match spec. Pre-fix, strict-validating agents were already rejecting our responses; this release aligns implementation with the protocol version we declare in the manifest.
* UCP: HTML entities in product/variant/category name fields (e.g. `&#8211;`) are now decoded to plain Unicode before emission.

= 0.11.1 - 2026-05-08 =
**Fixed**
* UCP: variable-product variants now emit distinct titles and structured `options` derived from WC's `variation` formatted string. Pre-fix every variant in a set carried the parent product name and empty options, making siblings indistinguishable to agents.

= 0.11.0 - 2026-05-08 =
**New**
* JSON-LD: known attributes now emit as typed Schema.org `Product` properties (`color`/`size`/`material`/`pattern`).
* JSON-LD: variable products now emit as Schema.org `ProductGroup` with per-variant `hasVariant` entries, including a postmeta-direct override path for misconfigured variations.
* JSON-LD: cross-sells and upsells now emit as `Product.isRelatedTo` and `Product.isSimilarTo`.
* JSON-LD: `BuyAction.target.urlTemplate` now uses the WooCommerce Shareable Checkout URL format. `Offer.checkoutPageURLTemplate` emits alongside.
* JSON-LD: homepage `@type` switched from `OnlineStore` to `OnlineBusiness`. Now also emits `knowsAbout` (top product categories) and `hasMerchantReturnPolicy` at Organization level.

**Fixed**
* JSON-LD: per-variant `@id` no longer collapses to the parent URL on misconfigured variable products.

= 0.10.3 - 2026-05-06 =
**Fixed**
* No-space comma queries ("Hoodies,Belts") now resolve both terms and OR-join correctly, identical to spaced-comma queries ("Hoodies, Belts").

= 0.10.2 - 2026-05-06 =
**Fixed**
* Comma-separated multi-category searches (e.g. "Hoodies, Belts") now return results. Commas are now treated as multi-item connectors equivalent to "and", triggering an OR join when all terms resolve to taxonomy matches.
* "Hat or Shoes"-style queries now always OR-join. "Or" is an unambiguous choice and bypasses the taxonomy-match guard required for "and"/comma queries.

= 0.10.1 - 2026-05-06 =
**Fixed**
* "Hoodies and Belts"-style multi-category searches now return products from either category instead of zero results.

= 0.10.0 - 2026-05-06 =
**New**
* Homepage JSON-LD now emits `@type: OnlineStore` (a Schema.org `Organization` subtype) instead of `@type: Store`, so AI shopping agents and search engines can verify your brand identity. Adds three auto-sourced identity fields: `logo` (from your WP custom logo or site icon), `address` (from WooCommerce store address; `streetAddress` deliberately omitted to protect home-office merchants from publishing residential addresses in machine-readable form), and `contactPoint.email` (resolved from your WooCommerce reply-to or sender email, with a noreply-pattern guard). No new merchant settings, no new admin UI: everything sourced from WP/WC config you already have.

**Fixed**
* Manifest and `/llms.txt` hits from UCP-aware clients (UCPScanner, UCPCheckerBot, etc.) now record correctly. Previously, only User-Agents matching a hardcoded AI crawler list were recorded; legitimate UCP discovery scanners with well-formed product tokens were silently dropped, producing a misleading zero in the analytics page.
* `/llms.txt` hits now record correctly on CDN-fronted installs (Atomic/WordPress.com). Same fix shape as the 0.9.1 manifest hotfix; `/llms.txt` was missed in that fix and was still emitting `Cache-Control: public, max-age=3600`. Switched to `Cache-Control: no-store`.

**Chores**
* Local development workflow simplified: project-root `docker-compose.yml` replaces the prior wp-env setup. `docker compose up -d` from a fresh clone now produces correctly-named containers and runs first-boot setup automatically. Existing wp-env users are unaffected; the `.wp-env.json` config is retained.

= 0.9.1 - 2026-05-04 =
**Fixed**
* UCP manifest hits now record correctly on CDN-fronted installs (Atomic/WordPress.com). The manifest was emitting `Cache-Control: public, max-age=3600`, causing CDN edges to serve it without reaching PHP, so the crawl logger never fired and the counter always showed zero. Switched to `Cache-Control: no-store`.

= 0.9.0 - 2026-05-04 =
**New**
* Policies tab: new Shipping card lets merchants set minimum and maximum order handling time (business days). Emits `handlingTime` in product JSON-LD so AI agents can surface shipping timelines.
* JSON-LD now emits `shippingRate: 0` when unconditional free shipping is available for the store's base country, allowing AI agents to read "free shipping" as a machine-readable fact.

**Fixed**
* Orders table: missing comma before "+N more" in the items column when an order has more than two line items.

= 0.8.8 - 2026-05-03 =
**New**
* Discovery tab now adds helper text clarifying that the AI crawler allowlist controls AI-specific agents only. General-purpose search engines (Google, Bing, Yandex) are managed by WordPress and SEO plugins.
* Expanded AI crawler allow-list with four additional agents: YouBot (You.com), Mistralai-User (Mistral), anthropic-ai (Anthropic's older crawler), and Diffbot.
* robots.txt opt-in block now uses a single consolidated rule (RFC 9309 §2.2.1), reducing output from ~200 lines to ~30 lines on a typical install.

**Fixed**
* `/llms.txt` now points AI agents to the UCP REST API (`/wp-json/wc/ucp/v1`) instead of the raw Store API, ensuring proper agent fingerprinting, rate limiting, and access control.
* Discovery tab "Products seen" now correctly counts products returned by AI search queries, not just lookup hits.
* Discovery tab "UCP API hits" relabeled to "UCP manifest hits" to accurately reflect what is being counted (`.well-known/ucp` manifest fetches, not REST API calls).

= 0.8.7 - 2026-05-03 =
**New**
* Discovery stats: hourly rollup. Today's AI agent activity now appears in the Discovery tab within ~1 hour, instead of waiting for the next nightly rollup. Existing sites auto-migrate on upgrade; no manual steps. Developers can switch the cadence to `twicedaily` or `daily` via the new `wc_ai_storefront_rollup_interval` filter; slower cadences fall back to `hourly` to avoid silent data loss.
* Discovery tab: the "Top searches" subtitle now reflects the live cron cadence ("Updated hourly.", "Updated every 12 hours.", "Updated daily.") instead of a generic placeholder, so merchants know exactly how fresh the data is.

**Fixed**
* Discovery tab no longer shows "No AI agent activity recorded" when raw-log traffic exists but the first rollup hasn't run yet. A brand-new install with llms.txt or UCP hits will now correctly reflect that activity in the empty-state guard.
* Cron filter changes (`wc_ai_storefront_rollup_interval`) now take effect on the very next request without waiting for the 5-minute stats cache to expire.
* Top searches list no longer drops today's queries. The SQL window is now lower-bound only, and the lookback for `period=quarter` (90d) is correctly clamped to the raw log's 30-day retention so the search list and the rest of the card stay internally consistent.
* Period filters now span the exact selected window (off-by-one fix): "Last 7 days" returns 7 calendar dates including today, not 8.

= 0.8.6 - 2026-05-03 =
**New**
* Discovery tab: crawler-side visibility stats. The plugin now records every identified AI-agent request (llms.txt, UCP manifest, UCP REST, robots.txt, Store API rate limiter) into a write-buffered log and rolls it up daily. The Discovery tab surfaces total requests, unique products seen, top searches with the agents that issued them, throttle rate, and per-agent breakdowns. Raw events kept 30 days; daily aggregates kept 90 days. Tables removed on uninstall.
* UCP product search: AI-agent natural-language queries now match across the store's own categories, tags, brands, and attributes, not just the product title. "Hoodie with logo", "Running shoes for men", and "watches" each resolve to relevant products even when the exact phrase isn't in any product title. Plural/singular morphology is handled automatically (hoodies/hoodie, watches/watch, accessories/accessory). Storefront, Cart, and Checkout product searches are unaffected.

**Fixed**
* Updater: now reads `WC_AI_STOREFRONT_GITHUB_TOKEN` as a PHP constant fallback so local-development update checks against the internal GitHub repo authenticate without requiring a manual filter. Production sites using the existing `wc_ai_storefront_github_token` filter are unchanged.

= 0.8.5 - 2026-05-02 =
**New**
* Overview tab: new AI Revenue % stat card shows AI-attributed revenue as a share of total store revenue for the selected period. Shows an em-dash placeholder when no store revenue exists.

**Fixed**
* Recent AI Orders table: Customer column now links to the WooCommerce orders list filtered by that customer. Guest orders show the name only.
* Recent AI Orders table: rows-per-page, column visibility, sort order, density, and layout type are now persisted to localStorage and restored on the next visit.
* Developer tooling: `npm start` (webpack watch) no longer silently drops the DataViews stylesheet, which was causing unstyled/condensed table rows in local development.

= 0.8.4 - 2026-05-01 =
**Improved**
* Admin hero on the disabled state redesigned: rhetorical tagline is now the 28px headline ("List once. Sell everywhere AI shops."), subcopy is "Checkout stays on your store. One click.", reassurance line trimmed to "Read-only · Reversible anytime". Chip strip now in main column flow, 4 chips (ChatGPT, Gemini, Perplexity, Copilot; Claude removed from chip strip but remains in value-prop card body text). Single-column hero with a deterministic responsive chip grid (no more 4+1 orphan at narrow widths). No merchant behavior change; UI refactor.
* Pre-commit Git hook auto-regenerates `languages/woocommerce-ai-storefront.pot` on commits that touch translatable PHP or JS source, eliminating "fix(ci): refresh .pot for line drift" filler commits. Activated automatically on `npm install` / `composer install`; bypass per-commit with `git commit --no-verify`.

= 0.8.3 - 2026-05-01 =
**Fixed**
* Restored the bundled `PucReadmeParser` (and Parsedown) to the release zip. The release workflow's overly-broad `vendor/` rsync exclude was stripping `includes/lib/plugin-update-checker/vendor/` along with the project-root Composer vendor dir, fataling at `wp-admin/plugins.php` / `update-core.php` once a newer release was available. Sites on v0.8.0 to v0.8.2 should upgrade to clear the fatal. No source code change.

= 0.8.2 - 2026-05-01 =
**Improved**
* Unified admin page header with inline section nav (logo + title row, tabs row, shared bottom border). Tagline shown only on the disabled state. Tab label "Product visibility" renamed to "Visibility". Two new typography tokens (`brandHeading`, `brandTagline`). No merchant behavior change; UI refactor.
* Mobile fixes on the disabled-state value-prop cards (explicit 24px gap, single-column hero) and body horizontal padding for inset against the content area.
* Updated USER-GUIDE.md (header description + tab rename) and UI-CONVENTIONS.md (new typography tokens). Nine screenshots flagged for human recapture.

= 0.8.1 - 2026-05-01 =
**Fixed**
* Recent AI orders count uses a single `SELECT COUNT(DISTINCT id)` query instead of loading every matching order ID into PHP memory. HPOS-aware.
* Overview tab fixes: server-side pagination on the Recent AI orders table, two new stat cards (AI Order Rate, AI Revenue with $X/$Y reference), Discovery tab responsive at narrow widths, Product visibility mobile badge wrap, custom rate card state isolation, DataViews pagination alignment.

**Improved**
* Added `docs/engineering/JSON-LD-SCHEMA.md`: full reference for the structured-data shapes the plugin emits, with annotated example output, per-field semantics, public filter signatures, and validation guidance.
* Refreshed 11 of 12 USER-GUIDE.md screenshots to match the v0.8.1 UI.

= 0.8.0 - 2026-04-30 =
**New**
* Settings page editorial pass: visual treatment refresh, 6-card stat strip with `N / M` denominator on AI orders, single-click Disable footer, plugin-wide `AI crawlers` -> `AI agents` copy shift, corrected `Other AI agents` toggle help text, new typography token scale.
* Product visibility tab UX rewrite: typeahead search-and-dropdown for SELECTED mode (chips show `name (SKU)`); taxonomy pickers raise search threshold to 20 terms and render as 2-column grid.
* Discovery tab spec implementation: 32px form fields across all tabs, monospace endpoint URLs, collapsible crawler groups with count badges, 2x2 rate-limit card grid with Custom 120px input.

**Fixed**
* Date-range labels aligned with trailing-window behavior; new "Last 90 days" preset; period enum is now `day, week, month, quarter, year`.
* Bumped `@wordpress/components` to ^33; aligned `dataviews`/`icons`/`i18n` floors and dedup the install tree. Does NOT clear the `uuid` Dependabot alert -- @wordpress/components 33 still ships uuid 9.
* Bumped `@wordpress/scripts` to ^32: webpack 5 dev-server, ESLint 9 flat config (`eslint.config.cjs`), Node 18.12+/npm 8.19.2+ engines floor declared. Adds `@wordpress/html-entities` and `@wordpress/url` as proper dependencies.
* Pinned vulnerable transitive dev dependencies via `npm overrides`. Cleaned up redundant overrides after the modernization.
* Removed PHP 8.3 deprecation notice from the autoloader.

**Improved**
* Sidebar menu label shortened to "AI Storefront" (page heading remains "Woo AI Storefront").
* Added `@wordpress/env` local development environment with six npm scripts; port 8030.
* User guide and engineering docs (CONTRIBUTING.md, TESTING.md, UI-CONVENTIONS.md) refreshed for unreleased UI changes.

= 0.7.2 - 2026-04-29 =
**Fixed**
* Corrected two documentation errors introduced by 0.7.0: the extension filters `wc_ai_storefront_ucp_product` and `wc_ai_storefront_ucp_variant` were misnamed with a `_data` suffix in the changelog, and the 400 error code for invalid UCP requests was documented as `ucp_invalid_request` instead of the correct `invalid_input`.

= 0.7.1 - 2026-04-29 =
**Fixed**
* Plugin now activates on a fresh clone without running `composer install`. 0.7.0 shipped without the required autoloader files, causing an immediate activation error. A committed `includes/autoload.php` replaces the dependency on Composer-generated vendor files.

= 0.7.0 - 2026-04-29 =
**New**
* Three extension filters for third-party plugins and themes: `wc_ai_storefront_ucp_product`, `wc_ai_storefront_ucp_variant`, and `wc_ai_storefront_ucp_store_api_args`.

**Improved**
* UCP error codes centralized as typed constants on `WC_AI_Storefront_UCP_Error_Codes`; bare string literals removed from the REST controller.
* Settings defaults centralized in a single `settings_defaults()` helper; dead `CACHE_KEY` constant removed from `WC_AI_Storefront_Ucp`.
* Cache-invalidator dependency inverted: components now register their own cache keys via `WC_AI_Storefront_Cache_Invalidator::register()`; multisite callable resolution fixed.
* `WC_AI_Storefront_UCP_Product_Translator` made stateless: errors accepted by reference and reset before each call.
* Per-request static state on the UCP REST controller replaced with scoped context objects, safe under persistent-worker runtimes (Swoole, RoadRunner, FrankenPHP).
* Manual `require_once` chain replaced with Composer classmap autoload; `load_dependencies()` method removed.

= 0.6.6 - 2026-04-28 =
**Fixed**
* `Vary: Host` header added to `/llms.txt` and UCP manifest; llms.txt PHP-layer cache segmented by host to prevent cache-poisoning on shared CDNs and multisite installs.
* JSON-LD hex-escapes `<`, `>`, `&`, `'`, `"` to block script-tag breakout via category names (stored XSS via `manage_categories`).
* `/checkout-sessions` collapses duplicate line items so `continue_url` quantities match the response totals.
* Attribution STRICT gate no longer fires false-positive when `utm_id=woo_ucp` is only in `$_GET` at order-create time (non-AI orders no longer misattributed).
* Rate limiter counts 1 slot per outer UCP request instead of 1 slot per inner Store API dispatch, preventing unexpected 429s on large catalog lookups.
* Per-product final-sale JSON-LD override now emits even when the WC base country is unset.
* `_wc_ai_storefront_agent_host_raw` double-write fixed: lenient-gate normalized value wins over untrusted URL param when both paths fire.
* `allow_unknown_ucp_agents` setting now saves correctly via the REST settings endpoint (was silently dropped).
* Return policy `returnFees` and `returnMethod` values validated against Schema.org allow-lists at JSON-LD emit time (defense-in-depth).
* `wc_ai_storefront_jsonld_product` and `wc_ai_storefront_jsonld_store` filters now receive a minimal 3-key settings subset instead of the full internal array.
* `meta.source` in `/checkout-sessions` POST body validated to 253-char max and hostname-safe charset before attribution storage.
* llms.txt sitemap probe enables SSL certificate verification in production (was unconditionally disabled).

= 0.6.5 - 2026-04-28 =
**Fixed**
* Checkout-session buyer-handoff message renders as informational, not an error, so AI agents present a primary Buy Now CTA instead of a plain hyperlink.

= 0.1.0 =
* Initial pre-release under the AI Storefront name, developed in the Automattic organization.
