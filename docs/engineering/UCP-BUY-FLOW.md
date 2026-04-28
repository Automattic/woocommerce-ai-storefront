# How AI Chatbots Decide to Show a "Buy" Button

UCP is a **pull** protocol — the store doesn't push anything to AI chatbots. The chatbot fetches a few public surfaces and derives, in three layers, whether and when to render a purchase CTA.

## TL;DR

1. **Manifest** at `/.well-known/ucp` says the store sells via web redirect.
2. **Catalog** responses say which products the agent is allowed to surface.
3. **Checkout-session** response says whether *this specific cart* is ready for handoff. If yes, render the buy button and link it to the returned `continue_url`.

No signal in any one of those three layers? No buy button.

## Layer 1 — Manifest discovery

The agent fetches `/.well-known/ucp` and reads two things:

- **`capabilities['dev.ucp.shopping.checkout']`** — declares that checkout exists at all.
- **`payment_handlers: {}`** — empty object. The explicit signal for "web-redirect only; no in-chat / delegated payments." If a future store wanted to opt into delegated payments, this object would carry handler entries.

Code: [`includes/ai-storefront/class-wc-ai-storefront-ucp.php`](../../includes/ai-storefront/class-wc-ai-storefront-ucp.php)

```php
'capabilities' => [
    'dev.ucp.shopping.catalog.search' => [ ... ],
    'dev.ucp.shopping.catalog.lookup' => [ ... ],
    'dev.ucp.shopping.checkout'       => [ ... ],
],
'payment_handlers' => (object) [],
```

The same manifest carries a `config.store_context` block (currency, `prices_include_tax`, `shipping_enabled`, country, locale) that agents use to decide whether *they* can quote prices and shipping in the user's context.

## Layer 2 — Catalog filtering

When the agent calls `dev.ucp.shopping.catalog.search` or `.lookup`, it only sees products the merchant has chosen to syndicate. Filtering happens at the Store API layer in [`class-wc-ai-storefront-ucp-store-api-filter.php`](../../includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-store-api-filter.php). Three syndication modes — *all*, *by taxonomy*, or *specific products* — are enforced as a UNION.

If a product isn't returned, or `store_context.currency` doesn't match what the agent can quote, no buy CTA. Agents are expected to drop products they can't transact.

## Layer 3 — Checkout session (the real green light)

Per-cart eligibility is decided by `POST /wp-json/wc/ucp/v1/checkout-sessions`. The response uses a four-value status enum, but only one combination tells the agent to render a buy button:

| Response | Meaning | Agent action |
|---|---|---|
| `status: "requires_escalation"` + `continue_url` | Cart is valid; redirect needed to finish | **Render buy button → link to `continue_url`** |
| `status: "incomplete"` (no `continue_url`) | Cart problem (out of stock, minimum not met, invalid coupon, …) | Hide the button; surface `messages[]` |
| `status: "ready_for_complete"` | (delegated path, not used by this plugin) | n/a |
| `status: "complete_in_progress"` | (delegated path) | n/a |

Code: [`includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php`](../../includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php).

The accompanying `messages[]` includes a `buyer_handoff_required` entry with `type: info` + `severity: advisory` (post-PR #119) so AI assistants render the redirect informationally, not as an error. Agents that map `type: error` to red error styling will style the redirect correctly with the new shape.

The `continue_url` is a WooCommerce Shareable Checkout link with the canonical 0.5.0+ UTM payload baked in:

```
?utm_source=<agent hostname>&utm_medium=referral&utm_id=woo_ucp&ai_agent_host_raw=<raw host>&ai_session_id=<chk_…>
```

WC Order Attribution captures `utm_source` / `utm_medium` natively. The plugin's STRICT recognition gate matches on `utm_id=woo_ucp` (the "we routed this" flag), so attribution lands regardless of which `utm_source` value the agent declares.

## Belt-and-suspenders surfaces

For agents that don't speak UCP — typical SEO-style crawlers — the plugin still ships purchasability signals:

- **Enhanced JSON-LD** on product pages with `potentialAction` of type `BuyAction`. The `urlTemplate` carries the same UTM placeholders.
- **`/llms.txt`** lists the syndicated catalog and points at the UCP manifest and Store API.
- **`robots.txt`** allows the named AI crawlers.
- **Bare product URLs** in `/catalog/search` and `/catalog/lookup` responses also carry the canonical UTM payload (post-PR #116), so buyers who follow the bare product link from chat — rather than going through `/checkout-sessions` — still attribute correctly.

These surfaces help discovery; they don't by themselves authorize a buy CTA inside an AI chat. The manifest + checkout-session response are the gate.

## Why a pull model works for buy-button decisions

Push protocols (Stripe ACP, Google UCP delegated payments) require the merchant to register with each AI provider, hand over an API key, and accept platform fees on every purchase. The pull model trades that for cooperative web standards: the merchant publishes machine-readable signals, well-behaved agents read them, checkout always lands on the merchant's domain. The agent's "should I show Buy?" question is answered entirely from public, cacheable HTTP responses — no bilateral integration required.

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — overall component layout and design rationale
- [`API-REFERENCE.md`](API-REFERENCE.md) — UCP REST endpoint shapes and response examples
- [`DATA-MODEL.md`](DATA-MODEL.md) — UTM wire shape and the order-meta this attribution writes
- [`HOOKS.md`](HOOKS.md) — filters that intercept manifest, llms.txt, and JSON-LD output
- [`../user-guide/USER-GUIDE.md`](../user-guide/USER-GUIDE.md) — merchant-facing context for the same signals
