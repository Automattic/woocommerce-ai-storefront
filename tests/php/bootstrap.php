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
