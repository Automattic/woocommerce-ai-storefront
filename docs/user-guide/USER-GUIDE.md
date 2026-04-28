# WooCommerce AI Storefront — Merchant User Guide

A step-by-step guide for store owners. Get your catalog discoverable by AI shopping assistants like ChatGPT, Gemini, Claude, Perplexity, and Copilot — without giving up checkout, customer data, or your payment processor.

> Reading time: about 15 minutes. Following along: about 10 minutes plus optional verification.

---

## Table of contents

1. [What this plugin does (and what it doesn't)](#1-what-this-plugin-does-and-what-it-doesnt)
2. [Before you start](#2-before-you-start)
3. [Install and activate](#3-install-and-activate)
4. [Enable AI Storefront](#4-enable-ai-storefront)
5. [Choose which products to expose](#5-choose-which-products-to-expose)
6. [Configure crawlers and rate limits](#6-configure-crawlers-and-rate-limits)
7. [Set your return policy](#7-set-your-return-policy)
8. [Verify your discovery endpoints](#8-verify-your-discovery-endpoints)
9. [Read attribution stats](#9-read-attribution-stats)
10. [Day two: maintenance and monitoring](#10-day-two-maintenance-and-monitoring)
11. [Troubleshooting](#11-troubleshooting)
12. [Glossary](#12-glossary)
13. [Where to get help](#13-where-to-get-help)

---

## 1. What this plugin does (and what it doesn't)

### What it does

WooCommerce AI Storefront publishes machine-readable signals that let AI shopping assistants find your products and recommend them to shoppers. It does this in four ways:

- A Markdown store guide at `https://your-store.com/llms.txt` listing your categories, featured products, and how AI agents should attribute traffic back to you.
- A JSON manifest at `https://your-store.com/.well-known/ucp` declaring your store's commerce capabilities (catalog discovery, checkout policy).
- Enhanced Schema.org JSON-LD on your product pages with `BuyAction` markup, inventory, attributes, and shipping details.
- A small allow-list block in `robots.txt` for the major AI crawlers (GPTBot, ChatGPT-User, ClaudeBot, PerplexityBot, and others).

When a shopper asks an AI assistant for product recommendations and the agent returns yours, the click lands on your store, on your domain, with a `?utm_source=chatgpt&utm_medium=ai_agent` URL that WooCommerce automatically captures into the order's attribution.

### What it doesn't do

- **No delegated payments.** Checkout always happens on your store, with your payment processor. No middleman.
- **No platform fees.** Anthropic, OpenAI, Google, and others do not charge you anything for AI-referred traffic — you publish discovery signals, agents read them.
- **No API keys.** AI agents discover your store the same way a search engine does. There's no registration step.
- **No customer data sharing.** Customer data stays on your store. AI agents see your public catalog, nothing more.

### Who this plugin is for

You'll get value from AI Storefront if:

- You sell physical or digital goods through WooCommerce.
- Your product pages already work for normal shoppers (titles, prices, images, descriptions).
- You're comfortable visiting URLs in a browser to verify endpoints (you don't need to write code).

If your store is brand-new and has zero products published, finish populating your catalog first, then come back.

---

## 2. Before you start

You'll need:

- WordPress 6.7 or newer.
- WooCommerce 9.9 or newer.
- PHP 8.0 or newer (your hosting provider controls this — most modern hosts already meet this).
- A user account with the **Shop Manager** role or higher (technically any role with the `manage_woocommerce` capability).

You should also be able to:

- Visit your site's URL in a browser.
- Log in to WordPress admin (`https://your-store.com/wp-admin/`).

You **don't** need:

- A separate AI account.
- An API key from any provider.
- A developer.

> **Tip.** If your store is behind a staging password or "coming soon" plugin, AI agents won't see it. AI Storefront publishes public signals; for those signals to reach AI crawlers, your site needs to be reachable on the open internet.

---

## 3. Install and activate

1. In WordPress admin, go to **Plugins → Add New**.
2. Click **Upload Plugin**, select the `woocommerce-ai-storefront.zip` file, and click **Install Now**.
3. Once installed, click **Activate**.

![Plugin installed and activated on the Plugins screen](screenshots/01-plugins-screen.png)

After activation, a new menu item appears under **WooCommerce → AI Storefront**. If you don't see it, make sure WooCommerce itself is active — AI Storefront depends on it.

---

## 4. Enable AI Storefront

By default, AI Storefront installs in **paused** mode. None of your endpoints publish until you flip the switch.

1. Go to **WooCommerce → AI Storefront**. You'll land on the **Overview** tab.
2. Click **Enable AI Storefront** at the top of the page. (Once enabled, the same control becomes a **Disable** button.)

![Overview tab showing the AI Storefront active banner](screenshots/02-enable-toggle.png)

Enabling does five things:

- Adds AI-crawler `Allow:` directives to your `robots.txt`.
- Publishes the Markdown store guide at `/llms.txt`.
- Publishes the JSON manifest at `/.well-known/ucp`.
- Enables enhanced JSON-LD on product pages.
- Starts capturing AI-attributed orders into WooCommerce's standard order attribution system.

You can pause everything at any time by clicking **Disable** — discovery endpoints return 404, JSON-LD additions are removed, and `robots.txt` reverts to the WordPress default. Captured order attribution remains in place; it's part of your business records.

After enabling, the Overview tab shows a row of stat cards:

- **Products exposed** — how many products AI agents can currently see (matches your product visibility settings, see [section 5](#5-choose-which-products-to-expose)).
- **AI orders** and **Total orders** — orders attributed to AI agents in the selected period, alongside total store volume for context.
- **AI revenue** and **AI AOV** — gross revenue and average order value from AI-referred orders.
- **Top agent** and **Top agent share** — which AI agent is sending the most orders, and what share of AI revenue it represents.

![Overview tab showing stat cards after enabling](screenshots/03-overview-cards.png)

> Stats are blank on day one. Numbers populate as AI agents discover your store and shoppers click through. Most stores see first AI traffic within a few days; meaningful aggregate volume takes weeks.

---

## 5. Choose which products to expose

The **Product Visibility** tab controls what AI agents can see. Three modes, picked once and changed any time:

| Mode | What AI agents see | When to use this |
|------|--------------------|------------------|
| **All published products** | Everything currently published in WooCommerce | Default. Use unless you have a specific reason to scope down. |
| **Products by category, tag, or brand** | Only products in the taxonomies you select | You want to expose evergreen lines but exclude clearance, NSFW, or out-of-region products. |
| **Specific products only** | Only the individual products you pick | Curated launches, limited drops, B2B-restricted SKUs that shouldn't surface to consumer agents. |

### Step-by-step

1. Open the **Product Visibility** tab.
2. Pick the mode that fits your store.
3. If you picked **Products by category, tag, or brand**, switch between the **Categories**, **Tags**, and **Brands** sub-tabs and check the boxes for the taxonomies you want included. The product-count pill next to the mode label updates live as you click.
4. If you picked **Specific products only**, search for products by name or SKU, then click each one to add it to the list. The picker handles thousands of products without slowdown.
5. Click **Save changes** at the bottom-right.

![Products tab with by-category mode selected](screenshots/04-products-by-taxonomy.png)

![Products tab with the individual product picker open](screenshots/05-products-selected.png)

### How visibility selection interacts with each surface

Your visibility choice applies consistently across every surface AI agents touch:

- `/llms.txt` lists only the exposed products.
- The UCP catalog search and lookup endpoints return only the exposed products.
- Enhanced JSON-LD on product pages is added only on exposed products. Non-exposed product pages keep WooCommerce's default markup.

Exclusion is enforced at the data layer, not by hiding links. A product not in your selection cannot be returned by an AI agent's catalog query.

> **One thing to note.** Visibility settings do not change what regular shoppers see. The Shop page, search, and category archives keep working exactly as before. Only AI-agent-facing surfaces respect this scoping.

---

## 6. Configure crawlers and rate limits

The **Discovery** tab is where you control which AI crawlers can read your endpoints and how aggressively they're allowed to fetch.

![Discovery tab with crawler checkboxes and rate limit slider](screenshots/06-discovery-tab.png)

### Choose your crawlers

The checkbox list includes 18+ AI bots split into three groups:

- **Live browsing agents** — used by AI assistants when a user asks a question and the agent fetches the web in real time. Examples: ChatGPT-User, Claude-User, Perplexity-User, Storebot-Google. **Default: on.** These are the agents that drive shopping clicks.
- **Training crawlers** — used to update an AI provider's training corpus. Examples: GPTBot, ClaudeBot, Google-Extended, Meta-ExternalAgent. **Default: on.** Letting your catalog inform model updates is generally a good thing for long-tail product recommendations.
- **Test/validation crawlers** — for protocol-compliance testing. Example: UCPPlayground. **Default: off.** Turn these on only if you're actively debugging your store with a UCP test tool.

Uncheck a crawler to add a `Disallow:` directive for it in `robots.txt`. This is a cooperative protocol — well-behaved crawlers honor it; malicious bots ignore robots.txt entirely (but they don't make purchase recommendations either, so blocking them is moot).

### Set your rate limit

The **Rate limit** slider controls how many requests per minute the WooCommerce Store API will accept from each AI crawler before serving a 429 response.

- **Default: 25 requests per minute per crawler.** This is a balanced setting suitable for catalogs of any size.
- Lower it (down to 1 RPM) if your hosting plan is small and you've seen Store API spikes.
- Raise it (up to 1000 RPM) if you have a large catalog (10,000+ products) and notice agents pagination-stalling.

Rate limiting applies only to AI crawlers — it's keyed on the crawler's `User-Agent`. Regular customers, your storefront pages, and your admin REST traffic are completely unaffected.

### When to revisit these settings

- **Quarterly.** New AI crawlers come online; check whether the list has grown and whether you want them included. Plugin updates sync the canonical list — stale opt-outs stay opt-out.
- **After traffic spikes.** If your hosting provider flags AI crawler traffic as expensive, lower the rate limit before you remove crawlers entirely. Most spikes are first-time discovery; rates settle within a week.

---

## 7. Set your return policy

AI agents that surface your products often try to display the return window inline ("Free returns for 30 days"). The **Policies** tab lets you publish a structured return policy that agents can read directly.

![Policies tab with return policy options](screenshots/07-policies-tab.png)

You have three modes:

- **Not configured** *(default)*. No return policy is published in JSON-LD. Use this if your policy is complex enough that you'd rather have agents link customers to your dedicated returns page than try to summarize it.
- **Returns accepted**. Specify a return window in days, who pays return fees, and which return methods (in-store, mail-in) you accept. AI Storefront emits this as Schema.org `MerchantReturnPolicy` markup on your product pages.
- **Final sale**. Declares no returns are accepted. Same Schema.org markup, with the appropriate flag.

You can also link to your existing returns or refunds page from the dropdown — useful when your policy already lives on a customer-facing page and you just want agents pointed at the canonical source.

### Per-product overrides

Some merchants have a generally returnable catalog with specific final-sale items (clearance, custom orders, perishables). For those, AI Storefront adds an **AI: Final sale** checkbox to each product's edit screen, in the Inventory tab.

![Product edit screen showing the AI: Final sale checkbox](screenshots/08-product-final-sale.png)

Checking it overrides the store-wide policy for that single product. Agents reading your product's JSON-LD will see the final-sale signal and reflect it in their output.

---

## 8. Verify your discovery endpoints

Before you tell anyone your store is AI-ready, take 30 seconds to confirm the endpoints are live.

### What to verify

| URL | What you should see |
|-----|---------------------|
| `https://your-store.com/llms.txt` | A plain-text Markdown document starting with `# Your Store Name`. Includes category list and a "How AI agents should link to products" section. |
| `https://your-store.com/.well-known/ucp` | A JSON document. Pretty-printed, several hundred lines. Top-level keys: `name`, `version`, `capabilities`, `payment_handlers`, `services`. |
| `https://your-store.com/robots.txt` | Standard WordPress `robots.txt` plus a section near the top with `User-agent: GPTBot`, `User-agent: ChatGPT-User`, etc., each followed by `Allow:` lines. |
| Any product page → "View page source" | Search for `"@type":"Product"`. You should see a `BuyAction` block, an `offers` array with prices, and (if you configured one in section 7) a `hasMerchantReturnPolicy` block. |

### How to check from the admin

The Discovery tab includes a **"Discovery endpoints"** card with direct links and a small green dot if each endpoint is reachable.

![Discovery tab with endpoints reachability indicators](screenshots/09-endpoints-info.png)

Click any URL in the card to open it in a new tab. If something returns 404 or shows your homepage instead of the expected content, see [Troubleshooting](#11-troubleshooting).

### Test with an actual AI agent

Once endpoints check out, try this with one of the major AI assistants that has live web browsing:

> *"Find products at \[your-store.com\] that match \[some attribute, e.g. 'red running shoes under $100'\]."*

A correctly configured AI Storefront will let the agent return real product names with prices and links back to your store, generally within 3–10 seconds. If the agent says "I couldn't find products matching that on the site," wait a few hours (most agents cache crawl results) and retry. Some agents won't crawl a brand-new endpoint until their next scheduled re-index — which can be up to several days for some providers.

---

## 9. Read attribution stats

When a shopper completes a purchase after clicking a link from an AI agent, WooCommerce's standard Order Attribution captures three pieces of information:

- The agent's name (e.g. `chatgpt`, `gemini`, `perplexity`) — stored as `utm_source`.
- The medium — always `ai_agent` for AI-referred orders.
- A session correlation ID — useful for debugging but not personally identifying.

### Where to see attribution

Two places:

**The WooCommerce Orders list.** Open **WooCommerce → Orders**. The built-in **Origin** column shows the agent's hostname (`Source: Chatgpt.com`, `Source: Gemini.google.com`, `Source: Ucpplayground.com`, etc.) for AI-referred orders. Non-AI orders show `Direct`, `Unknown`, or the referring source as usual. This is WooCommerce core behavior; AI Storefront just feeds the right values into it.

![WooCommerce Orders list with the Origin column populated](screenshots/10-orders-origin.png)

**The Overview tab.** The "AI orders" and "AI revenue" stat cards show aggregates for the selected period. The **Top agent** and **Top agent share** cards highlight which agent is responsible for the most volume and what share of AI revenue it represents.

![Overview tab with per-agent stat cards](screenshots/11-per-agent-stats.png)

### Recent AI Orders table

Below the stat cards, the Overview tab includes a **Recent AI Orders** table — the most recent AI-attributed orders with order number, date, status, agent, and total. Clicking the order number opens the order edit screen in WooCommerce. The table includes search and column filtering controls.

![Overview tab with the Recent AI Orders table](screenshots/12-recent-ai-orders.png)

### What attribution doesn't capture

AI Storefront does not record:

- The shopper's identity beyond what WooCommerce already captures (name, email at checkout — same as any non-AI order).
- The shopper's conversation with the AI agent.
- Cross-device or cross-session journey data.

If you need more granular journey attribution (touchpoints, multi-session paths), pair this plugin with a dedicated analytics tool that reads WooCommerce's order attribution meta — that's the supported integration point.

---

## 10. Day two: maintenance and monitoring

Once you've enabled and configured AI Storefront, day-to-day maintenance is minimal. Here's what to check periodically.

### Weekly (5 minutes)

- Glance at the Overview tab. Are AI orders growing? Stable? Trending down? A sudden drop usually means an AI agent revised its crawl policy or your robots.txt accidentally changed.
- Check the per-agent breakdown. If one agent dominates, that's a signal to dig into how that agent surfaces your products (in your spare time).

### Monthly (10 minutes)

- Re-verify the four endpoint URLs from [section 8](#8-verify-your-discovery-endpoints). Hosting changes, CDN configurations, and security plugins can sometimes break virtual URLs.
- Review your product visibility scope. Did you add a new product line that should be exposed? An old line that should be excluded?
- If using **By category** mode, check that newly created categories are picked up correctly.

### After major store changes

Re-verify endpoints after:

- A WordPress core update.
- A WooCommerce major version update.
- Switching themes (some themes inject their own `robots.txt` or interfere with rewrite rules).
- Installing or updating a security plugin (some block `/.well-known/` paths by default; allow-list `/.well-known/ucp` if so).
- Migrating to a new host.

### Plugin updates

AI Storefront ships frequent updates while the protocol is evolving. Each release includes a CHANGELOG entry — review it before updating in production. Updates are backwards-compatible within a major version (0.x.y); a major version bump (e.g. 0.x → 1.0) will be called out explicitly with migration notes.

---

## 11. Troubleshooting

### `/llms.txt` returns 404

**Most likely cause:** WordPress permalinks need flushing. The plugin attempts this automatically on activation, but some hosts cache rewrite rules.

**Fix:**
1. Go to **Settings → Permalinks**.
2. Without changing anything, click **Save Changes**.
3. Reload `https://your-store.com/llms.txt`.

If still 404, check that AI Storefront is actually enabled (Overview tab toggle). The endpoint returns 404 by design when the plugin is paused.

### `/.well-known/ucp` returns 404 but `/llms.txt` works

**Most likely cause:** Your security or "hardening" plugin blocks all `/.well-known/` requests. This is a common over-aggressive default.

**Fix:** In your security plugin, allow-list these specific paths: `/.well-known/ucp`. Some plugins call this an "exception", "allow rule", or "whitelist".

### JSON-LD doesn't include the BuyAction on product pages

**Most likely cause:** A theme or page-builder is wrapping products in a way that overrides WooCommerce's `wp_head` hooks.

**Fix:**
1. Switch to a default theme like Storefront temporarily and re-check.
2. If the BuyAction now shows up, your theme is the culprit. Contact the theme developer or pick a theme that respects WooCommerce's structured-data hooks.

### AI agents say they can't find my store

**Most likely causes:**
- Your store has been live for less than 24 hours and AI crawlers haven't completed first discovery.
- Your robots.txt blocks the agent's user-agent (check the Discovery tab).
- Your `WordPress Address (URL)` in **Settings → General** doesn't match the public hostname AI agents see (e.g. you have `www` vs. non-`www` mismatches).

**Fix:**
1. Wait 24–48 hours after enabling. Most agents discover within that window.
2. Review the Discovery tab and ensure the relevant agent is checked.
3. Confirm your site URLs match.
4. Test directly: visit `https://your-store.com/llms.txt` from a fresh browser session (no cache). Endpoints work for you = endpoints work for the agent.

### Orders show up as AI-attributed when they shouldn't

**Most likely cause:** A team member tested with a real AI assistant and clicked through to your store before checking out themselves.

**Fix:** Nothing. The attribution is correct — that order was, technically, AI-referred. Use staff discounts or a dedicated test account for testing flows.

### Stats are zero after a week

**Most likely cause:** Your products are too new to be indexed; or your visibility settings exclude everything; or your robots.txt blocks every agent.

**Fix:**
1. Confirm "Products exposed" is greater than zero on the Overview tab.
2. Confirm at least one of the live-browsing crawlers is checked on the Discovery tab.
3. Verify endpoints from [section 8](#8-verify-your-discovery-endpoints).
4. Wait an additional week. Discovery is asynchronous; consumer-facing AI traffic is the lagging signal.

### I disabled the plugin and now `robots.txt` looks weird

When AI Storefront is disabled, it removes its own `Allow:` and `Disallow:` blocks from `robots.txt`, but WordPress's own additions remain. If `robots.txt` looks empty or only shows `User-agent: *` followed by `Disallow: /wp-admin/`, that's correct — that's the WordPress default with no plugins active.

---

## 12. Glossary

**Attribution.** The process of recording which AI agent referred a sale. WooCommerce stores attribution as order meta (`utm_source`, `utm_medium`).

**JSON-LD.** A way to embed structured data in HTML. Search engines and AI agents read it to understand what's on a page.

**llms.txt.** An emerging convention for "an AI-readable site overview." Plain Markdown, served at the site root.

**Order Attribution.** A WooCommerce 8.5+ feature that captures `utm_*` parameters into order meta and displays the source in the Orders list. AI Storefront feeds AI-specific values into it.

**Rate limit.** A maximum number of requests per minute the Store API will accept from a given AI crawler. Returns HTTP 429 when exceeded; well-behaved crawlers back off.

**robots.txt.** A standard text file at the site root that tells crawlers which paths they may fetch. Cooperative — well-behaved crawlers respect it, malicious ones don't.

**Schema.org.** A vocabulary for structured data. AI Storefront uses the `Product`, `BuyAction`, and `MerchantReturnPolicy` types.

**Store API.** WooCommerce's public REST API for headless storefronts and external clients (`/wp-json/wc/store/v1/`). AI Storefront's UCP REST adapter dispatches to it internally.

**UCP.** Universal Commerce Protocol. An open spec for how AI agents discover and transact against e-commerce stores. AI Storefront implements the discovery and redirect-to-checkout subset.

**UCP REST adapter.** A set of UCP-shaped REST endpoints at `/wp-json/wc/ucp/v1/` that translate the WooCommerce Store API into UCP responses agents can consume directly.

**utm_source / utm_medium.** Standard URL parameters used by analytics tools to identify traffic sources. AI Storefront sets `utm_medium=ai_agent` and `utm_source=<agent-name>` on AI-referred clicks.

---

## 13. Where to get help

- **Documentation index:** [`docs/README.md`](../README.md)
- **Engineering documentation** (for developers extending or debugging the plugin): [`docs/engineering/`](../engineering/)
- **Bug reports & feature requests:** open an issue at the project's GitHub repository.
- **Security issues:** see [`SECURITY.md`](../../SECURITY.md). Do **not** open a public issue for security reports.
- **General WooCommerce support:** [woocommerce.com/support](https://woocommerce.com/support/) — for questions about checkout, payments, taxes, shipping, or anything else WooCommerce-core-shaped.

---

*This guide covers AI Storefront 0.6.x. Screenshots are taken from a stock WordPress 6.7 + WooCommerce 9.9 install with the Twenty Twenty-Five theme. Your store may look slightly different depending on theme and admin color scheme.*
