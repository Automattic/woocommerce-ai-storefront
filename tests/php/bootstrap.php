<?php
/**
 * PHPUnit bootstrap for AI Syndication unit tests.
 *
 * @package WooCommerce_AI_Syndication
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// WordPress stubs (WP_Error, WP_REST_Request, etc.).
require_once __DIR__ . '/stubs.php';

// Define WordPress constants.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Load the settings stub before classes that reference WC_AI_Syndication statically.
require_once __DIR__ . '/stubs/class-wc-ai-syndication-stub.php';

// Load plugin files.
$plugin_path = dirname( __DIR__, 2 ) . '/includes/';

require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-logger.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-llms-txt.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-ucp.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-robots.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-store-api-rate-limiter.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-attribution.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-cache-invalidator.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-jsonld.php';

// UCP REST adapter module (1.3.0+).
$ucp_rest_path = $plugin_path . 'ai-syndication/ucp-rest/';
require_once $ucp_rest_path . 'class-wc-ai-syndication-ucp-agent-header.php';
require_once $ucp_rest_path . 'class-wc-ai-syndication-ucp-envelope.php';
require_once $ucp_rest_path . 'class-wc-ai-syndication-ucp-product-translator.php';
require_once $ucp_rest_path . 'class-wc-ai-syndication-ucp-variant-translator.php';
require_once $ucp_rest_path . 'class-wc-ai-syndication-ucp-store-api-filter.php';
require_once $ucp_rest_path . 'class-wc-ai-syndication-ucp-rest-controller.php';

// Self-updater wrapper around the PUC library (1.4.0+).
require_once $plugin_path . 'class-wc-ai-syndication-updater.php';

// The updater uses WC_AI_SYNDICATION_PLUGIN_PATH + _FILE to locate
// the vendored library at runtime. Define them here so unit tests
// can exercise the init guard clauses without requiring a full
// plugin bootstrap. These mirror the real constants set in the
// plugin entry file.
if ( ! defined( 'WC_AI_SYNDICATION_PLUGIN_PATH' ) ) {
	define( 'WC_AI_SYNDICATION_PLUGIN_PATH', dirname( __DIR__, 2 ) );
}
if ( ! defined( 'WC_AI_SYNDICATION_PLUGIN_FILE' ) ) {
	define( 'WC_AI_SYNDICATION_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/woocommerce-ai-syndication.php' );
}
