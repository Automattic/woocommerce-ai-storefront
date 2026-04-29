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
 * @package WooCommerce_AI_Storefront
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rate limits Store API requests from known AI bot user-agents.
 */
class WC_AI_Storefront_Store_Api_Rate_Limiter {

	/**
	 * Transient key prefix for outer-UCP-request rate-limit counters.
	 *
	 * Each AI-bot fingerprint (`ai_bot_<md5>`) gets its own sliding-window
	 * counter stored as a WP transient under this prefix. The TTL is reset
	 * to 60 seconds on every increment, so the window slides rather than
	 * fixing to a clock-aligned boundary. Separate from WC's inner-Store-API
	 * rate-limit storage so the two layers don't interfere.
	 */
	const OUTER_TRANSIENT_PREFIX = 'wc_ai_ucp_rl_';

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
	 * Suppresses rate limiting for inner `rest_do_request()` dispatches
	 * that originate from UCP route handlers. Those inner calls run
	 * inside the `enter_ucp_dispatch()` / `exit_ucp_dispatch()` bracket
	 * maintained by `WC_AI_Storefront_UCP_Store_API_Filter`. Each such
	 * inner call would otherwise consume a separate rate-limit slot,
	 * meaning a single `/catalog/lookup` with 50 IDs would drain 50
	 * slots — leaving the agent's next legitimate request 429'd even
	 * though only 1 logical outer request was made.
	 *
	 * The outer UCP request is already counted by
	 * `check_outer_rate_limit()` at `check_agent_access()` time, so
	 * suppressing the inner-call counter does not create a bypass
	 * surface: the outer gate has already run before any inner dispatch.
	 *
	 * @param array $options Default rate limit options.
	 * @return array Modified options.
	 */
	public function configure_rate_limits( $options ) {
		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return $options;
		}

		// Inner Store API dispatches from UCP handlers — suppress WC's
		// built-in per-call counter. The outer UCP request already
		// consumed one slot via `check_outer_rate_limit()`.
		if ( WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch() ) {
			return [ 'enabled' => false ];
		}

		return [
			'enabled'       => true,
			'proxy_support' => true,
			'limit'         => absint( $settings['rate_limit_rpm'] ?? 25 ),
			'seconds'       => 60,
		];
	}

	/**
	 * Fingerprint AI bot requests by bot name and IP.
	 *
	 * Known AI bot user-agents get a unique rate limit bucket keyed on
	 * the matched bot name (normalized from `AI_CRAWLERS`) and the
	 * client IP address. Using bot-name+IP rather than the raw UA string
	 * prevents two problems:
	 *
	 * 1. UA spoofing — a different IP claiming another bot's UA would
	 *    share (and exhaust) that bot's rate-limit window.
	 * 2. UA rotation — a single IP sending minor UA variants (different
	 *    version numbers, extra tokens) would create a new bucket for
	 *    each variant, bypassing the sliding window entirely.
	 *
	 * Regular customer traffic keeps the default fingerprint (IP-based),
	 * so it is unaffected.
	 *
	 * @param string $id Default rate limit identifier.
	 * @return string Modified identifier for AI bots, unchanged for others.
	 */
	public function fingerprint_ai_bots( $id ) {
		$ua = $this->get_user_agent();
		$ip = self::current_request_ip();

		foreach ( WC_AI_Storefront_Robots::AI_CRAWLERS as $bot ) {
			if ( stripos( $ua, $bot ) !== false ) {
				WC_AI_Storefront_Logger::debug(
					'rate-limit fingerprint matched AI bot: %s',
					$bot
				);
				return 'ai_bot_' . md5( $bot . '_' . $ip );
			}
		}

		return $id;
	}

	/**
	 * Check and increment the outer-UCP-request rate-limit counter.
	 *
	 * Called from `WC_AI_Storefront_UCP_REST_Controller::check_agent_access()`
	 * before any inner `rest_do_request()` dispatches so that exactly
	 * one slot is consumed per logical outer request. Returns a
	 * `WP_Error` with HTTP 429 when the per-minute budget is exhausted.
	 *
	 * Rate-limit fingerprint strategy (see also `fingerprint_ai_bots()`):
	 *
	 * - Known AI bot UA: keyed by `md5( $bot_name . '_' . $ip )` so
	 *   each origin IP gets its own per-minute window. This prevents a
	 *   single attacker from depleting another bot's budget by spoofing
	 *   its user-agent, and prevents UA rotation (minor version variants)
	 *   from opening a fresh window for the same IP.
	 * - Unknown or unrecognized UA: keyed by `md5( 'unknown_' . $ip )`.
	 *   Requests reaching this method from `check_agent_access()` are
	 *   already gated (they need a valid UCP-Agent header or the
	 *   `allow_unknown_ucp_agents=yes` setting), so applying the budget
	 *   here closes the gap where an unknown agent with that setting
	 *   enabled could make unlimited requests.
	 *
	 * Rate-limit window: 60-second sliding window backed by a WP
	 * transient (TTL is reset to 60 s on every increment). A race
	 * between two simultaneous requests may let a small number of extra
	 * requests through — this matches WC's own rate-limiter behavior and
	 * is acceptable for a per-minute budget.
	 *
	 * @return bool|WP_Error True when the request is within budget;
	 *                       WP_Error(status=429) when exceeded.
	 */
	public static function check_outer_rate_limit(): bool|WP_Error {
		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return true;
		}

		$ua = self::current_user_agent();
		$ip = self::current_request_ip();

		// Determine the rate-limit fingerprint.
		// Known bot: bot-name + IP (UA-version-agnostic, IP-scoped).
		// Unknown UA: IP only under 'unknown_' prefix.
		$matched_bot = '';
		foreach ( WC_AI_Storefront_Robots::AI_CRAWLERS as $bot ) {
			if ( stripos( $ua, $bot ) !== false ) {
				$matched_bot = $bot;
				break;
			}
		}

		$fingerprint = '' !== $matched_bot
			? 'ai_bot_' . md5( $matched_bot . '_' . $ip )
			: 'unknown_' . md5( $ip );

		$limit         = max( 1, absint( $settings['rate_limit_rpm'] ?? 25 ) );
		$transient_key = self::OUTER_TRANSIENT_PREFIX . $fingerprint;
		$count         = get_transient( $transient_key );

		if ( false !== $count && (int) $count >= $limit ) {
			WC_AI_Storefront_Logger::debug(
				'outer UCP rate limit exceeded — bot=%s count=%d limit=%d',
				'' !== $matched_bot ? $matched_bot : 'unknown',
				(int) $count,
				$limit
			);
			return new WP_Error(
				'ucp_rate_limit_exceeded',
				__( 'Too many requests. Please try again later.', 'woocommerce-ai-storefront' ),
				[
					'status'      => 429,
					'retry_after' => 60,
				]
			);
		}

		// Increment. First request starts a 60-second transient; each
		// subsequent call resets the TTL, making this a sliding window.
		$new_count = ( false === $count ) ? 1 : (int) $count + 1;
		set_transient( $transient_key, $new_count, 60 );

		return true;
	}

	/**
	 * Get the current request's user-agent string.
	 *
	 * @return string
	 */
	private function get_user_agent() {
		return self::current_user_agent();
	}

	/**
	 * Resolve the current request's user-agent string.
	 *
	 * Shared by the instance method `get_user_agent()` (used by the
	 * filter callback `fingerprint_ai_bots()`) and the static
	 * `check_outer_rate_limit()` so both use the same resolution path.
	 *
	 * @return string
	 */
	private static function current_user_agent(): string {
		if ( function_exists( 'wc_get_user_agent' ) ) {
			return wc_get_user_agent();
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		return isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
	}

	/**
	 * Resolve the current request's remote IP address.
	 *
	 * Delegates to WC_Geolocation::get_ip_address() so that the
	 * proxy_support WooCommerce setting is honoured. On stores behind
	 * Cloudflare or an ALB, REMOTE_ADDR is the proxy IP, collapsing all
	 * AI-bot traffic into a single rate-limit bucket. WC_Geolocation
	 * reads CF-Connecting-IP / X-Real-IP / X-Forwarded-For when the
	 * merchant has enabled proxy support, giving each real client IP its
	 * own bucket as intended.
	 *
	 * Falls back to REMOTE_ADDR when WC_Geolocation is not yet loaded or
	 * returns an empty string (e.g., CLI context). The empty-string case
	 * is handled in the caller (fingerprint will use the bot name only).
	 *
	 * @return string IP address, or empty string if not available.
	 */
	private static function current_request_ip(): string {
		if ( class_exists( 'WC_Geolocation' ) ) {
			$ip = WC_Geolocation::get_ip_address();
			if ( '' !== $ip ) {
				return $ip;
			}
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
	}
}
