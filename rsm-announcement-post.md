> **Draft prep:** screenshots below need a fresh capture from the current build before publishing.

---

# WooCommerce AI Storefront: making every Woo store AI-shoppable by default

**Solo:** Piero Rocca (Engineering + Product), pairing with Claude Code

**TL;DR.** AI assistants are becoming a primary product-search channel. **The job a Woo merchant brings to this**: win the AI-driven sale without surrendering first-party data, brand experience, or the long-term customer relationship. That's exactly what this plugin is built to do. End-to-end loop works. **Open for testing, refinement, and hardening.**

---

## The merchant's job to be done

> When consumers use AI assistants to search for products, I want my catalog to be perfectly structured and compliant with agentic standards for seamless discovery, while dictating that the final transaction routes to my own environment, so I can win the AI-driven sale without surrendering my first-party data, brand experience, or long-term customer relationship to the AI platform.

The job has three layers: **discovery, transaction routing, merchant ownership**. The rest of this post takes them one at a time.

1. **Discoverable, in a way the AI can use.** A structured catalog and agentic-standards compliance (manifest, structured product data on every product page, search and lookup APIs), so any AI assistant that adopts the open spec can read the catalog reliably, without per-platform feed work or aggregator middlemen.

2. **The transaction routes back to the merchant.** Discovery in chat; checkout on the merchant's domain. The signal section below is what happens when this layer breaks: when the AI tries to do the transaction itself.

3. **First-party data, brand, and customer relationship stay merchant-owned.** No AI-platform fees, no delegated payments, no normalized aggregator card. AI-referred orders flow through the same WooCommerce Order Attribution and surface in the same Orders list. The merchant builds a relationship the platform doesn't own.

The signal, the protocol-landscape comparison, and the plugin's implementation all answer to those three.

## The signal: what happens when transaction routing breaks

In the last month:

- **OpenAI walked back Instant Checkout.** Their official statement: they're "allowing merchants to use their own checkout experiences while we focus our efforts on product discovery." Adoption stayed below 8% across the five-month trial. ([CNBC](https://www.cnbc.com/2026/03/24/openai-revamps-shopping-experience-in-chatgpt-after-instant-checkout.html))
- **Walmart published the conversion gap.** In-chat purchases converted at **one-third the rate** of click-outs to walmart.com. Walmart is pulling out of OpenAI's checkout integration and embedding their own assistant into ChatGPT and Gemini. Agent does discovery, merchant retains checkout. ([Search Engine Land](https://searchengineland.com/walmart-chatgpt-checkout-converted-worse-472071))
- **AI-referred shopping traffic converts well, but only when checkout stays with the merchant.** Adobe Analytics on Black Friday 2025: AI-referred US retail traffic up **805% YoY**; AI-referred shoppers were **38% more likely to convert** than traditional-channel shoppers.

The picture is consistent: AI is good at matching shoppers to the right merchant; merchants are good at closing the sale on their own site, with their own UX, saved cards, and tax/shipping logic. Conversion stays at the merchant's baseline; the agent stays focused on what it's good at. **Layer 2 of the merchant's job is the breakable one. The industry just confirmed it can't be broken without paying.**

That's the architecture this plugin enables. ~30%+ of all e-commerce sites run on WooCommerce. One plugin makes every Woo store AI-discoverable on day one of any new agent's launch, without the merchant lifting a finger.

## Protocol landscape: Who lets the merchant keep what

Three agentic-commerce protocols competed for the merchant surface; in the last six months they started converging.

| | **UCP** (Google + Shopify) | **ACP** (OpenAI + Stripe) | **APP** (Klarna) |
|---|---|---|---|
| **Governance** | Multi-vendor open spec | OpenAI + Stripe co-maintained | Klarna-controlled; now feeds UCP |
| **Catalog** | Live store via `/.well-known/ucp` manifest | Feed shared with OpenAI | Feed shared with Klarna; aggregated and normalized |
| **Checkout** | In-chat, embedded, or redirect to merchant | In-chat or merchant redirect | Klarna, or via Stripe Shared Payment Tokens |
| **Brand experience** | Merchant brand surfaces directly | ChatGPT renders product cards from feed | Normalized into cross-merchant comparison rows |
| **Customer data** | Stays with merchant | OpenAI captures conversation | Klarna captures discovery |
| **Reach** | Any UCP-compliant agent | ChatGPT only | Klarna-integrated agents |

**UCP** is the only protocol that honors all three layers, and the space is consolidating around it. Klarna joined in February 2026 and APP feeds into it; Stripe and Shopify back UCP; even ACP walked back to merchant-redirect in March 2026 after Walmart's conversion data made the case undeniable. Merchant-redirect is one of UCP's three checkout postures (alongside in-chat and embedded checkout) and the one this plugin implements. ACP and APP are still worth running as complements for the reach they bring (ChatGPT discovery, Klarna network), but each trades part of layer 3 for that reach: OpenAI captures the conversation in ACP; Klarna normalizes the catalog into cross-merchant comparison rows in APP.

## What this plugin builds

This plugin implements UCP's redirect-to-merchant posture. UCP's other two checkout postures (in-chat and embedded) leak buyer data to the AI agent and don't fit the merchant-centric JTBD this plugin is built around; both are out of scope. What a Woo merchant gets:

- **One setup, every AI assistant.** Your catalog becomes visible to ChatGPT, Gemini, Claude, Perplexity, and Copilot, with no per-platform work when new agents launch.
- **Checkout stays on the store.** No AI-platform checkout fees. No delegated payments. You keep the customer, the checkout, and the data.
- **See which AI drove each sale.** Every AI-referred order is tagged with its source agent and revenue, surfaced in the plugin's Overview tab and in WooCommerce's built-in Origin column.

The full loop works end-to-end:

> **An AI agent finds the store → searches the catalog → looks up a product → gets a checkout link back to the merchant → the shopper completes checkout in the merchant's own flow → the order is attributed back to the agent that drove it.**

A merchant installs, activates, picks which catalog slices to expose, and that's it.

The plugin's settings live across four tabs (Overview / Product Visibility / Policies / Discovery), and the agent-facing surface (manifest, structured product data, search and lookup APIs, checkout-session redirect) is in place and working.

![Overview tab: AI traffic broken down by 24h / 7d / 30d / year, top agent canonically named.](./prototypes/overview-tab.png)

![Discovery tab: AI Crawlers list grouped by sub-category, endpoint reachability checker, rate-limit preset selector.](./prototypes/discovery-tab.png)

![Policies tab: return-policy modes with live preview of what AI agents will read.](./prototypes/policies-tab.png)

![Product Visibility tab: choose which catalog slices to expose to AI agents (all products, selected products, or by category/tag/brand).](./prototypes/product-visibility-tab.png)

![Structured product data on a product page (JSON-LD format): BuyAction with UCP attribution placeholders, return policy, inventory, brand. What AI agents read when they hit a single-product page.](./prototypes/jsonld-inspector.png)

## Why pull, when push exists

Google Merchant Center and ChatGPT's product feed are platform-owned commercial surfaces, not neutral data exchanges. Google Merchant Center underpins Shopping Ads: visibility costs ad spend, ranking is platform-controlled, the merchant's catalog feeds the platform's network. ChatGPT's product feed is heading the same direction. OpenAI controls placement and will eventually monetize it.

Both are real-traffic channels worth using. But depending on them exclusively means renting discoverability from for-profit gatekeepers who set the rules.

UCP-compliant pull is the third surface: open spec, merchant-owned, no rent. The right strategy is all three.

| | Push (feeds) | Pull (UCP) |
|---|---|---|
| **Owner** | Platform (Google, OpenAI) | Merchant |
| **Visibility model** | Pay-to-rank or platform algorithm | Open access for any compliant agent |
| **Data freshness** | 24–72h stale | Live |
| **Onboarding** | Per-platform feed setup | Plugin install + activate |

Push gets the merchant in front of the audience already on Google and ChatGPT. Pull gets them in front of every other agent that comes after, without writing a check or fitting into someone else's ranking model.

## How to test

Live AI agents on a public production store give the most realistic signal but need a publicly-reachable manifest. [UCPPlayground](https://ucpplayground.com), an open-source UI-based UCP test harness, is the convenient option for iteration. It hits the manifest the way a real agent would but doesn't need live-deployment access, so it works against local or staging stores. Several rounds through UCPPlayground caught real bugs in this plugin and a few in UCPPlayground itself, which we contributed back. Both sides improve; that's the upside of building against an open spec with an open-source reference client.

## Plan

Aiming to land the following by May 22:

**Delivery:**

- A8C-standards security audit and performance review
- Issues surfaced by alpha testing addressed
- Plugin ready for broader merchant distribution

**Alpha test:**

- 5-10 friendly-merchant stores onboarded, manifest reachable, AI-discoverable
- Production logs capturing 3+ distinct UCP-Agent hostnames (the lower bound that proves real agents are pulling, not just theoretically able to)
- 1 real AI-attributed order captured by Order Attribution (proves the full loop end-to-end with a real agent, not synthetic)
- Feedback loop in place via P2 thread + GitHub issues

The bottleneck: friendly-merchant recruitment. If 5-10 stores don't materialize, the production-traffic and attributed-order numbers become synthetic and the alpha becomes a closed-loop validation rather than open-merchant validation.

## Solo, for now

This started as a side project alongside several others, and by RSM kickoff the design and core were already well underway. Solo made sense for the prototype phase. The next phase doesn't: refinement, hardening, testing and quality, and market validation all benefit from collaborators. If you want to pair up on any of those, the asks below get specific.

## How you can help

The plugin is open for review. Specific asks:

1. **Performance review.** AI crawlers behave differently from browsers: bursty, unauthenticated, deep catalog scans. Worth a second set of eyes on the rate limiter, the search/lookup query shapes, and the per-product-page emission cost.
2. **Security review.** New public endpoints + a public manifest. Worth an A8C-standards security audit before broader rollout.
3. **Friendly-merchant candidates.** WooCommerce store owners (~50+ products, willing to expose a public manifest, willing to share AI-traffic logs for a month). Internal Automattician-owned stores are perfect. Drop a URL in the comments.
4. **Alpha/beta testing validation and go-to-market planning.** Pair-up help running the alpha cohort, capturing structured test feedback, and shaping how this plugin reaches merchants beyond the friendly-tester window.

Tags: #radical-speed-month #woo-ai-storefront #ai-commerce

Piero
