<?php
/**
 * Tests for WC_AI_Syndication_Ucp.
 *
 * Pins the UCP discovery profile manifest shape against the official
 * business_profile schema at:
 *
 *   https://github.com/Universal-Commerce-Protocol/ucp/blob/main/source/discovery/profile_schema.json
 *
 * Tests are structured in three layers:
 *
 *   1. Strict UCP compliance — required fields, shapes, enum values.
 *      These must never drift or our manifest stops being spec-compliant.
 *   2. Pull-model posture — zero capabilities, zero payment_handlers.
 *      This is the plugin's core product decision (no delegated checkout,
 *      no identity linking, no agent-driven order flows).
 *   3. Plugin-specific config — the purchase_urls and attribution nested
 *      inside service.config. Permissive by design (UCP allows it), but
 *      agents that learn our namespace depend on the shape.
 *
 * @package WooCommerce_AI_Syndication
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Syndication_Ucp $ucp;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->ucp = new WC_AI_Syndication_Ucp();

		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . ( $path ?: '/' )
		);
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://example.com/cart/' );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://example.com/checkout/' );
		Functions\when( 'rest_url' )->alias(
			static fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' )
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Layer 1: Strict UCP compliance
	// ------------------------------------------------------------------

	public function test_manifest_has_required_top_level_ucp_key(): void {
		// The UCP business_profile schema requires `ucp` at the top
		// level. Everything else nests inside it.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayHasKey( 'ucp', $manifest );
	}

	public function test_ucp_object_has_required_fields(): void {
		// business_schema requires version, services, and
		// payment_handlers. `capabilities` is optional but we always
		// emit it as an empty object to be explicit.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayHasKey( 'version', $manifest['ucp'] );
		$this->assertArrayHasKey( 'services', $manifest['ucp'] );
		$this->assertArrayHasKey( 'payment_handlers', $manifest['ucp'] );
		$this->assertArrayHasKey( 'capabilities', $manifest['ucp'] );
	}

	public function test_version_is_yyyy_mm_dd_format(): void {
		// UCP schema pattern: ^\d{4}-\d{2}-\d{2}$ — semver like "1.0"
		// would be rejected by strict consumers.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}$/',
			$manifest['ucp']['version']
		);
	}

	public function test_version_constant_matches_manifest_version(): void {
		// Consistency check: the constant is what the serving code
		// uses elsewhere (cache key versioning, etc.), so it must match
		// what ends up in the manifest.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals(
			WC_AI_Syndication_Ucp::PROTOCOL_VERSION,
			$manifest['ucp']['version']
		);
	}

	// ------------------------------------------------------------------
	// Layer 1: Services registry (the one declared service)
	// ------------------------------------------------------------------

	public function test_services_registry_is_keyed_by_reverse_domain_name(): void {
		// UCP's propertyNames schema requires reverse-domain form for
		// both service and capability keys. The constant has to match
		// this pattern; verify at the manifest level too.
		$manifest = $this->ucp->generate_manifest( [] );

		$keys = array_keys( (array) $manifest['ucp']['services'] );
		$this->assertCount( 1, $keys );
		$this->assertMatchesRegularExpression(
			'/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9_]*)+$/',
			$keys[0]
		);
	}

	public function test_service_name_is_store_api_under_woocommerce_namespace(): void {
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayHasKey(
			'com.woocommerce.store_api',
			$manifest['ucp']['services']
		);
	}

	public function test_service_value_is_an_array_of_bindings(): void {
		// UCP schema: `services` is `object of string → array of service`.
		// Each key maps to an ARRAY, not a single binding, so a service
		// can declare multiple transport bindings in the future.
		$manifest = $this->ucp->generate_manifest( [] );
		$bindings = $manifest['ucp']['services']['com.woocommerce.store_api'];

		$this->assertIsArray( $bindings );
		$this->assertCount( 1, $bindings );
	}

	public function test_service_binding_has_required_rest_fields(): void {
		// For business_schema REST transport, `endpoint` is required.
		// `version` and `transport` are required on all entities;
		// `spec` is required at platform level and good practice at
		// business level (lets agents find human docs).
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['services']['com.woocommerce.store_api'][0];

		$this->assertArrayHasKey( 'version', $binding );
		$this->assertArrayHasKey( 'transport', $binding );
		$this->assertArrayHasKey( 'endpoint', $binding );
		$this->assertArrayHasKey( 'spec', $binding );
	}

	public function test_transport_enum_is_rest(): void {
		// UCP transport enum: rest | mcp | a2a | embedded.
		// The WC Store API is a REST endpoint, so we declare 'rest'.
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['services']['com.woocommerce.store_api'][0];

		$this->assertEquals( 'rest', $binding['transport'] );
	}

	public function test_endpoint_points_to_public_wc_store_api(): void {
		// The Store API is where agents actually fetch product data.
		// If this URL ever drifts, crawlers hit 404s and our pull
		// model breaks.
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['services']['com.woocommerce.store_api'][0];

		$this->assertEquals(
			'https://example.com/wp-json/wc/store/v1',
			$binding['endpoint']
		);
	}

	public function test_spec_url_points_to_official_wc_store_api_docs(): void {
		// Verified during authorship; documented in the generator's
		// docblock. If WooCommerce ever relocates this URL, update
		// the generator AND this test in the same commit.
		$manifest = $this->ucp->generate_manifest( [] );
		$binding  = $manifest['ucp']['services']['com.woocommerce.store_api'][0];

		$this->assertEquals(
			'https://developer.woocommerce.com/docs/apis/store-api',
			$binding['spec']
		);
	}

	// ------------------------------------------------------------------
	// Layer 2: Pull-model posture — zero capabilities, zero handlers
	// ------------------------------------------------------------------

	public function test_capabilities_is_empty_object(): void {
		// The plugin's core product decision: we don't implement UCP
		// Checkout, Identity Linking, Order webhooks, or Payment Token
		// Exchange capabilities. Declaring zero is the honest posture.
		//
		// JSON serializes PHP `[]` as an array and `new stdClass` (or
		// `(object) []`) as an object. The UCP schema requires OBJECT
		// shape here. Verify it survives the json_encode round-trip.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals(
			'{}',
			wp_json_encode_or_native( $manifest['ucp']['capabilities'] )
		);
	}

	public function test_payment_handlers_is_empty_object(): void {
		// Required top-level key per business_schema. Empty object
		// declares "zero handlers" — valid for merchants who don't
		// mediate payments through UCP.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals(
			'{}',
			wp_json_encode_or_native( $manifest['ucp']['payment_handlers'] )
		);
	}

	public function test_no_checkout_top_level_key(): void {
		// UCP has no top-level `checkout` object — checkout is a
		// `capability` businesses implement or don't. Our previous
		// manifest had a top-level `checkout` block declaring
		// "web_redirect only"; that's now implicit (zero capabilities).
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayNotHasKey( 'checkout', $manifest );
		$this->assertArrayNotHasKey( 'checkout', $manifest['ucp'] ?? [] );
	}

	public function test_no_stale_pre_ucp_top_level_fields(): void {
		// Regression guard: the 1.1.x manifest had these fields at the
		// top level. If a future refactor accidentally resurrects them
		// alongside the `ucp` key, the manifest violates the schema.
		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertArrayNotHasKey( 'protocol_version', $manifest );
		$this->assertArrayNotHasKey( 'store', $manifest );
		$this->assertArrayNotHasKey( 'purchase', $manifest );
		$this->assertArrayNotHasKey( 'attribution', $manifest );
		$this->assertArrayNotHasKey( 'discovery', $manifest );
		$this->assertArrayNotHasKey( 'rate_limits', $manifest );
		$this->assertArrayNotHasKey( 'store_api', $manifest );
	}

	// ------------------------------------------------------------------
	// Layer 3: Plugin-specific service config
	// ------------------------------------------------------------------

	private function get_config(): array {
		$manifest = $this->ucp->generate_manifest( [] );
		return $manifest['ucp']['services']['com.woocommerce.store_api'][0]['config'];
	}

	public function test_service_config_has_purchase_urls_and_attribution(): void {
		$config = $this->get_config();

		$this->assertArrayHasKey( 'purchase_urls', $config );
		$this->assertArrayHasKey( 'attribution', $config );
	}

	// ----- purchase_urls.checkout_link ----------------------------------

	public function test_checkout_link_templates_cover_all_supported_types(): void {
		// Agents generating purchase URLs need the full vocabulary of
		// product types the /checkout-link/ feature supports. Missing
		// one means that product type can't be handled by agents that
		// only read the manifest.
		$cl = $this->get_config()['purchase_urls']['checkout_link'];

		$this->assertArrayHasKey( 'simple', $cl );
		$this->assertArrayHasKey( 'variable', $cl );
		$this->assertArrayHasKey( 'multi_item', $cl );
		$this->assertArrayHasKey( 'with_coupon', $cl );
		$this->assertArrayHasKey( 'unsupported', $cl );
	}

	public function test_checkout_link_simple_template_matches_wc_spec(): void {
		// Per https://woocommerce.com/document/creating-sharable-checkout-urls-in-woocommerce/
		// single-product format is: /checkout-link/?products=PRODUCT_ID:QUANTITY
		$cl = $this->get_config()['purchase_urls']['checkout_link'];

		$this->assertEquals(
			'https://example.com/checkout-link/?products={product_id}:{quantity}',
			$cl['simple']
		);
	}

	public function test_checkout_link_multi_item_uses_comma_separator(): void {
		// Per WC docs: multi-product format is
		// `?products=id:qty,id:qty`.
		$cl = $this->get_config()['purchase_urls']['checkout_link'];

		$this->assertStringContainsString( ',', $cl['multi_item'] );
		$this->assertStringContainsString( ':', $cl['multi_item'] );
	}

	public function test_checkout_link_with_coupon_includes_coupon_param(): void {
		$cl = $this->get_config()['purchase_urls']['checkout_link'];

		$this->assertStringContainsString( 'coupon={coupon_code}', $cl['with_coupon'] );
	}

	public function test_checkout_link_declares_unsupported_types(): void {
		// Grouped, external, and subscription products CANNOT use the
		// /checkout-link/ feature. Agents need to know not to try.
		$cl = $this->get_config()['purchase_urls']['checkout_link'];

		$this->assertEqualsCanonicalizing(
			[ 'grouped', 'external', 'subscription' ],
			$cl['unsupported']
		);
	}

	// ----- purchase_urls.add_to_cart ------------------------------------

	public function test_add_to_cart_has_three_redirect_variants(): void {
		// Per https://woocommerce.com/document/quick-guide-to-woocommerce-add-to-cart-urls/
		// the three variants are: no redirect (home), redirect to cart,
		// redirect to checkout. All three have different base URLs.
		$a2c = $this->get_config()['purchase_urls']['add_to_cart'];

		$this->assertArrayHasKey( 'add_only', $a2c );
		$this->assertArrayHasKey( 'add_and_cart', $a2c );
		$this->assertArrayHasKey( 'add_and_checkout', $a2c );
	}

	public function test_add_and_cart_uses_cart_url_as_base(): void {
		$a2c = $this->get_config()['purchase_urls']['add_to_cart'];

		$this->assertStringStartsWith(
			'https://example.com/cart/',
			$a2c['add_and_cart']
		);
	}

	public function test_add_and_checkout_uses_checkout_url_as_base(): void {
		$a2c = $this->get_config()['purchase_urls']['add_to_cart'];

		$this->assertStringStartsWith(
			'https://example.com/checkout/',
			$a2c['add_and_checkout']
		);
	}

	public function test_add_to_cart_grouped_has_template_and_note(): void {
		// Grouped products have a separate entry because their URL
		// shape differs (quantity is a per-sub-product map, not a
		// single number). The note documents the quirk.
		$grouped = $this->get_config()['purchase_urls']['add_to_cart']['grouped'];

		$this->assertArrayHasKey( 'template', $grouped );
		$this->assertArrayHasKey( 'note', $grouped );
		$this->assertStringContainsString(
			'quantity[{sub_product_id}]={quantity}',
			$grouped['template']
		);
	}

	public function test_add_to_cart_external_note_present(): void {
		// External/affiliate products must not be add-to-carted —
		// agents link directly to the product's external_url field.
		$a2c = $this->get_config()['purchase_urls']['add_to_cart'];

		$this->assertArrayHasKey( 'external_note', $a2c );
		$this->assertStringContainsString( 'external_url', $a2c['external_note'] );
	}

	// ----- purchase_urls spec pointers ----------------------------------

	public function test_purchase_urls_points_to_canonical_wc_spec(): void {
		// Both spec URLs were verified during authorship. If they ever
		// break, AI agents reading the manifest get pointed at broken
		// docs. A link-check on these in CI would be ideal future work.
		$pu = $this->get_config()['purchase_urls'];

		$this->assertEquals(
			'https://woocommerce.com/document/creating-sharable-checkout-urls-in-woocommerce/',
			$pu['spec']
		);
		$this->assertEquals(
			'https://woocommerce.com/document/quick-guide-to-woocommerce-add-to-cart-urls/',
			$pu['add_to_cart_spec']
		);
	}

	// ----- attribution ---------------------------------------------------

	public function test_attribution_declares_woocommerce_order_attribution(): void {
		// The plugin's attribution strategy is "use WC's built-in
		// system" — not a custom invention. The `system` field makes
		// that explicit for agents that want to verify.
		$attr = $this->get_config()['attribution'];

		$this->assertEquals( 'woocommerce_order_attribution', $attr['system'] );
	}

	public function test_attribution_exposes_required_utm_parameters(): void {
		$attr = $this->get_config()['attribution'];

		$this->assertArrayHasKey( 'utm_source', $attr['parameters'] );
		$this->assertArrayHasKey( 'utm_medium', $attr['parameters'] );
		$this->assertArrayHasKey( 'utm_campaign', $attr['parameters'] );
		$this->assertArrayHasKey( 'ai_session_id', $attr['parameters'] );
	}

	public function test_attribution_utm_medium_documents_required_value(): void {
		// utm_medium MUST be "ai_agent" for the PHP-side detector to
		// capture the order. Document that in the param description
		// so agents know it's not free-form.
		$attr = $this->get_config()['attribution'];

		$this->assertStringContainsString(
			'ai_agent',
			$attr['parameters']['utm_medium']
		);
	}

	public function test_attribution_spec_points_to_wc_order_attribution_docs(): void {
		$attr = $this->get_config()['attribution'];

		$this->assertEquals(
			'https://woocommerce.com/document/order-attribution-tracking/',
			$attr['spec']
		);
	}

	// ------------------------------------------------------------------
	// Filter extensibility
	// ------------------------------------------------------------------

	public function test_manifest_is_filterable(): void {
		// The filter preserves the v1 signature: `apply_filters(
		// 'wc_ai_syndication_ucp_manifest', $manifest, $settings )`.
		// Third parties can extend the manifest (e.g., a plugin that
		// adds Payment Token Exchange would inject a payment handler
		// under ucp.payment_handlers).
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_syndication_ucp_manifest' === $hook ) {
					$value['ucp']['custom_key'] = 'extended';
				}
				return $value;
			}
		);

		$manifest = $this->ucp->generate_manifest( [] );

		$this->assertEquals( 'extended', $manifest['ucp']['custom_key'] );
	}
}

/**
 * Helper: encode JSON using native PHP json_encode (avoids depending on
 * wp_json_encode, which isn't stubbed for every test). The key behavior
 * we're verifying is that `(object) []` serializes as `{}` not `[]`.
 *
 * @param mixed $value Value to encode.
 * @return string
 */
function wp_json_encode_or_native( $value ): string {
	return json_encode( $value, JSON_UNESCAPED_SLASHES );
}
