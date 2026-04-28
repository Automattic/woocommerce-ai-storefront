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
- **`payment_handlers: {}`** — empty object. This is the explicit signal for "web-redirect only; no in-chat / delegated payments." If a future store wanted to opt into delegated payments, this object would carry handler entries.

Code: [`includes/ai-storefront/class-wc-ai-storefront-ucp.php`](../../includes/ai-storefront/class-wc-ai-storefront-ucp.php)

```php
'capabilities' => [
    'dev.ucp.shopping.catalog.search' => [ ... ],
    'dev.ucp.shopping.catalog.lookup' => [ ... ],
    'dev.ucp.shopping.checkout'       => [ ... ],   // line 284
],
'payment_handlers' => (object) [],                  // line 346
```

The same manifest carries a `config.store_context` block (currency, `prices_include_tax`, `shipping_enabled`, country, locale) that agents use to decide whether *they* can quote prices and shipping in the user's context. See `build_store_context()` (line 404).

## Layer 2 — Catalog filtering

When the agent calls `dev.ucp.shopping.catalog.search` or `.lookup`, it only sees products the merchant has chosen to syndicate. Filtering happens at the Store API layer in `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-store-api-filter.php`. Three syndication modes — *all*, *by taxonomy*, or *individually selected* — are enforced as a UNION.

If a product isn't returned, or `store_context.currency` doesn't match what the agent can quote, no buy CTA. Agents are expected to drop products they can't transact.

## Layer 3 — Checkout session (the real green light)

Per-cart eligibility is decided by `POST /wp-json/wc/ucp/v1/checkout-sessions`. The response uses a four-value status enum, but only one combination tells the agent to render a buy button:

| Response | Meaning | Agent action |
|---|---|---|
| `status: "requires_escalation"` + `continue_url` | Cart is valid; redirect needed to finish | **Render buy button → link to `continue_url`** |
| `status: "incomplete"` (no `continue_url`) | Cart problem (out of stock, minimum not met, invalid coupon, …) | Hide the button; surface `messages[]` |
| `status: "ready_for_complete"` | (delegated path, not used by this plugin) | n/a |
| `status: "complete_in_progress"` | (delegated path) | n/a |

Code: [`includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php`](../../includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php)

```php
$continue_url = $should_redirect
    ? self::build_continue_url( $processed, $agent_name )   // line 1140
    : '';

'status' => $should_redirect ? 'requires_escalation' : 'incomplete',  // line 1220

if ( '' !== $continue_url ) {
    $response_body['continue_url'] = $continue_url;          // line 1245
}
```

The `continue_url` is a WooCommerce Shareable Checkout link with `utm_source={agent_name}&utm_medium=ai_agent&ai_session_id={session}` baked in, so attribution lands in WooCommerce's standard Order Attribution column when the customer completes checkout on the merchant's site.

## Belt-and-suspenders surfaces

For agents that don't speak UCP — typical SEO-style crawlers — the plugin still ships purchasability signals:

- **Enhanced JSON-LD** on product pages with `potentialAction` of type `BuyAction` (`includes/ai-storefront/class-wc-ai-storefront-jsonld.php:45`). The action's `urlTemplate` carries the same UTM placeholders.
- **`/llms.txt`** lists the syndicated catalog and points at the UCP manifest and Store API.
- **`robots.txt`** allows the named AI crawlers (GPTBot, ChatGPT-User, OAI-SearchBot, Google-Extended, Gemini, PerplexityBot, Perplexity-User, ClaudeBot, Claude-User, Meta-ExternalAgent, Amazonbot, Applebot-Extended).

These surfaces help discovery; they do not by themselves authorize a buy CTA inside an AI chat. The manifest + checkout-session response are the gate.

## Why a pull model works for "buy button" decisions

Push protocols (e.g. Stripe ACP, Google UCP delegated payments) require the merchant to register with each AI provider, hand over an API key, and accept platform fees on every purchase. The pull model trades that for cooperative web standards: the merchant publishes machine-readable signals, well-behaved agents read them, and checkout always lands on the merchant's domain. The agent's "should I show Buy?" question is answered entirely from public, cacheable HTTP responses — no bilateral integration required.
