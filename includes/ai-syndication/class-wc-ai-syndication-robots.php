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
		'Google-Extended',
		'Gemini',
		'PerplexityBot',
		'ClaudeBot',
		'Amazonbot',
		'Applebot-Extended',
		'Bytespider',
		'CCBot',
		'anthropic-ai',
		'cohere-ai',
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

		foreach ( $allowed_bots as $bot ) {
			$bot     = sanitize_text_field( $bot );
			$output .= "User-agent: {$bot}\n";
			$output .= "Allow: /llms.txt\n";
			$output .= "Allow: /.well-known/ucp\n";
			$output .= "Allow: /wp-json/wc/v3/ai-syndication/\n";
			$output .= "Allow: /shop/\n";
			$output .= "Allow: /product/\n";
			$output .= "Allow: /product-category/\n";
			$output .= "Disallow: /cart/\n";
			$output .= "Disallow: /checkout/\n";
			$output .= "Disallow: /my-account/\n";
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
