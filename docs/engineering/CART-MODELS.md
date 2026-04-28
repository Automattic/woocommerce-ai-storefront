# Cart and Checkout Models

How a shopping conversation results in a purchase. Four models, three of them shipping today, one as a roadmap option. All four preserve **merchant ownership of checkout** — the buyer always lands on the merchant's domain to complete payment.

## Why this doc exists

UCP defines two distinct capabilities relevant to "build a cart, then check out":

- `dev.ucp.shopping.checkout` — payment lifecycle, status enum, redirect or delegated completion.
- `dev.ucp.cart` — lightweight CRUD over a basket. No payment, no status lifecycle.

This plugin currently implements `dev.ucp.shopping.checkout` only. We don't ship `dev.ucp.cart`, but the same end-user behavior (build a multi-item cart over multiple turns, check out together) is achievable today via three different flows. A fourth flow — the canonical UCP `dev.ucp.cart` capability — is documented here as a future option for when the trade-offs change.

The matrix at the bottom is the decision tool.

## Model 1 — Surface only

Agent calls `/catalog/search` or `/catalog/lookup`, gets product cards with bare permalinks (UTM-stamped). Buyer clicks a single product card → lands on merchant's product page → adds to cart on merchant site → checks out.

```
Agent → /catalog/search → product[] with .url
Buyer clicks product card → merchant /product/{slug}/?utm_source=...
Buyer adds to cart on merchant site → WC checkout
```

| Cart state lives in | Nothing on agent or merchant side. Each link is independent. |
| Per-item network cost | 0 |
| Discoverability | Standard UCP catalog endpoints; nothing extra needed. |
| Status | **Shipped.** |

**Best for:** single-product purchases, gift recommendations, "find me one X" lookups.

## Model 2 — Single-shot multi-item handoff

Agent maintains `items[]` in its conversation memory across turns. When the buyer commits, agent calls `POST /checkout-sessions` once with the full list, receives a `continue_url` that encodes the cart in URL params, renders one Buy button.

```
Agent maintains items[] in chat context
Agent → POST /checkout-sessions { items: [...] } → continue_url
Buyer clicks Buy → /checkout-link/?products=42:1,99:1&utm_*
WC populates cart from URL → WC checkout
```

| Cart state lives in | Agent's chat memory (transient) + the URL itself (encoded). Plugin holds nothing. |
| Per-item network cost | 1 round-trip per validation moment (typically once at commit). Optional per-cart-change `/checkout-sessions` calls for live totals. |
| Discoverability | `dev.ucp.shopping.checkout` capability in manifest; standard UCP. |
| Status | **Shipped.** |

**Best for:** multi-item assemblies the agent finalizes before redirecting. Works as long as the chat session survives.

This is the path most spec-aware agents take today.

## Model 3 — Agent-constructed URL

Variant of Model 2 where the agent skips the `/checkout-sessions` round-trip and constructs the cart URL itself, using the documented WooCommerce Shareable Checkout grammar.

```
Agent reads format from engineering docs (or /checkout-sessions response examples)
Agent maintains items[] in chat context
Agent constructs URL locally: /checkout-link/?products=42:1,99:1&utm_*
Buyer clicks Buy → WC populates cart → WC checkout
```

The URL grammar is documented in [`API-REFERENCE.md`](API-REFERENCE.md) (within the `/checkout-sessions` response examples) and [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md) (the continue_url shape paragraph). An agent that reads either doc can produce a valid cart URL without calling the merchant for it.

The format:

```
{merchant_origin}/checkout-link/?products={ID:QTY[,ID:QTY]*}
                               &utm_source={agent_hostname}
                               &utm_medium=referral
                               &utm_id=woo_ucp
                               &ai_agent_host_raw={raw_host}
                               &ai_session_id={chk_…}    # optional, agent-generated
```

| Cart state lives in | Agent's chat memory + the URL. Plugin holds nothing, isn't called for the cart. |
| Per-item network cost | 0 |
| Discoverability | Documented in the engineering docs above; not (currently) advertised as a structured field in the UCP manifest. |
| Status | **Supported.** Agents can use the format directly. |

**Best for:** high-volume agent integrations where the per-cart-change network cost matters and the agent doesn't need fresh per-cart validation.

**Trade-offs vs. Model 2:**

- No live validation. The constructed URL might point at an out-of-stock product or a price that's drifted; the agent finds out only when the buyer reaches WC's checkout page (which still validates server-side).
- No fresh totals or shipping preview before redirect. Agent can opt back into a `/checkout-sessions` call if it needs those.
- Format coupling. The `?products=ID:QTY` shape is WooCommerce's Shareable Checkout grammar. It's stable but it's a WC implementation choice; the engineering docs note this.

**Future option:** if agents want this format published as a structured field in the UCP manifest (rather than read out of band from the engineering docs), we'd add a `purchase_url_template` to the `com.woocommerce.ai_storefront` extension block. Small lift (~30 lines + tests). Not done today; the engineering docs cover the discoverability need for the agents we work with.

## Model 4 — Full UCP `dev.ucp.cart` capability (future)

**Not currently shipped.** Documented here as a roadmap option.

The UCP spec defines `dev.ucp.cart` as a separate capability from `dev.ucp.shopping.checkout`: lightweight CRUD over a server-side cart, no payment handlers, no status lifecycle. Cart converts to checkout via a `cart_id` parameter on the Create Checkout request. The conversion preserves the redirect-only checkout posture — the response is still `requires_escalation` + `continue_url`, identical to today.

```
Agent → POST /cart-sessions { items: [...] } → cart_id, line_items, totals
Agent → PATCH /cart-sessions/{id} { add/remove/update } → updated state
Agent → GET /cart-sessions/{id} → fresh totals/availability, current cart contents
Agent → POST /checkout-sessions { cart_id } → continue_url (REQUIRES_ESCALATION)
Buyer clicks Buy → WC populates cart → WC checkout
```

| Cart state lives in | Plugin storage (transient or new options/table), keyed by `cart_id`. Survives the chat session. |
| Per-item network cost | 1 PATCH per cart change. Same order of magnitude as Model 2's optional per-change validation calls, but server-side state lets the cart survive. |
| Discoverability | Standard UCP capability ID `dev.ucp.cart` declared in the manifest's `capabilities` array. |
| Status | **Roadmap.** |

### What it would take to implement

1. **New endpoints under `/wp-json/wc/ucp/v1/cart-sessions`:**
   - `POST /cart-sessions` — create a new cart, optionally with initial items.
   - `GET /cart-sessions/{id}` — read current state, totals, availability.
   - `PATCH /cart-sessions/{id}` — add/remove/update items.
   - `DELETE /cart-sessions/{id}` — cancel.
2. **Storage layer.** Either a new `wp_options`-backed transient keyed by `cart_id`, or piggyback on WC's existing `/wp-json/wc/store/v1/cart` (cookie-keyed; closer to WC's grain but cookie semantics complicate agent integration). Transient is more agent-friendly.
3. **TTL + cleanup.** Carts expire after, e.g., 24 hours. WP-Cron job to garbage-collect.
4. **Concurrency.** ETag/If-Match on PATCH, return 409 on mismatch.
5. **Cart→Checkout conversion.** Update `POST /checkout-sessions` to accept `cart_id` (in addition to today's `items[]` shape). Convert: load cart, snapshot items, generate `continue_url`, optionally keep the cart linked for back-to-storefront flows.
6. **Capability declaration.** Add `dev.ucp.cart` to `WC_AI_Storefront_Ucp::CANONICAL_CAPABILITIES` so the manifest advertises it.
7. **Tests.** Cart lifecycle (create → mutate → checkout), TTL/expiration, concurrency, cart-during-active-checkout reflection, multi-currency, inventory-hold (optional).

Roughly 3–5 days of work. The 405-with-explanatory-body stub we ship today for `/checkout-sessions/{id}` PATCH/PUT/DELETE was deliberately positioned so that agents trying these verbs get a structured "this endpoint is stateless; here's the path" answer rather than a generic 404. The same architectural pattern applies on a future `/cart-sessions/{id}`.

### When to add Model 4

Trigger conditions — add it when at least one of these surfaces:

- **Cart needs to survive a chat-session loss.** Buyer closes the chat, comes back tomorrow, expects to find the cart they were building. Today the cart dies with the chat.
- **Cart needs to be cross-device.** Buyer adds items on phone, finishes on laptop. Today there's no shared identity.
- **Cart needs human review before purchase.** Procurement, B2B approvals, "let me show this to my manager" flows. The cart needs to outlive the agent's chat.
- **Inventory hold during shopping.** Reserve items while the buyer thinks. WC has hooks for this; cart-session could orchestrate them.

Until at least one of these is real customer demand, agent-managed (Models 2/3) is strictly simpler and covers consumer-shopping needs.

### What stays the same in Model 4

- **Merchant owns checkout.** The cart→checkout conversion still emits `requires_escalation` + `continue_url`. Buyer still lands on the merchant's domain to pay. `payment_handlers` stays empty. The plugin's data sovereignty posture is unchanged.
- **Attribution wire shape.** The `continue_url` still carries the canonical `utm_*` payload (`utm_source`, `utm_medium=referral`, `utm_id=woo_ucp`, `ai_agent_host_raw`).
- **Existing endpoints.** `/catalog/search`, `/catalog/lookup`, `/checkout-sessions` continue to work for stateless flows. Adding `dev.ucp.cart` is additive.

## Decision matrix

| | Model 1 | Model 2 | Model 3 | Model 4 |
|---|---|---|---|---|
| Plugin holds cart state | No | No | No | **Yes** |
| Live validation per cart change | N/A (no cart) | Yes (each call) | No (or opt-in) | Yes (each PATCH) |
| Survives chat-session loss | N/A | No | No | **Yes** |
| Cross-device cart resume | No | No | No | **Yes** |
| Per-cart-change network cost | 0 | 1 round-trip | 0 | 1 round-trip |
| Implementation cost | Shipped | Shipped | Shipped (docs only) | ~3–5 days |
| UCP spec alignment | Catalog only | Checkout only | Checkout + WC-format extension | **Cart + Checkout (canonical)** |
| Merchant-owns-checkout posture | ✓ | ✓ | ✓ | ✓ |

## Recommendation

**Today: Models 1, 2, 3 cover the consumer shopping case.** Most spec-aware agents will use Model 2 (call `/checkout-sessions` with the full cart at commit). Sophisticated agents that want to skip the round-trip use Model 3 by reading the documented URL grammar. Both happen without merchant-side state.

**Add Model 4 when** a real B2B procurement, cross-device, or chat-recovery use case surfaces — not before. The implementation is small but it adds a stateful surface (storage, expiration, concurrency) we deliberately avoided in the initial design.

## See also

- [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md) — how an AI agent decides to render a Buy CTA from the three discovery layers
- [`API-REFERENCE.md`](API-REFERENCE.md) — endpoint shapes for `/catalog/search`, `/catalog/lookup`, `/checkout-sessions`
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — overall plugin structure, key design decisions
- [`HOOKS.md`](HOOKS.md) — filters that intercept relevant data
- [UCP Cart specification](https://ucp.dev/draft/specification/cart/) — the spec for `dev.ucp.cart`
- [UCP home](https://ucp.dev/)
