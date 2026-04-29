<?php
/**
 * Plugin Name: WooCommerce AI Storefront
 * Plugin URI: https://woocommerce.com/
 * Description: Make your WooCommerce store ready for AI shopping assistants (ChatGPT, Gemini, Perplexity, Claude). Full merchant control with store-only checkout and standard WooCommerce attribution.
 * Version: 0.6.6
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Text Domain: woocommerce-ai-storefront
 * Domain Path: /languages
 * Requires at least: 6.7
 * Tested up to: 6.8
 * Requires Plugins: woocommerce
 * WC requires at least: 9.9
 * WC tested up to: 9.9
 * Requires PHP: 8.1
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI: https://github.com/Automattic/woocommerce-ai-storefront
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

// Composer autoloader — required for non-namespaced class autoloading.
// In source checkouts this file won't exist until `composer install` is run.
// The release ZIP always ships with vendor/ pre-built; see AGENTS.md.
if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static string, no user input.
			echo '<div class="notice notice-error"><p><strong>WooCommerce AI Storefront:</strong> Composer dependencies are missing. Run <code>composer install</code> in the plugin directory.</p></div>';
		}
	);
	return;
}
require_once __DIR__ . '/vendor/autoload.php';

define( 'WC_AI_STOREFRONT_VERSION', '0.6.6' );
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
 */
function wc_ai_storefront_activate() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$instance = WC_AI_Storefront::get_instance();
	$instance->init_components();

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
}
register_deactivation_hook( __FILE__, 'wc_ai_storefront_deactivate' );
