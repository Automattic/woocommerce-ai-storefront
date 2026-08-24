<?php
/**
 * Plugin Name: WooCommerce AI Storefront
 * Plugin URI: https://woocommerce.com/
 * Description: Make your WooCommerce store ready for AI shopping assistants (ChatGPT, Gemini, Perplexity, Claude). Full merchant control with store-only checkout and standard WooCommerce attribution.
 * Version: 0.39.1
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Text Domain: woocommerce-ai-storefront
 * Domain Path: /languages
 * Requires at least: 6.7
 * Tested up to: 6.8
 * Requires Plugins: woocommerce
 * WC requires at least: 9.9
 * WC tested up to: 10.9
 * Requires PHP: 8.1
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI: https://github.com/Automattic/woocommerce-ai-storefront
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

// Classmap autoloader for all plugin classes. This file is committed
// to the repo so no `composer install` is required to activate the plugin.
// Update includes/autoload.php when adding or removing plugin classes.
require_once __DIR__ . '/includes/autoload.php';

define( 'WC_AI_STOREFRONT_VERSION', '0.39.1' );
define( 'WC_AI_STOREFRONT_PLUGIN_FILE', __FILE__ );
define( 'WC_AI_STOREFRONT_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'WC_AI_STOREFRONT_PLUGIN_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );

/**
 * Declare compatibility with WooCommerce features.
 *
 * HPOS (custom_order_tables): This plugin uses WC_Order methods and
 * wc_get_orders() for all order access — no direct post meta queries
 * on shop_order posts. The get_stats() SQL query supports both HPOS
 * and legacy tables with a runtime check.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Initialize the plugin after WooCommerce is loaded.
 */
function wc_ai_storefront_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wc_ai_storefront_missing_wc_notice' );
		return;
	}

	WC_AI_Storefront::get_instance();
}
add_action( 'plugins_loaded', 'wc_ai_storefront_init' );

/**
 * Register the self-updater against our GitHub release feed.
 *
 * Runs on `init` rather than `plugins_loaded` so it fires regardless
 * of whether WooCommerce is active — merchants who deactivate Woo
 * temporarily should still receive plugin updates.
 *
 * Admin-only: the update machinery only runs in wp-admin (and WP-CLI
 * / cron), so skipping front-end requests avoids loading the PUC
 * library on every pageview.
 */
function wc_ai_storefront_init_updater() {
	if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! wp_doing_cron() ) {
		return;
	}
	WC_AI_Storefront_Updater::init();
}
add_action( 'init', 'wc_ai_storefront_init_updater' );

/**
 * Admin notice when WooCommerce is not active.
 */
function wc_ai_storefront_missing_wc_notice() {
	echo '<div class="error"><p>';
	echo esc_html__( 'WooCommerce AI Storefront requires WooCommerce to be installed and active.', 'woocommerce-ai-storefront' );
	echo '</p></div>';
}

/**
 * Flush rewrite rules on activation.
 *
 * This runs on fresh activation AND on in-place upgrades (WordPress
 * fires the activation hook when the zip is uploaded over an existing
 * install). We intentionally do NOT update the stored version option
 * here — that's handled by `WC_AI_Storefront::register_rewrite_rules()`
 * which detects the version mismatch, clears content caches, and
 * then writes the new version. Writing the version here would
 * short-circuit that branch: the boot-time check would see a matching
 * version and skip the cache bust, leaving stale llms.txt / UCP
 * manifest content cached even though the code has been upgraded.
 *
 * This was a latent bug from 1.0.0 → 1.1.x that only surfaced on
 * in-place zip upgrades; see the "old UCP file served after upgrade"
 * diagnosis in the 1.2.0 work.
 *
 * "In-place upgrade" above means specifically a manual zip re-upload
 * over an existing install (Plugins > Add New > Upload Plugin > Replace
 * current with uploaded) — WordPress deactivates then reactivates the
 * plugin for that flow, so this hook fires. An automatic update
 * (WordPress core auto-updates, or this plugin's own self-updater,
 * `WC_AI_Storefront_Updater`) replaces the files in place without
 * deactivating the plugin, so this hook does NOT fire for that path.
 * Both statements are true, of different mechanisms — do not read them
 * as contradicting each other. That second one is exactly why
 * `WC_AI_Storefront::register_rewrite_rules()`'s version-mismatch
 * branch still has a job on updates, even though this hook also runs
 * on some of them: it is the only thing that reaches a store that
 * auto-updated rather than being manually re-uploaded, including the
 * attribute-seeding backstop added in #629. Do not remove that branch
 * as "redundant" with this hook.
 */
function wc_ai_storefront_activate() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$instance = WC_AI_Storefront::get_instance();
	$instance->init_components();

	// Explicit call, rather than relying only on get_instance() above. On a
	// FRESH install this is actually a no-op: get_instance() already reaches
	// this same seeder inline, through register_rewrite_rules()'s
	// version-mismatch branch (true here since the version option has never
	// been written) calling schedule_attribute_seeding(), which itself runs
	// seed() immediately because did_action( 'init' ) is already true this
	// deep into a register_activation_hook callback — see that method's
	// docblock. By the time execution reaches this line, needs_seeding()
	// has already gone false.
	//
	// The value is on RE-activation. Deactivating and reactivating does not
	// touch `wc_ai_storefront_version`, so on the next activation the
	// version-mismatch branch is false and schedule_attribute_seeding() is
	// never reached that way — reactivation would otherwise get no chance
	// to reconsider seeding at all. Calling seed() directly here decouples
	// every activation, first or repeat, from that heuristic: seeding is
	// reconsidered on its own terms each time, safely, because seed() is
	// guarded by the seed flag and no-ops once it has already run.
	//
	// NOTE: this deliberately does NOT write `wc_ai_storefront_version` —
	// see this function's docblock for why doing so breaks the cache bust.
	WC_AI_Storefront_Attribute_Seeder::seed();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wc_ai_storefront_activate' );

/**
 * Clean up on deactivation.
 */
function wc_ai_storefront_deactivate() {
	flush_rewrite_rules();

	// Clean up cache and scheduled events.
	WC_AI_Storefront_Cache_Invalidator::deactivate();
	WC_AI_Storefront_IndexNow::deactivate();
	WC_AI_Storefront_Crawl_Logger::clear_crons();
}
register_deactivation_hook( __FILE__, 'wc_ai_storefront_deactivate' );
