<?php
/**
 * AI Storefront: UCP Per-Request Product Cache
 *
 * Holds the per-request memoization cache used by
 * `WC_AI_Storefront_UCP_REST_Controller::fetch_store_api_product()`.
 *
 * Replacing the previous static arrays in the controller with an
 * instance of this class eliminates the data-leak risk present in
 * long-lived PHP worker processes (Swoole, RoadRunner, FrankenPHP):
 * a static property lives for the lifetime of the worker process,
 * meaning a product fetched for Request A can be seen by Request B
 * unless the handler remembers to call `reset()`. With an instance
 * object the controller simply creates a fresh one (or calls
 * `reset()`) at the top of each handler, and the class owns the
 * invariant entirely.
 *
 * @package WooCommerce_AI_Storefront
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-request product cache for UCP REST controller handlers.
 *
 * Keyed on integer WC product ID. A `null` value is a valid cached
 * result (meaning "product not found / out of scope"), distinguished
 * from a cache miss via the separate `$has_key` map — this prevents
 * repeated lookups of out-of-scope or non-existent products from
 * re-dispatching inner `rest_do_request` calls.
 */
final class WC_AI_Storefront_UCP_Request_Context {

	/**
	 * Cache storage: int id => ?array product data.
	 *
	 * @var array<int, ?array<string, mixed>>
	 */
	private array $product_cache = array();

	/**
	 * Tracks which keys have been resolved (including null-resolving
	 * ones). Separates "cache hit with null" from "cache miss" so
	 * repeated 404 lookups don't re-dispatch.
	 *
	 * @var array<int, bool>
	 */
	private array $product_cache_has_key = array();

	/**
	 * Return the cached product data for the given WC product ID.
	 *
	 * Callers must call `has_product()` first — this method does not
	 * distinguish between a cached `null` and a cache miss.
	 *
	 * @param int $id WC product ID.
	 * @return array<string, mixed>|null Cached product data, or null for a
	 *                                   cached "not found / out of scope".
	 */
	public function get_product( int $id ): ?array {
		return isset( $this->product_cache[ $id ] ) ? $this->product_cache[ $id ] : null;
	}

	/**
	 * Whether the given product ID has been resolved this request.
	 *
	 * Returns true for both positive hits and cached nulls (not-found).
	 *
	 * @param int $id WC product ID.
	 * @return bool
	 */
	public function has_product( int $id ): bool {
		return isset( $this->product_cache_has_key[ $id ] );
	}

	/**
	 * Store a resolved product in the cache.
	 *
	 * Pass `null` to record a "not found / out of scope" result so
	 * the next lookup short-circuits without re-dispatching.
	 *
	 * @param int                       $id      WC product ID.
	 * @param ?array<string, mixed>     $product Product data or null.
	 */
	public function set_product( int $id, ?array $product ): void {
		$this->product_cache[ $id ]         = $product;
		$this->product_cache_has_key[ $id ] = true;
	}

	/**
	 * Clear all cached entries. Called at the top of each handler
	 * entry point so data from a prior request cannot leak into the
	 * current one.
	 */
	public function reset(): void {
		$this->product_cache         = array();
		$this->product_cache_has_key = array();
	}
}
