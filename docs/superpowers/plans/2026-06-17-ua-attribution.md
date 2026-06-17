# User-Agent Attribution Classification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Derive agent identity from the `User-Agent` header as the last resort before `ucp_unknown`, so AI orders that send no explicit signal still attribute to the right brand.

**Architecture:** A pure classifier `classify_user_agent()` on `WC_AI_Storefront_UCP_Agent_Header` maps a curated set of answer-agent UA tokens (via the existing `detect_crawler_from_ua()` + `canonicalize_host()`) to the same `{name, source_host, raw_host}` triple the resolvers already produce. It's wired into both `resolve_agent_host()` (REST) and `resolve_agent_data_from_name()` (MCP) immediately before their `ucp_unknown` fallback. Attribution only — no access-control change.

**Tech Stack:** PHP 7.4+ / WordPress / WooCommerce; PHPUnit + Brain Monkey + Mockery.

---

## Background (verified — do not re-derive)

- `FALLBACK_SOURCE = 'ucp_unknown'` and `OTHER_AI_BUCKET = 'Other AI'` are constants on `WC_AI_Storefront_UCP_Agent_Header`.
- `KNOWN_AGENT_HOSTS` (same class) includes the keys `chatgpt.com`→`ChatGPT`, `claude.ai`→`Claude`, `perplexity.ai`→`Perplexity` (confirmed). `mistral.ai` and `duckduckgo.com` are **absent** — so those agents are intentionally NOT mapped.
- `canonicalize_host( string $host ): string` is pure: `'' → ''`; else `KNOWN_AGENT_HOSTS[strtolower($host)] ?? OTHER_AI_BUCKET`.
- `normalize_host_string( string $value ): string` only calls `wp_parse_url` for scheme/`//`-prefixed inputs; a bare host like `chatgpt.com` takes a pure string path.
- `WC_AI_Storefront_Robots::detect_crawler_from_ua()` currently takes NO args and reads `$_SERVER['HTTP_USER_AGENT']` via `sanitize_text_field( wp_unslash( … ) )`, then stage-1 substring-matches against `AI_CRAWLERS` (returning the matched token, e.g. `ChatGPT-User`), else stage-2 extracts a leading product token. The tokens `ChatGPT-User`, `GPTBot`, `OAI-SearchBot`, `Claude-User`, `ClaudeBot`, `Claude-SearchBot`, `Perplexity-User`, `PerplexityBot` are all in `AI_CRAWLERS`.
- `resolve_agent_host( WP_REST_Request $request )` (REST, **private static**, controller line ~5636) resolves: Path 1 `UCP-Agent` profile host → Path 2 product token → Path 3 body `meta.source` → **Path 4** (line ~5718) returns `['name'=>FALLBACK_SOURCE,'raw_host'=>'','source_host'=>'']`.
- `resolve_agent_data_from_name( string $name )` (MCP, **public static**, line ~5736) returns the `FALLBACK_SOURCE` triple **only** when `'' === strtolower(trim($name))` (the empty-name branch, ~5738). A non-empty unknown name resolves to `Other AI` (a declared signal that correctly outranks UA).
- `WP_REST_Request::get_header( $name )` returns the header string or `null`.
- Test commands: single `vendor/bin/phpunit tests/php/unit/<File>.php --filter 'NAME'`; full `composer test`. PHPUnit 10.5 — do NOT pass `-v`.

## File map

- `includes/ai-storefront/class-wc-ai-storefront-robots.php` — `detect_crawler_from_ua()` gains an optional `?string $ua = null` param.
- `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php` — new `UA_AGENT_HOSTS` constant + `classify_user_agent()`.
- `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` — UA fallback wired into both resolvers.
- `tests/php/unit/RobotsTest.php` — optional-param test.
- `tests/php/unit/UcpUserAgentAttributionTest.php` — NEW: classifier tests (Task 2) + resolver-wiring tests (Task 3).

---

## Task 1: `detect_crawler_from_ua()` accepts an explicit UA

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-robots.php`
- Test: `tests/php/unit/RobotsTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/php/unit/RobotsTest.php` (near the other `detect_crawler_from_ua` tests, ~line 1034):

```php
	public function test_detect_crawler_from_ua_uses_explicit_arg_over_server(): void {
		// An explicit UA argument is used directly and does NOT read $_SERVER.
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Bingbot/2.0)';

		$this->assertSame(
			'ChatGPT-User',
			WC_AI_Storefront_Robots::detect_crawler_from_ua(
				'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)'
			)
		);
	}

	public function test_detect_crawler_from_ua_null_arg_reads_server(): void {
		// Passing null (the default) preserves the original $_SERVER behavior.
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';

		$this->assertSame( 'ClaudeBot', WC_AI_Storefront_Robots::detect_crawler_from_ua( null ) );
	}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/php/unit/RobotsTest.php --filter 'detect_crawler_from_ua_uses_explicit_arg_over_server'`
Expected: FAIL — `detect_crawler_from_ua()` currently takes no args, so PHP raises an `ArgumentCountError`.

- [ ] **Step 3: Add the optional parameter**

In `class-wc-ai-storefront-robots.php`, change the signature and the UA-acquisition block of `detect_crawler_from_ua()`:

```php
	public static function detect_crawler_from_ua( ?string $ua = null ): string {
		if ( null === $ua ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
				: '';
		}
		// An explicitly-passed $ua is the caller's already-extracted header
		// value (e.g. WP_REST_Request::get_header( 'user-agent' )); it is used
		// only for substring matching + charset-bounded token extraction and is
		// never echoed raw, so it needs no further sanitization here.

		if ( '' === $ua ) {
			return '';
		}
```

Leave stage 1 (the `usort` + substring loop) and stage 2 (the `preg_match` product-token extraction) exactly as they are.

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit tests/php/unit/RobotsTest.php --filter 'detect_crawler_from_ua'`
Expected: PASS (the new tests + all existing `detect_crawler_from_ua` tests).

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-robots.php tests/php/unit/RobotsTest.php
git commit -m "refactor(robots): detect_crawler_from_ua accepts an explicit UA arg"
```

---

## Task 2: `UA_AGENT_HOSTS` + `classify_user_agent()`

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php`
- Test: `tests/php/unit/UcpUserAgentAttributionTest.php` (NEW)

- [ ] **Step 1: Write the failing tests**

Create `tests/php/unit/UcpUserAgentAttributionTest.php`:

```php
<?php
/**
 * Tests for User-Agent-derived attribution: the pure classifier
 * WC_AI_Storefront_UCP_Agent_Header::classify_user_agent() and its
 * wiring into the REST/MCP agent resolvers.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpUserAgentAttributionTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/** @var string|null */
	private $original_ua;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? (string) $_SERVER['HTTP_USER_AGENT']
			: null;

		// detect_crawler_from_ua()'s $_SERVER path uses these; the bare-host
		// path of normalize_host_string() may call wp_parse_url. Stub all
		// three as faithful pass-throughs so the classifier runs unmodified.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static fn( $url, $component = -1 ) => \parse_url( $url, $component )
		);
	}

	protected function tearDown(): void {
		if ( null === $this->original_ua ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $this->original_ua;
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---- classify_user_agent() ----

	public function test_classify_maps_chatgpt_user_to_chatgpt(): void {
		$result = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent(
			'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)'
		);

		$this->assertSame( 'ChatGPT', $result['name'] );
		$this->assertSame( 'chatgpt.com', $result['source_host'] );
		$this->assertSame( 'ChatGPT-User', $result['raw_host'] );
	}

	public function test_classify_maps_gptbot_to_chatgpt(): void {
		$result = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( 'GPTBot/1.2' );

		$this->assertSame( 'ChatGPT', $result['name'] );
		$this->assertSame( 'chatgpt.com', $result['source_host'] );
		$this->assertSame( 'GPTBot', $result['raw_host'] );
	}

	public function test_classify_maps_claudebot_to_claude(): void {
		$result = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent(
			'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)'
		);

		$this->assertSame( 'Claude', $result['name'] );
		$this->assertSame( 'claude.ai', $result['source_host'] );
		$this->assertSame( 'ClaudeBot', $result['raw_host'] );
	}

	public function test_classify_maps_perplexitybot_to_perplexity(): void {
		$result = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( 'PerplexityBot/1.0' );

		$this->assertSame( 'Perplexity', $result['name'] );
		$this->assertSame( 'perplexity.ai', $result['source_host'] );
	}

	public function test_classify_returns_null_for_generic_indexer(): void {
		// Bingbot is a generic indexer, deliberately NOT mapped → stays ucp_unknown.
		$this->assertNull(
			WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( 'Mozilla/5.0 (compatible; Bingbot/2.0)' )
		);
	}

	public function test_classify_returns_null_for_plain_browser(): void {
		$this->assertNull(
			WC_AI_Storefront_UCP_Agent_Header::classify_user_agent(
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
			)
		);
	}

	public function test_classify_returns_null_for_empty_ua(): void {
		$this->assertNull( WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( '' ) );
	}

	public function test_classify_never_returns_other_ai(): void {
		// Every mapped token resolves to a real brand, never the Other AI bucket.
		$result = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( 'OAI-SearchBot/1.0' );

		$this->assertNotSame( WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET, $result['name'] );
		$this->assertSame( 'ChatGPT', $result['name'] );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/php/unit/UcpUserAgentAttributionTest.php --filter 'classify'`
Expected: FAIL — `classify_user_agent()` does not exist yet.

- [ ] **Step 3: Add the `UA_AGENT_HOSTS` constant**

In `class-wc-ai-storefront-ucp-agent-header.php`, immediately after the `KNOWN_AGENT_HOSTS` constant, add:

```php
	/**
	 * Map: User-Agent crawler token (as returned by
	 * WC_AI_Storefront_Robots::detect_crawler_from_ua()) → representative
	 * hostname for attribution.
	 *
	 * Used as the LAST-RESORT attribution signal: when no UCP-Agent header or
	 * meta.source resolved the agent, we derive it from the User-Agent so the
	 * order attributes to a brand instead of `ucp_unknown`. ANSWER-AGENTS ONLY:
	 * every value here must already be a key in KNOWN_AGENT_HOSTS so
	 * canonicalize_host() yields a real brand (never OTHER_AI_BUCKET). Generic
	 * indexers (Bingbot, Googlebot, Applebot) and training crawlers are
	 * intentionally absent — they index broadly and aren't a buying agent, so
	 * they stay `ucp_unknown`. This is an ATTRIBUTION signal only; it never
	 * grants access.
	 *
	 * @var array<string, string>
	 */
	const UA_AGENT_HOSTS = [
		// OpenAI.
		'ChatGPT-User'    => 'chatgpt.com',
		'GPTBot'          => 'chatgpt.com',
		'OAI-SearchBot'   => 'chatgpt.com',

		// Anthropic.
		'Claude-User'     => 'claude.ai',
		'ClaudeBot'       => 'claude.ai',
		'Claude-SearchBot' => 'claude.ai',

		// Perplexity.
		'Perplexity-User' => 'perplexity.ai',
		'PerplexityBot'   => 'perplexity.ai',
	];
```

- [ ] **Step 4: Add the `classify_user_agent()` method**

In the same class, add (e.g. just after `canonicalize_host()`):

```php
	/**
	 * Classify a User-Agent string into the attribution triple, or null.
	 *
	 * Detects the crawler token (WC_AI_Storefront_Robots::detect_crawler_from_ua),
	 * looks it up in UA_AGENT_HOSTS, and — on a hit — returns the same
	 * {name, source_host, raw_host} shape the REST/MCP resolvers produce, so an
	 * inferred agent merges into the SAME brand/utm_source bucket as a declared
	 * one. `raw_host` carries the UA token as provenance (a declared agent shows
	 * a hostname there; an inferred one shows a UA token). Returns null when the
	 * UA isn't a mapped answer-agent, so the caller falls through to ucp_unknown.
	 *
	 * @param string|null $ua Explicit User-Agent, or null to read $_SERVER.
	 * @return array{name: string, source_host: string, raw_host: string}|null
	 */
	public static function classify_user_agent( ?string $ua = null ): ?array {
		$token = WC_AI_Storefront_Robots::detect_crawler_from_ua( $ua );
		$host  = self::UA_AGENT_HOSTS[ $token ] ?? '';
		if ( '' === $host ) {
			return null;
		}

		return [
			'name'        => self::canonicalize_host( $host ),
			'source_host' => self::normalize_host_string( $host ),
			'raw_host'    => $token,
		];
	}
```

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/phpunit tests/php/unit/UcpUserAgentAttributionTest.php --filter 'classify'`
Expected: PASS (all 8 classify tests).

- [ ] **Step 6: Commit**

```bash
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php tests/php/unit/UcpUserAgentAttributionTest.php
git commit -m "feat(attribution): classify_user_agent maps answer-agent UAs to a brand"
```

---

## Task 3: Wire the UA fallback into both resolvers

**Files:**
- Modify: `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php`
- Test: `tests/php/unit/UcpUserAgentAttributionTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append these methods to `tests/php/unit/UcpUserAgentAttributionTest.php` (inside the class):

```php
	// ---- REST resolver wiring (resolve_agent_host, private static) ----

	private function invoke_resolve_agent_host( $request ): array {
		$method = new \ReflectionMethod( WC_AI_Storefront_UCP_REST_Controller::class, 'resolve_agent_host' );
		$method->setAccessible( true );
		return $method->invoke( null, $request );
	}

	public function test_rest_resolver_falls_back_to_user_agent(): void {
		// No UCP-Agent, no meta.source → the UA fallback resolves the agent.
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'ucp-agent' )->andReturn( '' );
		$request->shouldReceive( 'get_json_params' )->andReturn( null );
		$request->shouldReceive( 'get_header' )->with( 'user-agent' )
			->andReturn( 'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)' );

		$result = $this->invoke_resolve_agent_host( $request );

		$this->assertSame( 'ChatGPT', $result['name'] );
		$this->assertSame( 'chatgpt.com', $result['source_host'] );
		$this->assertSame( 'ChatGPT-User', $result['raw_host'] );
	}

	public function test_rest_resolver_explicit_ucp_agent_wins_over_user_agent(): void {
		// UCP-Agent profile present → it wins; the (different) UA is ignored.
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'ucp-agent' )
			->andReturn( 'profile="https://chatgpt.com/ucp.json"' );
		$request->shouldReceive( 'get_json_params' )->andReturn( null );
		$request->shouldReceive( 'get_header' )->with( 'user-agent' )->andReturn( 'PerplexityBot/1.0' );

		$result = $this->invoke_resolve_agent_host( $request );

		$this->assertSame( 'ChatGPT', $result['name'] );
		$this->assertSame( 'chatgpt.com', $result['raw_host'] );
	}

	public function test_rest_resolver_unmapped_ua_stays_ucp_unknown(): void {
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'ucp-agent' )->andReturn( '' );
		$request->shouldReceive( 'get_json_params' )->andReturn( null );
		$request->shouldReceive( 'get_header' )->with( 'user-agent' )
			->andReturn( 'Mozilla/5.0 (compatible; Bingbot/2.0)' );

		$result = $this->invoke_resolve_agent_host( $request );

		$this->assertSame( WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE, $result['name'] );
		$this->assertSame( '', $result['source_host'] );
	}

	// ---- MCP resolver wiring (resolve_agent_data_from_name, public static) ----

	public function test_mcp_resolver_empty_name_falls_back_to_user_agent(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';

		$result = WC_AI_Storefront_UCP_REST_Controller::resolve_agent_data_from_name( '' );

		$this->assertSame( 'Claude', $result['name'] );
		$this->assertSame( 'claude.ai', $result['source_host'] );
		$this->assertSame( 'ClaudeBot', $result['raw_host'] );
	}

	public function test_mcp_resolver_nonempty_name_ignores_user_agent(): void {
		// A declared (even if unknown) MCP name outranks the UA per precedence.
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ChatGPT-User/1.0)';

		$result = WC_AI_Storefront_UCP_REST_Controller::resolve_agent_data_from_name( 'some-unknown-agent' );

		$this->assertSame( WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET, $result['name'] );
		$this->assertNotSame( 'ChatGPT', $result['name'] );
	}

	public function test_mcp_resolver_empty_name_unmapped_ua_stays_ucp_unknown(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Bingbot/2.0)';

		$result = WC_AI_Storefront_UCP_REST_Controller::resolve_agent_data_from_name( '' );

		$this->assertSame( WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE, $result['name'] );
		$this->assertSame( '', $result['source_host'] );
	}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/php/unit/UcpUserAgentAttributionTest.php --filter 'resolver'`
Expected: FAIL — the resolvers don't consult the UA yet (REST returns `ucp_unknown`; MCP empty-name returns `ucp_unknown`).

- [ ] **Step 3: Insert the UA fallback in `resolve_agent_host()` (REST)**

In `class-wc-ai-storefront-ucp-rest-controller.php`, in `resolve_agent_host()`, between the Path 3 block (the `meta.source` `if (…) { return […]; }`) and the Path 4 final `return` (the `FALLBACK_SOURCE` triple), insert:

```php
		// Path 3.5: User-Agent fallback. No explicit UCP-Agent / meta.source
		// signal resolved the agent, but many answer-agents self-identify in
		// the User-Agent header. Derive the agent from it before giving up to
		// FALLBACK_SOURCE. Attribution only — never an access signal.
		$ua_data = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( $request->get_header( 'user-agent' ) );
		if ( null !== $ua_data ) {
			return $ua_data;
		}
```

- [ ] **Step 4: Insert the UA fallback in `resolve_agent_data_from_name()` (MCP)**

In the same file, in `resolve_agent_data_from_name()`, inside the empty-name branch (`if ( '' === $normalized ) { … }`), BEFORE the existing `return` of the `FALLBACK_SOURCE` triple, insert:

```php
			// No MCP client name declared. Try the User-Agent header (reads
			// $_SERVER — no request object here) before FALLBACK_SOURCE.
			$ua_data = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent();
			if ( null !== $ua_data ) {
				return $ua_data;
			}
```

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/phpunit tests/php/unit/UcpUserAgentAttributionTest.php`
Expected: PASS (all classifier + resolver tests).

- [ ] **Step 6: Commit**

```bash
git add includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpUserAgentAttributionTest.php
git commit -m "feat(attribution): wire UA fallback into REST + MCP agent resolvers"
```

---

## Task 4: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Full unit suite**

Run: `composer test`
Expected: all green (the new file + the existing Attribution/Robots/UcpAgentHeader suites). The UA fallback only changes behavior on the previously-`ucp_unknown` path, so existing attribution tests are unaffected.

- [ ] **Step 2: Static analysis + standards**

Run: `vendor/bin/phpcs includes/ai-storefront/class-wc-ai-storefront-robots.php includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php tests/php/unit/UcpUserAgentAttributionTest.php tests/php/unit/RobotsTest.php`
Then `vendor/bin/phpcbf <same paths>` if it reports fixable issues, and re-run phpcs.
Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: phpcs clean, phpstan clean.

- [ ] **Step 3: Docs-untouched guard**

Run: `git diff --name-only main...HEAD | grep -iE 'CHANGELOG|readme|USER-GUIDE' || echo "none ✓"`
Expected: `none ✓` (CHANGELOG handled in the pre-release pass).

- [ ] **Step 4: Manual smoke on local wp-env (localhost:8030)**

```bash
# A ChatGPT-User UA with no UCP-Agent should attribute to chatgpt.com, not ucp_unknown.
curl -s -m 8 -A 'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)' \
  -X POST http://localhost:8030/wp-json/wc/ucp/v1/checkout-sessions \
  -H 'Content-Type: application/json' -d '{"line_items":[]}' -i | grep -i 'continue_url\|ucp_unknown\|chatgpt' || true
```
Expected: where a `continue_url` is produced, its `utm_source` is `chatgpt.com` (not `ucp_unknown`) for the ChatGPT-User UA. (A malformed empty cart may 400 before building a continue_url; if so, exercise a real product line item from the local catalog.)

---

## Notes

- **No new setting** — UA attribution is always-on; it never gates access (`check_agent_access` is untouched).
- **Provenance:** inferred agents store the UA token (e.g. `ChatGPT-User`) in `raw_host` → `_wc_ai_storefront_agent_host_raw`; declared agents store a hostname. The merchant can always tell them apart.
- **Docs deferred** to the pre-release pass (no CHANGELOG/readme/USER-GUIDE in this branch).

## Self-review

- **Spec coverage:** `UA_AGENT_HOSTS` (answer-agents only) → Task 2. `classify_user_agent(?string)` reusing `canonicalize_host`/`normalize_host_string` → Task 2. `detect_crawler_from_ua(?string $ua = null)` → Task 1. Wire into REST `resolve_agent_host` + MCP `resolve_agent_data_from_name` before `ucp_unknown`, with precedence (explicit wins) → Task 3. Merge-with-provenance (`raw_host` = UA token) → Task 2 method + asserted in Tasks 2/3. Boundary (no access change) → nothing touches `check_agent_access`. Tests for mapped tokens, unmapped `Bingbot`, junk, precedence, MCP empty-vs-declared → Tasks 2/3. Verification → Task 4. All covered.
- **Placeholder scan:** every step has complete code/commands; no TBD/"handle edge cases"/"similar to".
- **Type/name consistency:** `classify_user_agent( ?string $ua = null ): ?array` returns keys `name`/`source_host`/`raw_host` — matched in the method, the resolver insertions, and every assertion. `detect_crawler_from_ua( ?string $ua = null )` signature matches its call in `classify_user_agent`. `UA_AGENT_HOSTS` keys match the exact `AI_CRAWLERS` tokens `detect_crawler_from_ua` returns. `FALLBACK_SOURCE`/`OTHER_AI_BUCKET` constants match the verified source.
