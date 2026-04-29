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

		// Delete the host-keyed llms.txt transient for the current request's
		// host (fast path — covers the common case).
		delete_transient( WC_AI_Storefront_Llms_Txt::host_cache_key() );

		// Also purge any other host-keyed variants (e.g. www vs non-www
		// alias domains). DB-backed transients are cleaned up here; sites
		// using a persistent object cache (Redis/Memcached) will expire
		// naturally within HOUR_IN_SECONDS — documented limitation.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_wc_ai_storefront_llms_txt_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_wc_ai_storefront_llms_txt_' ) . '%'
			)
		);
		// phpcs:enable

		// Catalog summary cache (store-level JSON-LD).
		delete_transient( 'wc_ai_storefront_catalog_summary' );

		// Sitemap URL discovery cache (24h TTL, independent of llms.txt content).
		// Intentionally busted on product/settings changes that could add or
		// remove sitemap paths (e.g. WooCommerce sitemap toggled in settings).
		delete_transient( WC_AI_Storefront_Llms_Txt::SITEMAP_CACHE_KEY );

		// UCP manifest is computed per-request (no transient) — the
		// delete below is a harmless no-op kept for backward compat.
		delete_transient( WC_AI_Storefront_Ucp::CACHE_KEY );

		// Schedule a one-shot warm-up, unless one is already pending.
		if ( ! wp_next_scheduled( self::WARMUP_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + self::WARMUP_DELAY, self::WARMUP_CRON_HOOK );
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

		// Delete the current-host key (fast path).
		delete_transient( WC_AI_Storefront_Llms_Txt::host_cache_key() );

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

		delete_transient( 'wc_ai_storefront_catalog_summary' );
		delete_transient( WC_AI_Storefront_Llms_Txt::SITEMAP_CACHE_KEY );
		delete_transient( WC_AI_Storefront_Ucp::CACHE_KEY );
		wp_clear_scheduled_hook( self::WARMUP_CRON_HOOK );
	}
}
