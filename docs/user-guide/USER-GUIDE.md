# WooCommerce AI Storefront: Merchant User Guide

A step-by-step guide for store owners. Make your catalog discoverable to AI shopping assistants (ChatGPT, Gemini, Claude, Perplexity, Copilot) without giving up checkout, customer data, or your payment processor.

> Reading time: about 10 minutes. Following along: about 10 minutes plus optional verification.

## Contents

1. [Before you start](#1-before-you-start)
2. [Install and activate](#2-install-and-activate)
3. [Enable AI Storefront](#3-enable-ai-storefront)
4. [Verify your discovery endpoints](#4-verify-your-discovery-endpoints)
5. [Choose which products to expose](#5-choose-which-products-to-expose)
6. [Configure crawlers and rate limits](#6-configure-crawlers-and-rate-limits)
7. [Set your store policies](#7-set-your-store-policies)
8. [Read attribution stats](#8-read-attribution-stats)
9. [Maintenance and monitoring](#9-maintenance-and-monitoring)
10. [Troubleshooting](#10-troubleshooting)
11. [Where to get help](#11-where-to-get-help)

---

## 1. Before you start

You'll need:

- WordPress 6.7+, WooCommerce 9.9+, PHP 8.1+ (your host controls PHP; most modern hosts already meet this).
- An admin or Shop Manager account (anything with `manage_woocommerce`).
- A site reachable on the public internet. AI agents won't see a store behind a staging password or "coming soon" plugin.

You **don't** need an AI account, an API key, or a developer.

---

## 2. Install and activate

1. **Plugins → Add New → Upload Plugin**.
2. Select `woocommerce-ai-storefront.zip` and click **Install Now**.
3. Click **Activate**.

![Plugins screen with WooCommerce AI Storefront activated](screenshots/01-plugins-screen.png)

A new menu item appears under **WooCommerce → AI Storefront** in the sidebar. The page opens with a slim header strip (small Woo logo + the title "AI Storefront"). On the disabled state, a purple-tinted hero block sits directly below with the headline "List once. Sell everywhere AI shops.", a one-line reassurance ("Checkout stays on your store. One click."), a strip of four assistant chips (ChatGPT, Gemini, Perplexity, Copilot), the **Enable AI Storefront** button, and a "Read-only · Reversible anytime" note beneath the button. Once you enable the plugin, the hero is replaced by the section nav (Overview, Visibility, Policies, Discovery) directly below the header strip. If you don't see the menu item, confirm WooCommerce itself is active. AI Storefront depends on it.

---

## 3. Enable AI Storefront

The plugin installs in **paused** mode. Nothing publishes until you turn it on.

1. Go to **WooCommerce → AI Storefront**. You'll land on the **Overview** tab.
2. Click **Enable AI Storefront** at the top of the page.

![Overview tab with the enable button](screenshots/02-enable-toggle.png)

Enabling does five things:

- Adds AI agent `Allow:` directives to `robots.txt`.
- Publishes the Markdown store guide at `/llms.txt`.
- Publishes the JSON manifest at `/.well-known/ucp`.
- Enables enhanced JSON-LD on product pages.
- Starts capturing AI-attributed orders into WooCommerce Order Attribution.

To pause, click **Disable AI Storefront** at the bottom of the page. Discovery endpoints return 404, JSON-LD additions are removed, `robots.txt` reverts to the WordPress default. Captured order attribution stays in place.

The Overview tab populates with stat cards once data flows in:

- **Products exposed**: products AI agents can currently see (matches your visibility settings). Shown on a tinted background to distinguish a configuration value from the performance stats below.
- **AI orders**: AI-attributed volume in the period, shown as `N / M` where M is the total orders denominator.
- **AI Order Rate**: percentage of all orders in the period that came from AI agents (`AI orders / all orders`). Shows `0.0%` when orders exist but none are AI-attributed.
- **AI revenue**: gross revenue from AI-referred orders, shown as `$X / $Y` where Y is total store revenue in the period (no decimals on the denominator).
- **AI revenue %**: AI-attributed revenue as a percentage of total store revenue (`AI revenue / all revenue`). Shows a dash placeholder when no store revenue exists in the period.
- **AI AOV**: average order value from AI-referred orders.
- **Top agent**: which agent drives the most AI volume.
- **Top agent share**: what share of AI revenue the top agent represents.

![Overview tab stat cards](screenshots/03-overview-cards.png)

A date-range strip above the stat cards lets you pick the window: **Today**, **Last 7 days**, **Last 30 days**, **Last 90 days**, **Last 12 months**. Each is a trailing window from the moment you click; the labels match the underlying behavior (a "Last 12 months" click means the last 365 days, not the previous calendar year).

> Stats are blank on day one. First AI traffic typically lands within a few days; meaningful aggregate volume takes weeks.

---

## 4. Verify your discovery endpoints

Before configuring anything else, take 30 seconds to confirm the endpoints are live.

| URL | What you should see |
|-----|---------------------|
| `https://your-store.com/llms.txt` | A plain-text Markdown document starting with `# Your Store Name`, with a category list and "How AI agents should link to products" section. |
| `https://your-store.com/.well-known/ucp` | A pretty-printed JSON document in monospace. Top-level keys: `name`, `version`, `capabilities`, `payment_handlers`, `services`. |
| `https://your-store.com/robots.txt` | The standard WordPress `robots.txt` plus a block of `User-agent: GPTBot` / `User-agent: ChatGPT-User` / etc. each with `Allow:` lines. |
| Homepage → "View page source" | Search for `"@type":"OnlineStore"`. This is your store's brand-identity card, surfaced for AI shopping agents. See [section 4a](#4a-what-the-homepage-publishes-to-ai-agents). |
| Any product page → "View page source" | Search for `"@type":"Product"`. Look for a `BuyAction` block, an `offers` array with prices, and (once you set one in [section 7](#7-set-your-return-policy)) `hasMerchantReturnPolicy`. |

The Discovery tab shows the same URLs as clickable links with reachability dots. URLs render in monospace font:

![Discovery tab Discovery Endpoints card](screenshots/09-endpoints-info.png)

If something returns 404 or shows your homepage, jump to [Troubleshooting](#10-troubleshooting).

**Smoke test with an AI assistant.** Once endpoints check out, ask one of the major assistants with live web browsing:

> *"Find products at \[your-store.com\] that match \[some attribute, e.g. 'red running shoes under $100'\]."*

A working setup returns real product names with prices and links to your store within 3–10 seconds. If the agent says it can't find anything, wait a few hours (most agents cache crawl results) and retry.

Natural-language search queries match against your product categories, tags, brands, and attributes, not just product titles. So an agent asking for "hoodies" will find products in your "Hoodies" category even if the individual product titles use a different word, and "watches" will find products in a "Watch" category. Plural and singular forms are handled automatically.

### 4a. What the homepage publishes to AI agents

Your homepage now includes a JSON-LD `OnlineStore` block. AI shopping agents and search engines use this to verify your brand identity and link your manifest data to a recognizable entity.

| Field | Source | Notes |
|-------|--------|-------|
| `name`, `description`, `url` | WordPress Site Settings | Edit at **Settings → General**. |
| `currenciesAccepted` | WooCommerce currency setting | Edit at **WooCommerce → Settings → General**. |
| `logo` | WP Custom Logo (preferred) or Site Icon (fallback) | Edit at **Appearance → Customize → Site Identity**. Omitted if neither is set. |
| `address` | WooCommerce Store Address | Edit at **WooCommerce → Settings → General**. Built from city, region, postcode, and country. |
| `contactPoint.email` | Two-stage from WooCommerce email settings | See **Customer service email** below. |
| `potentialAction` (search) | Auto-generated | Lets agents search your store with `?s=...&post_type=product`. |
| `hasOfferCatalog` | Auto-generated | A summary of your top product categories. |

**About the address.** Only city, region (state/province), postcode, and country are published. Your **street address is intentionally NOT included** in the JSON-LD, even when you have one set in WooCommerce settings. This is a privacy and safety choice: many small Woo merchants use a home address for tax-calculation purposes and don't expect it to appear in machine-readable form. City + region + postcode + country preserve everything an AI agent needs (jurisdiction, shipping origin, fraud-check) without leaking a residential address.

**Customer service email.** The plugin chooses one of two WooCommerce email settings, in this order:

1. **Reply-to address** at **WooCommerce → Settings → Emails → Sender options**, *if* you've enabled the "Reply-to" toggle. This is WooCommerce's purpose-built field for "where customers should reach me."
2. **From address** at the same screen, as a fallback. Skipped if it looks like a noreply address (starts with `noreply`, `no-reply`, `donotreply`, or `do-not-reply`, with or without a `+tag` suffix). Many merchants set their From to a noreply address to avoid bounce-handling, which means publishing it as a customer contact would route real questions into a black hole.

The plugin **never** publishes your WordPress admin email as a public contact. If neither of the WooCommerce email settings produces a usable address, the `contactPoint` block is omitted entirely.

**Phone and social profiles** are not published by this plugin. WooCommerce doesn't store either, and ecosystem plugins (Jetpack, Yoast, etc.) typically own that capture. Developers can inject these fields via the `wc_ai_storefront_jsonld_store` filter (see the engineering reference in `docs/engineering/HOOKS.md`).

---

## 5. Choose which products to expose

The **Visibility** tab controls what AI agents can see. Three modes:

| Mode | What AI agents see | Use when |
|------|--------------------|----------|
| **All published products** | Everything currently published | Default. Use unless you have a reason to scope. |
| **Products by category, tag, or brand** | Only products in the selected taxonomies | You want to expose evergreen lines but exclude clearance, NSFW, or out-of-region products. |
| **Specific products only** | Only products you pick individually (with typeahead search) | Curated launches, B2B-restricted SKUs, limited drops. |

**Steps:**

1. Open the **Visibility** tab.
2. Pick the mode.
3. For **Products by category, tag, or brand**, switch between the **Categories**, **Tags**, and **Brands** sub-tabs and check what you want included. The **Brands** sub-tab only appears if your store has a `product_brand` taxonomy registered (typically via WooCommerce Brands or a similar plugin); without one, you'll see only Categories and Tags. Taxonomies with 20+ terms have a search bar. Checkboxes render in a 2-column grid. The product-count pill updates live.
4. For **Specific products only**, use the typeahead search box to find products by name or SKU. Click a match to add a chip; already-added items appear disabled with a checkmark. Chips show the product name and SKU.
5. Click **Save changes** at the bottom-right.

![Visibility tab, by-taxonomy mode](screenshots/04-products-by-taxonomy.png)

![Visibility tab, specific products mode](screenshots/05-products-selected.png)

Visibility applies consistently across `/llms.txt`, the UCP catalog endpoints, and JSON-LD on product pages. Excluded products cannot be returned by an AI agent's catalog query; exclusion is enforced at the data layer, not by hiding links.

### Catalog access

The **Catalog access** card shows the total product count and the count exposed to AI agents. Visibility settings are enforced on the selection mode you choose.

> **Note.** Visibility settings only affect AI-agent-facing surfaces. The Shop page, search, and category archives keep working exactly as before for regular shoppers.

---

## 6. Configure crawlers and rate limits

The **Discovery** tab controls which AI crawlers can read your endpoints and how aggressively. All form input fields on this tab are 32px tall.

![Discovery tab](screenshots/06-discovery-tab.png)

### AI agent access

The crawler list groups 18+ AI bots into three collapsible sections, each with a count badge and chevron:

- **Live browsing agents**: fetch pages in real time when a user asks. Default: **on**. These drive shopping clicks.
- **Training crawlers**: feed AI providers' training corpora. Default: **on**. Letting your catalog inform model updates is generally good for long-tail product discovery.
- **Test/validation crawlers**: for protocol-compliance tools like UCPPlayground. Default: **off**.

Unchecking adds a `Disallow:` directive for that user-agent. This is a cooperative protocol; well-behaved crawlers respect it; malicious bots ignore robots.txt entirely (and don't make purchase recommendations either).

A toggle labeled **Other AI agents** controls whether unlisted crawlers can access your store. When checked, AI agents whose brand isn't in the list can access your store.

### Rate limits

A 2 × 2 card grid shows four preset options: Free, Light, Standard, and Custom. Select one to set how many AI commerce requests per minute each AI crawler can make before receiving HTTP 429. One request counts as one slot regardless of how many products are in the request (a catalog lookup for 50 products counts the same as a lookup for 1).

Selecting **Custom** reveals a 120px input with `requests / min` suffix directly below the Custom card.

Rate limiting only affects AI crawlers (matched by user-agent). Regular customers, your storefront, and admin REST traffic are unaffected.

### AI activity log

The Discovery tab includes a visibility section that surfaces what AI crawlers are actually doing on your store. The plugin records every identified AI-agent request that hits your discovery endpoints (llms.txt, the UCP manifest, the UCP API, robots.txt, and the Store API) into a private log on your own database. Nothing is sent off-site.

The period selector at the top (Day / Week / Month / Quarter) drives all three cards:

- **Top searches.** The most common search phrases AI agents have asked for, with the agents that issued each one. If you see "running shoes" but you don't sell shoes, that's a signal an AI is sending the wrong shoppers your way; if you see "hoodies" and you sell hoodies but not enough are showing, you may want to check that your hoodies category is named conventionally.
- **Products seen.** A sampled list of products that have been returned to AI agents in the period, with the count of how many times each was surfaced.
- **Per-agent breakdown.** Total requests grouped by AI brand (ChatGPT, Perplexity, Gemini, etc.). Useful for noticing when a new crawler starts visiting your store.

There's nothing to configure. Data starts populating from the moment you enable the plugin. Raw events are kept for 30 days; aggregated daily counts are kept for 90 days. There is no option to extend retention; if you need long-term analytics, treat this as a "what changed last quarter" tool, not a permanent dashboard.

Stats refresh on every rollup run (hourly by default), so today's traffic appears in the dashboard within one rollup cycle of occurring. On upgrade the plugin automatically migrates any pre-existing cron event to the new cadence, so no manual steps are needed.

### Revisit cadence

- **Quarterly.** New AI crawlers come online; check the list. Plugin updates sync the canonical roster; stale opt-outs stay opt-out.
- **After traffic spikes.** Lower the rate limit before you remove crawlers. Most spikes are first-time discovery; rates settle within a week.

---

## 7. Set your store policies

The **Policies** tab publishes structured signals that AI agents read when deciding what to show shoppers: return terms, shipping timelines, and more. Two cards are currently available.

![Store policies tab](screenshots/07-policies-tab.png)

### Return & refund policy

AI agents that surface your products often try to display return windows inline ("Free returns for 30 days").

Three modes:

- **Not configured** *(default)*. No JSON-LD return policy is published. Use when your policy is too complex to summarize and you'd rather link to a dedicated returns page.
- **Returns accepted.** Specify window in days, who pays return fees, accepted return methods. Emits as Schema.org `MerchantReturnPolicy`.
- **Final sale.** Declares no returns. Same Schema.org markup, with the appropriate flag.

You can also link to an existing returns/refunds page from the dropdown. This is useful when the policy already lives on a customer-facing page.

#### Per-product overrides

Some merchants have a generally returnable catalog with specific final-sale items (clearance, custom, perishable). The **AI: Final sale** checkbox in the product editor's Inventory tab overrides the store-wide policy for that single product.

![Product editor Inventory tab with AI Final sale checkbox](screenshots/08-product-final-sale.png)

### Shipping: handling time

AI agents that compare products often surface shipping timelines ("Ships in 1–2 business days"). The **Shipping** card lets you declare your order handling time so agents can read it as structured data rather than guessing from free-text descriptions.

Set **Minimum** and **Maximum** business days using the stepper inputs (0–365). When both are greater than 0, the plugin emits a `handlingTime` block under `OfferShippingDetails` in the product JSON-LD.

- Leave both at **0** (default) to omit handling time from structured data entirely.
- If you set max below min, max is automatically raised to match min.
- A live preview beneath the inputs shows the would-be structured-data shape so you can verify before saving.

> **Note:** Handling time reflects how long it takes to pack and dispatch, not total transit time. Transit time depends on the carrier and destination and is not currently modeled.

---

## 8. Read attribution stats

When a shopper completes a purchase via an AI-agent link, WooCommerce captures three things on the order:

- The agent's hostname (e.g. `chatgpt.com`), stored as `utm_source`.
- `utm_medium=referral` and `utm_id=woo_ucp`: the signals AI Storefront uses to identify the order as AI-referred.
- A session correlation ID (`ai_session_id`): useful for debugging; not personally identifying.

### Reachability check

The Discovery tab shows a reachability indicator in the card intro. The note "Reachability is checked from your browser" confirms the endpoints are accessible to AI agents.

### Where to see it

**WooCommerce Orders list.** Open **WooCommerce → Orders**. The built-in **Origin** column shows the agent hostname (`Source: Chatgpt.com`, `Source: Gemini.google.com`, `Source: Ucpplayground.com`) for AI-referred orders. Non-AI orders show `Direct`, `Unknown`, or the standard referring source.

![WooCommerce Orders list with Origin column](screenshots/10-orders-origin.png)

**Overview tab.** The stat cards aggregate AI orders, revenue, AOV, and top-agent share for the selected period.

![Overview tab per-agent stat cards](screenshots/11-per-agent-stats.png)

**Recent AI Orders table** (Overview tab). The most recent AI-attributed orders with order number, customer, date, status, items, agent, and total. Clicking the order number opens the WC order edit screen. Clicking a customer name opens the WooCommerce orders list filtered to that customer. Search, column-sort, and pagination work server-side, so the table fetches only the current page and large order volumes don't slow the Overview tab. Filters by **agent** and by **status** narrow the result set without leaving the table. Table preferences (rows per page, column visibility, sort order, and density) are saved in the browser and restored on your next visit.

![Recent AI Orders table](screenshots/12-recent-ai-orders.png)

### What attribution doesn't capture

The plugin does not record:

- Shopper identity beyond what WooCommerce already captures at checkout (name, email).
- The shopper's conversation with the AI agent.
- Cross-device or cross-session journey data.

For multi-touch journey attribution, pair this plugin with an analytics tool that reads WooCommerce's order-attribution meta. That's the supported integration point.

---

## 9. Maintenance and monitoring

Day-to-day maintenance is minimal.

**Weekly (5 min).** Glance at the Overview tab. Sudden drops in AI orders usually mean an agent revised its crawl policy or `robots.txt` changed. If one agent dominates, dig into how that agent surfaces your products.

**Monthly (10 min).** Re-verify the four endpoint URLs from [section 4](#4-verify-your-discovery-endpoints). Hosting, CDN, and security plugin changes can break virtual URLs. Review your visibility scope.

**After major changes.** Re-verify endpoints after a WordPress core update, a WooCommerce major version update, switching themes, installing or updating a security plugin (some block `/.well-known/` by default; allow-list `/.well-known/ucp` if so), or migrating hosts.

**Plugin updates.** AI Storefront ships frequent updates while the protocol is evolving. Each release has a CHANGELOG entry; review before updating in production. 0.x.y updates are backwards-compatible; a major version bump (e.g. 0.x → 1.0) will be called out explicitly with migration notes.

---

## 10. Troubleshooting

### `/llms.txt` returns 404

Permalinks need flushing. Go to **Settings → Permalinks**, click **Save Changes** without changing anything, reload `/llms.txt`. If still 404, check the plugin is enabled. Paused means 404 by design.

### `/.well-known/ucp` returns 404 but `/llms.txt` works

A security or hardening plugin is blocking `/.well-known/`. Allow-list `/.well-known/ucp` in its rules.

### JSON-LD doesn't include the BuyAction

A theme or page-builder is overriding WooCommerce's `wp_head` hooks. Switch to Storefront temporarily to confirm; then either contact the theme developer or pick a theme that respects WC's structured-data hooks.

### AI agents say they can't find your store

Most likely the store has been live for less than 24 hours, or `robots.txt` blocks the agent's user-agent, or your `WordPress Address (URL)` in **Settings → General** doesn't match the public hostname. Wait 24–48 hours; check the Discovery tab; confirm the URLs match; visit `/llms.txt` from a fresh browser session.

### Stats are zero after a week

Confirm "Products exposed" > 0 on the Overview tab, at least one live-browsing crawler is checked on the Discovery tab, and the four endpoints from section 4 verify. Discovery is asynchronous; consumer-facing AI traffic is the lagging signal.

### Orders show up as AI-attributed when they shouldn't

A team member tested with a real AI assistant and clicked through. The attribution is correct: that order really was AI-referred. Use a dedicated test account or staff discounts when smoke-testing.

### `robots.txt` looks empty after disabling

Correct behavior. The plugin removes its own block; what remains is WordPress's default (`User-agent: *` + `Disallow: /wp-admin/`).

---

## 11. Where to get help

- **Documentation index:** [`docs/README.md`](../README.md)
- **Engineering docs** (for developers extending or debugging): [`docs/engineering/`](../engineering/)
- **Bug reports & feature requests:** open an issue on GitHub.
- **Security issues:** see [`SECURITY.md`](../../SECURITY.md). **Do not** open a public issue for security reports.
- **General WooCommerce support:** [woocommerce.com/support](https://woocommerce.com/support/) for questions about checkout, payments, taxes, shipping, or anything else WooCommerce-core-shaped.

---

*Covers AI Storefront 0.6.x. Screenshots taken from a stock WordPress 6.7 + WooCommerce 9.9 install. Your store may look slightly different depending on theme and admin color scheme.*
