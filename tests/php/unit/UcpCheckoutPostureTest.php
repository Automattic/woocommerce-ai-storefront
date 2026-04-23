<?php
/**
 * Tests for the plugin's "merchant-only checkout" security posture.
 *
 * The plugin is built on a specific security commitment: all
 * purchases complete on the merchant's site; no in-chat payment,
 * no embedded checkout, no agent-delegated authorization. This is
 * NOT enforced by runtime code checks — it's enforced by NOT
 * implementing the dangerous UCP surfaces in the first place.
 *
 * These tests lock that posture in place. Each test maps to a
 * specific UCP feature that, if accidentally enabled, would weaken
 * the merchant-only-checkout commitment. Collectively they prevent
 * posture drift: a future maintainer adding one of the flagged
 * features without conscious security review will fail CI.
 *
 * The invariants covered (1.6.6 "Moderate" vigilance level):
 *
 *   Manifest shape:
 *     - payment_handlers is empty ({})
 *     - No `dev.ucp.shopping.ap2_mandate` capability
 *     - No `dev.ucp.shopping.cart` capability
 *     - All service bindings use transport: "rest" (no embedded)
 *     - Checkout capability carries no config (removed in 1.6.5)
 *
 *   Runtime behavior:
 *     - continue_url uses the merchant's own origin
 *     - REST routes registered are exactly {search, lookup,
 *       checkout-sessions} — no Complete Checkout endpoint
 *     - Response status is only "requires_escalation" or "error"
 *
 * Maintainer note: if you need to legitimately add one of these
 * surfaces (e.g. introduce cart support), expect to update this
 * file. That's the point — changing posture should require a
 * conscious test update, not silent capability drift.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpCheckoutPostureTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Ucp $ucp;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->ucp = new WC_AI_Storefront_Ucp();

		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . ( $path ?: '/' )
		);
		Functions\when( 'rest_url' )->alias(
			static fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' )
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wc_prices_include_tax' )->justReturn( false );
		Functions\when( 'wc_shipping_enabled' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Manifest-level posture invariants
	// ------------------------------------------------------------------

	public function test_payment_handlers_is_empty_object(): void {
		// Declaring ANY payment handler (Stripe tokens, Google Pay,
		// Shop Pay, etc.) means accepting agent-delegated payment
		// instruments — which could flow through our server without
		// the buyer touching merchant UI. Empty object = zero
		// handlers = buyer MUST reach merchant checkout for payment.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertSame( '{}', json_encode( $manifest['ucp']['payment_handlers'] ) );
	}

	public function test_no_ap2_mandate_capability_advertised(): void {
		// AP2 Mandates enable agents to carry cryptographically-
		// signed authorizations proving the user delegated payment
		// authority. Advertising this capability means accepting
		// agent-delegated transactions — the opposite of merchant-
		// only checkout. Per spec: "Businesses declare support by
		// adding `dev.ucp.shopping.ap2_mandate` to their
		// capabilities list." Absence is the spec-canonical way
		// to declare non-support.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayNotHasKey(
			'dev.ucp.shopping.ap2_mandate',
			$manifest['ucp']['capabilities'],
			'AP2 Mandates accept agent-delegated payment authorization — this plugin does not support that'
		);
	}

	public function test_no_cart_capability_advertised(): void {
		// Persistent cart state is a building block for agent-side
		// checkout completion. Declaring `dev.ucp.shopping.cart`
		// means agents can build up server-side cart state that's
		// then finalized via Checkout's Complete operation — a path
		// to programmatic (agent-initiated) finalization that
		// bypasses merchant UI. Non-declaration keeps the session
		// stateless and handoff-only.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayNotHasKey(
			'dev.ucp.shopping.cart',
			$manifest['ucp']['capabilities'],
			'Cart capability enables stateful carts that could be completed without buyer handoff'
		);
	}

	public function test_no_embedded_protocol_transport(): void {
		// The UCP Embedded Protocol (EP) transport lets agents
		// render the business's checkout UI inline in an iframe or
		// webview. While technically still merchant-hosted, it
		// breaks the "user navigates to merchant site" UX pattern
		// and enables tighter agent-mediated flows. We advertise
		// REST transport exclusively; no EP binding is offered.
		$manifest = $this->ucp->generate_manifest( [] );

		foreach ( $manifest['ucp']['services'] as $service_name => $bindings ) {
			foreach ( $bindings as $binding ) {
				$this->assertSame(
					'rest',
					$binding['transport'],
					"Service $service_name declares non-REST transport — EP/MCP/A2A risk checkout UX boundary"
				);
			}
		}
	}

	public function test_checkout_capability_has_no_config_block(): void {
		// Pre-1.6.5 we carried URL templates + attribution in a
		// config block here. Removed per UCP spec's SHOULD
		// directive favoring business-provided continue_url.
		// Regression guard: if config re-appears, we've either
		// re-introduced templates (spec-unfavored) or added some
		// other capability override that might weaken the posture.
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['capabilities']['dev.ucp.shopping.checkout'][0];

		$this->assertArrayNotHasKey(
			'config',
			$binding,
			'Checkout capability config was removed in 1.6.5 — re-adding it requires posture review'
		);
	}

	public function test_checkout_capability_has_no_mode_field(): void {
		// The pre-1.6.5 `mode: "handoff"` was a custom hint. If a
		// future refactor adds something like `mode: "embedded"` or
		// `mode: "delegated"`, this test fires — those modes would
		// imply non-merchant completion paths.
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['capabilities']['dev.ucp.shopping.checkout'][0];

		$this->assertArrayNotHasKey( 'mode', $binding );
	}

	public function test_no_payment_token_exchange_capability(): void {
		// Payment Token Exchange extensions let agents swap opaque
		// tokens for payment instruments. Any capability key
		// containing "payment" or "token" beyond what we advertise
		// warrants review.
		$manifest         = $this->ucp->generate_manifest( [] );
		$capability_names = array_keys( $manifest['ucp']['capabilities'] );

		foreach ( $capability_names as $name ) {
			$this->assertStringNotContainsString( 'payment', strtolower( $name ), "Capability $name mentions payment — review for delegated-payment risk" );
			$this->assertStringNotContainsString( 'token', strtolower( $name ), "Capability $name mentions token — review for token-exchange risk" );
		}
	}

	public function test_extension_capability_carries_no_payment_fields(): void {
		// The `com.woocommerce.ai_storefront` extension is
		// legitimately ours to extend — but the merchant-only
		// posture requires that it carry only store_context +
		// attribution guidance, not any payment-related fields.
		$manifest = $this->ucp->generate_manifest( [] );
		$config   = $manifest['ucp']['capabilities']['com.woocommerce.ai_storefront'][0]['config'];

		foreach ( array_keys( $config ) as $key ) {
			$this->assertStringNotContainsString( 'payment', strtolower( $key ), "Extension config field $key mentions payment" );
			$this->assertStringNotContainsString( 'handler', strtolower( $key ), "Extension config field $key mentions handler" );
			$this->assertStringNotContainsString( 'mandate', strtolower( $key ), "Extension config field $key mentions mandate" );
		}
	}

	// ------------------------------------------------------------------
	// Runtime-behavior posture invariants
	// ------------------------------------------------------------------

	public function test_registered_rest_routes_are_the_exact_posture_set(): void {
		// The plugin registers three commerce POST routes
		// (catalog/search, catalog/lookup, checkout-sessions) and
		// one docs GET route (extension/schema). No Complete
		// Checkout, no Update Checkout, no GET /checkout-sessions/{id},
		// no cart routes — those would either enable programmatic
		// completion or imply persistent checkout state that the
		// handoff model rejects.
		//
		// `extension/schema` is a read-only JSON Schema endpoint for
		// the `com.woocommerce.ai_storefront` merchant extension —
		// serves static documentation content, NOT commerce state. It
		// is explicitly posture-compatible: no order/cart/payment
		// semantics.
		//
		// If a future change adds any route not in this whitelist,
		// this test fires — forcing the maintainer to either legitimize
		// the new route in the posture docs or revert.
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route ) use ( &$registered ) {
				$registered[] = rtrim( $namespace, '/' ) . $route;
				return true;
			}
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->register_routes();

		sort( $registered );
		$this->assertSame(
			[
				'wc/ucp/v1/catalog/lookup',
				'wc/ucp/v1/catalog/search',
				'wc/ucp/v1/checkout-sessions',
				'wc/ucp/v1/extension/schema',
			],
			$registered
		);
	}

	public function test_declared_capabilities_list_is_exactly_expected(): void {
		// Hard-pin the canonical capability set: three UCP capabilities
		// we implement + one merchant-specific extension. Any additional
		// key risks introducing a path we haven't reviewed for posture
		// compliance.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEqualsCanonicalizing(
			[
				'dev.ucp.shopping.catalog.search',
				'dev.ucp.shopping.catalog.lookup',
				'dev.ucp.shopping.checkout',
				'com.woocommerce.ai_storefront',
			],
			array_keys( $manifest['ucp']['capabilities'] ),
			'Capability set changed — audit new capability for merchant-only-checkout impact'
		);
	}

	public function test_no_signing_keys_at_profile_root(): void {
		// The UCP discovery profile base schema allows `signing_keys`
		// at root — used by extensions like AP2 Mandates to verify
		// business-signed JWS authorizations. Emitting signing_keys
		// would imply we can cryptographically authorize transactions
		// the agent could then complete. We don't. Absence of
		// signing_keys at root is a signal that this merchant
		// doesn't participate in agent-delegated payment flows.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayNotHasKey(
			'signing_keys',
			$manifest,
			'signing_keys support authorized-delegation flows like AP2 Mandates'
		);
	}
}
