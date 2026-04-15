<?php
/**
 * Plugin Name: WooCommerce AI Syndication
 * Plugin URI: https://woocommerce.com/
 * Description: Merchant-led AI product syndication for WooCommerce. Expose products to AI shopping agents (ChatGPT, Gemini, Perplexity, Claude) with full merchant control. Store-only checkout, standard WooCommerce attribution.
 * Version: 1.0.0
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Text Domain: woocommerce-ai-syndication
 * Domain Path: /languages
 * Requires at least: 6.7
 * Tested up to: 6.8
 * WC requires at least: 9.9
 * WC tested up to: 9.9
 * Requires PHP: 8.0
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package WooCommerce_AI_Syndication
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_AI_SYNDICATION_VERSION', '1.0.0' );
define( 'WC_AI_SYNDICATION_PLUGIN_FILE', __FILE__ );
define( 'WC_AI_SYNDICATION_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'WC_AI_SYNDICATION_PLUGIN_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );

/**
 * Initialize the plugin after WooCommerce is loaded.
 */
function wc_ai_syndication_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wc_ai_syndication_missing_wc_notice' );
		return;
	}

	require_once WC_AI_SYNDICATION_PLUGIN_PATH . '/includes/class-wc-ai-syndication.php';
	WC_AI_Syndication::get_instance();
}
add_action( 'plugins_loaded', 'wc_ai_syndication_init' );

/**
 * Admin notice when WooCommerce is not active.
 */
function wc_ai_syndication_missing_wc_notice() {
	echo '<div class="error"><p>';
	echo esc_html__( 'WooCommerce AI Syndication requires WooCommerce to be installed and active.', 'woocommerce-ai-syndication' );
	echo '</p></div>';
}

/**
 * Flush rewrite rules on activation.
 */
function wc_ai_syndication_activate() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once WC_AI_SYNDICATION_PLUGIN_PATH . '/includes/class-wc-ai-syndication.php';
	$instance = WC_AI_Syndication::get_instance();
	$instance->init_components();

	flush_rewrite_rules();
	update_option( 'wc_ai_syndication_version', WC_AI_SYNDICATION_VERSION );
}
register_activation_hook( __FILE__, 'wc_ai_syndication_activate' );

/**
 * Clean up on deactivation.
 */
function wc_ai_syndication_deactivate() {
	flush_rewrite_rules();

	// Clean up cache and scheduled events.
	require_once WC_AI_SYNDICATION_PLUGIN_PATH . '/includes/ai-syndication/class-wc-ai-syndication-llms-txt.php';
	require_once WC_AI_SYNDICATION_PLUGIN_PATH . '/includes/ai-syndication/class-wc-ai-syndication-cache-invalidator.php';
	WC_AI_Syndication_Cache_Invalidator::deactivate();
}
register_deactivation_hook( __FILE__, 'wc_ai_syndication_deactivate' );
