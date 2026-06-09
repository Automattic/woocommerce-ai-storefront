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

The same filter also rewrites the `search` query so natural-language phrases match products via the store's own taxonomies, not just literal title substrings. Each signal term in the query is resolved against `product_cat` / `product_tag` / `product_brand` / `pa_*` (with morphological variant matching: hoodies↔hoodie, watches↔watch, accessories↔accessory) and emits an EXISTS subquery; words that don't match any taxonomy fall back to a title LIKE expanded to both plural and singular forms. Per-term clauses combine OR (taxonomy hit OR title hit), and per-query clauses combine AND, so a product must satisfy all signal words but each can be satisfied via either route. The merchant's syndication scope is unchanged — search runs *inside* it.

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

The accompanying `messages[]` includes a `buyer_handoff_required` entry with `type: info` so AI assistants render the redirect informationally, not as an error. Per UCP `message_info.json` (release/2026-04-08), info messages have no `severity` field — only `type: error` carries severity. Agents that map `type: error` to red error styling will style the redirect correctly with this shape.

When the cart is otherwise valid (in stock, meets minimum, IDs resolve), the `continue_url` shape depends on the cart contents. The plugin emits one of several outcomes; the rows that produce a `continue_url` carry the canonical UTM payload (`utm_source`, `utm_medium=referral`, `utm_id=woo_ucp`, plus optional `ai_agent_host_raw` when the agent was identifiable) — `with_woo_ucp_utm()` runs on every URL shape, including the configurable bundle/grouped/variable permalink path. The `ai_session_id` query param is *agent-supplied* — agents append their own `ai_session_id=chk_…` if they want it captured into order meta; the plugin's server-side stamping helper does not append it.

**Multi-currency stamping (0.17.0+).** When the request carries `context.currency` and the value is a member of `store_context.accepted_currencies` (advertised on the manifest), `?currency=XXX` is stamped onto the `continue_url` ahead of the UTM block. Every row in the table below picks this up — Shareable Checkout, deterministic bundle, deterministic grouped, and the configurable bundle/grouped/variable PDP permalink fallback all flow through the same stamping helper. Example shape: `/checkout-link/?currency=EUR&products=42:1&utm_source=chatgpt.com&utm_medium=referral&utm_id=woo_ucp`. WooPayments' page-level currency switcher honors the param on the destination page so the buyer sees the requested currency at checkout. When `context.currency` is absent, malformed, or outside the accepted set, the URL is unchanged.

URL-shape table:

| Cart contents | Response | `continue_url` |
|---|---|---|
| Simple / variation / `subscription` / `subscription_variation` line items only | `requires_escalation` | WooCommerce Shareable Checkout: `/checkout-link/?products=ID:QTY,…` |
| Single deterministic bundle (all bundled items resolvable from author defaults) | `requires_escalation` | `/checkout/?add-to-cart=BUNDLE&quantity=<N>&bundle_quantity_<bid>=…&bundle_attribute_<attr>_<bid>=…` (PR #360) |
| Single deterministic grouped (all children `type=simple` and in stock) | `requires_escalation` | `/checkout/?add-to-cart=PARENT&quantity[CHILD]=N&…` (PR #362) |
| Single configurable bundle/grouped, **with** PDP permalink | `requires_escalation` | the bundle/grouped product permalink (buyer completes configuration on the merchant PDP); `field_required` + `severity: requires_buyer_input` accompanies |
| Single configurable bundle/grouped, **without** PDP permalink | `incomplete` | none — `field_required` + `severity: recoverable` (merchant misconfig) |
| Single `variable` or `variable-subscription` parent ID (no variation chosen), **with** PDP permalink | `requires_escalation` | the variable parent's permalink (buyer picks a variation on the PDP, where the merchant's `_default_attributes` pre-fills the dropdown); `field_required` + `severity: requires_buyer_input` accompanies (PR #370) |
| Single `variable` or `variable-subscription` parent ID, **without** PDP permalink | `incomplete` | none — `field_required` + `severity: recoverable` (merchant misconfig) |

The deterministic-bundle row's top-level `quantity=<N>` is the agent's bundle line-item quantity (the number of fully-configured bundles to add to cart); WC multiplies the per-bundled-item `bundle_quantity_<bid>` values by N server-side. Grouped has no parent inventory, so its `quantity[CHILD]=N` per-child entries are absolute (the controller multiplies the agent's `quantity` by each child's default at URL-construction time).

**The variable-parent permalink fallback does NOT auto-resolve `_default_attributes` into a specific variation URL** (PR #370). Doing so would let agents bypass presenting choice to the buyer — they'd send the parent ID and get a deterministic checkout URL for the merchant's default variation without ever showing alternatives. The buyer's choice is preserved by routing to the PDP instead, where WC core's default-attribute behavior pre-fills the dropdown but the buyer retains the final decision. The catalog response's `match: featured` marker (see [API-REFERENCE.md](API-REFERENCE.md)) surfaces the merchant's hint at lookup time — what the agent does with it (present, override, ignore) is up to the agent's UI.

Mixed/multi bundle, grouped, **or variable-parent** carts produce `status: incomplete` with **one `field_required` error per offending container line item** (JSONPath-attributed to `$.line_items[N]`, `severity: recoverable`) and **no `continue_url`** — agents must split the cart into per-line `/checkout-sessions` requests. The variable-parent must-split rule shares the same rationale as bundle/grouped: the permalink fallback can only redirect to one PDP, which cannot also add a sibling simple-product line item to the cart.

The table above is specifically about *continue_url routing when a redirect is possible*. Other validation outcomes split into two categories:

- **Per-line-item failures** (`out_of_stock`, `item_unpurchasable`, unknown product IDs, malformed ID grammar, etc. — `severity: unrecoverable` per `checkout_error_message()` default) drop the failing line from `line_items` but don't necessarily block the redirect — when at least one line survives, the response is still `201 requires_escalation` + `continue_url` covering the survivors, with the failures surfaced as `messages[].code` entries. Only when *no* line survives does the response fall back to `incomplete`.
    - **`item_unpurchasable`** (#373) — distinct from `out_of_stock`. Fires when WC reports `is_purchasable: false` (typically missing a price, draft / catalog-hidden, or merchant-misconfigured). Catalog responses already filter unpurchasable variations upstream in `fetch_variations_for()` so the broken ID never enters the agent's variant set; this code defends against stale or guessed variant IDs arriving at the checkout endpoint. Same line-drop routing as `out_of_stock`.
- **Cart-level failures** (`minimum_not_met` with `severity: requires_buyer_input`; `field_required` on mixed/multi container carts with `severity: recoverable`; malformed top-level shape with `400 invalid_input`) block the redirect outright: `status: incomplete`, no `continue_url`.

See [`API-REFERENCE.md`](API-REFERENCE.md) for the full error-code catalog and partial-validation semantics.

WC Order Attribution captures `utm_source` / `utm_medium` natively. The plugin's STRICT recognition gate matches on `utm_id=woo_ucp` (the "we routed this" flag), so attribution lands regardless of which `utm_source` value the agent declares.

## Belt-and-suspenders surfaces

For agents that don't speak UCP — typical SEO-style crawlers — the plugin still ships purchasability signals:

- **`<link rel="ucp-agent">`** injected in every page `<head>` pointing at `/.well-known/ucp`. Caught by head-scraping agents (Perplexity, Bing, etc.) that read `<head>` before loading the DOM and may never reach llms.txt. Only emitted when AI Storefront is enabled.
- **`<link rel="search">`** injected alongside it in every page `<head>`, pointing at the `/opensearch.xml` OpenSearch descriptor — a machine-readable pointer to both the HTML product-search page and the `GET /catalog/search` REST endpoint, for agents that scan `<head>` for a search interface.
- **Global `WebSite` + `SearchAction` JSON-LD** on every page, advertising the same two search entry points (native search + `GET /catalog/search`). This is the `SearchAction` shape Google already exercises for sitelinks search boxes, now also pointing agents at the catalog API.
- **Enhanced JSON-LD** on product pages with `potentialAction` of type `BuyAction`. The `urlTemplate` carries the same UTM placeholders. Archive pages (shop/category/tag/product-search) additionally emit an `ItemList` of product stubs so an agent can read a results page without following each product URL.
- **Public GET catalog endpoints** — `GET /catalog/search` and `GET /catalog/lookup` give fetch-based agents that can't POST a JSON body a way to query the catalog directly with query-string params. Same gate, same response envelope as the POST routes.
- **`/llms.txt`** publishes store identity, a top-categories sample, shipping/returns policy, browse/search URLs (with `utm_id=woo_llms` for channel attribution), and pointers at the UCP manifest, REST base, and checkout-sessions endpoint.
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
