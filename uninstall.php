<?php
/**
 * Plugin uninstall handler.
 *
 * Runs only when the merchant deletes the plugin from the Plugins
 * screen — not on deactivate. Removes plugin-owned options,
 * transients, and scheduled events.
 *
 * Intentionally NOT removed:
 *
 * - Order meta keys (`_wc_ai_storefront_agent`,
 *   `_wc_ai_storefront_session_id`, and WooCommerce's own
 *   `_wc_order_attribution_*` keys). These are historical order
 *   records — merchant-owned transaction data. Destroying them
 *   would erase legitimate business history. If a merchant wants
 *   to purge this, they can do it with WP-CLI after uninstall.
 *
 * @package WooCommerce_AI_Storefront
 */

// If uninstall wasn't called by WordPress, bail. This is a security
// check: an attacker with file-level access shouldn't be able to
// trigger destructive cleanup just by requesting the file.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * --------------------------------------------------------------------------
 * Options
 * --------------------------------------------------------------------------
 */

// Main settings (stored with autoload=true — removal also flushes alloptions cache).
delete_option( 'wc_ai_storefront_settings' );

// Version marker (triggers rewrite flush + cache bust on plugin update).
delete_option( 'wc_ai_storefront_version' );

// Shopify-compatible /products.json feed cache version (the versioned key
// prefix bumped on product/settings change). Single small integer row.
delete_option( 'wc_ai_storefront_products_feed_version' );

// IndexNow: generated key (hex string), pending-URL queue, and last flush result.
delete_option( 'wc_ai_storefront_indexnow_key' );
delete_option( 'wc_ai_storefront_indexnow_pending' );
delete_option( 'wc_ai_storefront_indexnow_last_result' );
delete_option( 'wc_ai_storefront_indexnow_dropped' );

/*
 * --------------------------------------------------------------------------
 * Transients
 * --------------------------------------------------------------------------
 *
 * The cache keys are intentionally hard-coded here rather than resolved
 * from class constants. Uninstall runs with only WordPress loaded — the
 * plugin's own classes are not bootstrapped — so the constants aren't
 * available. Keeping the string literals here is the canonical WP
 * pattern. If a cache key ever changes in a class constant, update
 * this file in the same commit.
 */
delete_transient( 'wc_ai_storefront_llms_txt' );
delete_transient( 'wc_ai_storefront_ucp' );
delete_transient( 'wc_ai_storefront_flush_rewrite' );
delete_transient( 'wc_ai_storefront_catalog_summary' );
delete_transient( 'wc_ai_storefront_sitemap_urls' );
delete_transient( 'wc_ai_storefront_website_jsonld' );

// Also purge prefix-keyed transient families with a direct $wpdb delete:
//   - host-keyed llms.txt variants (wc_ai_storefront_llms_txt_<md5(host)>, since 0.6.6)
//   - per-page archive ItemList JSON-LD (wc_ai_storefront_itemlist_<context>_<page>)
//   - Shopify /products.json feed pages (wc_ai_sf_pjson_<md5(host|version|limit|page)>)
//   - v2 scoped feed families: single product (wc_ai_sf_prod_),
//     per-collection (wc_ai_sf_coll_), collection list (wc_ai_sf_colls_).
//     NOTE: coll_ and colls_ need separate patterns — esc_like() escapes the
//     trailing underscore to a literal, so `wc_ai_sf_coll_%` does NOT match a
//     `wc_ai_sf_colls_` key (the char after `coll` differs).
// The plugin classes are not loaded during uninstall, so we can't call
// host_cache_key() or read the prefix constant — direct deletes are the only
// option. (Object-cache-backed transients on these dynamic keys are reaped by
// their 1h TTL; same limitation as the existing llms.txt wildcard.)
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wc_ai_storefront_llms_txt_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wc_ai_storefront_llms_txt_' ) . '%',
		$wpdb->esc_like( '_transient_wc_ai_storefront_itemlist_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wc_ai_storefront_itemlist_' ) . '%',
		$wpdb->esc_like( '_transient_wc_ai_sf_pjson_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wc_ai_sf_pjson_' ) . '%',
		$wpdb->esc_like( '_transient_wc_ai_sf_prod_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wc_ai_sf_prod_' ) . '%',
		$wpdb->esc_like( '_transient_wc_ai_sf_coll_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wc_ai_sf_coll_' ) . '%',
		$wpdb->esc_like( '_transient_wc_ai_sf_colls_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wc_ai_sf_colls_' ) . '%'
	)
);
// phpcs:enable

foreach ( array( 'day', 'week', 'month', 'year' ) as $wc_ai_storefront_period ) {
	delete_transient( 'wc_ai_storefront_stats_' . $wc_ai_storefront_period );
}
unset( $wc_ai_storefront_period );
foreach ( array( 'day', 'week', 'month', 'quarter' ) as $wc_ai_storefront_period ) {
	delete_transient( 'wc_ai_storefront_crawl_stats_' . $wc_ai_storefront_period );
}
unset( $wc_ai_storefront_period );

/*
 * --------------------------------------------------------------------------
 * Scheduled events
 * --------------------------------------------------------------------------
 */

wp_clear_scheduled_hook( 'wc_ai_storefront_warm_llms_txt_cache' );
wp_clear_scheduled_hook( 'wc_ai_storefront_prune_crawl_log' );
wp_clear_scheduled_hook( 'wc_ai_storefront_rollup_crawl_log' );
wp_clear_scheduled_hook( 'wc_ai_storefront_indexnow_flush' );

/*
 * --------------------------------------------------------------------------
 * Crawl log tables
 * --------------------------------------------------------------------------
 */

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_ai_storefront_crawl_summary" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_ai_storefront_crawl_log" );     // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

/*
 * --------------------------------------------------------------------------
 * Multisite
 * --------------------------------------------------------------------------
 *
 * When activated network-wide, each site has its own options + transients.
 * Loop through them all.
 */
// Wrapped in a function to keep loop variables out of global scope.
if ( ! function_exists( 'wc_ai_storefront_uninstall_multisite' ) ) {
	/**
	 * Delete plugin rows from every site in a multisite network.
	 */
	function wc_ai_storefront_uninstall_multisite(): void {
		$ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $ids as $id ) {
			switch_to_blog( $id );

			delete_option( 'wc_ai_storefront_settings' );
			delete_option( 'wc_ai_storefront_version' );
			delete_option( 'wc_ai_storefront_products_feed_version' );
			delete_option( 'wc_ai_storefront_indexnow_key' );
			delete_option( 'wc_ai_storefront_indexnow_pending' );
			delete_option( 'wc_ai_storefront_indexnow_last_result' );
			delete_option( 'wc_ai_storefront_indexnow_dropped' );
			delete_transient( 'wc_ai_storefront_llms_txt' );
			delete_transient( 'wc_ai_storefront_ucp' );
			delete_transient( 'wc_ai_storefront_flush_rewrite' );
			delete_transient( 'wc_ai_storefront_catalog_summary' );
			delete_transient( 'wc_ai_storefront_sitemap_urls' );
			delete_transient( 'wc_ai_storefront_website_jsonld' );
			foreach ( array( 'day', 'week', 'month', 'year' ) as $_period ) {
				delete_transient( 'wc_ai_storefront_stats_' . $_period );
			}
			unset( $_period );
			foreach ( array( 'day', 'week', 'month', 'quarter' ) as $_period ) {
				delete_transient( 'wc_ai_storefront_crawl_stats_' . $_period );
			}
			unset( $_period );
			wp_clear_scheduled_hook( 'wc_ai_storefront_warm_llms_txt_cache' );
			wp_clear_scheduled_hook( 'wc_ai_storefront_prune_crawl_log' );
			wp_clear_scheduled_hook( 'wc_ai_storefront_rollup_crawl_log' );
			wp_clear_scheduled_hook( 'wc_ai_storefront_indexnow_flush' );

			// Also purge prefix-keyed transient families for this site's table
			// (host-keyed llms.txt + per-page archive ItemList JSON-LD +
			// Shopify /products.json bulk feed pages + the v2 scoped feed
			// families: single product wc_ai_sf_prod_, per-collection
			// wc_ai_sf_coll_, collection list wc_ai_sf_colls_). coll_ and
			// colls_ need separate patterns (esc_like escapes the trailing
			// underscore literal). Same rationale as the single-site block.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			global $wpdb;
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( '_transient_wc_ai_storefront_llms_txt_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_wc_ai_storefront_llms_txt_' ) . '%',
					$wpdb->esc_like( '_transient_wc_ai_storefront_itemlist_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_wc_ai_storefront_itemlist_' ) . '%',
					$wpdb->esc_like( '_transient_wc_ai_sf_pjson_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_wc_ai_sf_pjson_' ) . '%',
					$wpdb->esc_like( '_transient_wc_ai_sf_prod_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_wc_ai_sf_prod_' ) . '%',
					$wpdb->esc_like( '_transient_wc_ai_sf_coll_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_wc_ai_sf_coll_' ) . '%',
					$wpdb->esc_like( '_transient_wc_ai_sf_colls_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_wc_ai_sf_colls_' ) . '%'
				)
			);

			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_ai_storefront_crawl_summary" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_ai_storefront_crawl_log" );     // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			// phpcs:enable

			restore_current_blog();
		}
	}
}

if ( is_multisite() ) {
	wc_ai_storefront_uninstall_multisite();
}
