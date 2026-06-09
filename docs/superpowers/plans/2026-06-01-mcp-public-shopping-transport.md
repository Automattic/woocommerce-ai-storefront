# MCP Public Shopping Transport — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a public MCP (Model Context Protocol) Streamable-HTTP endpoint that exposes the existing UCP shopping capabilities (`catalog_search`, `catalog_lookup`, `checkout_create`) as MCP tools for external shopping agents, reusing the REST layer's logic through a thin shared seam.

**Architecture:** Use the **minimal seam** (per spec Decision #7). Each `WC_AI_Storefront_UCP_REST_Controller::handle_X(WP_REST_Request)` is split into a thin HTTP wrapper plus a transport-neutral `run_X(array $params): array` core that returns `['body' => array, 'status' => int]`. REST handlers and a new MCP server both build `$params` and call the same cores — one code path, two transports. The MCP server is a JSON-RPC 2.0 adapter (`initialize` → `tools/list` → `tools/call`) that reuses the existing agent-allow-list gate and rate limiter, deriving agent identity from the MCP handshake instead of the `UCP-Agent` header.

**Tech Stack:** PHP 8.1, WordPress/WooCommerce REST infrastructure, static classmap autoloader (no Composer autoload at runtime), PHPUnit 10 + Brain Monkey + Mockery (stub-based, no DB), WP transients for ephemeral MCP sessions.

---

## Background facts (verified against the codebase)

These are the anchors every task depends on. File paths are absolute-from-repo-root unless noted.

- **Controller:** `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php`, class `WC_AI_Storefront_UCP_REST_Controller`, namespace `wc/ucp/v1`. Handlers are **instance** methods using `$this->request_context` and `$this->fetch_store_api_product()`.
- **Handler line ranges & input reads** (verified):
  - `handle_catalog_search()` — lines 621–827. Reads: `get_request_currency($request)` (630), `resolve_agent_host($request)` (665), `get_header('ucp-agent')` (669), `get_param('signals')` (685), `get_json_params()` (702), `map_ucp_search_to_store_api($request)` (713). Returns `new WP_REST_Response($body, 200)` (755); errors 503 (668), 500 (754), 503 (756).
  - `handle_catalog_lookup()` — lines 1713–2228. Reads: `resolve_agent_host($request)` (1739), `get_header('ucp-agent')` (1743), `get_param('signals')` (1757), `get_param('ids')` (1765), `get_request_currency($request)` (1827). Returns `new WP_REST_Response($response_body, 200)` (2227); errors 503 (1734), 503 (2226).
  - `handle_checkout_sessions_create()` — lines 2358–3035. Reads: `get_param('line_items')` (2372), `resolve_agent_host($request)` (2394), `get_param('context')` (2413), `context['locale']` (2415), `get_request_currency($request)` (2430). Returns `new WP_REST_Response($response_body, $should_redirect ? 201 : 200)` (3031–3034); errors via `self::ucp_checkout_error_response(...)` at 503 (2364), 400 (2375), 400 (2383).
- **Request-derived helpers** (all `static`):
  - `resolve_agent_host(WP_REST_Request $request): array` — lines 5173–5263. Reads `get_header('ucp-agent')` (5174) and `get_json_params()['meta']['source']` (5227). Returns `['name'=>string,'raw_host'=>string,'source_host'=>string]`. **Stays request-bound; only the REST wrapper calls it.**
  - `get_request_currency(WP_REST_Request $request): ?string` — lines 5715–5729. Reads `get_param('context')['currency']`, validates ISO-4217.
  - `map_ucp_search_to_store_api(WP_REST_Request $request): array` — lines 4086–4487. Reads `get_param('query')` (4090), `get_param('pagination')` (4107), `get_param('sort')` (4204), `get_param('filters')` (4264), `get_param('context')` (4332).
- **Permission:** all three public routes use `permission_callback => '__return_true'` style (gating happens inside handlers via `check_agent_access` / `is_syndication_disabled`).
- **Reusable gate statics** (no edits needed):
  - `WC_AI_Storefront_UCP_Agent_Header::canonicalize_product(string): string`, `::canonicalize_host(string): string`, `::is_agent_allowed(string $canonical, array $allowed): bool`, constant `::OTHER_AI_BUCKET`.
  - `WC_AI_Storefront_Robots::resolve_allowed_crawlers(array $settings): array`.
  - `WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit(): bool|WP_Error` (static; keys by UA+IP, falls back to IP for unknown UA; returns `WP_Error` status 429 when exceeded).
- **Settings:** `WC_AI_Storefront::get_settings(): array`; defaults in `WC_AI_Storefront::settings_defaults()` (lines 42–81); `enabled` is `'yes'|'no'`; `is_syndication_disabled()` checks `'yes' !== ($settings['enabled'] ?? 'no')`.
- **Admin settings write path:** REST schema args in `class-wc-ai-storefront-admin-controller.php` (boolean pattern: `['type'=>'string','enum'=>['yes','no']]`); allowed `$fields` array at line 401; per-field validation in `WC_AI_Storefront::update_settings()` (lines 575–664, yes/no pattern at 605–609).
- **Settings UI:** `client/settings/ai-storefront/endpoint-info.js` (`CheckboxControl` pattern ~lines 2392–2408); reads/writes through `client/data/ai-storefront/` (resolvers/actions; POST to `wc/v3/ai-storefront/admin/settings`).
- **Discovery manifest:** `includes/ai-storefront/class-wc-ai-storefront-ucp.php`, `WC_AI_Storefront_Ucp::generate_manifest()`. The `ucp.services['dev.ucp.shopping']` value is an **array of binding objects**; the single existing binding has `'transport' => 'rest'` (lines 274–284). `$ucp_endpoint = rest_url('wc/ucp/v1')` (line 229).
- **Bootstrap/wiring:** `WC_AI_Storefront::register_rest_routes()` (lines 305–316) instantiates each controller and calls `register_routes()`.
- **Autoload:** `includes/autoload.php` static `$classmap` (lines 19–54), entries like `'WC_AI_Storefront_UCP_REST_Controller' => '/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php'`.
- **Tests:** `phpunit.xml.dist`, suite dir `tests/php/unit/` (suffix `Test.php`), base class `\PHPUnit\Framework\TestCase`, Brain Monkey (`Monkey\setUp/tearDown`, `Functions\when(...)`) + Mockery. New production classes **must** be added to the manual require list in `tests/php/bootstrap.php` (lines 46–84). Commands: full suite `./vendor/bin/phpunit` (or `composer test`); single `./vendor/bin/phpunit --filter <name> tests/php/unit/<File>.php`; style `vendor/bin/phpcs` then `vendor/bin/phpcbf`; static `vendor/bin/phpstan analyse --memory-limit=512M`.

---

## Shared contracts (referenced by every task)

### The `$params` contract consumed by the neutral cores

```php
// run_catalog_search( array $params )
$params = [
    'query'      => ?string,
    'context'    => ?array,   // { currency?, locale?, ... }
    'signals'    => ?array,
    'filters'    => ?array,
    'pagination' => ?array,
    'sort'       => ?array,
    'agent_data' => array,    // ['name'=>string,'raw_host'=>string,'source_host'=>string]
    'ucp_agent_header' => string, // raw header for REST; '' for MCP
    'json_body'  => array,    // full decoded body (REST) or tool arguments (MCP)
];

// run_catalog_lookup( array $params )
$params = [
    'ids'        => array,
    'context'    => ?array,
    'signals'    => ?array,
    'agent_data' => array,
    'ucp_agent_header' => string,
    'json_body'  => array,
];

// run_checkout_create( array $params )
$params = [
    'line_items' => array,
    'context'    => ?array,
    'agent_data' => array,
    'ucp_agent_header' => string,
    'json_body'  => array,
];
```

### The core return contract

```php
// Every run_*() returns:
[ 'body' => array, 'status' => int ]   // exactly what was passed to new WP_REST_Response( $body, $status )
```

### Transport mapping of `['body','status']`

- **REST wrapper:** `return new WP_REST_Response( $r['body'], $r['status'] );`
- **MCP `tools/call`:** `status >= 400` → MCP result `{ isError: true, content: [{type:'text', text: <code+message from body>}] }`; otherwise `{ content: [{type:'text', text: <one-line summary>}], structuredContent: <body> }`.

---

## File structure

**Modify**

- `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` — split 3 handlers into wrappers + `run_*` cores; param-ize `map_ucp_search_to_store_api`; add `get_currency_from_context`.
- `includes/class-wc-ai-storefront.php` — wire MCP server in `register_rest_routes()`; add `mcp_enabled` default in `settings_defaults()`; validate `mcp_enabled` in `update_settings()`.
- `includes/admin/class-wc-ai-storefront-admin-controller.php` — add `mcp_enabled` to settings args schema and to the allowed `$fields` array (line 401).
- `includes/ai-storefront/class-wc-ai-storefront-ucp.php` — add an `mcp` binding to the `services['dev.ucp.shopping']` array in `generate_manifest()`.
- `includes/autoload.php` — add classmap entries for the 3 new MCP classes.
- `tests/php/bootstrap.php` — require the 3 new MCP class files.
- `client/settings/ai-storefront/endpoint-info.js` — add an `mcp_enabled` `CheckboxControl`.

**Create**

- `includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-server.php` — `WC_AI_Storefront_MCP_Server`: route registration, Origin + protocol-version validation, JSON-RPC dispatch, session lifecycle, gate + rate limit, response framing.
- `includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-tools.php` — `WC_AI_Storefront_MCP_Tools`: tool definitions (`tools/list`), argument → `$params` mapping, core-result → MCP-result mapping.
- `includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-session.php` — `WC_AI_Storefront_MCP_Session`: mint/validate `Mcp-Session-Id`, transient storage, gate logic against the handshake name.
- `tests/php/unit/McpSessionTest.php`, `tests/php/unit/McpToolsTest.php`, `tests/php/unit/McpServerTest.php`.

---

# PHASE 1 — Neutral seam for catalog (search + lookup)

Behavior-preserving refactor. No new feature behavior. Existing suite is the regression net; run it **in full** after every commit.

### Task 1.1: Add `get_currency_from_context()` and keep `get_request_currency()` as a wrapper

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php:5715-5729`

- [ ] **Step 1: Write the failing test**

Create `tests/php/unit/UcpNeutralCoresTest.php`:

```php
<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpNeutralCoresTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_currency_from_context_extracts_valid_iso4217(): void {
		$this->assertSame(
			'EUR',
			WC_AI_Storefront_UCP_REST_Controller::get_currency_from_context( [ 'currency' => 'eur' ] )
		);
	}

	public function test_get_currency_from_context_returns_null_for_gibberish(): void {
		$this->assertNull(
			WC_AI_Storefront_UCP_REST_Controller::get_currency_from_context( [ 'currency' => 'gibberish' ] )
		);
		$this->assertNull( WC_AI_Storefront_UCP_REST_Controller::get_currency_from_context( null ) );
	}
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `./vendor/bin/phpunit --filter UcpNeutralCoresTest tests/php/unit/UcpNeutralCoresTest.php`
Expected: FAIL — `Error: Call to undefined method ...::get_currency_from_context()`.

- [ ] **Step 3: Implement `get_currency_from_context` and re-point `get_request_currency`**

In the controller, locate `get_request_currency()` (lines 5715–5729). Extract its validation body into a new `public static` method that takes the context array, and make `get_request_currency` delegate:

```php
/**
 * Extract & validate an ISO-4217 currency from a UCP context array.
 * Transport-neutral: takes the already-decoded context, not a request.
 *
 * @param array|null $context UCP request context ( { currency?, ... } ).
 * @return string|null Uppercased 3-letter code, or null when absent/invalid.
 */
public static function get_currency_from_context( ?array $context ): ?string {
	$raw = is_array( $context ) ? ( $context['currency'] ?? null ) : null;
	if ( ! is_string( $raw ) ) {
		return null;
	}
	$code = strtoupper( trim( $raw ) );
	return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : null;
}

public static function get_request_currency( WP_REST_Request $request ): ?string {
	return self::get_currency_from_context( $request->get_param( 'context' ) );
}
```

> If the original `get_request_currency` validation differs (e.g. additional normalization), preserve it verbatim inside `get_currency_from_context` — copy the exact logic from lines 5716–5728, replacing only the `$request->get_param('context')` read with the `$context` parameter.

- [ ] **Step 4: Add the new test file to the test bootstrap if needed**

`UcpNeutralCoresTest` only needs the controller class, already required in `tests/php/bootstrap.php`. No bootstrap change.

- [ ] **Step 5: Run the new test and the full suite**

Run: `./vendor/bin/phpunit --filter UcpNeutralCoresTest tests/php/unit/UcpNeutralCoresTest.php` → PASS.
Then: `./vendor/bin/phpunit` → all green (full suite, per the call-graph-change rule).

- [ ] **Step 6: Lint + commit**

```bash
vendor/bin/phpcbf includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpNeutralCoresTest.php
vendor/bin/phpcs includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpNeutralCoresTest.php
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpNeutralCoresTest.php
git commit -m "refactor(ucp): add transport-neutral get_currency_from_context"
```

### Task 1.2: Param-ize `map_ucp_search_to_store_api()`

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php:4086-4487`

- [ ] **Step 1: Change the signature and the 5 input reads**

Change `map_ucp_search_to_store_api( WP_REST_Request $request )` to `map_ucp_search_to_store_api( array $params )`. Apply exactly these substitutions (leave every other line untouched):

| Line | Before | After |
|------|--------|-------|
| 4090 | `$query = $request->get_param( 'query' );` | `$query = $params['query'] ?? null;` |
| 4107 | `$pagination = $request->get_param( 'pagination' );` | `$pagination = $params['pagination'] ?? null;` |
| 4204 | `$sort = $request->get_param( 'sort' );` | `$sort = $params['sort'] ?? null;` |
| 4264 | `$filters = $request->get_param( 'filters' );` | `$filters = $params['filters'] ?? null;` |
| 4332 | `$context = $request->get_param( 'context' );` | `$context = $params['context'] ?? null;` |

- [ ] **Step 2: Update the call site in `handle_catalog_search` temporarily**

At line 713, change `self::map_ucp_search_to_store_api( $request )` to `self::map_ucp_search_to_store_api( [ 'query' => $request->get_param('query'), 'pagination' => $request->get_param('pagination'), 'sort' => $request->get_param('sort'), 'filters' => $request->get_param('filters'), 'context' => $request->get_param('context') ] )`. (This call site is replaced wholesale in Task 1.3; this interim keeps the suite green between commits.)

- [ ] **Step 3: Run the full suite**

Run: `./vendor/bin/phpunit` → all green. (If a search test fed a `WP_REST_Request` into `map_ucp_search_to_store_api` directly, update it to pass the array.)

- [ ] **Step 4: Lint + commit**

```bash
vendor/bin/phpcbf includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php
git commit -m "refactor(ucp): map_ucp_search_to_store_api takes params array"
```

### Task 1.3: Split `handle_catalog_search` into wrapper + `run_catalog_search`

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php:621-827`

- [ ] **Step 1: Rename the method body into the core**

Rename `handle_catalog_search` to `run_catalog_search` and change its signature to `public function run_catalog_search( array $params ): array`. Inside the (now) core, apply these substitutions:

| Line | Before | After |
|------|--------|-------|
| 630 | `$currency = self::get_request_currency( $request );` | `$currency = self::get_currency_from_context( $params['context'] ?? null );` |
| 665 | `$agent_data = self::resolve_agent_host( $request );` | `$agent_data = $params['agent_data'];` |
| ~669 | `$header = $request->get_header( 'ucp-agent' );` *(only if a direct header read exists in the body; the trace was ambiguous)* | `$header = $params['ucp_agent_header'] ?? '';` — if no such direct read exists, skip this row; the value is still available via the param. |
| 685 | `$signals = $request->get_param( 'signals' );` | `$signals = $params['signals'] ?? null;` |
| 702 | `$request_body = $request->get_json_params() ?? [];` | `$request_body = $params['json_body'] ?? [];` |
| 713 | `$api_params = self::map_ucp_search_to_store_api( $request );` | `$api_params = self::map_ucp_search_to_store_api( $params );` |

Then replace **every** return statement's `new WP_REST_Response( $X, $status )` with `return [ 'body' => $X, 'status' => $status ];`:
- line 668 (503), line 754 (500), line 755 (200), line 756 (503).

- [ ] **Step 2: Add the thin wrapper**

Immediately above `run_catalog_search`, add:

```php
public function handle_catalog_search( WP_REST_Request $request ) {
	$params = [
		'query'            => $request->get_param( 'query' ),
		'context'          => $request->get_param( 'context' ),
		'signals'          => $request->get_param( 'signals' ),
		'filters'          => $request->get_param( 'filters' ),
		'pagination'       => $request->get_param( 'pagination' ),
		'sort'             => $request->get_param( 'sort' ),
		'agent_data'       => self::resolve_agent_host( $request ),
		'ucp_agent_header' => (string) $request->get_header( 'ucp-agent' ),
		'json_body'        => (array) ( $request->get_json_params() ?? [] ),
	];
	$result = $this->run_catalog_search( $params );
	return new WP_REST_Response( $result['body'], $result['status'] );
}
```

- [ ] **Step 3: Run the full suite**

Run: `./vendor/bin/phpunit` → all green. The `register_routes` callback still points at `[$this,'handle_catalog_search']`, which now delegates. Route-registration tests pass unchanged.

- [ ] **Step 4: Add a neutral-core regression test**

Append to `tests/php/unit/UcpNeutralCoresTest.php`:

```php
public function test_run_catalog_search_returns_503_body_when_syndication_disabled(): void {
	Functions\when( 'get_option' )->justReturn( [ 'enabled' => 'no' ] );
	// Stub any other functions the early-return path touches (e.g. apply_filters → returnArg).
	Functions\when( 'apply_filters' )->returnArg( 1 );

	$controller = new WC_AI_Storefront_UCP_REST_Controller();
	$result     = $controller->run_catalog_search( [
		'agent_data'       => [ 'name' => 'gibberish', 'raw_host' => '', 'source_host' => '' ],
		'ucp_agent_header' => '',
		'json_body'        => [],
	] );

	$this->assertSame( 503, $result['status'] );
	$this->assertIsArray( $result['body'] );
}
```

> If `WC_AI_Storefront::get_settings()` reads the option via a different function than `get_option`, stub that one instead — match whatever `is_syndication_disabled()` calls. Verify by reading the early-return path (lines 660–668) before writing the stub.

- [ ] **Step 5: Run the test + full suite, then lint + commit**

```bash
./vendor/bin/phpunit --filter UcpNeutralCoresTest tests/php/unit/UcpNeutralCoresTest.php
./vendor/bin/phpunit
vendor/bin/phpcbf includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpNeutralCoresTest.php
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpNeutralCoresTest.php
git commit -m "refactor(ucp): extract run_catalog_search neutral core"
```

### Task 1.4: Split `handle_catalog_lookup` into wrapper + `run_catalog_lookup`

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php:1713-2228`

- [ ] **Step 1: Rename body into core + substitute reads**

Rename `handle_catalog_lookup` → `run_catalog_lookup( array $params ): array`. Substitutions:

| Line | Before | After |
|------|--------|-------|
| 1739 | `$agent_data = self::resolve_agent_host( $request );` | `$agent_data = $params['agent_data'];` |
| ~1743 | `$header = $request->get_header( 'ucp-agent' );` *(only if a direct header read exists in the body; the trace was ambiguous)* | `$header = $params['ucp_agent_header'] ?? '';` — if no such direct read exists, skip this row. |
| 1757 | `$signals = $request->get_param( 'signals' );` | `$signals = $params['signals'] ?? null;` |
| 1765 | `$ids = $request->get_param( 'ids' );` | `$ids = $params['ids'] ?? null;` |
| 1827 | `$currency = self::get_request_currency( $request );` | `$currency = self::get_currency_from_context( $params['context'] ?? null );` |

Replace return statements: line 1734 (503), line 2226 (error), line 2227 (200) → `return [ 'body' => $X, 'status' => $status ];`.

- [ ] **Step 2: Add the thin wrapper**

```php
public function handle_catalog_lookup( WP_REST_Request $request ) {
	$params = [
		'ids'              => $request->get_param( 'ids' ),
		'context'          => $request->get_param( 'context' ),
		'signals'          => $request->get_param( 'signals' ),
		'agent_data'       => self::resolve_agent_host( $request ),
		'ucp_agent_header' => (string) $request->get_header( 'ucp-agent' ),
		'json_body'        => (array) ( $request->get_json_params() ?? [] ),
	];
	$result = $this->run_catalog_lookup( $params );
	return new WP_REST_Response( $result['body'], $result['status'] );
}
```

- [ ] **Step 3: Run the full suite** → all green.

- [ ] **Step 4: Lint + commit**

```bash
vendor/bin/phpcbf includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php
git commit -m "refactor(ucp): extract run_catalog_lookup neutral core"
```

---

# PHASE 2 — Neutral seam for checkout

### Task 2.1: Split `handle_checkout_sessions_create` into wrapper + `run_checkout_create`

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php:2358-3035`

- [ ] **Step 1: Rename body into core + substitute reads**

Rename `handle_checkout_sessions_create` → `run_checkout_create( array $params ): array`. Substitutions:

| Line | Before | After |
|------|--------|-------|
| 2372 | `$line_items_raw = $request->get_param( 'line_items' );` | `$line_items_raw = $params['line_items'] ?? null;` |
| 2394 | `$agent_data = self::resolve_agent_host( $request );` | `$agent_data = $params['agent_data'];` |
| 2413 | `$context = $request->get_param( 'context' );` | `$context = $params['context'] ?? null;` |
| 2430 | `$request_currency = self::get_request_currency( $request );` | `$request_currency = self::get_currency_from_context( $params['context'] ?? null );` |

Line 2415's `context['locale']` read already operates on the `$context` variable set at 2413 — no change beyond the 2413 substitution. Replace returns: `self::ucp_checkout_error_response(...)` paths at 2364/2375/2383 already return arrays-with-status via that helper — **leave those untouched if `ucp_checkout_error_response` returns a `WP_REST_Response`**; otherwise wrap. Check the helper's return type first:
  - If it returns `WP_REST_Response`: change these three `return self::ucp_checkout_error_response( $msg, $code, $status );` to `$resp = self::ucp_checkout_error_response(...); return [ 'body' => $resp->get_data(), 'status' => $resp->get_status() ];` — OR refactor `ucp_checkout_error_response` to return `['body'=>..,'status'=>..]` and have the wrapper build the response. Prefer the latter for consistency; update its other callers accordingly and run the full suite.
  - The success return at 3031–3034 `new WP_REST_Response( $response_body, $should_redirect ? 201 : 200 )` → `return [ 'body' => $response_body, 'status' => $should_redirect ? 201 : 200 ];`.

- [ ] **Step 2: Add the thin wrapper**

```php
public function handle_checkout_sessions_create( WP_REST_Request $request ) {
	$params = [
		'line_items'       => $request->get_param( 'line_items' ),
		'context'          => $request->get_param( 'context' ),
		'agent_data'       => self::resolve_agent_host( $request ),
		'ucp_agent_header' => (string) $request->get_header( 'ucp-agent' ),
		'json_body'        => (array) ( $request->get_json_params() ?? [] ),
	];
	$result = $this->run_checkout_create( $params );
	return new WP_REST_Response( $result['body'], $result['status'] );
}
```

- [ ] **Step 3: Run the full suite** → all green.

- [ ] **Step 4: Add neutral-core error-path test**

Append to `tests/php/unit/UcpNeutralCoresTest.php` a test that `run_checkout_create` with empty `line_items` returns `status === 400` (stub `get_settings` to `enabled=yes` so it passes the syndication gate and reaches the line-items validation at 2375). Mirror the stub style of the existing search test; assert `$result['status'] === 400`.

- [ ] **Step 5: Run test + full suite, lint + commit**

```bash
./vendor/bin/phpunit
vendor/bin/phpcbf includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpNeutralCoresTest.php
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpNeutralCoresTest.php
git commit -m "refactor(ucp): extract run_checkout_create neutral core"
```

---

# PHASE 3 — MCP transport layer

Net-new code. Full TDD. None of this requires WooCommerce/DB — JSON-RPC dispatch, sessions, and the gate are testable with Brain Monkey stubs.

### Task 3.1: `WC_AI_Storefront_MCP_Session` — mint/validate sessions + gate

**Files:**
- Create: `includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-session.php`
- Modify: `includes/autoload.php`, `tests/php/bootstrap.php`
- Test: `tests/php/unit/McpSessionTest.php`

- [ ] **Step 1: Register autoload + bootstrap require**

In `includes/autoload.php` add to `$classmap`:
```php
'WC_AI_Storefront_MCP_Session' => '/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-session.php',
```
In `tests/php/bootstrap.php` (the manual require block, ~lines 46–84) add:
```php
require_once __DIR__ . '/../../includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-session.php';
```

- [ ] **Step 2: Write the failing test**

Create `tests/php/unit/McpSessionTest.php`:

```php
<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class McpSessionTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_generate_uuid4' )->justReturn( '11111111-2222-4333-8444-555555555555' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_gate_denies_unknown_agent_when_unknowns_disallowed(): void {
		$settings = [ 'allow_unknown_ucp_agents' => 'no', 'allowed_crawlers' => [] ];
		$result   = WC_AI_Storefront_MCP_Session::gate_client_name( 'gibberish-client', $settings );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_gate_allows_unknown_agent_when_unknowns_allowed(): void {
		$settings = [ 'allow_unknown_ucp_agents' => 'yes', 'allowed_crawlers' => [] ];
		$result   = WC_AI_Storefront_MCP_Session::gate_client_name( 'gibberish-client', $settings );
		$this->assertIsString( $result ); // returns the canonical name on allow
	}

	public function test_start_then_validate_round_trips_client_name(): void {
		$store = [];
		Functions\when( 'set_transient' )->alias(
			static function ( $k, $v ) use ( &$store ) { $store[ $k ] = $v; return true; }
		);
		Functions\when( 'get_transient' )->alias(
			static function ( $k ) use ( &$store ) { return $store[ $k ] ?? false; }
		);

		$id = WC_AI_Storefront_MCP_Session::start( 'Other AI' );
		$this->assertNotSame( '', $id );
		$this->assertSame( 'Other AI', WC_AI_Storefront_MCP_Session::client_name_for( $id ) );
		$this->assertNull( WC_AI_Storefront_MCP_Session::client_name_for( 'no-such-id' ) );
	}
}
```

- [ ] **Step 3: Run it and verify it fails**

Run: `./vendor/bin/phpunit --filter McpSessionTest tests/php/unit/McpSessionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Implement the class**

```php
<?php
/**
 * MCP transport: ephemeral session + handshake-identity gate.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mints and validates Mcp-Session-Id values and gates handshake client names
 * against the merchant's UCP allow-list. Sessions exist only to carry the
 * vetted client identity across stateless HTTP requests.
 */
class WC_AI_Storefront_MCP_Session {

	const TRANSIENT_PREFIX = 'wc_ai_mcp_sess_';
	const TTL_SECONDS      = 900; // 15 minutes; tune per open question #3.

	/**
	 * Resolve + gate a free-form handshake client name against settings.
	 *
	 * @param string $client_name MCP initialize clientInfo.name.
	 * @param array  $settings    WC_AI_Storefront::get_settings().
	 * @return string|WP_Error Canonical name on allow; WP_Error(403) on deny.
	 */
	public static function gate_client_name( string $client_name, array $settings ) {
		$normalized = strtolower( trim( $client_name ) );
		$canonical  = WC_AI_Storefront_UCP_Agent_Header::canonicalize_product( $normalized );
		if ( WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET === $canonical ) {
			$canonical = WC_AI_Storefront_UCP_Agent_Header::canonicalize_host( $normalized );
		}

		if ( WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET === $canonical ) {
			$allow_unknown = isset( $settings['allow_unknown_ucp_agents'] )
				&& 'yes' === $settings['allow_unknown_ucp_agents'];
			if ( ! $allow_unknown ) {
				return new WP_Error(
					'mcp_agent_unknown_blocked',
					__( 'Unknown agent blocked.', 'woocommerce-ai-storefront' ),
					[ 'status' => 403 ]
				);
			}
		}

		$allowed = WC_AI_Storefront_Robots::resolve_allowed_crawlers( $settings );
		if ( ! WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed( $canonical, $allowed ) ) {
			return new WP_Error(
				'mcp_agent_blocked',
				__( 'This agent is not allowed.', 'woocommerce-ai-storefront' ),
				[ 'status' => 403 ]
			);
		}

		return $canonical;
	}

	/**
	 * Mint a session for a vetted canonical client name.
	 *
	 * @param string $canonical_name Canonical client name.
	 * @return string The new Mcp-Session-Id.
	 */
	public static function start( string $canonical_name ): string {
		$id = (string) wp_generate_uuid4();
		set_transient( self::TRANSIENT_PREFIX . $id, $canonical_name, self::TTL_SECONDS );
		return $id;
	}

	/**
	 * Look up the client name behind a session id.
	 *
	 * @param string $session_id Mcp-Session-Id header value.
	 * @return string|null Client name, or null when absent/expired.
	 */
	public static function client_name_for( string $session_id ): ?string {
		if ( '' === $session_id ) {
			return null;
		}
		$value = get_transient( self::TRANSIENT_PREFIX . $session_id );
		return is_string( $value ) ? $value : null;
	}
}
```

- [ ] **Step 5: Run the test + full suite**

Run: `./vendor/bin/phpunit --filter McpSessionTest tests/php/unit/McpSessionTest.php` → PASS.
Run: `./vendor/bin/phpunit` → all green.

- [ ] **Step 6: Lint + commit**

```bash
vendor/bin/phpcbf includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-session.php includes/autoload.php tests/php/unit/McpSessionTest.php
git add includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-session.php includes/autoload.php tests/php/bootstrap.php tests/php/unit/McpSessionTest.php
git commit -m "feat(mcp): add MCP_Session with handshake-identity gate"
```

### Task 3.2: `WC_AI_Storefront_MCP_Tools` — definitions + mapping

**Files:**
- Create: `includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-tools.php`
- Modify: `includes/autoload.php`, `tests/php/bootstrap.php`
- Test: `tests/php/unit/McpToolsTest.php`

- [ ] **Step 1: Register autoload + bootstrap require**

Add classmap entry `'WC_AI_Storefront_MCP_Tools' => '/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-tools.php',` and the matching `require_once` in `tests/php/bootstrap.php`.

- [ ] **Step 2: Write the failing test**

Create `tests/php/unit/McpToolsTest.php`:

```php
<?php

use Brain\Monkey;
use Brain\Monkey\Functions;

class McpToolsTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_definitions_expose_three_tools_with_input_schemas(): void {
		$defs   = WC_AI_Storefront_MCP_Tools::definitions();
		$names  = array_column( $defs, 'name' );
		sort( $names );
		$this->assertSame( [ 'catalog_lookup', 'catalog_search', 'checkout_create' ], $names );
		foreach ( $defs as $def ) {
			$this->assertArrayHasKey( 'description', $def );
			$this->assertSame( 'object', $def['inputSchema']['type'] );
		}
	}

	public function test_lookup_requires_ids(): void {
		$def = self::def_for( 'catalog_lookup' );
		$this->assertContains( 'ids', $def['inputSchema']['required'] );
	}

	public function test_success_core_result_maps_to_structured_content(): void {
		$mcp = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			[ 'body' => [ 'ok' => true ], 'status' => 200 ],
			'Found products'
		);
		$this->assertArrayNotHasKey( 'isError', $mcp );
		$this->assertSame( [ 'ok' => true ], $mcp['structuredContent'] );
		$this->assertSame( 'text', $mcp['content'][0]['type'] );
	}

	public function test_error_core_result_maps_to_iserror(): void {
		$mcp = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			[ 'body' => [ 'error' => [ 'code' => 'ucp_disabled', 'message' => 'Off' ] ], 'status' => 503 ],
			'Search'
		);
		$this->assertTrue( $mcp['isError'] );
		$this->assertStringContainsString( 'ucp_disabled', $mcp['content'][0]['text'] );
	}

	private static function def_for( string $name ): array {
		foreach ( WC_AI_Storefront_MCP_Tools::definitions() as $d ) {
			if ( $d['name'] === $name ) {
				return $d;
			}
		}
		throw new \RuntimeException( 'missing tool' );
	}
}
```

- [ ] **Step 3: Run it and verify it fails**

Run: `./vendor/bin/phpunit --filter McpToolsTest tests/php/unit/McpToolsTest.php` → FAIL (class not found).

- [ ] **Step 4: Implement the class**

```php
<?php
/**
 * MCP transport: tool catalog + argument/result mapping.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Declares the three shopping tools (mirroring the UCP REST route args) and
 * maps MCP tool arguments → neutral-core $params and core results → MCP results.
 */
class WC_AI_Storefront_MCP_Tools {

	/**
	 * tools/list payload. inputSchemas mirror the wc/ucp/v1 route args.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		return [
			[
				'name'        => 'catalog_search',
				'description' => __( 'Search the store catalog. Returns UCP products.', 'woocommerce-ai-storefront' ),
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'query'      => [ 'type' => 'string' ],
						'context'    => [ 'type' => 'object' ],
						'signals'    => [ 'type' => 'object' ],
						'filters'    => [ 'type' => 'object' ],
						'pagination' => [ 'type' => 'object' ],
						'sort'       => [ 'type' => 'object' ],
					],
				],
			],
			[
				'name'        => 'catalog_lookup',
				'description' => __( 'Look up specific products by id. Max 100 ids.', 'woocommerce-ai-storefront' ),
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'ids'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'context' => [ 'type' => 'object' ],
						'signals' => [ 'type' => 'object' ],
					],
					'required'   => [ 'ids' ],
				],
			],
			[
				'name'        => 'checkout_create',
				'description' => __( 'Create a stateless checkout session and get a continue_url.', 'woocommerce-ai-storefront' ),
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'line_items' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
						'context'    => [ 'type' => 'object' ],
					],
					'required'   => [ 'line_items' ],
				],
			],
		];
	}

	/**
	 * Dispatch a tool call to its neutral core and return an MCP tool result.
	 *
	 * @param string $tool_name    One of catalog_search|catalog_lookup|checkout_create.
	 * @param array  $arguments    Validated tool arguments.
	 * @param string $client_name  Canonical agent name from the session.
	 * @return array|WP_Error MCP tools/call result, or WP_Error for unknown tool.
	 */
	public static function call( string $tool_name, array $arguments, string $client_name ) {
		$agent_data = [ 'name' => $client_name, 'raw_host' => $client_name, 'source_host' => '' ];
		$base       = [
			'agent_data'       => $agent_data,
			'ucp_agent_header' => '',
			'json_body'        => $arguments,
		];
		$controller = new WC_AI_Storefront_UCP_REST_Controller();

		switch ( $tool_name ) {
			case 'catalog_search':
				$params = array_merge( $base, [
					'query'      => $arguments['query'] ?? null,
					'context'    => $arguments['context'] ?? null,
					'signals'    => $arguments['signals'] ?? null,
					'filters'    => $arguments['filters'] ?? null,
					'pagination' => $arguments['pagination'] ?? null,
					'sort'       => $arguments['sort'] ?? null,
				] );
				return self::core_result_to_mcp( $controller->run_catalog_search( $params ), __( 'Catalog search', 'woocommerce-ai-storefront' ) );

			case 'catalog_lookup':
				$params = array_merge( $base, [
					'ids'     => $arguments['ids'] ?? [],
					'context' => $arguments['context'] ?? null,
					'signals' => $arguments['signals'] ?? null,
				] );
				return self::core_result_to_mcp( $controller->run_catalog_lookup( $params ), __( 'Catalog lookup', 'woocommerce-ai-storefront' ) );

			case 'checkout_create':
				$params = array_merge( $base, [
					'line_items' => $arguments['line_items'] ?? [],
					'context'    => $arguments['context'] ?? null,
				] );
				return self::core_result_to_mcp( $controller->run_checkout_create( $params ), __( 'Checkout', 'woocommerce-ai-storefront' ) );

			default:
				return new WP_Error( 'mcp_unknown_tool', __( 'Unknown tool.', 'woocommerce-ai-storefront' ) );
		}
	}

	/**
	 * Map a neutral-core ['body','status'] result to an MCP tools/call result.
	 *
	 * @param array  $result  ['body'=>array,'status'=>int].
	 * @param string $summary One-line summary label for the text block.
	 * @return array MCP tool result.
	 */
	public static function core_result_to_mcp( array $result, string $summary ): array {
		$status = (int) ( $result['status'] ?? 200 );
		$body   = is_array( $result['body'] ?? null ) ? $result['body'] : [];

		if ( $status >= 400 ) {
			$code    = $body['error']['code'] ?? 'error';
			$message = $body['error']['message'] ?? '';
			return [
				'isError' => true,
				'content' => [ [ 'type' => 'text', 'text' => trim( $code . ' ' . $message ) ] ],
			];
		}

		return [
			'content'           => [ [ 'type' => 'text', 'text' => $summary ] ],
			'structuredContent' => $body,
		];
	}
}
```

- [ ] **Step 5: Run test + full suite, lint + commit**

```bash
./vendor/bin/phpunit --filter McpToolsTest tests/php/unit/McpToolsTest.php
./vendor/bin/phpunit
vendor/bin/phpcbf includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-tools.php includes/autoload.php tests/php/unit/McpToolsTest.php
git add includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-tools.php includes/autoload.php tests/php/bootstrap.php tests/php/unit/McpToolsTest.php
git commit -m "feat(mcp): add MCP_Tools definitions + argument/result mapping"
```

### Task 3.3: `WC_AI_Storefront_MCP_Server` — JSON-RPC dispatch + route

**Files:**
- Create: `includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-server.php`
- Modify: `includes/autoload.php`, `tests/php/bootstrap.php`
- Test: `tests/php/unit/McpServerTest.php`

- [ ] **Step 1: Register autoload + bootstrap require**

Add `'WC_AI_Storefront_MCP_Server' => '/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-server.php',` and the matching `require_once` in `tests/php/bootstrap.php`.

- [ ] **Step 2: Write the failing test**

Create `tests/php/unit/McpServerTest.php`. Cover: route registration shape; `initialize` returns serverInfo + capabilities and a session header on allow; `initialize` 403 on a blocked agent; missing session on `tools/list` → 400; unknown session → 404; `tools/list` returns 3 tools; unknown method → JSON-RPC -32601; `notifications/initialized` → 202; disabled feature → 404.

```php
<?php

use Brain\Monkey;
use Brain\Monkey\Functions;

class McpServerTest extends \PHPUnit\Framework\TestCase {

	private array $routes = [];
	private array $store  = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'sess-aaaa' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.example' );

		$store = &$this->store;
		Functions\when( 'set_transient' )->alias( static function ( $k, $v ) use ( &$store ) { $store[ $k ] = $v; return true; } );
		Functions\when( 'get_transient' )->alias( static function ( $k ) use ( &$store ) { return $store[ $k ] ?? false; } );

		$routes = &$this->routes;
		Functions\when( 'register_rest_route' )->alias(
			static function ( $ns, $route, $args ) use ( &$routes ) { $routes[] = compact( 'ns', 'route', 'args' ); return true; }
		);
		// Feature enabled by default for most tests.
		Functions\when( 'get_option' )->justReturn( [ 'enabled' => 'yes', 'mcp_enabled' => 'yes', 'allow_unknown_ucp_agents' => 'yes', 'allowed_crawlers' => [] ] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function dispatch( array $rpc, array $headers = [] ): WP_REST_Response {
		$req = new WP_REST_Request();
		$req->set_body( wp_json_encode( $rpc ) );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		return ( new WC_AI_Storefront_MCP_Server() )->handle( $req );
	}

	public function test_registers_mcp_route(): void {
		( new WC_AI_Storefront_MCP_Server() )->register_routes();
		$paths = array_column( $this->routes, 'route' );
		$this->assertContains( '/mcp', $paths );
	}

	public function test_initialize_allows_and_sets_session_header(): void {
		$resp = $this->dispatch( [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
		] );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertNotEmpty( $resp->get_headers()['Mcp-Session-Id'] ?? '' );
		$data = $resp->get_data();
		$this->assertSame( 'dev.ucp.shopping', $data['result']['serverInfo']['name'] );
	}

	public function test_tools_list_requires_session(): void {
		$resp = $this->dispatch( [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ] );
		$this->assertSame( 400, $resp->get_status() );
	}

	public function test_unknown_session_returns_404(): void {
		$resp = $this->dispatch(
			[ 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list' ],
			[ 'Mcp-Session-Id' => 'nope', 'MCP-Protocol-Version' => '2025-06-18' ]
		);
		$this->assertSame( 404, $resp->get_status() );
	}

	public function test_disabled_feature_returns_404(): void {
		Functions\when( 'get_option' )->justReturn( [ 'enabled' => 'no' ] );
		$resp = $this->dispatch( [ 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'initialize', 'params' => [] ] );
		$this->assertSame( 404, $resp->get_status() );
	}
}
```

> `WC_AI_Storefront::get_settings()` must resolve to the stubbed option. Confirm which function `get_settings()` calls (likely `get_option('wc_ai_storefront_settings', ...)`) and stub that exact function; adjust the `get_option` alias to key on the option name if `get_settings` passes defaults.

- [ ] **Step 3: Run it and verify it fails** → FAIL (class not found).

- [ ] **Step 4: Implement the class**

```php
<?php
/**
 * MCP transport: Streamable-HTTP JSON-RPC server for UCP shopping tools.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * A single POST+GET endpoint at wc/ucp/v1/mcp speaking MCP JSON-RPC 2.0.
 * Responds with application/json (no SSE). Required-session mode: identity is
 * vetted at initialize, carried via Mcp-Session-Id, re-checked per tool call.
 */
class WC_AI_Storefront_MCP_Server {

	const LATEST_PROTOCOL    = '2025-06-18';
	const FALLBACK_PROTOCOL  = '2025-03-26';
	const SUPPORTED          = [ '2025-06-18', '2025-03-26' ];

	/**
	 * Register the MCP endpoint (POST does work; GET returns 405 — no SSE).
	 */
	public function register_routes(): void {
		register_rest_route(
			'wc/ucp/v1',
			'/mcp',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'handle' ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'handle_get' ],
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	/**
	 * GET on the MCP endpoint: we offer no SSE stream.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_get(): WP_REST_Response {
		return new WP_REST_Response( null, 405 );
	}

	/**
	 * Main POST handler.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) || 'yes' !== ( $settings['mcp_enabled'] ?? 'no' ) ) {
			return new WP_REST_Response( null, 404 );
		}

		// Origin validation (MUST). Allow absent Origin (server-to-server);
		// reject a present Origin whose host differs from the site host.
		$origin = (string) $request->get_header( 'origin' );
		if ( '' !== $origin && ! $this->origin_matches_site( $origin ) ) {
			return new WP_REST_Response( null, 403 );
		}

		$rpc = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $rpc ) || ! isset( $rpc['method'] ) ) {
			return $this->rpc_error( null, -32700, 'Parse error', 400 );
		}
		$id     = $rpc['id'] ?? null;
		$method = (string) $rpc['method'];
		$params = is_array( $rpc['params'] ?? null ) ? $rpc['params'] : [];

		if ( 'initialize' === $method ) {
			return $this->do_initialize( $id, $params, $settings );
		}

		// Protocol version header required on post-init requests.
		$version = (string) $request->get_header( 'mcp-protocol-version' );
		if ( '' === $version ) {
			$version = self::FALLBACK_PROTOCOL;
		}
		if ( ! in_array( $version, self::SUPPORTED, true ) ) {
			return new WP_REST_Response( null, 400 );
		}

		// Session required on everything except initialize.
		$session_id = (string) $request->get_header( 'mcp-session-id' );
		if ( '' === $session_id ) {
			return new WP_REST_Response( null, 400 );
		}
		$client_name = WC_AI_Storefront_MCP_Session::client_name_for( $session_id );
		if ( null === $client_name ) {
			return new WP_REST_Response( null, 404 );
		}

		// Per-call re-gate so mid-session crawler removal revokes promptly.
		$regate = WC_AI_Storefront_MCP_Session::gate_client_name( $client_name, $settings );
		if ( is_wp_error( $regate ) ) {
			return new WP_REST_Response( null, 403 );
		}

		// Rate limit (reuses the UCP outer limiter; IP-keyed for MCP).
		$rl = WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();
		if ( is_wp_error( $rl ) ) {
			return new WP_REST_Response( null, 429 );
		}

		switch ( $method ) {
			case 'notifications/initialized':
				return new WP_REST_Response( null, 202 );

			case 'ping':
				return $this->rpc_result( $id, (object) [] );

			case 'tools/list':
				return $this->rpc_result( $id, [ 'tools' => WC_AI_Storefront_MCP_Tools::definitions() ] );

			case 'tools/call':
				$name = (string) ( $params['name'] ?? '' );
				$args = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [];
				$result = WC_AI_Storefront_MCP_Tools::call( $name, $args, $client_name );
				if ( is_wp_error( $result ) ) {
					return $this->rpc_error( $id, -32602, $result->get_error_message(), 200 );
				}
				return $this->rpc_result( $id, $result );

			default:
				return $this->rpc_error( $id, -32601, 'Method not found', 200 );
		}
	}

	/**
	 * Handle the initialize handshake: gate identity, mint a session.
	 *
	 * @param mixed $id       JSON-RPC id.
	 * @param array $params   initialize params.
	 * @param array $settings Merchant settings.
	 * @return WP_REST_Response
	 */
	private function do_initialize( $id, array $params, array $settings ): WP_REST_Response {
		$client_name = (string) ( $params['clientInfo']['name'] ?? '' );
		$gated       = WC_AI_Storefront_MCP_Session::gate_client_name( $client_name, $settings );
		if ( is_wp_error( $gated ) ) {
			return new WP_REST_Response( null, 403 );
		}

		$session_id = WC_AI_Storefront_MCP_Session::start( $gated );
		$response   = $this->rpc_result(
			$id,
			[
				'protocolVersion' => self::LATEST_PROTOCOL,
				'capabilities'    => [ 'tools' => (object) [] ],
				'serverInfo'      => [
					'name'    => 'dev.ucp.shopping',
					'version' => defined( 'WC_AI_STOREFRONT_VERSION' ) ? WC_AI_STOREFRONT_VERSION : '0',
				],
			]
		);
		$response->header( 'Mcp-Session-Id', $session_id );
		return $response;
	}

	/**
	 * True when an Origin header's host equals the site host.
	 *
	 * @param string $origin Origin header value.
	 * @return bool
	 */
	private function origin_matches_site( string $origin ): bool {
		$o = wp_parse_url( $origin, PHP_URL_HOST );
		$s = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $o ) && is_string( $s ) && strtolower( $o ) === strtolower( $s );
	}

	/**
	 * Build a JSON-RPC success response.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param mixed $result Result payload.
	 * @return WP_REST_Response
	 */
	private function rpc_result( $id, $result ): WP_REST_Response {
		return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ], 200 );
	}

	/**
	 * Build a JSON-RPC error response with an HTTP status.
	 *
	 * @param mixed  $id      JSON-RPC id.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status.
	 * @return WP_REST_Response
	 */
	private function rpc_error( $id, int $code, string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			[ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ],
			$status
		);
	}
}
```

> If the stub `WP_REST_Request` lacks `set_body`/`get_body`/`set_header`/`get_header`/`get_headers` or `WP_REST_Response` lacks `header()`/`get_headers()`, extend the stubs in `tests/php/stubs.php` to add them (minimal getters/setters over an internal array). Do this as part of Step 4 if Step 3's failure is a stub method error rather than "class not found".

- [ ] **Step 5: Run the test + full suite** until green. Add any missing stub methods, then re-run.

- [ ] **Step 6: Lint + commit**

```bash
vendor/bin/phpcbf includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-server.php includes/autoload.php tests/php/unit/McpServerTest.php tests/php/stubs.php
git add includes/ai-storefront/ucp-mcp/class-wc-ai-storefront-mcp-server.php includes/autoload.php tests/php/bootstrap.php tests/php/unit/McpServerTest.php tests/php/stubs.php
git commit -m "feat(mcp): add MCP_Server JSON-RPC dispatch + endpoint"
```

### Task 3.4: Wire the MCP server into bootstrap

**Files:**
- Modify: `includes/class-wc-ai-storefront.php:305-316`

- [ ] **Step 1: Instantiate + register in `register_rest_routes()`**

After the UCP REST controller registration, add:

```php
$mcp_server = new WC_AI_Storefront_MCP_Server();
$mcp_server->register_routes();
```

- [ ] **Step 2: Run the full suite** → green. (If a bootstrap-level test asserts the count of registered routes for the plugin, update the expected count.)

- [ ] **Step 3: Commit**

```bash
git add includes/class-wc-ai-storefront.php
git commit -m "feat(mcp): register MCP server route on rest_api_init"
```

### Task 3.5: Add the `mcp_enabled` setting (default + write path + schema)

**Files:**
- Modify: `includes/class-wc-ai-storefront.php` (`settings_defaults()` ~42–81; `update_settings()` ~575–664)
- Modify: `includes/admin/class-wc-ai-storefront-admin-controller.php` (args schema; `$fields` line 401)

- [ ] **Step 1: Write the failing test**

Add to `tests/php/unit/UcpNeutralCoresTest.php` (or a settings test) a check that the defaults include `mcp_enabled` and that an invalid value is coerced. If `settings_defaults()` is private, assert via `get_settings()` with the option unset (stub `get_option` to return `false`) that `mcp_enabled` resolves to its default.

```php
public function test_mcp_enabled_default_present(): void {
	Functions\when( 'get_option' )->justReturn( false );
	$settings = WC_AI_Storefront::get_settings();
	$this->assertArrayHasKey( 'mcp_enabled', $settings );
}
```

- [ ] **Step 2: Run it** → FAIL (key missing).

- [ ] **Step 3: Implement**

In `settings_defaults()` add `'mcp_enabled' => 'yes',` (active by default when syndication is enabled; the server also checks `enabled`).
In `update_settings()` add a yes/no validation mirroring lines 605–609:
```php
$mcp_enabled = $merged['mcp_enabled'] ?? 'yes';
if ( ! in_array( $mcp_enabled, [ 'yes', 'no' ], true ) ) {
	$mcp_enabled = 'yes';
}
$merged['mcp_enabled'] = $mcp_enabled;
```
In the admin controller's settings args schema add:
```php
'mcp_enabled' => array(
	'type' => 'string',
	'enum' => array( 'yes', 'no' ),
),
```
And add `'mcp_enabled'` to the `$fields` array at line 401.

- [ ] **Step 4: Run the test + full suite** → green.

- [ ] **Step 5: Lint + commit**

```bash
vendor/bin/phpcbf includes/class-wc-ai-storefront.php includes/admin/class-wc-ai-storefront-admin-controller.php
git add includes/class-wc-ai-storefront.php includes/admin/class-wc-ai-storefront-admin-controller.php tests/php/unit/UcpNeutralCoresTest.php
git commit -m "feat(mcp): add mcp_enabled setting (default yes)"
```

### Task 3.6: Advertise the MCP transport in the discovery manifest

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-ucp.php` (`generate_manifest()` ~274–284)

- [ ] **Step 1: Write the failing test**

If a manifest test exists (search `tests/php/unit` for a Ucp/manifest test), add a case asserting the `dev.ucp.shopping` service array contains a binding with `transport === 'mcp'`. Otherwise create `tests/php/unit/UcpManifestMcpTest.php` that stubs `rest_url`/`home_url`/`get_option`/currency helpers and calls `generate_manifest( [ 'enabled' => 'yes' ] )`, asserting:

```php
$service = $manifest['ucp']['services']['dev.ucp.shopping'];
$transports = array_column( $service, 'transport' );
$this->assertContains( 'mcp', $transports );
$this->assertContains( 'rest', $transports );
```

- [ ] **Step 2: Run it** → FAIL.

- [ ] **Step 3: Implement**

In the `services` array, after the existing `transport => 'rest'` binding, add a second binding object:

```php
[
	'version'   => self::PROTOCOL_VERSION,
	'transport' => 'mcp',
	'endpoint'  => rest_url( 'wc/ucp/v1/mcp' ),
],
```

- [ ] **Step 4: Run the test + full suite** → green.

- [ ] **Step 5: Lint + commit**

```bash
vendor/bin/phpcbf includes/ai-storefront/class-wc-ai-storefront-ucp.php
git add includes/ai-storefront/class-wc-ai-storefront-ucp.php tests/php/unit/UcpManifestMcpTest.php
git commit -m "feat(mcp): advertise MCP transport in /.well-known/ucp manifest"
```

### Task 3.7: Add the `mcp_enabled` toggle to the settings UI

**Files:**
- Modify: `client/settings/ai-storefront/endpoint-info.js` (near the `allow_unknown_ucp_agents` `CheckboxControl`, ~2392–2408)

- [ ] **Step 1: Add the control**

Mirror the existing `CheckboxControl` pattern:

```jsx
<CheckboxControl
	label={ __( 'Enable MCP transport for agents', 'woocommerce-ai-storefront' ) }
	checked={ settings.mcp_enabled === 'yes' }
	onChange={ ( checked ) =>
		onChange( { mcp_enabled: checked ? 'yes' : 'no' } )
	}
	__nextHasNoMarginBottom
/>
```

Add a short `<p>` description above it matching the muted-text style used for the sibling toggle (explain that it exposes the same catalog/checkout capabilities over MCP).

- [ ] **Step 2: Lint JS + build**

Run: `npm run lint:js` (fix any issues), then `npm run build`.

- [ ] **Step 3: Commit**

```bash
git add client/settings/ai-storefront/endpoint-info.js
# Include the production build output per the project's build-artifact convention.
git add <built asset paths produced by npm run build>
git commit -m "feat(mcp): add mcp_enabled toggle to settings UI"
```

### Task 3.8: Final verification pass

- [ ] **Step 1: Full quality gate**

```bash
./vendor/bin/phpunit
vendor/bin/phpcbf
vendor/bin/phpcs
vendor/bin/phpstan analyse --memory-limit=512M
npm run lint:js
```
All must pass clean.

- [ ] **Step 2: Manual smoke test (local wp-env, syndication enabled)**

With the local environment running and syndication + MCP enabled, exercise the endpoint with curl (a real MCP client does this automatically):

```bash
# initialize → expect 200 + Mcp-Session-Id header
curl -i -X POST "$SITE/wp-json/wc/ucp/v1/mcp" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"clientInfo":{"name":"smoke-test"},"protocolVersion":"2025-06-18","capabilities":{}}}'

# tools/list with the returned session id → expect 3 tools
curl -s -X POST "$SITE/wp-json/wc/ucp/v1/mcp" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2025-06-18' -H "Mcp-Session-Id: <id>" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'

# tools/call catalog_search → expect structuredContent with UCP products
curl -s -X POST "$SITE/wp-json/wc/ucp/v1/mcp" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2025-06-18' -H "Mcp-Session-Id: <id>" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"catalog_search","arguments":{"query":"shirt"}}}'

# discovery advertises the mcp binding
curl -s "$SITE/.well-known/ucp" | grep -A2 '"transport"'
```

- [ ] **Step 3: Confirm the REST path is unchanged**

Hit `POST /wp-json/wc/ucp/v1/catalog/search` directly and confirm the response is byte-for-byte what it was before the refactor (the cores are shared, so REST behavior must be identical).

---

## Self-review notes (filled in after writing)

- **Spec coverage:** §Architecture → Phases 1–2 + Task 3.3; §Protocol surface (initialize/initialized/tools/list/tools/call/ping) → Task 3.3; §Endpoint POST+GET, GET→405 → Task 3.3 `handle_get`; §Discovery binding → Task 3.6; §Auth required-session + per-call re-gate + 400/404/403 → Tasks 3.1, 3.3; §Origin + protocol-version MUSTs → Task 3.3; §Error mapping (isError) → Task 3.2; §Feature gating `mcp_enabled` → Task 3.5; §Testing → tests in every task + Task 3.8. Open question #4 (tools/call result shape) is pinned in `core_result_to_mcp`; #3 (TTL) pinned to 900s in `MCP_Session`.
- **Type consistency:** `run_catalog_search` / `run_catalog_lookup` / `run_checkout_create` used identically in the controller and in `MCP_Tools::call`; all return `['body','status']`; `core_result_to_mcp` consumes exactly that; `gate_client_name` returns `string|WP_Error` consistently at its two call sites (initialize + per-call re-gate); `client_name_for` returns `?string` consistently.
- **Placeholder scan:** the only deferred items are explicit verification steps tied to facts that can only be confirmed against the live stubs/helpers at execution time (stub method availability; exact `get_settings` option name; `ucp_checkout_error_response` return type). Each names the precise check and the action — not "handle errors appropriately".

---

## Execution outcome & deviations from plan

Implemented on branch `worktree-mcp-transport` (off `origin/main`), subagent-driven with per-task spec + code-quality review and a final security-aware whole-feature review. Final state: **1,539 PHPUnit tests / 0 failures, PHPStan 0 errors, PHPCS clean, JS lint clean.** Where execution diverged from this plan (each caught by a fresh-eyes reviewer reading the actual code, not by tests):

1. **Error envelope shape (plan was wrong).** The plan's `core_result_to_mcp` assumed `body['error']['code']`. The real UCP error envelope from `ucp_*_error_response` is `body['messages'][]` with `{ type:'error', code, severity, content }` (no top-level `error` key). `core_result_to_mcp` was rewritten to read the first `error`-typed message (`code` + `content`), with a first-message fallback. Verified empirically against a live core 503.
2. **`json_body` param dropped.** The plan's `$params` carried `json_body` as a safety net; no `run_*` core ever read it (the one incidental `get_json_params()` read — the `X-WC-AI-Storefront-Unknown-Params` header — was kept in the REST wrapper reading `$request` directly). Removed as dead weight, including from `MCP_Tools::call`.
3. **`ucp_agent_header` kept (unlike `json_body`).** Unused by the checkout core but **live** in the search/lookup cores (debug-log gating), so retained for a uniform cross-core `$params` contract — the seam that lets one params dict dispatch to any core.
4. **Currency threading.** `translate_products_for_search`'s last param changed from `WP_REST_Request` to `?string $currency`; the search core computes the currency once via `get_currency_from_context` and threads it, so the core is fully `WP_REST_Request`-free.
5. **Response-header preservation.** The search success path's conditional `X-WC-AI-Storefront-Unknown-Params` header is re-applied in the REST wrapper (an HTTP concern that does not belong in the transport-neutral core); the `[body,status]` contract stayed unchanged.
6. **JSON-RPC correctness.** Split `-32700` (unparseable body) from `-32600` (valid JSON, no `method`).
7. **Discovery binding gated on `mcp_enabled`.** The manifest advertises the `mcp` transport binding only when `mcp_enabled === 'yes'`, so a disabled endpoint is never advertised (it would 404).
8. **Dead-method removal.** After the refactor the private `get_request_currency()` became unused (PHPStan caught it); removed, its conceptual docs merged into `get_currency_from_context`.
9. **Security hardening (final review — Critical + Important).** (a) A blank/whitespace `clientInfo.name` canonicalized to `''` and slipped past the allow-list (`is_agent_allowed('', …)` is true) — `gate_client_name` now coerces an empty canonical to `OTHER_AI_BUCKET` so a nameless agent is governed by `allow_unknown_ucp_agents` (403 when off). (b) `initialize` was un-rate-limited (session-transient flood) — `check_outer_rate_limit()` now runs for **every** request, including `initialize`. Both covered by regression tests reproducing the exploits.

**Stub/production note:** the unit harness loads a STUB `WC_AI_Storefront`, not the real class, so `mcp_enabled`'s default + sanitization were mirrored into the stub to keep it in lockstep with production (`settings_defaults()` / `update_settings()`).

## Migration to core MCP (future)

This hand-rolled transport is intentional and temporary. The eventual goal is to **standardize on `wordpress/mcp-adapter`** (the WordPress Abilities API + MCP Adapter — the same stack WooCommerce core's `mcp_integration` uses) once it reaches 1.0 and the MCP-engagement hypothesis is validated. The migration is cheap because the `run_*` cores are the stable seam: register the three ops as `McpTool` instances (or Abilities) wrapping the cores, supply a custom transport `permission_callback` = the existing UCP allow-list gate (`MCP_Session::gate_client_name`), keep the `/mcp` endpoint URL stable, and delete `MCP_Server`/`MCP_Tools`/`MCP_Session`. A `MIGRATION` docblock on `WC_AI_Storefront_MCP_Server` records this. Constraint to weigh at migration time: the adapter needs WP ≥ 6.9 (Abilities API in core) vs. the plugin's current WP 6.7 floor, and it would be the plugin's first runtime Composer dependency.
