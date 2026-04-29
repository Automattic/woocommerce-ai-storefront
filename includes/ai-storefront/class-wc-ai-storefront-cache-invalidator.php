<?php
/**
 * AI Syndication: Cache Invalidator
 *
 * Listens for product, category, and settings changes, then invalidates
 * the llms.txt transient cache so the next request gets fresh data.
 * Schedules a debounced background warm-up via WP-Cron so the next
 * real /llms.txt request gets a cache hit.
 *
 * @package WooCommerce_AI_Storefront
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Event-driven cache invalidation for AI syndication data.
 */
class WC_AI_Storefront_Cache_Invalidator {

	/**
	 * WP-Cron hook name for background cache warm-up.
	 */
	const WARMUP_CRON_HOOK = 'wc_ai_storefront_warm_llms_txt_cache';

	/**
	 * Seconds to delay the warm-up cron after invalidation.
	 */
	const WARMUP_DELAY = 30;

	/**
	 * Keys (or callables that return keys) registered for content invalidation.
	 *
	 * Populated by components via register(). Each entry is either a string
	 * transient key or a callable that returns a string key at invalidation time.
	 *
	 * @var array
	 */
	private static $registered_keys = array();

	/**
	 * Register a transient key (or callable) for content invalidation.
	 *
	 * Called by components (e.g. WC_AI_Storefront_Llms_Txt, WC_AI_Storefront_JsonLd)
	 * to declare which transient keys they own, so the invalidator does not need
	 * to hardcode class names or key strings.
	 *
	 * @param string|callable $key_or_callable A string transient key, or a callable
	 *                                         (e.g. array( 'ClassName', 'method' ))
	 *                                         that returns a string key at call time.
	 */
	public static function register( $key_or_callable ) {
		self::$registered_keys[] = $key_or_callable;
	}

	/**
	 * Reset all registered keys.
	 *
	 * Intended for use in tests to restore a clean state between test runs.
	 * Not part of the public API for production code.
	 */
	public static function reset_registered_keys() {
		self::$registered_keys = array();
	}

	/**
	 * Resolve a registered key entry to a string transient key.
	 *
	 * @param string|callable $entry A string key or a callable that returns one.
	 * @return string
	 */
	private static function resolve_key( $entry ) {
		if ( is_callable( $entry ) ) {
			return (string) call_user_func( $entry );
		}
		return (string) $entry;
	}

	/**
	 * Register invalidation hooks.
	 *
	 * Called unconditionally (not behind the syndication-enabled check)
	 * so cached content is still invalidated while syndication is temporarily
	 * disabled, avoiding stale content after it is re-enabled.
	 */
	public function init() {
		// These hooks are intentionally scoped to events that change the
		// content of llms.txt (product data, categories, plugin settings).
		// Order-lifecycle hooks (woocommerce_order_status_*, woocommerce_new_order)
		// are deliberately NOT registered here — orders affect stats/attribution
		// but not the publicly-advertised product catalogue, so there is no
		// reason to bust the llms.txt cache on every order completion.

		// Product lifecycle.
		add_action( 'woocommerce_update_product', [ $this, 'invalidate' ] );
		add_action( 'woocommerce_new_product', [ $this, 'invalidate' ] );
		add_action( 'woocommerce_trash_product', [ $this, 'invalidate' ] );
		add_action( 'woocommerce_delete_product', [ $this, 'invalidate' ] );

		// Stock status changes (covers programmatic updates that skip product save).
		add_action( 'woocommerce_product_set_stock_status', [ $this, 'invalidate' ] );

		// Product category taxonomy changes.
		add_action( 'created_product_cat', [ $this, 'invalidate' ] );
		add_action( 'edited_product_cat', [ $this, 'invalidate' ] );
		add_action( 'delete_product_cat', [ $this, 'invalidate' ] );

		// Syndication settings changed (catches any code path that writes the option).
		add_action( 'update_option_' . WC_AI_Storefront::SETTINGS_OPTION, [ $this, 'invalidate' ] );

		// Sitemap discovery cache: only bust on settings changes that could
		// affect sitemap *location* (e.g. WooCommerce Sitemaps toggled on/off,
		// site URL changed). Product and category edits do NOT affect which
		// sitemap URLs exist, so we don't hook into the product/category
		// lifecycle above — busting on every product save would collapse the
		// 24h TTL and force a synchronous HTTP HEAD probe on every llms.txt
		// regeneration.
		add_action( 'update_option_' . WC_AI_Storefront::SETTINGS_OPTION, [ $this, 'invalidate_sitemap_cache' ] );

		// Cron handler for background warm-up.
		add_action( self::WARMUP_CRON_HOOK, [ $this, 'warm_cache' ] );
	}

	/**
	 * Invalidate the llms.txt transient and schedule a background warm-up.
	 *
	 * delete_transient() is idempotent, so calling this thousands of times
	 * during a bulk import is harmless. The warm-up scheduling uses
	 * wp_next_scheduled() to reduce duplicate events, though under high
	 * concurrency a few duplicate warm-ups may fire — this is tolerable
	 * since warm_cache() is itself idempotent (skips if cache already exists).
	 */
	public function invalidate() {
		global $wpdb;

		// Iterate all registered keys and delete each transient.
		// Each entry is either a string key or a callable that returns one at
		// invalidation time (e.g. WC_AI_Storefront_Llms_Txt::host_cache_key()
		// is host-scoped and must be resolved fresh on every call).
		foreach ( self::$registered_keys as $entry ) {
			delete_transient( self::resolve_key( $entry ) );
		}

		// Also purge any other host-keyed variants (e.g. www vs non-www
		// alias domains). DB-backed transients are cleaned up here; sites
		// using a persistent object cache (Redis/Memcached) will expire
		// naturally within HOUR_IN_SECONDS — documented limitation.
		// This wildcard delete is an implementation detail specific to how
		// llms.txt host keys are stored; it is not generalised through the
		// registration API.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_wc_ai_storefront_llms_txt_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_wc_ai_storefront_llms_txt_' ) . '%'
			)
		);
		// phpcs:enable

		// Note: SITEMAP_CACHE_KEY is intentionally NOT busted here. The 24h
		// TTL on sitemap URL discovery exists to decouple expensive HTTP HEAD
		// probes from content changes. Sitemap location is determined by site
		// settings, not product data. See invalidate_sitemap_cache(), which is
		// hooked to settings changes only.

		// On multisite, replicate the purge for every other site in the
		// network. After switch_to_blog() $wpdb->options points to the
		// subsite's table so the wildcard query deletes the right rows.
		// host_cache_key() is request-scoped (not blog-scoped) so we
		// skip the fast-path delete here and rely on the wildcard query.
		// Paginated in batches of 500 so a single invalidate() on a very
		// large network doesn't build a 10 000-element ID array in memory.
		if ( is_multisite() ) {
			$current_blog_id = get_current_blog_id();
			$offset          = 0;
			$batch           = 500;
			do {
				$blog_ids = get_sites(
					array(
						'fields' => 'ids',
						'number' => $batch,
						'offset' => $offset,
					)
				);
				foreach ( $blog_ids as $blog_id ) {
					if ( (int) $blog_id === $current_blog_id ) {
						continue; // Already handled above.
					}
					switch_to_blog( $blog_id );
					try {
						// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$wpdb->query(
							$wpdb->prepare(
								"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
								$wpdb->esc_like( '_transient_wc_ai_storefront_llms_txt_' ) . '%',
								$wpdb->esc_like( '_transient_timeout_wc_ai_storefront_llms_txt_' ) . '%'
							)
						);
						// phpcs:enable
						foreach ( self::$registered_keys as $entry ) {
							// Skip host-keyed callables in the multisite loop:
							// the host is request-scoped, not blog-scoped, so
							// the callable would return the same key for every
							// blog. The wildcard DB delete above already covers
							// all host variants for this blog.
							if ( ! is_callable( $entry ) ) {
								delete_transient( self::resolve_key( $entry ) );
							}
						}
					} finally {
						restore_current_blog();
					}
				}
				$offset       += $batch;
				$fetched_count = count( $blog_ids );
			} while ( $fetched_count === $batch );
		}

		// Schedule a one-shot warm-up, unless one is already pending.
		if ( ! wp_next_scheduled( self::WARMUP_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + self::WARMUP_DELAY, self::WARMUP_CRON_HOOK );
		}
	}

	/**
	 * Invalidate the sitemap URL discovery cache on settings changes.
	 *
	 * Called only on `update_option_<SETTINGS_OPTION>`, NOT on product or
	 * category edits. Sitemap *location* (which URLs to probe) depends on
	 * plugin settings (e.g. the WooCommerce Sitemaps toggle), not on
	 * individual product data changes. Busting on every product save would
	 * collapse the 24h TTL and force a synchronous HTTP HEAD probe on every
	 * subsequent llms.txt regeneration, defeating the P-18 performance goal.
	 *
	 * On multisite, replicate the purge for every other site exactly as
	 * invalidate() does for the llms.txt transients.
	 */
	public function invalidate_sitemap_cache() {
		delete_transient( WC_AI_Storefront_Llms_Txt::SITEMAP_CACHE_KEY );

		if ( is_multisite() ) {
			$current_blog_id = get_current_blog_id();
			$offset          = 0;
			$batch           = 500;
			do {
				$blog_ids = get_sites(
					array(
						'fields' => 'ids',
						'number' => $batch,
						'offset' => $offset,
					)
				);
				foreach ( $blog_ids as $blog_id ) {
					if ( (int) $blog_id === $current_blog_id ) {
						continue;
					}
					switch_to_blog( $blog_id );
					try {
						delete_transient( WC_AI_Storefront_Llms_Txt::SITEMAP_CACHE_KEY );
					} finally {
						restore_current_blog();
					}
				}
				$offset       += $batch;
				$fetched_count = count( $blog_ids );
			} while ( $fetched_count === $batch );
		}
	}

	/**
	 * Proactively regenerate the llms.txt cache in the background.
	 *
	 * Runs via WP-Cron so the next real /llms.txt request gets a cache hit
	 * instead of waiting for on-demand regeneration.
	 */
	public function warm_cache() {
		// If the cache was already rebuilt by a real request, nothing to do.
		if ( false !== get_transient( WC_AI_Storefront_Llms_Txt::host_cache_key() ) ) {
			return;
		}

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return;
		}

		$llms_txt = new WC_AI_Storefront_Llms_Txt();
		$content  = $llms_txt->generate();
		set_transient( WC_AI_Storefront_Llms_Txt::host_cache_key(), $content, HOUR_IN_SECONDS );
	}

	/**
	 * Clean up on plugin deactivation.
	 */
	public static function deactivate() {
		global $wpdb;

		// Delete all registered content-invalidation transients.
		foreach ( self::$registered_keys as $entry ) {
			delete_transient( self::resolve_key( $entry ) );
		}

		// Also purge any other host-keyed variants (same rationale as invalidate()).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_wc_ai_storefront_llms_txt_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_wc_ai_storefront_llms_txt_' ) . '%'
			)
		);
		// phpcs:enable

		// SITEMAP_CACHE_KEY is not in the registered list because it has
		// different trigger semantics (settings-only, not content-changes).
		// On deactivation we must still clear it explicitly.
		delete_transient( WC_AI_Storefront_Llms_Txt::SITEMAP_CACHE_KEY );

		// On multisite, replicate the purge for every other site. Same
		// rationale as invalidate() — wildcard query covers all host-keyed
		// variants once $wpdb->options is redirected by switch_to_blog().
		// Paginated in batches of 500; see invalidate() for rationale.
		// Also clears the warmup cron hook on each subsite since cron
		// events are stored per-blog (wp_options table of each site).
		if ( is_multisite() ) {
			$current_blog_id = get_current_blog_id();
			$offset          = 0;
			$batch           = 500;
			do {
				$blog_ids = get_sites(
					array(
						'fields' => 'ids',
						'number' => $batch,
						'offset' => $offset,
					)
				);
				foreach ( $blog_ids as $blog_id ) {
					if ( (int) $blog_id === $current_blog_id ) {
						continue; // Already handled above.
					}
					switch_to_blog( $blog_id );
					try {
						// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$wpdb->query(
							$wpdb->prepare(
								"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
								$wpdb->esc_like( '_transient_wc_ai_storefront_llms_txt_' ) . '%',
								$wpdb->esc_like( '_transient_timeout_wc_ai_storefront_llms_txt_' ) . '%'
							)
						);
						// phpcs:enable
						foreach ( self::$registered_keys as $entry ) {
							// Skip callables in the multisite loop (same reason
							// as in invalidate(): host-keyed keys are request-
							// scoped, not blog-scoped).
							if ( ! is_callable( $entry ) ) {
								delete_transient( self::resolve_key( $entry ) );
							}
						}
						wp_clear_scheduled_hook( self::WARMUP_CRON_HOOK );
					} finally {
						restore_current_blog();
					}
				}
				$offset       += $batch;
				$fetched_count = count( $blog_ids );
			} while ( $fetched_count === $batch );
		}

		wp_clear_scheduled_hook( self::WARMUP_CRON_HOOK );
	}
}
