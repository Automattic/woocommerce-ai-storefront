# Design: Public MCP transport for UCP shopping capabilities

- **Date:** 2026-06-01
- **Status:** Approved design — pending implementation plan
- **Author:** Piero Rocca (with Claude Code)
- **Branch:** `add/mcp-transport-spec`

## Motivation

The plugin already exposes its public shopping capabilities — `catalog.search`,
`catalog.lookup`, and `checkout` — to external shopping agents over a UCP REST
transport (`wc/ucp/v1`). The hypothesis driving this work is that **some agents
will not engage with a REST/HTTP transport but will engage with an MCP server**.

This design adds a **public MCP (Model Context Protocol) transport that lives
beside the existing REST layer** and surfaces the same shopping capabilities as
MCP tools. It is purpose-built for external shopping agents.

### Explicit non-goals

- **Not** a merchant-admin MCP server. WooCommerce core 10.3+ already ships
  native admin-side MCP via the Abilities API; this design does not touch that.
- **Not** a replacement for the REST transport. REST remains; MCP is additive.
- **Not** OAuth, SSE streaming, MCP resources/prompts, or a pretty-URL rewrite in
  v1 (see [YAGNI](#yagni-boundaries-for-v1)).

## Decisions (locked during brainstorming)

| # | Decision | Choice |
|---|----------|--------|
| 1 | Surface | Public shopping agents — mirror the UCP capabilities. |
| 2 | Agent identity / auth | Reuse the existing allow-list gate; derive identity from the MCP `initialize` handshake (`clientInfo.name`) rather than the `UCP-Agent` header. |
| 3 | Code-reuse seam | Extract a transport-neutral service layer; REST and MCP both become thin adapters over it. |
| 4 | Transport | Streamable HTTP, plain-JSON responses (no SSE). |
| 5 | Feature gating | Dedicated `mcp_enabled` setting (default `yes` when syndication is enabled) so the experiment toggles independently of REST. |
| 6 | Session handling | Required-session mode: `Mcp-Session-Id` is mandatory on every request **except** `initialize`, and the allow-list is re-checked per tool call. Follows from Decision #2 (identity-based gating requires per-request identity continuity) — see [Auth & session flow](#auth--session-flow). |
| 7 | Extraction depth | **Minimal seam** (decided during planning, after research revealed the controller is ~5,800 lines with ~11 coupled helpers — the "contained refactor" assumption below was too optimistic). Each `handle_X(WP_REST_Request)` splits into a thin HTTP wrapper + a transport-neutral `run_X(array $params): array` core **on the existing controller**. Standalone `Catalog_Service`/`Checkout_Service` classes are **deferred** — the cores are the controller's public neutral API for now, and the MCP layer calls them directly. Still one code path; far smaller blast radius. Supersedes the "new service classes" file list in [Files touched](#files-touched-summary). |

## Background: current REST architecture (what we build on)

- **Public UCP REST controller** — `WC_AI_Storefront_UCP_REST_Controller`,
  namespace `wc/ucp/v1`. Routes: `POST /catalog/search`, `POST /catalog/lookup`,
  `POST /checkout-sessions` (+ stub `GET|PUT|PATCH|DELETE /checkout-sessions/{id}`
  returning `405`), `GET /extension/schema`.
- **Permission gate** — `check_agent_access()` inspects the `UCP-Agent` header
  against the merchant's `allowed_crawlers` setting and the
  `allow_unknown_ucp_agents` toggle; returns `true` or a `WP_Error` (`403`/`429`).
- **Pure translators** — `WC_AI_Storefront_UCP_Product_Translator` and
  `..._Variant_Translator` are side-effect-free functions converting WC Store API
  shapes into UCP shapes (integer minor-unit prices, prefixed IDs).
- **Internal dispatch** — the controller does not loop back over HTTP; it uses
  `rest_do_request()` to call the WC Store API in-process, then translates and
  wraps results in a UCP envelope (`WC_AI_Storefront_UCP_Envelope`).
- **Discovery** — `WC_AI_Storefront_Ucp` serves `/.well-known/ucp` via a rewrite
  rule, advertising protocol version, service name (`dev.ucp.shopping`), and
  capabilities (`catalog.search`, `catalog.lookup`, `checkout`).
- **Bootstrap** — `WC_AI_Storefront::register_rest_routes()` instantiates each
  controller and calls `register_routes()` on `rest_api_init`. A static classmap
  autoloader (`includes/autoload.php`) maps `class-wc-ai-storefront-*.php` files;
  no Composer.

The translators being pure and the controller delegating to the Store API mean
the orchestration is already *almost* transport-neutral — the extraction below is
contained.

## Architecture

```
                    ┌─────────────────────────────┐
   REST agents ───▶ │ UCP_REST_Controller (thin)  │ ─┐
                    └─────────────────────────────┘  │
                                                      ├─▶ ┌──────────────────────┐
                    ┌─────────────────────────────┐  │   │ Catalog_Service       │ ─▶ Store API
   MCP agents  ───▶ │ MCP_Server (thin JSON-RPC)  │ ─┘   │ Checkout_Service      │ ─▶ translators
                    └─────────────────────────────┘      └──────────────────────┘
```

### Component 1 — transport-neutral service layer

Move the orchestration body currently inside `UCP_REST_Controller` into plain
service classes that return UCP **data structures** (associative arrays / value
objects), never `WP_REST_Response`.

- `WC_AI_Storefront_UCP_Catalog_Service`
  - `search( array $args ): array` — applies product scoping filters, dispatches
    to the Store API via `rest_do_request()`, translates, returns the UCP catalog
    payload (and pagination metadata).
  - `lookup( array $ids ): array` — enforces `MAX_IDS_PER_LOOKUP` (100), fetches,
    translates, returns the UCP product list.
- `WC_AI_Storefront_UCP_Checkout_Service`
  - `create_session( array $line_items, array $context ): array` — enforces
    `MAX_LINE_ITEMS_PER_CHECKOUT` (100), builds the stateless checkout redirect,
    returns the UCP checkout payload.

These services own the existing limits/constants and the
translate-and-shape logic. They do **not** know about HTTP status codes,
permission callbacks, or JSON-RPC. Business errors are returned as a typed result
(a UCP error code + message), letting each transport render them in its own idiom.

The REST controller keeps its routes, schemas, permission callbacks, and envelope
wrapping, but its handler bodies shrink to: validate input → call service →
wrap result in the UCP envelope / error envelope. Its existing PHPUnit tests
therefore become a **behavior-preserving regression harness** — they must pass
unchanged after the extraction.

### Component 2 — MCP transport adapter

New files under `includes/ai-storefront/ucp-mcp/`:

- `WC_AI_Storefront_MCP_Server` — registers the endpoint, parses the JSON-RPC 2.0
  envelope, dispatches by method, renders the JSON-RPC response. Wired into
  `WC_AI_Storefront::register_rest_routes()`.
- `WC_AI_Storefront_MCP_Tools` — the tool registry: tool name → input JSON Schema
  → handler that calls a service method and shapes the `tools/call` result.
- `WC_AI_Storefront_MCP_Session` — issues and validates the ephemeral
  `Mcp-Session-Id` (see [Auth & session flow](#auth--session-flow)); stores the
  handshake identity in a short-TTL transient.

All new classes are registered in the `includes/autoload.php` classmap.

## MCP protocol surface

One Streamable-HTTP endpoint speaking JSON-RPC 2.0, responding with plain JSON
(no SSE). Supported methods:

| Method | Behavior |
|--------|----------|
| `initialize` | Negotiate `protocolVersion`; advertise `capabilities: { tools: {} }`; return `serverInfo` (name `dev.ucp.shopping`, plugin version). Runs the auth gate (below) and, on success, issues `Mcp-Session-Id`. |
| `notifications/initialized` | Accept with HTTP `202` (no body); no-op. |
| `tools/list` | Return the three tools with input schemas. |
| `tools/call` | Validate the session, dispatch `name` → service, return the result. |
| `ping` | Liveness; return empty result. |

### Tools

Each tool's `inputSchema` mirrors the matching REST request schema. Each
successful `tools/call` returns `structuredContent` (the UCP payload) **plus** a
text-summary content block, so text-only clients still receive a usable answer.

| Tool name | Service call | Key inputs |
|-----------|--------------|-----------|
| `catalog_search` | `Catalog_Service::search()` | `query`, filters, `limit` (≤100), `sort` |
| `catalog_lookup` | `Catalog_Service::lookup()` | `ids[]` (≤100) |
| `checkout_create` | `Checkout_Service::create_session()` | `line_items[]` (≤100) → redirect URL |

### Transport-level requirements (verified against MCP `2025-06-18`)

The following were confirmed verbatim against the live Streamable HTTP transport
spec (revision `2025-06-18`) and are normative MUSTs we honor regardless of any
other choice:

- **Single endpoint, POST + GET.** The server MUST expose one MCP endpoint path.
  We implement POST (JSON-RPC) and respond with `Content-Type: application/json`
  (single JSON object), which the spec explicitly permits in lieu of SSE. A GET
  with no SSE support MAY return `405`.
- **`Origin` validation (MUST).** The server MUST validate the `Origin` header on
  incoming connections to prevent DNS-rebinding attacks.
- **Protocol version.** Negotiate in `initialize`; latest supported is
  `2025-06-18`. Clients MUST send `MCP-Protocol-Version` on post-init requests; if
  absent, the server SHOULD assume `2025-03-26`; an invalid/unsupported version
  MUST get `400`.
- **Notifications/responses → `202`.** A POSTed JSON-RPC notification or response
  (e.g. `notifications/initialized`) that the server accepts MUST return HTTP `202`
  with no body.
- **`Accept` header.** Clients send `Accept: application/json, text/event-stream`;
  we answer with `application/json`.

> **Spec-verification gate (per project rule "verify spec claims before
> building").** Session semantics, status-code mapping, and protocol versioning
> are now verified (above + [Auth & session flow](#auth--session-flow)). **Still
> to verify during planning:** the exact `tools/call` result shape — the
> `content` block types and the `structuredContent` field — against the live spec
> before coding the tool responses.

## Endpoint & discovery

- **Route:** `wc/ucp/v1/mcp`, registered alongside the other UCP routes so all
  agent-facing surfaces share one namespace. Register **both** `POST` (JSON-RPC)
  and `GET` on the route: POST does the work; GET returns `405` (we offer no SSE
  stream). This satisfies the spec's "endpoint MUST support POST and GET" without
  WP returning a bare `404` on GET. A pretty rewrite alias (`/mcp`, mirroring the
  `/.well-known/ucp` + `/llms.txt` rewrite pattern) is **deferred** to a later
  iteration.
- **Discovery:** add an MCP transport pointer to the existing `/.well-known/ucp`
  manifest so UCP-aware agents auto-discover the MCP option from the single
  discovery document they already read. This is the bridge that lets one
  discovery doc advertise both transports.

## Auth & session flow

MCP clients do not send the `UCP-Agent` header, so identity comes from the
handshake. Because the transport is stateless HTTP, a `tools/call` arrives in a
different request than `initialize`; MCP carries continuity via the
`Mcp-Session-Id` header.

**Required-session mode** (Decision #6). Session IDs are optional in MCP, but we
*require* them because identity-based gating needs identity on every gated request
(a `tools/call` carries no `clientInfo`). The spec anticipates this — "servers that
require a session ID" is a first-class mode.

1. `initialize` reads `clientInfo.name`. Run the **existing** allow-list gate
   (`allowed_crawlers` + `allow_unknown_ucp_agents`) against that name.
   - Allowed → issue a cryptographically-random `Mcp-Session-Id`; persist
     `{ client_name }` in a short-TTL transient (store the **name**, not a cached
     `allowed` boolean — see step 3).
   - Not allowed → HTTP `403`.
2. Every subsequent request (i.e. all except `initialize`) MUST carry
   `Mcp-Session-Id`. Per the spec's status mapping:
   - **Missing** header on a non-`initialize` request → HTTP `400`.
   - Header present but **unknown/expired** (lapsed TTL = terminated session) →
     HTTP `404`; the client then re-`initialize`s.
3. On each `tools/call`, look up `client_name` from the session and **re-run the
   allow-list check** (cheap) rather than trusting a cached verdict. This means a
   merchant who removes a crawler mid-session sees near-immediate revocation,
   bounded only by request cadence — not by TTL. You can only re-check an identity
   you carry, which is the deeper reason required-session mode beats stateless.
4. Rate limiting reuses the existing limiter, keyed by `client_name`; over-limit
   → HTTP `429`.

This ephemeral session state exists **only** to carry handshake identity and key
the rate limiter. It is unrelated to checkout: **UCP checkout remains stateless**
(no server-side cart). Genuine MCP clients perform this handshake and echo the
session header automatically, so it adds no friction for legitimate agents — it
only turns away clients that refuse the handshake, which can't be identified
anyway.

## Error handling

| Error class | Rendering |
|-------------|-----------|
| Unparseable JSON body | JSON-RPC `-32700` (Parse error), HTTP `400`. |
| Valid JSON, not a request (no `method`) | JSON-RPC `-32600` (Invalid Request), HTTP `400`. |
| Unknown method / unknown tool | JSON-RPC `-32601` / `-32602`. |
| Auth (agent not on allow-list; a blank `clientInfo.name` is treated as the unknown bucket, never silently allowed) | HTTP `403` at `initialize`; re-checked per call. |
| Rate limit | HTTP `429`, reusing the existing limiter — applied to **every** request including `initialize` (so a session-creation flood is throttled). |
| Missing session header (non-`initialize`) | HTTP `400`. |
| Unknown/expired session | HTTP `404`; client re-`initialize`s. |
| Invalid/unsupported `MCP-Protocol-Version` | HTTP `400`. |
| Bad `Origin` | Reject (DNS-rebinding defense). |
| Business (product not found, invalid checkout, syndication paused) | A **successful** `tools/call` result with `isError: true`; the text block carries the first `error`-typed entry from the core's `body.messages[]` (`code` + `content`) — the real UCP error-envelope shape (not a top-level `error{}`). |

## Feature gating

- New setting `mcp_enabled` (boolean, default `yes` when syndication `enabled` is
  `yes`). The MCP endpoint is only registered/active when both `enabled` and
  `mcp_enabled` are `yes`. This lets the merchant toggle the MCP experiment
  without disabling the REST transport — directly serving the "do agents engage
  more with MCP?" question.
- Add `mcp_enabled` to the admin settings REST schema
  (`WC_AI_Storefront_Admin_Controller`) and the settings UI.

## Testing strategy

- **Service unit tests** — `Catalog_Service::search()/lookup()` and
  `Checkout_Service::create_session()`: filtering, limits, translation shape,
  error results. Test sentinels for invalid input use obviously-fake values.
- **Adapter tests** — `initialize` handshake (capabilities/serverInfo/version
  negotiation), `tools/list` shape, `tools/call` dispatch to each service, auth
  rejection (`403`), rate limiting (`429`), session validation, and business-error
  mapping (`isError: true`).
- **Regression** — the existing UCP REST controller tests run unchanged as the
  behavior-preserving net for the extraction.
- Run the **full** PHPUnit suite (not a filtered subset) after the extraction,
  since orchestration is hoisted into a shared entry point.

## YAGNI boundaries for v1

Deferred unless signal warrants:

- SSE streaming responses (tools are short request/response).
- MCP-spec OAuth 2.1 bearer auth.
- MCP `resources` and `prompts` (tools only).
- Pretty-URL `/mcp` rewrite alias.
- A standalone `.well-known/mcp` discovery document (we advertise via the UCP
  manifest instead).

## Open implementation questions (for the plan, not blockers)

1. ~~Exact MCP `protocolVersion`(s) to accept/echo.~~ **Resolved:** support
   `2025-06-18`; absent the `MCP-Protocol-Version` header, assume `2025-03-26`;
   invalid/unsupported → `400`.
2. ~~Whether `Mcp-Session-Id` is mandatory on `tools/call`.~~ **Resolved:**
   required-session mode (mandatory on all non-`initialize` requests) with a
   per-call allow-list re-check. See Decision #6 and [Auth & session
   flow](#auth--session-flow).
3. Transient TTL for session identity — still open (proposed: short, on the order
   of minutes). Shorter TTL tightens revocation latency for clients that pause
   between requests but forces more re-`initialize` round-trips.
4. Exact `tools/call` result shape (`content` block types + `structuredContent`)
   — verify against the live spec before coding tool responses.

## Files touched (summary)

**New**

- `includes/ai-storefront/ucp/class-wc-ai-storefront-ucp-catalog-service.php`
- `includes/ai-storefront/ucp/class-wc-ai-storefront-ucp-checkout-service.php`
- `includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-server.php`
- `includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-tools.php`
- `includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-session.php`

**Modified**

- `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` (delegate to services)
- `includes/class-wc-ai-storefront.php` (`register_rest_routes()` wires the MCP server; gate on `mcp_enabled`)
- `includes/ai-storefront/class-wc-ai-storefront-ucp.php` (advertise MCP transport in discovery)
- `includes/admin/class-wc-ai-storefront-admin-controller.php` (`mcp_enabled` schema)
- `includes/autoload.php` (classmap entries)
- Settings UI for the `mcp_enabled` toggle
