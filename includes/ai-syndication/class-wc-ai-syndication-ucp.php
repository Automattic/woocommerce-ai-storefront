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

		echo wp_json_encode( $this->generate_manifest( $settings ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
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
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
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
						'description' => 'Prepare a cart with items for redirect checkout',
					],
				],
			],

			// Cart synchronization via WooCommerce Store API.
			'cart_sync'        => [
				'enabled'     => true,
				'store_api'   => rest_url( 'wc/store/v1' ),
				'add_to_cart' => $shop_url . '?add-to-cart={product_id}&quantity={quantity}&utm_source={agent_id}&utm_medium=ai_agent',
				'description' => 'AI agents can pre-populate a cart before redirecting the customer to the store.',
			],

			// Attribution via standard WooCommerce Order Attribution.
			'attribution'      => [
				'system'     => 'woocommerce_order_attribution',
				'parameters' => [
					'utm_source'    => 'Agent identifier (e.g. chatgpt, gemini, perplexity)',
					'utm_medium'    => 'Must be set to "ai_agent"',
					'utm_campaign'  => 'Optional campaign name',
					'ai_session_id' => 'Conversation/session identifier for tracking',
				],
				'url_template' => $site_url . '{product_path}?utm_source={agent_id}&utm_medium=ai_agent&utm_campaign={campaign}&ai_session_id={session_id}',
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
