# Attribution

How AI-agent traffic gets identified, tagged, and credited on orders.

The plugin attaches a canonical UTM signature to every URL it emits that an AI agent might dispatch a shopper to. WooCommerce core's built-in Order Attribution captures the UTMs onto the resulting order; the plugin then lifts the AI-specific signals into its own meta and surfaces them in the admin dashboard. There are no merchant-facing settings — attribution is automatic for any agent that calls our endpoints or follows URLs we publish.

## The two channels

Since 0.15.0 ([#386](https://github.com/Automattic/woocommerce-ai-storefront/pull/386)), AI orders split into two channels in the merchant dashboard, distinguished by the `utm_id` value stamped on the URL:

| Channel | `utm_id` | Origin | What it represents |
|---------|----------|--------|--------------------|
| **Agent** | `woo_ucp` | `/checkout-sessions` continue_url or `/catalog/{search,lookup}` product URL | Order originated from a live UCP shopping session. The agent walked the user through to checkout. |
| **Referral** | `woo_jsonld` | JSON-LD `BuyAction` / `SearchAction` `urlTemplate` on a product or homepage | Order originated from a JSON-LD scrape. An AI surface consumed our template, filled it in, served the URL as a search/answer result. The agent surfaced us; the user converted independently. |

Both are AI-attributed; the split lets merchants tell investment in agent partnerships apart from AI-discovery SEO. Both `utm_id` values are server-stamped — the plugin owns the URLs they appear on — so the recognition gate trusts them strictly (see [Recognition gates](#recognition-gates) below).

A third referral source (`utm_id=woo_llms`) is [proposed for llms.txt browse and fallback-checkout URLs](https://github.com/Automattic/woocommerce-ai-storefront/issues/398) and not yet shipped. When added, it slots into the Referral channel alongside `woo_jsonld`.

## The canonical UTM shape

The plugin's 0.5.0+ canonical 3-tuple stamped on Agent-channel URLs:

```
utm_source = <lowercase agent hostname>     e.g. chatgpt.com, gemini.google.com
utm_medium = referral                        Google-canonical; auto-buckets under GA4's Referral default channel
utm_id     = woo_ucp                         "we routed this" flag
```

Plus, when the producer-side raw identifier differs from the canonicalized `utm_source` (Other AI hosts, product-form UCP-Agent headers), an `ai_agent_host_raw=<token>` URL parameter carries the verbatim identifier for drill-in. See [`AGENT_HOST_RAW_META_KEY`](#what-lands-on-the-order) below.

The Referral channel uses the same shape with `utm_id=woo_jsonld`, but its `utm_source` is a literal `{agent_id}` placeholder rather than a resolved hostname — see [JSON-LD `urlTemplate`](#json-ld-urltemplate-aspirational-placeholder) below.

| Param | Agent channel | Referral channel (JSON-LD) | Referral channel (llms.txt, proposed) |
|-------|--------------|----------------------------|----------------------------------------|
| `utm_source` | Canonicalized hostname, e.g. `chatgpt.com` | Literal `{agent_id}` template placeholder | (omitted; filled in by `Referer` downstream) |
| `utm_medium` | `referral` | `referral` | `referral` |
| `utm_id` | `woo_ucp` | `woo_jsonld` | `woo_llms` |
| `ai_agent_host_raw` | Set when raw header value ≠ canonical | Not emitted | Not emitted |

## Where UTMs come from

Three emission surfaces. Each has its own constraints on what `utm_source` can carry, which is why the channel split exists.

### `/checkout-sessions` continue_url

The primary attribution path. An agent `POST`s to `/wp-json/wc/ucp/v1/checkout-sessions` with a `UCP-Agent` request header; the REST controller parses the header, canonicalizes the agent identity through `KNOWN_AGENT_HOSTS` / `KNOWN_AGENT_PRODUCT_NAMES` / `PRODUCT_TO_HOSTNAME`, and calls `WC_AI_Storefront_Attribution::with_woo_ucp_utm($url, $source_host, $raw_host)` on the response's `continue_url`.

The response payload includes `status: "requires_escalation"` plus the tagged `continue_url`. The agent redirects the shopper there; WC Order Attribution captures the UTMs.

Source: [`with_woo_ucp_utm()`](../../includes/ai-storefront/class-wc-ai-storefront-attribution.php) (line ~264). Helper is shared by the next surface as well.

### `/catalog/{search,lookup}` product URL

Less obvious but equally important: the bare product permalink in the `url` field of search/lookup results is also tagged via `with_woo_ucp_utm()` before being returned. Reason: an agent renders that URL as the "view product" link in chat; shoppers who click it, add to cart, and check out themselves (rather than going through the agent's checkout-session integration) still need the UTM payload, otherwise their orders bucket as "direct" or generic referral traffic and never get the AI-orders dashboard's `_wc_ai_storefront_agent` meta.

Same canonical 3-tuple as `continue_url`. Applied by `WC_AI_Storefront_UCP_Product_Translator`.

### JSON-LD `urlTemplate` (aspirational placeholder)

Product pages emit a `Product.potentialAction.BuyAction` whose `target.urlTemplate` carries:

```
?products={product_id}:{qty}&utm_source={agent_id}&utm_medium=referral&utm_id=woo_jsonld
```

The homepage `OnlineBusiness.potentialAction.SearchAction` carries the same triple with `{search_term}` substitution.

Key difference from the Agent channel: `utm_source` is a literal `{agent_id}` template placeholder, not a resolved hostname. The placeholder is **aspirational** — no AI agent currently substitutes it at recommendation time (Google's sitelinks searchbox does substitute `{search_term}` in `SearchAction.urlTemplate`, but no consumer exercises `{agent_id}` today). Crawlers store the template string verbatim; no session data leaks. See the [JSON-LD schema reference](./JSON-LD-SCHEMA.md#potentialaction-buyaction) for the full per-product / per-variant shape.

Source: [`add_buy_action()`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) and `output_store_jsonld()` for the homepage `SearchAction`.

## The UCP-Agent header

Agents identify themselves to the UCP REST surface via the `UCP-Agent` request header. Two formats are accepted:

| Format | Example | Parser |
|--------|---------|--------|
| Profile URL (RFC 8941 structured-fields) | `UCP-Agent: profile="https://chatgpt.com/agent-profile"` | `extract_profile_hostname()` |
| Product/Version (RFC 7231 §5.5.3 User-Agent style) | `UCP-Agent: UCP-Playground/1.0` | `extract_product_token()` |

The server resolves either form to a `utm_source` hostname:

- **Profile URL form** → `utm_source` = lowercased hostname of the URL (`chatgpt.com`).
- **Product/Version form** → `utm_source` = `PRODUCT_TO_HOSTNAME[product]` when mapped, otherwise the lowercased product token itself (`ucp-playground`).

When neither form is parseable and no fallback identifier exists, `utm_source` falls back to `FALLBACK_SOURCE` = `ucp_unknown`. Keeping the sentinel preserves the "agent didn't identify itself" cohort as a distinct row in WC Origin breakdowns rather than collapsing it into "direct" traffic.

### Body-field fallback

Clients that can't send custom headers (some browser-context shims, sandboxed runtimes) may include `meta.source` in the request body of `POST /checkout-sessions` as a fallback identifier. The server treats it as an identifier of last resort — only consulted when no `UCP-Agent` header is present.

## Recognition gates

[`capture_ai_attribution()`](../../includes/ai-storefront/class-wc-ai-storefront-attribution.php) (line ~403) decides whether a given order is AI-attributed via two parallel gates, OR-combined:

### STRICT gate

Trusts the `utm_id` value. Fires when any of:

- `utm_id === 'woo_ucp'` — continue_url from `/checkout-sessions` or tagged catalog URL.
- `utm_id === 'woo_jsonld'` — JSON-LD template URL filled in by a consumer.
- `utm_medium === 'ai_agent'` — legacy pre-0.5.0 shape, retained so already-placed orders attribute correctly through the upgrade window. Removable ~12 months after 0.5.0 ship date.

All three signals are server-emitted — the plugin's own code produced the URL — so STRICT trusts them without further checks on `utm_source`.

### LENIENT gate

Catches agents that bypass our endpoints entirely and stamp their own URLs. Fires when `utm_source` (after `normalize_host_string()`) exactly matches a key in `KNOWN_AGENT_HOSTS`. Example: UCPPlayground's harness sends `?utm_source=ucpplayground.com&utm_medium=referral` on orders that don't go through `/checkout-sessions` — the host match recognizes them as AI traffic regardless.

Safety: the LENIENT gate keys on `KNOWN_AGENT_HOSTS` keys (a curated allow-list of ~15 hostnames), not values. A random attacker can't forge AI attribution by setting `utm_source=evil.example` because that hostname isn't in the map. An attacker would have to (a) know the recognized hosts AND (b) want their fake order to attribute to a real brand — combination has no economic payoff to the attacker, only stats-pollution to the merchant.

Either gate firing classifies the order as AI; both firing is harmless (the second is a no-op after early return).

## Canonical brand resolution

The merchant-facing brand name is decoupled from the URL `utm_source` value, mediated by three lookup tables in [`WC_AI_Storefront_UCP_Agent_Header`](../../includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php):

| Constant | Direction | Purpose |
|----------|-----------|---------|
| `KNOWN_AGENT_HOSTS` | hostname → brand | Display label for hostname-form identifications. ~15 entries: `chatgpt.com → ChatGPT`, `claude.ai → Claude`, `gemini.google.com → Gemini`, etc. |
| `KNOWN_AGENT_PRODUCT_NAMES` | product token → brand | Display label for product-form identifications (e.g. `ucp-playground → UCPPlayground`). |
| `PRODUCT_TO_HOSTNAME` | product token → hostname | Used by the canonical UTM shape so a product-form agent attributes to the same `utm_source` hostname as its profile-URL form. |

Unknown hostnames and product tokens bucket to `OTHER_AI_BUCKET` = `"Other AI"` (since 0.3.x). Bucketing rather than scattering keeps the Top Agent card and per-agent breakdown legible — without it, a long tail of one-off hostnames (`agent.foo-startup.com`, `bot.experiment.dev`) would crowd out named brands.

The raw identifier is preserved on the order regardless of bucketing — see `AGENT_HOST_RAW_META_KEY` below.

### Adding a new known agent

When a particular unknown hostname becomes prominent across enough orders:

1. Add the hostname → canonical brand entry to `KNOWN_AGENT_HOSTS` (or product token → brand to `KNOWN_AGENT_PRODUCT_NAMES`).
2. If product-form, also add the matching product token → hostname entry to `PRODUCT_TO_HOSTNAME` so the canonical UTM shape converges on one `utm_source` regardless of header format.
3. Update the [`UCP_AGENT_CRAWLER_MAP`](../../includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php) if the agent's brand maps to one or more crawler IDs in `WC_AI_Storefront_Robots::AI_CRAWLERS` (otherwise the merchant's `allowed_crawlers` setting can't gate the new brand).
4. Test coverage in `UcpAgentHeaderTest`.

There's no hard threshold — both maps are curated by-hand from observed traffic. The `_wc_ai_storefront_agent_host_raw` meta is the cohort signal for graduation review.

## What lands on the order

[`capture_ai_attribution()`](../../includes/ai-storefront/class-wc-ai-storefront-attribution.php) hooks `woocommerce_checkout_order_created` and `woocommerce_store_api_checkout_order_processed` (Blocks checkout). When either gate matches, it stamps:

| Meta key | Value | Notes |
|----------|-------|-------|
| `_wc_ai_storefront_agent` (`AGENT_META_KEY`) | Canonical brand name (e.g. `ChatGPT`, `Other AI`) | Falls back to raw `utm_source` when STRICT matched but LENIENT didn't (unknown agent still stamped `utm_id=woo_ucp`). |
| `_wc_ai_storefront_agent_host_raw` (`AGENT_HOST_RAW_META_KEY`) | Producer-side raw identifier | Preserves provenance for "Other AI" drill-in. Two writers: STRICT path lifts the `ai_agent_host_raw` URL param verbatim; LENIENT path writes the output of `normalize_host_string()`. |
| `_wc_ai_storefront_session_id` (`SESSION_META_KEY`) | AI session identifier from the request | Conversation-tracking hint passed by some agents; optional. |

WooCommerce core captures `utm_source` / `utm_medium` / `utm_id` itself into `_wc_order_attribution_utm_*` meta — the plugin doesn't duplicate that work, it just lifts the AI-specific signals into its own meta.

### Mixed-era cardinality

Long-lived stores carry orders from three eras of the AGENT_META_KEY storage:

| Era | Stored shape |
|-----|--------------|
| pre-1.6.7 | Raw hostnames (`agent.foo.com`) |
| 1.6.7 → 0.2.x | Canonical brand names for known hosts; raw hostnames for unknown |
| 0.3.x onward | Canonical brand names for known; `"Other AI"` literal for unknown |

`canonicalize_host_idempotent()` at display time maps known pre-1.6.7 raw hostnames forward to canonical names; unknown raw hostnames pass through. `get_stats()` `GROUP BY` on this meta therefore mixes canonical brand names, the literal `"Other AI"` (only from 0.3.x), and long-tail raw hostnames from earlier eras. Pre-1.6.7 unknown-agent rows continue to appear as their own buckets until they age out of the rolling stats window (~14 months). A migration pass is intentionally out of scope.

## What merchants see

The Orders list shows AI traffic in WooCommerce core's built-in **Origin** column (since 1.6.7), fed by `_wc_order_attribution_utm_source`. The column renders the lowercase hostname directly — `chatgpt.com`, `gemini.google.com`, `ucp_unknown` for unidentified agents. A custom "AI Agent" column was removed in 1.6.7 because it duplicated the Origin column.

The AI Storefront admin page surfaces:

- Total AI orders + revenue (sum of orders matching either recognition gate).
- Channel split: Agent (`woo_ucp`) vs. Referral (`woo_jsonld`).
- Top Agents card — canonical brand breakdown, sourced from `AGENT_META_KEY`.
- Recent AI Orders table — drill-in to the raw host for "Other AI" rows via `AGENT_HOST_RAW_META_KEY`.

Stats are cached in a transient busted on order status changes (`woocommerce_order_status_completed`, `_processing`, `_delete_order`, `_trash_order`). The bust is gated on whether the order has AI attribution (P-11) so non-AI status changes don't churn the cache.

## For agent integrators

The contract you need to follow:

1. **Send a `UCP-Agent` header on every request.** Profile-URL form preferred; `Product/Version` form accepted. Without it your attribution buckets as `ucp_unknown` and you don't appear in the merchant's Top Agents card.
2. **POST to `/wp-json/wc/ucp/v1/checkout-sessions`** for buy intent. The response carries `status: "requires_escalation"` and a `continue_url` with attribution already attached. Redirect the shopper to that URL — do not construct UTMs yourself.
3. **Do not modify `utm_source` on URLs we return.** The server resolved your identity from the header. If you append your own `utm_source=...`, you'll clobber the canonical one and your attribution drops to the LENIENT gate (which only fires for hostnames already in `KNOWN_AGENT_HOSTS`).
4. **Header-less fallback**: include `meta.source` in the request body of `POST /checkout-sessions` when you cannot send custom headers. The server treats it as the identifier of last resort.
5. **No client-side substitution of JSON-LD placeholders.** The `{agent_id}` placeholder in `BuyAction.urlTemplate` is aspirational; no AI vendor currently substitutes it. If you scrape JSON-LD and want attribution, the recommended path is to instead call the UCP endpoints with a `UCP-Agent` header.

If you're not in `KNOWN_AGENT_HOSTS` yet, your orders still attribute correctly via the STRICT gate's `utm_id=woo_ucp` flag — they just display under "Other AI" in the merchant dashboard. Reach out to get added to the curated map.

## References

| Topic | Source |
|-------|--------|
| The UTM 3-tuple builder | [`with_woo_ucp_utm()`](../../includes/ai-storefront/class-wc-ai-storefront-attribution.php) |
| Recognition gates (STRICT + LENIENT) | [`capture_ai_attribution()`](../../includes/ai-storefront/class-wc-ai-storefront-attribution.php) |
| Header parsing + canonicalization | [`WC_AI_Storefront_UCP_Agent_Header`](../../includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php) |
| Catalog product URL tagging | [`WC_AI_Storefront_UCP_Product_Translator`](../../includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-product-translator.php) |
| `BuyAction` / `SearchAction` template emission | [`class-wc-ai-storefront-jsonld.php`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php), [JSON-LD schema reference](./JSON-LD-SCHEMA.md) |
| Stats query + channel split | [`get_stats()`](../../includes/ai-storefront/class-wc-ai-storefront-attribution.php) |
| Test coverage | `UcpAgentHeaderTest`, `AttributionTest`, `AttributionCaptureTest`, `AttributionStatsTest` |
| Channel-split rollout | [#386](https://github.com/Automattic/woocommerce-ai-storefront/pull/386), 0.15.0 |
| Proposed llms.txt channel (`woo_llms`) | [#398](https://github.com/Automattic/woocommerce-ai-storefront/issues/398) |
