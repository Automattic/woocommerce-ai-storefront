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
	 * Uses atomic increment via wp_cache_incr when a persistent object cache
	 * is available (Redis/Memcached). Falls back to increment-then-check on
	 * transients for sites without persistent cache.
	 *
	 * @param string $bot_id The bot identifier.
	 * @return true|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check( $bot_id ) {
		$settings = WC_AI_Syndication::get_settings();
		$rpm      = absint( $settings['rate_limit_rpm'] ?? 60 );
		$rph      = absint( $settings['rate_limit_rph'] ?? 1000 );

		$minute_key = self::TRANSIENT_PREFIX . md5( $bot_id . '_min_' . gmdate( 'YmdHi' ) );
		$hour_key   = self::TRANSIENT_PREFIX . md5( $bot_id . '_hr_' . gmdate( 'YmdH' ) );

		// Check per-minute limit.
		$minute_count = $this->atomic_increment( $minute_key, 120 );
		if ( $minute_count > $rpm ) {
			$retry_after = 60 - (int) gmdate( 's' );
			return new WP_Error(
				'ai_syndication_rate_limited',
				__( 'Rate limit exceeded. Please try again later.', 'woocommerce-ai-syndication' ),
				[
					'status'      => 429,
					'retry_after' => max( 1, $retry_after ),
				]
			);
		}

		// Check per-hour limit.
		$hour_count = $this->atomic_increment( $hour_key, 7200 );
		if ( $hour_count > $rph ) {
			$retry_after = 3600 - ( (int) gmdate( 'i' ) * 60 + (int) gmdate( 's' ) );
			return new WP_Error(
				'ai_syndication_rate_limited',
				__( 'Hourly rate limit exceeded. Please try again later.', 'woocommerce-ai-syndication' ),
				[
					'status'      => 429,
					'retry_after' => max( 1, $retry_after ),
				]
			);
		}

		return true;
	}

	/**
	 * Atomically increment a counter, using wp_cache_incr when available.
	 *
	 * When a persistent object cache (Redis/Memcached) is active, wp_cache_incr
	 * maps to an atomic INCR command, preventing the TOCTOU race condition
	 * inherent in get-then-set patterns.
	 *
	 * @param string $key        Cache/transient key.
	 * @param int    $expiration TTL in seconds.
	 * @return int The count after incrementing.
	 */
	private function atomic_increment( $key, $expiration ) {
		if ( wp_using_ext_object_cache() ) {
			$group = 'wc_ai_syndication_rl';

			// Try atomic increment first.
			$result = wp_cache_incr( $key, 1, $group );
			if ( false !== $result ) {
				return (int) $result;
			}

			// Key doesn't exist yet — initialize it.
			wp_cache_set( $key, 1, $group, $expiration );
			return 1;
		}

		// Fallback for sites without persistent object cache: use transients.
		// This is still susceptible to narrow race windows on the DB backend,
		// but is a significant improvement over the previous check-then-set.
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, $expiration );
		return $count + 1;
	}
}
