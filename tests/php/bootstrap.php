<?php
/**
 * PHPUnit bootstrap for AI Syndication unit tests.
 *
 * @package WooCommerce_AI_Storefront
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// WordPress stubs (WP_Error, WP_REST_Request, etc.).
require_once __DIR__ . '/stubs.php';

// Define WordPress constants.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Load the settings stub before classes that reference WC_AI_Storefront statically.
require_once __DIR__ . '/stubs/class-wc-ai-storefront-stub.php';

// Load plugin files.
$plugin_path = dirname( __DIR__, 2 ) . '/includes/';

require_once $plugin_path . 'ai-storefront/class-wc-ai-storefront-logger.php';
require_once $plugin_path . 'ai-storefront/class-wc-ai-storefront-return-policy.php';
require_once $plugin_path . 'ai-storefront/class-wc-ai-storefront-llms-txt.php';
require_once $plugin_path . 'ai-storefront/class-wc-ai-storefront-ucp.php';
require_once $plugin_path . 'ai-storefront/class-wc-ai-storefront-robots.php';
require_once $plugin_path . 'ai-storefront/class-wc-ai-storefront-store-api-rate-limiter.php';
require_once $plugin_path . 'ai-storefront/class-wc-ai-storefront-attribution.php';
require_once $plugin_path . 'ai-storefront/class-wc-ai-storefront-cache-invalidator.php';
require_once $plugin_path . 'ai-storefront/class-wc-ai-storefront-jsonld.php';

// UCP REST adapter module (1.3.0+).
$ucp_rest_path = $plugin_path . 'ai-storefront/ucp-rest/';
require_once $ucp_rest_path . 'class-wc-ai-storefront-ucp-agent-header.php';
require_once $ucp_rest_path . 'class-wc-ai-storefront-ucp-envelope.php';
require_once $ucp_rest_path . 'class-wc-ai-storefront-ucp-product-translator.php';
require_once $ucp_rest_path . 'class-wc-ai-storefront-ucp-variant-translator.php';
require_once $ucp_rest_path . 'class-wc-ai-storefront-ucp-store-api-filter.php';
require_once $ucp_rest_path . 'class-wc-ai-storefront-store-api-extension.php';
require_once $ucp_rest_path . 'class-wc-ai-storefront-ucp-rest-controller.php';

// Admin REST controller. Covers admin-surface endpoints (settings,
// stats, recent-orders) — exercised by AdminRecentOrdersTest.
require_once $plugin_path . 'admin/class-wc-ai-storefront-admin-controller.php';

// Per-product final-sale meta box. Reads `_wc_ai_storefront_final_sale`
// post meta which is consumed by the JSON-LD return-policy emitter
// — exercised by JsonLdReturnPolicyTest's per-product override
// branches plus the dedicated ProductMetaBoxTest.
require_once $plugin_path . 'admin/class-wc-ai-storefront-product-meta-box.php';

// Self-updater wrapper around the PUC library (1.4.0+).
require_once $plugin_path . 'class-wc-ai-storefront-updater.php';

// The updater uses WC_AI_STOREFRONT_PLUGIN_PATH + _FILE to locate
// the vendored library at runtime. Define them here so unit tests
// can exercise the init guard clauses without requiring a full
// plugin bootstrap. These mirror the real constants set in the
// plugin entry file.
if ( ! defined( 'WC_AI_STOREFRONT_PLUGIN_PATH' ) ) {
	define( 'WC_AI_STOREFRONT_PLUGIN_PATH', dirname( __DIR__, 2 ) );
}
if ( ! defined( 'WC_AI_STOREFRONT_PLUGIN_FILE' ) ) {
	define( 'WC_AI_STOREFRONT_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/woocommerce-ai-storefront.php' );
}
