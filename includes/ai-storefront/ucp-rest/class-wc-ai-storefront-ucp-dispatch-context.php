<?php
/**
 * AI Storefront: UCP Dispatch Depth Tracker
 *
 * Provides a clean, named API around the dispatch-depth counter that
 * was previously an anonymous static integer inside
 * `WC_AI_Storefront_UCP_Store_API_Filter`.
 *
 * The depth counter itself remains process-level static state because
 * the Store API `pre_get_posts` filter is a global WordPress hook: it
 * fires for every `WP_Query` in the process, and the only way to
 * decide whether a given query was triggered by a UCP controller call
 * is to check shared state. Moving the counter to an instance would
 * not reduce the concurrency risk (PHP is synchronous within a single
 * request) and would make the filter unable to read it.
 *
 * What this class adds over the raw static:
 *   - A named, documented API (`enter` / `exit` / `is_active`).
 *   - An explicit `reset_for_test()` method so test setup/teardown
 *     is obvious about what it is resetting.
 *   - A single home for depth-management logic (previously spread
 *     across three methods of `WC_AI_Storefront_UCP_Store_API_Filter`).
 *
 * @package WooCommerce_AI_Storefront
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracks whether the current call stack is inside a
 * UCP-controller-initiated Store API dispatch.
 *
 * Usage pattern in the controller:
 *
 *   WC_AI_Storefront_UCP_Dispatch_Context::enter();
 *   try {
 *       rest_do_request( $inner_request );
 *   } finally {
 *       WC_AI_Storefront_UCP_Dispatch_Context::exit();
 *   }
 *
 * The `pre_get_posts` filter reads `is_active()` to decide whether to
 * apply the merchant's product-scoping rules.
 */
final class WC_AI_Storefront_UCP_Dispatch_Context {

	/**
	 * Current nesting depth of UCP-initiated Store API dispatches.
	 *
	 * Starts at 0 (no active dispatch). Incremented by `enter()`,
	 * decremented by `exit()` (clamped to 0). A counter rather than a
	 * boolean so nested dispatches terminate correctly.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Mark the start of a UCP dispatch. Must be paired with `exit()`
	 * in a `finally` block so exceptions never leave the depth positive.
	 */
	public static function enter(): void {
		++self::$depth;
	}

	/**
	 * Mark the end of a UCP dispatch. Idempotent: clamps to 0 so an
	 * accidental double-call from a `finally` block cannot produce
	 * a negative depth.
	 */
	public static function exit(): void {
		if ( self::$depth <= 0 ) {
			WC_AI_Storefront_Logger::debug(
				'WC_AI_Storefront_UCP_Dispatch_Context::exit called with depth=0 (unbalanced enter/exit)'
			);
		}
		self::$depth = max( 0, self::$depth - 1 );
	}

	/**
	 * Whether the current call stack is inside a UCP dispatch.
	 *
	 * @return bool True when depth > 0.
	 */
	public static function is_active(): bool {
		return self::$depth > 0;
	}

	/**
	 * Reset depth to 0. Test-only: allows test setup/teardown to
	 * restore a clean state without relying on balanced enter/exit
	 * calls from previous test cases.
	 *
	 * @internal
	 */
	public static function reset_for_test(): void {
		self::$depth = 0;
	}
}
