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
	 * UCP protocol version.
	 */
	const PROTOCOL_VERSION = '1.0';

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
		header( 'Access-Control-Allow-Headers: X-AI-Agent-Key' );

		if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 204 );
			exit;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( false === $cached ) {
			$cached = wp_json_encode( $this->generate_manifest( $settings ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
			set_transient( self::CACHE_KEY, $cached, HOUR_IN_SECONDS );
		}
		echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON content.
		exit;
	}

	/**
	 * Generate the UCP manifest.
	 *
	 * @param array $settings AI syndication settings.
	 * @return array The manifest data.
	 */
	public function generate_manifest( $settings ) {
		$site_url  = home_url( '/' );
		$shop_url  = wc_get_page_permalink( 'shop' );
		$cart_url  = wc_get_cart_url();
		$checkout_url = wc_get_checkout_url();

		$manifest = [
			'protocol_version' => self::PROTOCOL_VERSION,
			'store'            => [
				'name'        => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'description' => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' ),
				'url'         => $site_url,
				'currency'    => get_woocommerce_currency(),
				'locale'      => get_locale(),
				'timezone'    => wp_timezone_string(),
			],

			// Checkout policy: web redirect ONLY, no in-chat payments.
			'checkout'         => [
				'method'       => 'web_redirect',
				'url'          => $checkout_url,
				'cart_url'     => $cart_url,
				'in_chat'      => false,
				'delegated'    => false,
				'instructions' => 'All purchases must be completed on the merchant website. Generate a redirect link to the store.',
			],

			// Capabilities the store exposes to AI agents.
			'capabilities'     => [
				'product_search'     => true,
				'cart_synchronization' => true,
				'price_verification' => true,
				'inventory_check'    => true,
				'category_browse'    => true,
				'attribution'        => true,
			],

			// API discovery.
			'api'              => [
				'base_url'       => rest_url( 'wc/v3/ai-syndication' ),
				'authentication' => [
					'type'   => 'api_key',
					'header' => 'X-AI-Agent-Key',
				],
				'endpoints'      => [
					[
						'path'        => '/products',
						'method'      => 'GET',
						'description' => 'Search and browse products',
						'parameters'  => [
							'search'   => 'Search query string',
							'category' => 'Category slug or ID',
							'per_page' => 'Results per page (max 100)',
							'page'     => 'Page number',
							'orderby'  => 'Sort field: popularity, price, date, rating',
							'order'    => 'Sort direction: asc, desc',
						],
					],
					[
						'path'        => '/products/{id}',
						'method'      => 'GET',
						'description' => 'Get a single product with full details',
					],
					[
						'path'        => '/categories',
						'method'      => 'GET',
						'description' => 'List product categories',
					],
					[
						'path'        => '/store',
						'method'      => 'GET',
						'description' => 'Store information and policies',
					],
					[
						'path'        => '/cart/prepare',
						'method'      => 'POST',
						'description' => 'Validate items and get checkout link + add-to-cart URLs',
						'parameters'  => [
							'items'      => 'Array of {product_id, quantity, variation_id}',
							'session_id' => 'AI session/conversation ID for attribution',
							'coupon'     => 'Optional coupon code to apply at checkout',
						],
					],
				],
			],

			// Purchase URLs — two patterns for different flows.
			'purchase'         => [
				// Recommended: checkout links add items AND redirect to checkout.
				// Customer never sees the cart — fewest clicks to purchase.
				'checkout_link' => [
					'simple'      => $site_url . 'checkout-link/?products={product_id}:{quantity}',
					'variable'    => $site_url . 'checkout-link/?products={variation_id}:{quantity}',
					'multi_item'  => $site_url . 'checkout-link/?products={id}:{qty},{id}:{qty}',
					'with_coupon' => $site_url . 'checkout-link/?products={id}:{qty}&coupon={coupon_code}',
					'grouped'     => $checkout_url . '?add-to-cart={grouped_product_id}&quantity[{sub_product_id}]={quantity}',
					'note'        => 'For simple/variable/multi-item: use checkout-link format. For grouped products: use add-to-cart with the checkout page as the base URL (adds to cart and lands on checkout). Use variation_id from the product detail API for variable products.',
				],
				// Alternative: add-to-cart URLs only add items to the cart.
				// Customer must navigate to checkout separately.
				'add_to_cart'   => [
					'simple'   => $site_url . '?add-to-cart={product_id}&quantity={quantity}',
					'variable' => $site_url . '?add-to-cart={variation_id}&quantity={quantity}',
					'grouped'  => $site_url . '?add-to-cart={grouped_product_id}&quantity[{sub_product_id}]={quantity}',
					'note'     => 'Adds to cart only — does not redirect to checkout. To redirect, use the checkout page URL as the base instead. External/affiliate products cannot be added via URL.',
				],
				'store_api'     => rest_url( 'wc/store/v1' ),
			],

			// Attribution via standard WooCommerce Order Attribution.
			'attribution'      => [
				'system'     => 'woocommerce_order_attribution',
				'parameters' => [
					'utm_source'    => 'Your agent identifier (e.g. chatgpt, gemini, perplexity)',
					'utm_medium'    => 'Must be set to "ai_agent"',
					'utm_campaign'  => 'Optional campaign name',
					'ai_session_id' => 'Conversation/session identifier for tracking',
				],
				'usage'        => 'Append these parameters to any add_to_cart or checkout_link URL.',
			],

			// Discovery endpoints.
			'discovery'        => [
				'llms_txt' => $site_url . 'llms.txt',
				'sitemap'  => home_url( '/wp-sitemap.xml' ),
			],

			// Rate limits.
			'rate_limits'      => [
				'requests_per_minute' => absint( $settings['rate_limit_rpm'] ?? 60 ),
				'requests_per_hour'   => absint( $settings['rate_limit_rph'] ?? 1000 ),
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
