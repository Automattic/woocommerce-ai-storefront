<?php
/**
 * AI Syndication: Store API Rate Limiter
 *
 * Enables WooCommerce Store API rate limiting for AI bot traffic
 * using the built-in woocommerce_store_api_rate_limit_options and
 * woocommerce_store_api_rate_limit_id filters.
 *
 * Regular customer traffic is unaffected — only requests from
 * known AI bot user-agents are rate limited.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rate limits Store API requests from known AI bot user-agents.
 */
class WC_AI_Syndication_Store_Api_Rate_Limiter {

	/**
	 * Initialize filters.
	 */
	public function init() {
		add_filter( 'woocommerce_store_api_rate_limit_options', [ $this, 'configure_rate_limits' ] );
		add_filter( 'woocommerce_store_api_rate_limit_id', [ $this, 'fingerprint_ai_bots' ] );
	}

	/**
	 * Enable Store API rate limiting with merchant-configured limits.
	 *
	 * @param array $options Default rate limit options.
	 * @return array Modified options.
	 */
	public function configure_rate_limits( $options ) {
		$settings = WC_AI_Syndication::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return $options;
		}

		return [
			'enabled'       => true,
			'proxy_support' => true,
			'limit'         => absint( $settings['rate_limit_rpm'] ?? 25 ),
			'seconds'       => 60,
		];
	}

	/**
	 * Fingerprint AI bot requests by user-agent.
	 *
	 * Known AI bot user-agents get a unique rate limit bucket based
	 * on their user-agent string. Regular customer traffic keeps the
	 * default fingerprint (IP-based), so it is unaffected.
	 *
	 * @param string $id Default rate limit identifier.
	 * @return string Modified identifier for AI bots, unchanged for others.
	 */
	public function fingerprint_ai_bots( $id ) {
		$ua = $this->get_user_agent();

		foreach ( WC_AI_Syndication_Robots::AI_CRAWLERS as $bot ) {
			if ( stripos( $ua, $bot ) !== false ) {
				WC_AI_Syndication_Logger::debug(
					'rate-limit fingerprint matched AI bot: %s',
					$bot
				);
				return 'ai_bot_' . md5( $ua );
			}
		}

		return $id;
	}

	/**
	 * Get the current request's user-agent string.
	 *
	 * @return string
	 */
	private function get_user_agent() {
		if ( function_exists( 'wc_get_user_agent' ) ) {
			return wc_get_user_agent();
		}
		return isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
	}
}
