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
	 * Bumped from 2026-01-11 → 2026-04-08 in plugin 1.3.0 to track the
	 * then-current UCP spec revision referenced below.
	 * `WC_AI_Syndication_UCP_Envelope::catalog_envelope()` and
	 * `checkout_envelope()` both read this constant, so the bump flows
	 * through to every handler response envelope automatically.
	 *
	 * @link https://github.com/Universal-Commerce-Protocol/ucp/blob/main/source/schemas/ucp.json
	 */
	const PROTOCOL_VERSION = '2026-04-08';

	/**
	 * Reverse-domain name for our published service.
	 *
	 * Pre-1.3.0 this was `com.woocommerce.store_api` — we advertised
	 * the raw WC Store API as a generic service. Now that we host
	 * UCP-shaped endpoints at `/wp-json/wc/ucp/v1/`, we declare the
	 * canonical `dev.ucp.shopping` identifier so UCP-aware agents
	 * discover the right entry point directly.
	 *
	 * `dev.ucp.*` is not reserved against third-party use per the UCP
	 * authority-identifier convention — it names UCP-defined services
	 * that anyone can implement. Our implementation lives at the
	 * endpoint this constant points to.
	 */
	const SERVICE_NAME = 'dev.ucp.shopping';

	/**
	 * Transient key for cached UCP manifest.
	 */
	const CACHE_KEY = 'wc_ai_syndication_ucp';

	/**
	 * Short-circuit canonical-URL redirects for the manifest endpoint.
	 *
	 * @param string|false $redirect_url WP's candidate canonical URL.
	 * @return string|false               False disables the redirect;
	 *                                   original value otherwise.
	 */
	public function suppress_canonical_redirect( $redirect_url ) {
		if ( get_query_var( 'wc_ai_syndication_ucp' ) ) {
			return false;
		}
		return $redirect_url;
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
	 * Plugin 1.3.0 shifted posture from "discovery-only" to "UCP
	 * adapter":
	 *
	 *   - One `service`: `dev.ucp.shopping` (REST) pointing at our
	 *     own `/wp-json/wc/ucp/v1/` endpoint. Agents hit this base
	 *     URL + capability-specific paths (e.g. POST /catalog/search)
	 *     to invoke UCP operations.
	 *   - Two declared `capabilities`:
	 *       - `dev.ucp.shopping.catalog`  (search + lookup)
	 *       - `dev.ucp.shopping.checkout` (stateless create)
	 *   - Zero `payment_handlers`. Checkout stays on the merchant's
	 *     site — every checkout-sessions response returns
	 *     `status: requires_escalation` with a `continue_url` into
	 *     WooCommerce's native Shareable Checkout flow. Merchants
	 *     keep ownership of payment, tax, fulfillment.
	 *
	 * Service-level `config` preserves the purchase-URL templates and
	 * attribution guidance we emitted pre-1.3.0. Agents that consume
	 * our UCP endpoints rarely need these — our checkout-sessions
	 * handler assembles the final URL itself — but the config is
	 * still useful documentation for merchants and for agents that
	 * want to construct checkout URLs directly without the UCP round
	 * trip. UCP schema permits `config` as additionalProperties on
	 * any entity, so strict consumers ignore it gracefully.
	 *
	 * A sibling `store_context` object declares merchant-level
	 * context (currency, locale, country, tax/shipping posture) so
	 * agents know what currency they'll be quoting in and whether
	 * the catalog price matches the checkout price — without having
	 * to either fetch llms.txt first or call the Store API. Added in
	 * 1.4.5 in response to cross-agent review feedback that surfaced
	 * this as the dominant manifest-level gap. See
	 * `build_store_context()` for field-by-field rationale.
	 *
	 * Not included (deliberately):
	 *   - Store name/description: redundant with Store API responses
	 *     and llms.txt. The store_context block is for commerce-
	 *     semantic hints only, not human-readable metadata.
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
		$ucp_endpoint = rest_url( 'wc/ucp/v1' );

		$manifest = [
			'ucp'           => [
				'version'          => self::PROTOCOL_VERSION,

				// Services — single REST binding at our UCP namespace.
				// Under business_schema, a REST binding requires
				// `endpoint`; `spec` points at the UCP spec for
				// consumers that want to verify our shape.
				'services'         => [
					self::SERVICE_NAME => [
						[
							'version'   => self::PROTOCOL_VERSION,

							// Pin the spec URL to the git tag that
							// matches our declared PROTOCOL_VERSION.
							// The UCP spec repo publishes date-named
							// tags (e.g. `v2026-04-08`) that align
							// with the protocol version string, so
							// one constant drives both — if we bump
							// PROTOCOL_VERSION the URL tracks
							// automatically. Pre-1.4.5 this pointed
							// at `/tree/main/` which is a moving
							// target; a consumer verifying our shape
							// a year later could have been reading
							// a spec revision we never conformed to.
							'spec'      => 'https://github.com/Universal-Commerce-Protocol/ucp/tree/v' . self::PROTOCOL_VERSION . '/source/schemas/shopping',
							'transport' => 'rest',
							'endpoint'  => $ucp_endpoint,

							// Service-specific config. UCP's entity
							// schema explicitly allows this (`config`
							// on any entity, `additionalProperties:
							// true`). Kept for documentation value
							// and for agents that construct checkout
							// URLs directly; strict UCP consumers
							// ignore these keys.
							'config'    => [
								'purchase_urls' => [
									'spec'             => 'https://woocommerce.com/document/creating-sharable-checkout-urls-in-woocommerce/',
									'add_to_cart_spec' => 'https://woocommerce.com/document/quick-guide-to-woocommerce-add-to-cart-urls/',

									// Checkout-link: adds items AND
									// redirects to checkout. Customer
									// never sees the cart — fewest
									// clicks to purchase.
									'checkout_link'    => [
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
									'add_to_cart'      => [
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

								'attribution'   => [
									'spec'       => 'https://woocommerce.com/document/order-attribution-tracking/',
									'system'     => 'woocommerce_order_attribution',
									'parameters' => [
										'utm_source'    => 'Your agent identifier (e.g. chatgpt, gemini, perplexity)',
										'utm_medium'    => 'Must be set to "ai_agent"',
										'utm_campaign'  => 'Optional campaign name',
										'ai_session_id' => 'Conversation/session identifier for tracking',
									],
									'usage_note' => 'Append these parameters to any checkout_link or add_to_cart URL. The UCP /checkout-sessions endpoint adds utm_source + utm_medium automatically from the UCP-Agent header.',
								],
							],
						],
					],
				],

				// UCP shopping capabilities we implement. Per the
				// business_profile schema linked above, each capability
				// key maps to an ARRAY of binding objects (one per
				// implementation version) — the array wrapper leaves
				// room to advertise multiple versions concurrently in
				// the future. Consumers key off
				// `dev.ucp.shopping.{capability}` to discover whether
				// our implementation covers their use case.
				//
				// Since 1.6.0 we advertise the two catalog sub-
				// capabilities explicitly rather than the umbrella
				// `dev.ucp.shopping.catalog`. The April UCP spec
				// formalized `catalog.search` and `catalog.lookup`
				// as separate schemas; splitting the advertisement
				// lets agents discover precisely which operations
				// are available. Our implementation covers both.
				'capabilities'     => [
					'dev.ucp.shopping.catalog.search' => [
						[ 'version' => self::PROTOCOL_VERSION ],
					],
					'dev.ucp.shopping.catalog.lookup' => [
						[ 'version' => self::PROTOCOL_VERSION ],
					],
					// `mode: handoff` signals that our checkout
					// implementation is redirect-only — agents that
					// call POST /checkout-sessions get a `continue_url`
					// back and are expected to redirect the user to
					// WooCommerce's own checkout. No in-chat payment
					// processing, no server-side cart lifecycle. This
					// is an additive hint (UCP schema's
					// additionalProperties: true accommodates it) so
					// agents that understand it can branch on it, and
					// agents that don't just see an extra field.
					//
					// The runtime signal for the same pattern is the
					// response's `status: requires_escalation` +
					// `continue_url`, but the manifest-level `mode`
					// lets agents decide whether to invoke at all
					// without a roundtrip.
					'dev.ucp.shopping.checkout'       => [
						[
							'version' => self::PROTOCOL_VERSION,
							'mode'    => 'handoff',
						],
					],
				],

				// Required by business_schema. Empty object is the
				// valid "zero handlers" declaration — merchant's WC
				// checkout handles payment via their configured gateway.
				'payment_handlers' => (object) [],
			],

			// Merchant-level commerce context. Sibling to `ucp`
			// rather than nested inside, because these facts are
			// agnostic of the UCP spec — any AI-commerce ecosystem
			// tool (UCP-aware or not) can read them. Fields match
			// common ecommerce-platform conventions (Stripe, Shopify)
			// so consumer code already familiar with those names
			// reads our manifest without a glossary step.
			'store_context' => $this->build_store_context(),
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

	/**
	 * Build the merchant-level commerce context block.
	 *
	 * Declares the store's currency, locale, base country, and
	 * commerce-posture facts (tax inclusion, shipping enablement)
	 * that agents need to know BEFORE they start consuming catalog
	 * data — not after. Pre-1.4.5 this information lived only in
	 * llms.txt (currency) and Store API responses (tax posture
	 * on per-price endpoints), leaving agents who only fetched the
	 * UCP manifest flying blind on basic commerce semantics.
	 *
	 * Field notes:
	 *
	 *   - `currency`: ISO 4217. Source of truth is WooCommerce's
	 *     stored currency setting; drives every price string on
	 *     the store.
	 *
	 *   - `locale`: BCP 47 form (`en-US`). WordPress stores locale
	 *     in ICU underscore form (`en_US`); we convert for standards
	 *     compatibility — HTTP `Accept-Language`, browser
	 *     `navigator.language`, and most web APIs expect hyphens.
	 *
	 *   - `country`: ISO 3166-1 alpha-2. The merchant's base
	 *     country, which controls default tax and shipping behavior.
	 *     Not the customer's country — an AI agent talking to a
	 *     customer in France buying from a US-based store should
	 *     see `country: US` here.
	 *
	 *   - `prices_include_tax`: boolean. Tells agents whether the
	 *     price string they see on catalog endpoints already
	 *     includes tax, or whether tax will be added at checkout.
	 *     This is THE critical signal for agents quoting totals
	 *     honestly to users. VAT-inclusive storefronts (common in
	 *     EU) return `true`; US-style tax-exclusive storefronts
	 *     return `false`.
	 *
	 *   - `shipping_enabled`: boolean. Whether the store collects
	 *     shipping addresses at checkout. `false` → digital-only
	 *     or in-person-only store; agents can skip shipping
	 *     prompts. Detected via `wc_shipping_enabled()`, which
	 *     reflects the top-level WC shipping toggle.
	 *
	 * @return array The store context block.
	 */
	private function build_store_context() {
		$country = null;
		if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'countries' ) && WC()->countries ) {
			$country = WC()->countries->get_base_country();
		}

		// `get_locale()` returns ICU format (e.g. `en_US`). BCP 47
		// uses hyphens. Swap the single underscore delimiter; if
		// future WP releases add more (e.g. script subtags), the
		// conversion still holds because BCP 47 also uses hyphens
		// for those.
		$locale = str_replace( '_', '-', get_locale() );

		return [
			'currency'           => get_woocommerce_currency(),
			'locale'             => $locale,
			'country'            => $country ? $country : null,
			'prices_include_tax' => (bool) wc_prices_include_tax(),
			'shipping_enabled'   => (bool) wc_shipping_enabled(),
		];
	}
}
