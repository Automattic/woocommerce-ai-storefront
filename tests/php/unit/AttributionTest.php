<?php
/**
 * Tests for WC_AI_Storefront_Attribution.
 *
 * Covers capture_ai_attribution (meta detection, session/agent capture).
 * The custom "AI Agent" orders-list column was removed in 1.6.7 —
 * WooCommerce core's "Origin" column already displays the same data
 * sourced from `_wc_order_attribution_utm_source`.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class AttributionTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Attribution $attribution;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->attribution = new WC_AI_Storefront_Attribution();

		// Clear $_GET between tests.
		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// capture_ai_attribution
	// ------------------------------------------------------------------

	public function test_capture_skips_non_ai_orders(): void {
		$order = new WC_Order();
		// No utm_medium meta, no $_GET params.

		Functions\expect( 'do_action' )->never();

		$this->attribution->capture_ai_attribution( $order );

		// Order should not have been saved.
		$this->assertFalse( $order->was_saved() );
	}

	public function test_capture_detects_ai_medium_from_order_meta(): void {
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'chatgpt' );

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertTrue( $order->was_saved() );
		// `chatgpt` is NOT a KNOWN_AGENT_HOSTS key (the key is
		// `chatgpt.com`), so the lenient gate doesn't fire and the
		// STRICT branch buckets to "Other AI" per 0.5.2 behavior.
		// (Pre-0.5.2 this test stored the raw `'chatgpt'` value
		// verbatim, which fragmented Top Agent stats.)
		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			$order->get_meta( '_wc_ai_storefront_agent' )
		);
	}

	// ------------------------------------------------------------------
	// STRICT gate dual-check (added 0.5.0)
	// ------------------------------------------------------------------
	//
	// 0.5.0 introduced a canonical UTM shape:
	// `utm_source=hostname&utm_medium=referral&utm_id=woo_ucp`. The
	// STRICT gate now matches `utm_id === 'woo_ucp'` as the canonical
	// "we routed this" signal AND keeps matching legacy
	// `utm_medium === 'ai_agent'` so already-placed orders attribute
	// correctly through the upgrade window. Both branches are tested
	// independently; either one alone must satisfy STRICT.

	public function test_capture_strict_gate_fires_on_woo_ucp_utm_id_meta(): void {
		// Canonical 0.5.0 shape: utm_id=woo_ucp on order meta
		// triggers STRICT regardless of utm_medium value (which is
		// `referral` in the new shape, not `ai_agent`).
		//
		// `utm_source=mysteryagent.example` is deliberately NOT in
		// `KNOWN_AGENT_HOSTS`, so the LENIENT path cannot fire here —
		// the only way this test passes is via STRICT. A regression
		// that broke STRICT but left LENIENT intact would not be
		// caught by an assertion that uses `chatgpt.com` (which both
		// gates would match).
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_id', 'woo_ucp' );
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'referral' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'mysteryagent.example' );

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertTrue( $order->was_saved() );
		// LENIENT did not fire (host unknown), so the agent meta
		// stamps the raw utm_source verbatim per the post-STRICT
		// fallback at attribution.php's `$canonical_agent = ... :
		// (string) $utm_source` branch.
		// `mysteryagent.example` is NOT in KNOWN_AGENT_HOSTS → STRICT
		// fires but LENIENT doesn't → 0.5.2 buckets to "Other AI"
		// (was: stored the raw value, which fragmented Top Agent
		// stats for unknown agents).
		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			$order->get_meta( '_wc_ai_storefront_agent' )
		);
	}

	public function test_capture_strict_gate_fires_on_woo_ucp_utm_id_get_fallback(): void {
		// Canonical 0.5.0 shape via $_GET: utm_id=woo_ucp triggers
		// STRICT before WC core has finished writing meta (the
		// `wc_order_attribution_install_metadata` race the legacy gate
		// also covers via the $_GET fallback).
		//
		// Same self-fulfilling guard as the meta variant above:
		// `utm_source=mysteryagent.example` is NOT in
		// `KNOWN_AGENT_HOSTS`, so the only way this test passes is
		// the STRICT path matching utm_id.
		$order = new WC_Order();
		$_GET['utm_id']        = 'woo_ucp';
		$_GET['utm_medium']    = 'referral';
		$_GET['utm_source']    = 'mysteryagent.example';
		$_GET['ai_session_id'] = 'session-xyz';

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertTrue( $order->was_saved() );
		// `mysteryagent.example` is NOT in KNOWN_AGENT_HOSTS → STRICT
		// fires but LENIENT doesn't → 0.5.2 buckets to "Other AI"
		// (was: stored the raw value, which fragmented Top Agent
		// stats for unknown agents).
		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			$order->get_meta( '_wc_ai_storefront_agent' )
		);
		$this->assertEquals( 'session-xyz', $order->get_meta( '_wc_ai_storefront_session_id' ) );
	}

	public function test_capture_strict_gate_fires_when_both_signals_present(): void {
		// Migration-window cross-cohort case: an order carrying BOTH
		// the canonical `utm_id=woo_ucp` AND the legacy
		// `utm_medium=ai_agent` (e.g., re-attempted order from a
		// session straddling deploys, or a defensively-stamped
		// continue_url) must still attribute through STRICT.
		//
		// A future "be clever" refactor that checked utm_id first
		// and silently returned without examining utm_medium when
		// utm_id was present would still pass — that's fine. But a
		// refactor that special-cased "if utm_id is set, ONLY fire
		// when it's woo_ucp; ignore utm_medium entirely" could
		// regress legacy-cohort orders where utm_id might
		// accidentally be set to something else by an intermediary.
		// This test pins the OR-semantic explicitly.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_id', 'woo_ucp' );
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'mysteryagent.example' );

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertTrue( $order->was_saved() );
		// `mysteryagent.example` is NOT in KNOWN_AGENT_HOSTS → STRICT
		// fires but LENIENT doesn't → 0.5.2 buckets to "Other AI"
		// (was: stored the raw value, which fragmented Top Agent
		// stats for unknown agents).
		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			$order->get_meta( '_wc_ai_storefront_agent' )
		);
	}

	public function test_capture_strict_gate_legacy_ai_agent_medium_still_fires(): void {
		// Pre-0.5.0 orders have `utm_medium=ai_agent` but no `utm_id`.
		// The dual-check must still recognize them so historical
		// orders attribute correctly through the upgrade window.
		// This is the "removable after 6 months" branch — pinning the
		// behavior so a premature removal fails CI loudly.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'ChatGPT' );
		// No utm_id meta at all (pre-0.5.0 shape).

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertTrue( $order->was_saved() );
		// Pre-0.5.0 utm_source values were canonical brand names
		// like "ChatGPT" — not in KNOWN_AGENT_HOSTS (which keys on
		// hostnames like `chatgpt.com`). 0.5.2 buckets these
		// pre-canonical-shape orders to "Other AI" too. The display
		// layer's `canonicalize_host_idempotent()` already maps the
		// "ChatGPT" string back to the brand for legacy-stats reads
		// from `_wc_ai_storefront_agent` — so historical orders
		// captured pre-0.5.2 keep their original values; only orders
		// captured AFTER this change get the bucket.
		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			$order->get_meta( '_wc_ai_storefront_agent' )
		);
	}

	public function test_capture_detects_ai_medium_from_get_fallback(): void {
		$order = new WC_Order();
		// No meta, but $_GET has the params.
		$_GET['utm_medium']    = 'ai_agent';
		$_GET['utm_source']    = 'gemini';
		$_GET['ai_session_id'] = 'session-abc';

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertTrue( $order->was_saved() );
		// `gemini` is NOT a KNOWN_AGENT_HOSTS key (the key is
		// `gemini.google.com`) → 0.5.2 buckets to "Other AI".
		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			$order->get_meta( '_wc_ai_storefront_agent' )
		);
		$this->assertEquals( 'session-abc', $order->get_meta( '_wc_ai_storefront_session_id' ) );
	}

	public function test_capture_stores_session_id_when_present(): void {
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );

		$_GET['ai_session_id'] = 'sess-123';

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals( 'sess-123', $order->get_meta( '_wc_ai_storefront_session_id' ) );
	}

	public function test_capture_does_not_store_empty_session_id(): void {
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'claude' );
		// No ai_session_id in $_GET.

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals( '', $order->get_meta( '_wc_ai_storefront_session_id' ) );
		// `claude` is NOT a KNOWN_AGENT_HOSTS key (key is `claude.ai`)
		// → 0.5.2 buckets to "Other AI".
		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			$order->get_meta( '_wc_ai_storefront_agent' )
		);
	}

	// ------------------------------------------------------------------
	// No custom orders-list column since 1.6.7.
	//
	// Lock-in: if a future change reintroduces a custom column (or
	// filter dropdown) that duplicates WC core's "Origin" column,
	// these assertions fire and force a conscious design review.
	// ------------------------------------------------------------------

	public function test_init_does_not_register_custom_orders_list_column(): void {
		// Track every add_filter / add_action call during init() and
		// assert none of them touch the orders-list column or filter
		// hooks. This is a regression guard: the 1.6.7 removal wasn't
		// enforced by runtime code — it was enforced by not attaching
		// the hooks. If someone re-adds the hooks, this test fires.
		$hooks = [];
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
			}
		);
		Functions\when( 'add_filter' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
			}
		);

		$this->attribution->init();

		$forbidden = [
			'manage_woocommerce_page_wc-orders_columns',
			'manage_woocommerce_page_wc-orders_custom_column',
			'manage_edit-shop_order_columns',
			'manage_shop_order_posts_custom_column',
			'woocommerce_order_list_table_restrict_manage_orders',
			'restrict_manage_posts',
			'woocommerce_order_list_table_prepare_items_query_args',
			'pre_get_posts',
		];
		foreach ( $forbidden as $hook ) {
			$this->assertNotContains(
				$hook,
				$hooks,
				"Hook {$hook} reintroduces the orders-list column or filter removed in 1.6.7 — WC core's Origin column already shows this data"
			);
		}
	}

	public function test_attribution_class_exposes_no_column_rendering_methods(): void {
		// If a future maintainer reintroduces the column-rendering
		// methods (render_order_list_column, add_order_list_column,
		// render_agent_filter, filter_orders_by_agent), they must
		// also wire up the hooks — which the test above prevents.
		// Belt-and-braces: catch the method-level reintroduction too.
		$removed = [
			'add_order_list_column',
			'render_order_list_column',
			'render_agent_filter',
			'render_agent_filter_legacy',
			'filter_orders_by_agent',
			'filter_orders_by_agent_legacy',
		];
		foreach ( $removed as $method ) {
			$this->assertFalse(
				method_exists( $this->attribution, $method ),
				"Method {$method} was removed in 1.6.7; reintroduction duplicates WC core's Origin column"
			);
		}
	}

	// ------------------------------------------------------------------
	// Raw-host capture (`ai_agent_host_raw` URL param → meta stamp)
	// ------------------------------------------------------------------

	public function test_capture_stamps_raw_host_meta_when_well_formed(): void {
		// Well-formed hostname value in $_GET → captured into the
		// AGENT_HOST_RAW_META_KEY meta. This is the happy path: agent
		// sends a known hostname, controller round-trips it through
		// the continue_url, attribution layer pins it to the order
		// for diagnostic/graduation review.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'Gemini' );

		$_GET['ai_agent_host_raw'] = 'gemini.google.com';

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals(
			'gemini.google.com',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY )
		);
	}

	public function test_capture_rejects_oversized_raw_host(): void {
		// 254 chars exceeds RFC 1035 max DNS hostname length (253).
		// Without the cap, an attacker-forged UCP-Agent → forged URL
		// param → unbounded postmeta write. Regression guard: this
		// assertion fires if the strlen() check is removed.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'Other AI' );

		$_GET['ai_agent_host_raw'] = str_repeat( 'a', 254 );

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals(
			'',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY ),
			'Oversized raw_host (>253 chars) must be rejected, not stamped to meta.'
		);
	}

	public function test_capture_rejects_malformed_raw_host(): void {
		// Hostname-shape regex is the second-layer validator. A value
		// that passes sanitize_text_field (no HTML to strip) but isn't
		// a plausible hostname (e.g. shell metachars, JSON injection,
		// URL fragments) gets rejected. The producer-side
		// extract_profile_hostname filters real values via wp_parse_url,
		// so legitimate traffic always passes; only attacker-tampered
		// URLs fail.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'Other AI' );

		$_GET['ai_agent_host_raw'] = 'not a hostname; rm -rf /';

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals(
			'',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY ),
			'Malformed raw_host (non-hostname charset) must be rejected.'
		);
	}

	public function test_capture_skips_raw_host_meta_when_param_absent(): void {
		// No ai_agent_host_raw URL param at all (UCP request without a
		// UCP-Agent header — fallback path) → meta is NOT stamped
		// (left blank). Important: an absent param must not write an
		// empty string into the meta.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'ucp_unknown' );

		// $_GET intentionally has no ai_agent_host_raw key.

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals(
			'',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY )
		);
	}

	// ------------------------------------------------------------------
	// display_attribution_in_admin (admin order-edit screen rendering)
	// ------------------------------------------------------------------

	public function test_display_renders_canonical_agent_only_when_no_raw_host(): void {
		// Pre-PR-93 orders won't have AGENT_HOST_RAW_META_KEY stamped,
		// so the raw-host paragraph must NOT render for them. Just the
		// canonical agent line + (optional) session-id line.
		$order = new WC_Order();
		$order->set_test_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY, 'Gemini' );

		Functions\expect( 'esc_html__' )->andReturnFirstArg();
		Functions\expect( 'esc_html' )->andReturnFirstArg();

		ob_start();
		$this->attribution->display_attribution_in_admin( $order );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Gemini', $html );
		$this->assertStringNotContainsString( 'Agent host:', $html );
	}

	public function test_display_renders_raw_host_paragraph_when_present(): void {
		// Post-PR-93 orders that round-tripped a raw_host through
		// continue_url get the new "Agent host:" paragraph rendered
		// alongside the canonical agent. Drill-in surface for "Other AI"
		// orders so merchants can identify the actual hostname.
		$order = new WC_Order();
		$order->set_test_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY, 'Other AI' );
		$order->set_test_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY, 'novel-agent.example.com' );

		Functions\expect( 'esc_html__' )->andReturnFirstArg();
		Functions\expect( 'esc_html' )->andReturnFirstArg();

		ob_start();
		$this->attribution->display_attribution_in_admin( $order );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Other AI', $html );
		$this->assertStringContainsString( 'Agent host:', $html );
		$this->assertStringContainsString( 'novel-agent.example.com', $html );
	}

	public function test_display_returns_early_when_no_agent_meta(): void {
		// Non-AI orders (no AGENT_META_KEY stamped) must produce zero
		// output — the meta-box's add_action only fires this callback
		// for orders that flowed through capture_ai_attribution, but a
		// defensive zero-output guard prevents a stray empty
		// "AI Agent Attribution" heading from appearing on non-AI
		// orders if the gating logic ever drifts.
		$order = new WC_Order();
		// No AGENT_META_KEY meta set.

		ob_start();
		$this->attribution->display_attribution_in_admin( $order );
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	// ------------------------------------------------------------------
	// Lenient host-match gate (utm_source canonicalizes to known agent)
	// ------------------------------------------------------------------
	//
	// Production scenario this set of tests pins:
	//
	// An AI agent (UCPPlayground in the original report) called our
	// /catalog/search and /checkout-sessions endpoints, then bypassed
	// the continue_url we returned and built its OWN Shareable Checkout
	// link — `?products=...&utm_source=ucpplayground.com&utm_medium=referral&utm_campaign=...`.
	// Pre-fix, our `capture_ai_attribution()` early-returned because
	// utm_medium != 'ai_agent', so the resulting order had no
	// `_wc_ai_storefront_agent` meta — it surfaced as ordinary referral
	// traffic in the dashboard despite being driven by an AI agent.
	//
	// The fix: if utm_source canonicalizes to a known agent host (any
	// hostname in `KNOWN_AGENT_HOSTS`), recognize the order as
	// AI-attributed regardless of utm_medium. The host match is safe
	// because it requires an entry in the code-controlled allow-list.

	public function test_capture_lenient_gate_fires_on_known_host_with_referral_medium(): void {
		// THE production bug. utm_medium = 'referral' (UCPPlayground
		// chose its own value), utm_source = 'ucpplayground.com'.
		// Pre-fix this slipped past the strict gate; post-fix the
		// host match recognizes it as an AI order.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'referral' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'ucpplayground.com' );

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertTrue( $order->was_saved() );
		// Stamps the CANONICAL brand name (not the raw hostname) so
		// the Recent Orders display + Top Agent stats both surface
		// the friendly form.
		$this->assertEquals(
			'UCPPlayground',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY )
		);
		// Stamps the raw hostname into AGENT_HOST_RAW_META_KEY so
		// the order-edit drill-in can show the original source the
		// agent declared.
		$this->assertEquals(
			'ucpplayground.com',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY )
		);
	}

	public function test_capture_lenient_gate_canonicalizes_chatgpt_host(): void {
		// Generic case: any hostname in KNOWN_AGENT_HOSTS triggers the
		// lenient path. openai.com → "ChatGPT".
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'organic' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'openai.com' );

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals(
			'ChatGPT',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY )
		);
		$this->assertEquals(
			'openai.com',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY )
		);
	}

	public function test_capture_lenient_gate_does_not_fire_on_unknown_host(): void {
		// "evil.example" is not in KNOWN_AGENT_HOSTS — canonicalizes
		// to "Other AI". The lenient gate intentionally REJECTS this
		// path because Other-AI inclusion would let any random
		// referrer self-attribute as an AI source. The gate only
		// honors the code-controlled allow-list.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'referral' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'evil.example' );

		Functions\expect( 'do_action' )->never();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertFalse( $order->was_saved() );
		$this->assertEquals( '', $order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY ) );
	}

	public function test_capture_lenient_gate_does_not_fire_on_empty_utm_source(): void {
		// utm_medium != 'ai_agent', utm_source absent. Strict gate
		// fails, lenient gate has nothing to match against — order
		// shouldn't be touched.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'direct' );
		// No utm_source.

		Functions\expect( 'do_action' )->never();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertFalse( $order->was_saved() );
	}

	public function test_capture_strict_gate_still_fires_on_canonical_utm_source(): void {
		// Regression check: pre-0.5.0 orders placed via OUR
		// continue_url had utm_source = canonical brand name
		// (e.g. "Gemini") + utm_medium = 'ai_agent'. The strict gate
		// must keep firing — not be shadowed by lenient-gate behavior.
		// Post-0.5.2: STRICT-only matches (utm_source not in
		// KNOWN_AGENT_HOSTS) bucket to "Other AI" rather than storing
		// the raw "Gemini" canonical-string verbatim. This is fine —
		// the canonical string is publicly guessable and storing it
		// verbatim was never load-bearing for the cohort that
		// captured the brand display.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'Gemini' );

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY )
		);
		// "Gemini" is a canonical brand name, NOT a hostname — the
		// lenient gate intentionally does NOT match it (matching
		// canonical names would be a spoofing surface). So host_raw
		// is NOT stamped by the lenient path here. The legacy block
		// below would write it from the `ai_agent_host_raw` URL
		// param if present; absent here, the meta stays empty. This
		// is the correct shape: host_raw is for HOSTNAMES, not for
		// canonical name strings — putting "Gemini" in a meta named
		// "host_raw" would mislead drill-in/debugging.
		$this->assertEquals(
			'',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY )
		);
	}

	// ------------------------------------------------------------------
	// Lenient gate: spoofing defenses
	// ------------------------------------------------------------------

	public function test_capture_lenient_gate_rejects_canonical_brand_name_in_utm_source(): void {
		// Critical: the lenient gate matches HOSTNAME KEYS in
		// KNOWN_AGENT_HOSTS, not canonical brand-name VALUES. A
		// non-AI referrer with `utm_source=Gemini&utm_medium=referral`
		// must NOT self-attribute as AI traffic — canonical brand
		// names are publicly known, so accepting them would be a
		// trivial spoofing surface. Hostnames are a much narrower
		// allow-list to attack.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'referral' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'Gemini' );

		Functions\expect( 'do_action' )->never();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertFalse( $order->was_saved() );
		$this->assertEquals( '', $order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY ) );
	}

	public function test_capture_lenient_gate_rejects_other_ai_string_in_utm_source(): void {
		// The OTHER_AI_BUCKET sentinel ("Other AI") is an internal
		// canonical value — it must not be a self-attribution path.
		// Without the strict-key match, an attacker setting
		// `utm_source=Other AI` could route their orders into the
		// catch-all bucket, polluting "Other AI" stats with non-AI
		// traffic.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'referral' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'Other AI' );

		Functions\expect( 'do_action' )->never();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertFalse( $order->was_saved() );
	}

	public function test_capture_lenient_gate_is_case_insensitive(): void {
		// DNS hostnames are case-insensitive per RFC 1035. An agent
		// sending `utm_source=OpenAI.COM` should resolve the same as
		// `openai.com` — `KNOWN_AGENT_HOSTS` keys are stored
		// lowercase, so the lookup uses strtolower.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'referral' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'OpenAI.COM' );

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals(
			'ChatGPT',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY )
		);
		// host_raw stores the NORMALIZED form (lowercase, no scheme,
		// no port, no trailing dot). Earlier behavior preserved the
		// agent's raw casing for "diagnostic provenance," but in
		// practice the value is consumed by display surfaces that
		// expect a clean hostname shape — and DNS case-insensitivity
		// makes the casing cosmetic noise. If a debug session needs
		// the literal raw value the agent sent, that's what
		// `_wc_order_attribution_utm_source` already preserves
		// verbatim (WC core writes it without modification).
		$this->assertEquals(
			'openai.com',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY )
		);
	}

	/**
	 * Real-world utm_source variants Copilot flagged: `https://openai.com`,
	 * `openai.com/`, `openai.com:443`, trailing dot, etc. All forms
	 * must collapse to the same `openai.com` lookup key and produce
	 * identical attribution. Without `normalize_host_string()` the
	 * lenient gate silently missed any non-bare-host form.
	 *
	 * Each variant is its own test invocation via the data provider,
	 * which gives a clean Brain Monkey + Mockery lifecycle per
	 * iteration (PHPUnit's standard setUp/tearDown runs around each
	 * invocation). An earlier revision used a foreach loop with
	 * `\Mockery::close()` + `Monkey\setUp()` mid-test to reset
	 * expectations — that worked but left Brain Monkey in a
	 * half-torn-down shape that would silently leak into other tests
	 * if a future iteration ever threw. The data provider is the
	 * idiomatic fix.
	 *
	 * @dataProvider lenient_gate_url_shaped_variant_provider
	 */
	public function test_capture_lenient_gate_normalizes_url_shaped_utm_source(
		string $utm_source
	): void {
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'referral' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', $utm_source );

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals(
			'ChatGPT',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY ),
			"utm_source variant '{$utm_source}' should canonicalize to ChatGPT"
		);
		$this->assertEquals(
			'openai.com',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY ),
			"utm_source variant '{$utm_source}' should normalize to bare openai.com in host_raw"
		);
	}

	public static function lenient_gate_url_shaped_variant_provider(): array {
		return [
			'https URL'                 => [ 'https://openai.com' ],
			'https URL trailing slash'  => [ 'https://openai.com/' ],
			'https URL with path'       => [ 'https://openai.com/some/path' ],
			'host with port'            => [ 'openai.com:443' ],
			'FQDN trailing dot'         => [ 'openai.com.' ],
		];
	}

	public function test_capture_strict_path_uses_url_param_for_host_raw(): void {
		// Strict path (continue_url with utm_medium=ai_agent + canonical
		// utm_source like "Gemini") relies on the `ai_agent_host_raw`
		// URL param to populate the host_raw meta. The lenient path
		// no longer fires for canonical brand names (correctly — that
		// would be a spoofing surface), so there's nothing to "overwrite";
		// the URL param is the SOLE source of host_raw on this path.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'Gemini' );
		$_GET['ai_agent_host_raw'] = 'gemini.google.com';

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		// 0.5.2 buckets STRICT-only orders to "Other AI" when the
		// utm_source isn't in KNOWN_AGENT_HOSTS. "Gemini" canonical
		// string isn't a KNOWN_AGENT_HOSTS key (the key is
		// `gemini.google.com`), so this lands in the bucket. The
		// `_wc_ai_storefront_agent_host_raw` URL-param still
		// populates separately, preserving the actual hostname for
		// drill-in.
		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY )
		);
		// URL-param value lands in host_raw — drill-in still shows
		// the actual hostname even when the friendly meta says
		// "Other AI".
		$this->assertEquals(
			'gemini.google.com',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY )
		);
	}

	// ------------------------------------------------------------------
	// 0.5.2: STRICT-only unknown agents bucket to "Other AI"
	// ------------------------------------------------------------------
	//
	// Pre-0.5.2, STRICT-captured orders whose `utm_source` was not in
	// `KNOWN_AGENT_HOSTS` stored the raw `utm_source` verbatim in
	// `_wc_ai_storefront_agent` meta. With the canonical UTM shape
	// (0.5.0+) emitting hostname-shaped `utm_source` for unknown
	// agents, that fragmented `get_stats()` and the Top Agent card
	// into long-tail buckets. 0.5.2 buckets these to `OTHER_AI_BUCKET`
	// while preserving the raw identifier in
	// `_wc_ai_storefront_agent_host_raw` for drill-in.

	public function test_capture_strict_unknown_buckets_to_other_ai_via_utm_id(): void {
		// 0.5.2 canonical-shape STRICT path: utm_id=woo_ucp +
		// hostname-shape utm_source not in KNOWN_AGENT_HOSTS.
		// Friendly meta should be "Other AI"; raw identifier still
		// flows into the host_raw meta when the URL param is set.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_id', 'woo_ucp' );
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'referral' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'agent.example.com' );
		$_GET['ai_agent_host_raw'] = 'agent.example.com';

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY )
		);
		$this->assertEquals(
			'agent.example.com',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY )
		);
	}

	public function test_capture_strict_unknown_skips_meta_when_utm_source_empty(): void {
		// Edge case: STRICT fires (utm_id=woo_ucp) but utm_source is
		// empty. Pre-0.5.2 the empty-source path stored an empty
		// canonical-agent value (the `'' !== $canonical_agent` guard
		// dropped the meta write). 0.5.2 must preserve that no-stamp
		// behavior — bucketing an empty-source order to "Other AI"
		// would manufacture an attribution where there's none.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_id', 'woo_ucp' );
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'referral' );
		// utm_source empty, simulating the FALLBACK_SOURCE='ucp_unknown'
		// case where build_continue_url stamped the sentinel.
		$order->set_test_meta( '_wc_order_attribution_utm_source', '' );

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		// `_wc_ai_storefront_agent` not stamped (empty utm_source
		// means we have nothing to bucket).
		$this->assertEquals(
			'',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY )
		);
	}

	public function test_capture_lenient_gate_emits_canonical_identifier_to_action_hook(): void {
		// External listeners on `wc_ai_storefront_attribution_captured`
		// should receive the SAME identifier that's stored in
		// `AGENT_META_KEY` — anything else means a third-party plugin
		// hooking the action sees a different agent name than the
		// merchant-facing display. Pre-fix, the hook fired with the raw
		// utm_source (`openai.com`) while the meta carried "ChatGPT";
		// the two surfaces diverged on every lenient capture. Lock the
		// invariant.
		$captured_args = null;
		Functions\expect( 'do_action' )
			->once()
			->andReturnUsing(
				static function ( ...$args ) use ( &$captured_args ) {
					$captured_args = $args;
				}
			);

		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'referral' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'openai.com' );

		$this->attribution->capture_ai_attribution( $order );

		$this->assertNotNull( $captured_args, 'Hook must fire on a successful lenient capture.' );
		$this->assertSame(
			'wc_ai_storefront_attribution_captured',
			$captured_args[0]
		);
		$this->assertSame(
			$order,
			$captured_args[1],
			'First payload arg is the order.'
		);
		$this->assertSame(
			'ChatGPT',
			$captured_args[2],
			'Second payload arg must be the canonical identifier (matches AGENT_META_KEY), not the raw utm_source.'
		);
		// And confirm meta + hook agree.
		$this->assertSame(
			$captured_args[2],
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY )
		);
	}
}
