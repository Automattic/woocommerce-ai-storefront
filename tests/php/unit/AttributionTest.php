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
		$this->assertEquals( 'chatgpt', $order->get_meta( '_wc_ai_storefront_agent' ) );
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
		$this->assertEquals( 'gemini', $order->get_meta( '_wc_ai_storefront_agent' ) );
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
		$this->assertEquals( 'claude', $order->get_meta( '_wc_ai_storefront_agent' ) );
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
		// Regression check: orders placed via OUR continue_url have
		// utm_source = canonical brand name (e.g. "Gemini") + utm_medium
		// = 'ai_agent'. The strict gate must keep firing — not be
		// shadowed by lenient-gate behavior.
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'Gemini' );

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals(
			'Gemini',
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
		// host_raw preserves the actual case the agent sent, since
		// it's diagnostic provenance — DNS may be case-insensitive
		// but a future debug session might need to know the agent
		// declared "OpenAI.COM" specifically.
		$this->assertEquals(
			'OpenAI.COM',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY )
		);
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

		$this->assertEquals(
			'Gemini',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY )
		);
		// URL-param value lands in host_raw — the canonical "Gemini"
		// in utm_source is correctly NOT used here.
		$this->assertEquals(
			'gemini.google.com',
			$order->get_meta( WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY )
		);
	}
}
