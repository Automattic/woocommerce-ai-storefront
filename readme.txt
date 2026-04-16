=== WooCommerce AI Syndication ===
Contributors: woocommerce
Tags: woocommerce, ai, chatgpt, seo, llms-txt
Requires at least: 6.7
Tested up to: 6.8
Requires PHP: 8.0
WC requires at least: 9.9
WC tested up to: 9.9
Stable tag: 1.2.1
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
6. When a customer clicks through from an AI agent and buys, WooCommerce's standard Order Attribution captures the agent name in order meta. The admin shows AI orders alongside regular orders with an "AI Agent" column, and the plugin's settings page displays AI-attributed revenue by agent and time period.

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

When an AI agent links to a product page, it appends `utm_source={agent_id}&utm_medium=ai_agent&ai_session_id={session_id}` query parameters. WooCommerce's Order Attribution system (included in WooCommerce core since 8.5) captures these values into order meta. The plugin adds an `AI Agent` column to the orders list and surfaces per-agent revenue totals in the settings overview.

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
