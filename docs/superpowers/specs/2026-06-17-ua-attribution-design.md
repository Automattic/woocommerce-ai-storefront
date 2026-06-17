# User-Agent Attribution Classification — Design

**Date:** 2026-06-17
**Status:** Approved (design); pending implementation plan
**Branch:** `feat/ua-attribution`
**Issue:** #464

## Context

On the direct UCP API path, when an agent sends no identity signal, the resolved `source_host` is empty and `WC_AI_Storefront_Attribution::with_woo_ucp_utm()` stamps `utm_source=ucp_unknown` (`FALLBACK_SOURCE` in `WC_AI_Storefront_UCP_Agent_Header`).

`resolve_agent_host( WP_REST_Request $request )` (the REST resolver, used by every endpoint) and `resolve_agent_data_from_name( string $name )` (the MCP-transport mirror, called from `class-wc-ai-storefront-mcp-tools.php:252`) both resolve identity in this order:

1. `UCP-Agent` profile URL → hostname → `canonicalize_host()` (`KNOWN_AGENT_HOSTS`).
2. `UCP-Agent` Product/Version token → `canonicalize_product()`.
3. Request body `meta.source` (REST) / the MCP client name (MCP).
4. Nothing → empty `source_host` → `ucp_unknown`.

But most agents already self-identify in the **`User-Agent`** header (`ChatGPT-User`, `GPTBot`, `Claude-User`, `PerplexityBot`, …). The plugin already detects these for the crawl logger via `WC_AI_Storefront_Robots::detect_crawler_from_ua()`, but that signal is never used for attribution. So a large share of `ucp_unknown` is avoidable.

Identity here is **not** an access gate — the buyer-confirmed handoff is open to all agents; the access gate (`check_agent_access()`) is a separate path that this design does not touch. Identity affects **attribution** only (and is the future eligibility key for delegated checkout that ACP/AP2 standardize).

## Goals

- Shrink `utm_source=ucp_unknown` by deriving the agent from the `User-Agent` header when no explicit signal resolves.
- Merge inferred agents into the **same** brand/`utm_source` buckets as declared ones, while keeping an audit trail of how the identity was derived.

## Non-goals

- No change to access control (`check_agent_access`), rate limiting, or any gating. UA-derived identity never grants access.
- No bespoke agent-registration endpoint. (We accept standard signals: `UCP-Agent`, and now `User-Agent`.)
- No mapping of ambiguous generic crawlers (`Bingbot`, `Googlebot`, `Applebot`) — they index broadly and are not necessarily a shopping agent; they stay `ucp_unknown`.
- No new merchant setting/toggle — this is always-on attribution behavior.

## Decisions (locked)

- **Merge with provenance.** Inferred ChatGPT → brand `ChatGPT`, `utm_source=chatgpt.com` (identical bucket to a declared ChatGPT), AND the raw UA token recorded in the existing `_wc_ai_storefront_agent_host_raw` meta (a declared agent shows a hostname there; an inferred one shows a UA token like `ChatGPT-User`).
- **Answer-agents only.** Map only UAs that clearly act for a user / answer engine; leave generic indexers unmapped.
- **Scope: REST + MCP.** Both resolvers gain the UA fallback (the classifier reads `$_SERVER` and needs no request object).
- **Precedence:** UCP-Agent profile → UCP-Agent product → meta.source / MCP name → **UA-derived** → `ucp_unknown`. UA is lowest-confidence, last before giving up; explicit identity always wins.

## Design

### 1. Curated map `UA_AGENT_HOSTS` (UA-token → representative host)

A new constant on `WC_AI_Storefront_UCP_Agent_Header` (co-located with `KNOWN_AGENT_HOSTS` so all attribution canonicalization lives in one class). UA token (as returned by `detect_crawler_from_ua()`) → a representative hostname that **already exists in `KNOWN_AGENT_HOSTS`**, so the existing `canonicalize_host()` yields a real brand (never `Other AI`):

| UA token(s) | Representative host | Brand (via `canonicalize_host`) |
|---|---|---|
| `ChatGPT-User`, `GPTBot`, `OAI-SearchBot` | `chatgpt.com` | ChatGPT |
| `Claude-User`, `ClaudeBot`, `Claude-SearchBot` | `claude.ai` | Claude |
| `Perplexity-User`, `PerplexityBot` | `perplexity.ai` | Perplexity |

Additional answer-agent UAs **may** be added **iff** their representative host already exists in `KNOWN_AGENT_HOSTS` (e.g. `Mistralai-User`, `DuckAssistBot`) — the implementation verifies the host key exists before adding the row; if not, the token is omitted (stays `ucp_unknown`) rather than bucketing to `Other AI`. Generic indexers (`Bingbot`, `Googlebot`, `Applebot`, training crawlers) are intentionally **absent**.

### 2. Pure classifier `classify_user_agent( ?string $ua = null ): ?array`

A new static method on `WC_AI_Storefront_UCP_Agent_Header`:

1. `$token = WC_AI_Storefront_Robots::detect_crawler_from_ua( $ua )` — a `null` `$ua` propagates to the `$_SERVER['HTTP_USER_AGENT']` default; an explicit string (including `''`) is used as-is (so tests can pass a UA directly).
2. `$host = self::UA_AGENT_HOSTS[ $token ] ?? ''`.
3. If `$host === ''` → return `null` (unmapped / junk UA → caller falls through to `ucp_unknown`).
4. Else return:
   ```php
   [
     'name'        => self::canonicalize_host( $host ),      // e.g. 'ChatGPT'
     'source_host' => self::normalize_host_string( $host ),  // e.g. 'chatgpt.com' → utm_source
     'raw_host'    => $token,                                // provenance, e.g. 'ChatGPT-User'
   ]
   ```

This returns the same `['name', 'source_host', 'raw_host']` shape the resolvers already produce, so the downstream flow (continue_url UTM, order meta, stats) is unchanged.

**Enabling change:** give `WC_AI_Storefront_Robots::detect_crawler_from_ua()` an optional `?string $ua = null` parameter that defaults to `$_SERVER['HTTP_USER_AGENT']` (current behavior). This makes the classifier a pure function (testable with an explicit UA) and is backward-compatible — existing callers pass nothing.

### 3. Wire into both resolvers

In `resolve_agent_host( $request )` and `resolve_agent_data_from_name( $name )`, after step 3 (meta.source / name) fails to yield a source and **before** returning the empty/`ucp_unknown` result, insert:

```php
// REST resolver — pass the request's User-Agent header (may be null):
$ua_data = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( $request->get_header( 'user-agent' ) );
if ( null !== $ua_data ) {
    return $ua_data;
}

// MCP resolver — no request object; pass nothing so the classifier reads $_SERVER:
$ua_data = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent();
if ( null !== $ua_data ) {
    return $ua_data;
}
```

Both reach the same classifier. REST forwards the request header (`get_header()` returns the string or `null`); the MCP resolver, lacking a request object, calls `classify_user_agent()` with no argument so `$ua` defaults to `null` and `detect_crawler_from_ua()` reads `$_SERVER['HTTP_USER_AGENT']`.

### 4. Boundaries & honesty

- The access gate `check_agent_access()` is **untouched** — UA classification runs only in the attribution resolvers.
- UA headers are spoofable; this is acceptable for attribution (so is `UCP-Agent`). Explicit `UCP-Agent`/`meta.source` always take precedence, and the raw UA token is preserved in `_wc_ai_storefront_agent_host_raw` for audit, so a merchant can always see that an attribution was UA-inferred.

## Testing

- **Pure unit tests** for `classify_user_agent()`:
  - each mapped token → expected `name`/`source_host`/`raw_host` (e.g. `ChatGPT-User` → `ChatGPT`/`chatgpt.com`/`ChatGPT-User`);
  - unmapped known crawler (`Bingbot`) → `null`;
  - junk/empty UA → `null`;
  - a UA whose token maps to a host present in `KNOWN_AGENT_HOSTS` never returns `Other AI`.
- **`detect_crawler_from_ua()`** optional-param test: explicit `$ua` arg path returns the same token as the `$_SERVER` path.
- **Resolver tests** (REST + MCP parallel):
  - `ChatGPT-User` UA + no `UCP-Agent` → resolves to `chatgpt.com`/`ChatGPT` with `raw_host = 'ChatGPT-User'`;
  - explicit `UCP-Agent` profile present + `ChatGPT-User` UA → explicit wins (UA ignored);
  - `Bingbot` UA + no signal → still `ucp_unknown`.

Harness: Brain Monkey + Mockery, matching `UcpAgentHeaderTest` / `RobotsTest` / `AttributionTest`.

## Files

- `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php` — `UA_AGENT_HOSTS` constant + `classify_user_agent()`.
- `includes/ai-storefront/class-wc-ai-storefront-robots.php` — `detect_crawler_from_ua( ?string $ua = null )`.
- `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` — UA fallback in `resolve_agent_host()` and `resolve_agent_data_from_name()`.
- Tests: `tests/php/unit/UcpAgentHeaderTest.php` (classifier), `tests/php/unit/RobotsTest.php` (optional param), and resolver coverage (existing controller/attribution test file).

## Out of scope / deferred

- Surfacing "declared vs inferred" as a separate dashboard dimension (we merge; provenance is in meta only).
- Mapping generic indexers or a generic `ai_crawler` bucket.
- Layers 2–3 from the backlog memory (making the `UCP-Agent` ask more prominent; tying identity to a capability carrot).
