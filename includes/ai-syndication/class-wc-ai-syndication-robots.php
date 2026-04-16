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
	 * Sanitize an `allowed_crawlers` input against the canonical crawler list.
	 *
	 * Strips unknown IDs left over from plugin upgrades that rotated the
	 * crawler roster (e.g. the Bytespider → OAI-SearchBot swap in v1.1.0)
	 * and any malformed / non-matching strings. Keeping the stored list in
	 * sync with AI_CRAWLERS prevents deprecated `User-agent: Bytespider`
	 * blocks from leaking into `robots.txt` and keeps the admin UI's
	 * "X of Y" count honest.
	 *
	 * @param mixed $input Raw input from settings save — expected array of strings.
	 * @return string[]    Re-indexed list of valid crawler IDs.
	 */
	public static function sanitize_allowed_crawlers( $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$sanitized = array_map( 'sanitize_text_field', $input );

		// `array_intersect` preserves first-argument keys, so `array_values`
		// re-indexes — otherwise the JSON response serializes as an object.
		return array_values( array_intersect( $sanitized, self::AI_CRAWLERS ) );
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_filter( 'robots_txt', [ $this, 'add_ai_crawler_rules' ], 20, 2 );
	}

	/**
	 * Add AI crawler rules to robots.txt.
	 *
	 * Hooked onto WordPress's `robots_txt` filter. WP passes whether the
	 * site is "public" (Reading > Search engine visibility) as the second
	 * argument; we no-op on private sites to avoid advertising a catalog
	 * the operator explicitly wants hidden.
	 *
	 * @param string $output    The existing robots.txt content.
	 * @param bool   $is_public Whether the site is publicly visible.
	 * @return string Modified robots.txt content.
	 */
	public function add_ai_crawler_rules( $output, $is_public ) {
		$settings = WC_AI_Syndication::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return $output;
		}

		if ( ! $is_public ) {
			return $output;
		}

		$allowed_bots = $settings['allowed_crawlers'] ?? self::AI_CRAWLERS;

		$output .= "\n# WooCommerce AI Syndication\n";
		$output .= "# Machine-readable store data for AI-assisted product discovery\n\n";

		// Derive paths from actual WooCommerce permalink settings.
		// `wp_parse_url` can return an empty string, false, or null when the
		// permalink isn't set yet (fresh WC installs). Fall back to sensible
		// defaults that match WC's out-of-box routes.
		$parse_path    = static function ( string $page, string $fallback ): string {
			$path = wp_parse_url( wc_get_page_permalink( $page ), PHP_URL_PATH );
			return ( is_string( $path ) && '' !== $path ) ? $path : $fallback;
		};
		$shop_path     = $parse_path( 'shop', '/shop/' );
		$cart_path     = $parse_path( 'cart', '/cart/' );
		$checkout_path = $parse_path( 'checkout', '/checkout/' );
		$account_path  = $parse_path( 'myaccount', '/my-account/' );

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
