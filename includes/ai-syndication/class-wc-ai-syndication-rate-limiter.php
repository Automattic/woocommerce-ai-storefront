<?php
/**
 * AI Syndication: Rate Limiter
 *
 * Enforces per-agent request limits using WordPress transients.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rate limiter for AI agent API requests.
 */
class WC_AI_Syndication_Rate_Limiter {

	/**
	 * Transient prefix for rate limit counters.
	 */
	const TRANSIENT_PREFIX = 'wc_ai_rl_';

	/**
	 * Check if the request is within rate limits.
	 *
	 * @param string $bot_id The bot identifier.
	 * @return true|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check( $bot_id ) {
		$settings = WC_AI_Syndication::get_settings();
		$rpm      = absint( $settings['rate_limit_rpm'] ?? 60 );
		$rph      = absint( $settings['rate_limit_rph'] ?? 1000 );

		// Check per-minute limit.
		$minute_key   = self::TRANSIENT_PREFIX . md5( $bot_id . '_min_' . gmdate( 'YmdHi' ) );
		$minute_count = (int) get_transient( $minute_key );

		if ( $minute_count >= $rpm ) {
			return new WP_Error(
				'ai_syndication_rate_limited',
				__( 'Rate limit exceeded. Please try again later.', 'woocommerce-ai-syndication' ),
				[
					'status'      => 429,
					'retry_after' => 60 - (int) gmdate( 's' ),
				]
			);
		}

		// Check per-hour limit.
		$hour_key   = self::TRANSIENT_PREFIX . md5( $bot_id . '_hr_' . gmdate( 'YmdH' ) );
		$hour_count = (int) get_transient( $hour_key );

		if ( $hour_count >= $rph ) {
			return new WP_Error(
				'ai_syndication_rate_limited',
				__( 'Hourly rate limit exceeded. Please try again later.', 'woocommerce-ai-syndication' ),
				[
					'status'      => 429,
					'retry_after' => 3600 - ( (int) gmdate( 'i' ) * 60 + (int) gmdate( 's' ) ),
				]
			);
		}

		// Increment counters.
		set_transient( $minute_key, $minute_count + 1, 120 );
		set_transient( $hour_key, $hour_count + 1, 7200 );

		return true;
	}
}
