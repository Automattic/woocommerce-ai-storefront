<?php
/**
 * AI Syndication: Robots.txt Integration
 *
 * Updates robots.txt to welcome AI crawlers and point them
 * to the llms.txt and UCP manifest.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages robots.txt directives for AI crawlers.
 */
class WC_AI_Syndication_Robots {

	/**
	 * Known AI crawler user agents.
	 *
	 * @var string[]
	 */
	const AI_CRAWLERS = [
		'GPTBot',
		'ChatGPT-User',
		'OAI-SearchBot',
		'Google-Extended',
		'Gemini',
		'PerplexityBot',
		'Perplexity-User',
		'ClaudeBot',
		'Claude-User',
		'Meta-ExternalAgent',
		'Amazonbot',
		'Applebot-Extended',
	];

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_filter( 'robots_txt', [ $this, 'add_ai_crawler_rules' ], 20, 2 );
	}

	/**
	 * Add AI crawler rules to robots.txt.
	 *
	 * @param string $output The existing robots.txt content.
	 * @param bool   $public Whether the site is public.
	 * @return string Modified robots.txt content.
	 */
	public function add_ai_crawler_rules( $output, $public ) {
		$settings = WC_AI_Syndication::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return $output;
		}

		if ( ! $public ) {
			return $output;
		}

		$allowed_bots = $settings['allowed_crawlers'] ?? self::AI_CRAWLERS;

		$output .= "\n# WooCommerce AI Syndication\n";
		$output .= "# Machine-readable store data for AI-assisted product discovery\n\n";

		// Derive paths from actual WooCommerce permalink settings.
		$shop_path     = wp_parse_url( wc_get_page_permalink( 'shop' ), PHP_URL_PATH ) ?: '/shop/';
		$cart_path     = wp_parse_url( wc_get_page_permalink( 'cart' ), PHP_URL_PATH ) ?: '/cart/';
		$checkout_path = wp_parse_url( wc_get_page_permalink( 'checkout' ), PHP_URL_PATH ) ?: '/checkout/';
		$account_path  = wp_parse_url( wc_get_page_permalink( 'myaccount' ), PHP_URL_PATH ) ?: '/my-account/';

		$product_base  = '/' . trim( get_option( 'woocommerce_permalinks', [] )['product_base'] ?? 'product', '/' ) . '/';
		$category_base = '/' . trim( get_option( 'woocommerce_permalinks', [] )['category_base'] ?? 'product-category', '/' ) . '/';

		foreach ( $allowed_bots as $bot ) {
			$bot     = sanitize_text_field( $bot );
			$output .= "User-agent: {$bot}\n";
			$output .= "Allow: /llms.txt\n";
			$output .= "Allow: /.well-known/ucp\n";
			$output .= "Allow: /wp-json/wc/store/\n";
			if ( '/' !== $shop_path ) {
				$output .= "Allow: {$shop_path}\n";
			}
			$output .= "Allow: {$product_base}\n";
			$output .= "Allow: {$category_base}\n";
			$output .= "Disallow: {$cart_path}\n";
			$output .= "Disallow: {$checkout_path}\n";
			$output .= "Disallow: {$account_path}\n";
			$output .= "\n";
		}

		/**
		 * Filter the AI crawler robots.txt rules.
		 *
		 * @since 1.0.0
		 * @param string $output   The robots.txt content.
		 * @param array  $settings The AI syndication settings.
		 */
		return apply_filters( 'wc_ai_syndication_robots_txt', $output, $settings );
	}
}
