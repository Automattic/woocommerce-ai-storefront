<?php
/**
 * AI Syndication: Lightweight debug logger.
 *
 * Off by default. Enable for a single request by filter:
 *
 *     add_filter( 'wc_ai_storefront_debug', '__return_true' );
 *
 * Or conditionally (e.g. only for admins):
 *
 *     add_filter(
 *         'wc_ai_storefront_debug',
 *         fn() => current_user_can( 'manage_options' )
 *     );
 *
 * Output goes to PHP's error log (usually `/wp-content/debug.log`
 * when `WP_DEBUG_LOG` is enabled). Lines are prefixed with
 * `[wc-ai-storefront]` for easy grepping.
 *
 * The filter is evaluated ONCE per request and cached, so call
 * sites do a cheap static-property check — this lets us leave
 * `Logger::debug()` calls sprinkled throughout without paying
 * the cost when logging is off.
 *
 * @package WooCommerce_AI_Storefront
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Debug logger for the AI Syndication plugin.
 *
 * Usage:
 *
 *     WC_AI_Storefront_Logger::debug( 'llms.txt cache miss — regenerating' );
 *     WC_AI_Storefront_Logger::debug( 'rate-limit fingerprint matched: %s', $bot );
 */
class WC_AI_Storefront_Logger {

	/**
	 * Cached result of `apply_filters( 'wc_ai_storefront_debug' )`.
	 * `null` means "not computed yet this request".
	 *
	 * @var bool|null
	 */
	private static $is_enabled = null;

	/**
	 * Is debug logging enabled for this request?
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( null === self::$is_enabled ) {
			/**
			 * Filters whether AI Syndication debug logging is active.
			 *
			 * Return `true` to enable `error_log()` output from the
			 * plugin's instrumentation points. Off by default so
			 * production sites don't see noise.
			 *
			 * @since 1.2.0
			 * @param bool $enabled Whether to emit debug logs.
			 */
			self::$is_enabled = (bool) apply_filters( 'wc_ai_storefront_debug', false );
		}
		return self::$is_enabled;
	}

	/**
	 * Reset the cached enabled state. Test-only; in production the
	 * cached value is correct for the lifetime of a request.
	 */
	public static function reset_cache(): void {
		self::$is_enabled = null;
	}

	/**
	 * Log a message to the PHP error log if debug is enabled.
	 *
	 * Accepts `sprintf`-style format args for convenience:
	 *
	 *     Logger::debug( 'cache %s for key=%s', 'miss', 'llms_txt' );
	 *
	 * @param string $message Message, optionally a printf format string.
	 * @param mixed  ...$args Format arguments.
	 */
	public static function debug( string $message, ...$args ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$formatted = empty( $args )
			? $message
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_vsprintf
			: vsprintf( $message, $args );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[wc-ai-storefront] ' . $formatted );
	}
}
