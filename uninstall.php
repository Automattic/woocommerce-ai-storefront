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
			wp_clear_scheduled_hook( 'wc_ai_storefront_warm_llms_txt_cache' );

			restore_current_blog();
		}
	}
}

if ( is_multisite() ) {
	wc_ai_storefront_uninstall_multisite();
}
