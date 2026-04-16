<?php
/**
 * AI Syndication: Universal Commerce Protocol (UCP) Manifest
 *
 * Serves a /.well-known/ucp manifest that declares the store's
 * AI commerce capabilities with web-redirect only checkout.
 * No delegated/in-chat payments (no ACP).
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the /.well-known/ucp manifest endpoint.
 */
class WC_AI_Syndication_Ucp {

	/**
	 * UCP protocol version target.
	 *
	 * Date-formatted per the UCP schema (YYYY-MM-DD). This is the
	 * revision of the protocol our manifest conforms to, NOT our
	 * plugin version. It should change when we upgrade to a newer
	 * UCP protocol revision, which may or may not align with plugin
	 * releases.
	 *
	 * @link https://github.com/Universal-Commerce-Protocol/ucp/blob/main/source/schemas/ucp.json
	 */
	const PROTOCOL_VERSION = '2026-01-11';

	/**
	 * Reverse-domain name for our published service.
	 *
	 * UCP requires services keyed by reverse-domain name. The WooCommerce
	 * Store API is the REST endpoint agents pull product and cart data
	 * from — we publish it under our own namespace so it's uniquely
	 * identifiable even if a site runs multiple UCP-speaking plugins.
	 */
	const SERVICE_NAME = 'com.woocommerce.store_api';

	/**
	 * Transient key for cached UCP manifest.
	 */
	const CACHE_KEY = 'wc_ai_syndication_ucp';

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_action( 'template_redirect', [ $this, 'serve_manifest' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
	}

	/**
	 * Add rewrite rule for /.well-known/ucp.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^\.well-known/ucp$', 'index.php?wc_ai_syndication_ucp=1', 'top' );
	}

	/**
	 * Register query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'wc_ai_syndication_ucp';
		return $vars;
	}

	/**
	 * Serve the UCP manifest.
	 */
	public function serve_manifest() {
		if ( ! get_query_var( 'wc_ai_syndication_ucp' ) ) {
			return;
		}

		$settings = WC_AI_Syndication::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			status_header( 404 );
			exit;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
		// Note: no `Access-Control-Allow-Headers` entry. The UCP
		// manifest is fetched with a bare GET — no custom headers,
		// so CORS preflight with header allowlists isn't triggered.
		// A prior version of this file advertised `X-AI-Agent-Key`
		// here, left over from the authenticated-catalog-API era.

		if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 204 );
			exit;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( false === $cached ) {
			WC_AI_Syndication_Logger::debug( 'UCP manifest cache miss — regenerating' );
			$cached = wp_json_encode( $this->generate_manifest( $settings ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
			set_transient( self::CACHE_KEY, $cached, HOUR_IN_SECONDS );
		} else {
			WC_AI_Syndication_Logger::debug( 'UCP manifest cache hit' );
		}
		echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON content.
		exit;
	}

	/**
	 * Generate the UCP discovery profile manifest.
	 *
	 * Conforms to the UCP `business_profile` schema at:
	 *
	 *   https://github.com/Universal-Commerce-Protocol/ucp/blob/main/source/discovery/profile_schema.json
	 *
	 * The spec requires `ucp: { version, services, payment_handlers }`.
	 * `capabilities` is optional but must be an object when present.
	 *
	 * This plugin implements a discovery-only / pull-model posture:
	 *
	 *   - One `service`: the public WooCommerce Store API (REST).
	 *     This is where agents pull product and cart data.
	 *   - Zero `capabilities`. The plugin does not implement UCP
	 *     Checkout, Identity Linking, Order webhooks, or Payment
	 *     Token Exchange. Checkout stays on the merchant's site
	 *     (web-redirect model, never delegated or in-chat).
	 *   - Zero `payment_handlers`. Required top-level key by the
	 *     schema; empty object is the valid declaration for a
	 *     merchant that doesn't mediate payments.
	 *
	 * Bespoke information useful to AI agents — purchase URL templates,
	 * WooCommerce Order Attribution parameters — lives inside
	 * `services[...].config`, which is the UCP entity schema's
	 * documented place for entity-specific configuration. Strict
	 * UCP consumers ignore unknown config keys; agents that learn
	 * our namespace can use them.
	 *
	 * Not included (deliberately):
	 *   - Store metadata (name, description, currency): redundant
	 *     with the Store API's own responses and with llms.txt.
	 *   - Rate limits: enforced through HTTP 429 / Retry-After
	 *     response headers, not manifest advertisement.
	 *   - Pointers to llms.txt / sitemap: agents find llms.txt at
	 *     `/llms.txt` (known location) and sitemap via robots.txt.
	 *
	 * @param array $settings AI syndication settings (unused; retained
	 *                        in signature for the `apply_filters` hook
	 *                        contract).
	 * @return array The manifest data.
	 */
	public function generate_manifest( $settings ) {
		$site_url     = home_url( '/' );
		$checkout_url = wc_get_checkout_url();
		$cart_url     = wc_get_cart_url();
		$store_api    = rest_url( 'wc/store/v1' );

		$manifest = [
			'ucp' => [
				'version' => self::PROTOCOL_VERSION,

				// Services — REST transport binding for the public
				// Store API. Under business_schema, a REST binding
				// requires `endpoint`; `schema` is optional (WC does
				// not publish a single aggregated OpenAPI document,
				// so we point `spec` at the human docs instead).
				'services' => [
					self::SERVICE_NAME => [
						[
							'version'   => self::PROTOCOL_VERSION,
							'spec'      => 'https://developer.woocommerce.com/docs/apis/store-api',
							'transport' => 'rest',
							'endpoint'  => $store_api,

							// Service-specific config. UCP's entity
							// schema explicitly allows this (`config`
							// on any entity, `additionalProperties:
							// true`). Agents with knowledge of our
							// service namespace read these; strict
							// UCP consumers ignore them.
							'config'    => [
								'purchase_urls' => [
									'spec'             => 'https://woocommerce.com/document/creating-sharable-checkout-urls-in-woocommerce/',
									'add_to_cart_spec' => 'https://woocommerce.com/document/quick-guide-to-woocommerce-add-to-cart-urls/',

									// Checkout-link: adds items AND
									// redirects to checkout. Customer
									// never sees the cart — fewest
									// clicks to purchase.
									'checkout_link' => [
										'description' => 'Recommended — adds items and redirects to checkout in one step. Customer never sees the cart.',
										'simple'      => $site_url . 'checkout-link/?products={product_id}:{quantity}',
										'variable'    => $site_url . 'checkout-link/?products={variation_id}:{quantity}',
										'multi_item'  => $site_url . 'checkout-link/?products={id}:{qty},{id}:{qty}',
										'with_coupon' => $site_url . 'checkout-link/?products={id}:{qty}&coupon={coupon_code}',
										'unsupported' => [ 'grouped', 'external', 'subscription' ],
									],

									// Classic add-to-cart URL. Three
									// behaviors depending on base URL
									// — the official WC docs document
									// all three.
									'add_to_cart' => [
										'description'      => 'Classic WooCommerce add-to-cart URL. Three behaviors depending on base URL.',
										'add_only'         => $site_url . '?add-to-cart={product_id}&quantity={quantity}',
										'add_and_cart'     => $cart_url . '?add-to-cart={product_id}&quantity={quantity}',
										'add_and_checkout' => $checkout_url . '?add-to-cart={product_id}&quantity={quantity}',
										'grouped'          => [
											'template' => $checkout_url . '?add-to-cart={grouped_product_id}&quantity[{sub_product_id}]={quantity}',
											'note'     => 'Use the classic add-to-cart pattern for grouped products. The checkout-link feature does not support them.',
										],
										'external_note'    => 'For external/affiliate products (type: external), link directly to the product\'s external_url field from the Store API. Do not use add-to-cart.',
									],
								],

								'attribution' => [
									'spec'       => 'https://woocommerce.com/document/order-attribution-tracking/',
									'system'     => 'woocommerce_order_attribution',
									'parameters' => [
										'utm_source'    => 'Your agent identifier (e.g. chatgpt, gemini, perplexity)',
										'utm_medium'    => 'Must be set to "ai_agent"',
										'utm_campaign'  => 'Optional campaign name',
										'ai_session_id' => 'Conversation/session identifier for tracking',
									],
									'usage_note' => 'Append these parameters to any checkout_link or add_to_cart URL.',
								],
							],
						],
					],
				],

				// No UCP capabilities implemented. Declaring zero is
				// the honest posture for a merchant opted out of
				// delegated checkout, identity linking, and
				// agent-driven order flows. `(object)` cast ensures
				// JSON serializes as `{}` not `[]`.
				'capabilities' => (object) [],

				// Required by business_schema. Empty object is the
				// valid "zero handlers" declaration.
				'payment_handlers' => (object) [],
			],
		];

		/**
		 * Filter the UCP manifest data.
		 *
		 * @since 1.0.0
		 * @param array $manifest The UCP manifest.
		 * @param array $settings The AI syndication settings.
		 */
		return apply_filters( 'wc_ai_syndication_ucp_manifest', $manifest, $settings );
	}
}
