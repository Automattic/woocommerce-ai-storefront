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

// Also purge all host-keyed llms.txt transient variants
// (wc_ai_storefront_llms_txt_<md5(host)>) introduced in 0.6.6.
// The plugin classes are not loaded during uninstall, so we can't
// call host_cache_key() — a direct $wpdb delete is the only option.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wc_ai_storefront_llms_txt_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wc_ai_storefront_llms_txt_' ) . '%'
	)
);
// phpcs:enable

foreach ( array( 'day', 'week', 'month', 'year' ) as $wc_ai_storefront_period ) {
	delete_transient( 'wc_ai_storefront_stats_' . $wc_ai_storefront_period );
}
unset( $wc_ai_storefront_period );

/*
 * --------------------------------------------------------------------------
 * Scheduled events
 * --------------------------------------------------------------------------
 */

wp_clear_scheduled_hook( 'wc_ai_storefront_warm_llms_txt_cache' );

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
			[
				'fields' => 'ids',
				'number' => 0,
			]
		);
		foreach ( $ids as $id ) {
			switch_to_blog( $id );

			delete_option( 'wc_ai_storefront_settings' );
			delete_option( 'wc_ai_storefront_version' );
			delete_transient( 'wc_ai_storefront_llms_txt' );
			delete_transient( 'wc_ai_storefront_ucp' );
			delete_transient( 'wc_ai_storefront_flush_rewrite' );
			delete_transient( 'wc_ai_storefront_catalog_summary' );
			foreach ( array( 'day', 'week', 'month', 'year' ) as $_period ) {
				delete_transient( 'wc_ai_storefront_stats_' . $_period );
			}
			unset( $_period );
			wp_clear_scheduled_hook( 'wc_ai_storefront_warm_llms_txt_cache' );

			// Also purge all host-keyed llms.txt transient variants for
			// this site's table. Same rationale as the single-site block above.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			global $wpdb;
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( '_transient_wc_ai_storefront_llms_txt_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_wc_ai_storefront_llms_txt_' ) . '%'
				)
			);
			// phpcs:enable

			restore_current_blog();
		}
	}
}

if ( is_multisite() ) {
	wc_ai_storefront_uninstall_multisite();
}
