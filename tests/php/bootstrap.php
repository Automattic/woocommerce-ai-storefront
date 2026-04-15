<?php
/**
 * PHPUnit bootstrap for AI Syndication unit tests.
 *
 * Uses Brain Monkey to mock WordPress functions so tests run
 * without a WordPress installation. Fast and CI-friendly.
 *
 * @package WooCommerce_AI_Syndication
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// WordPress stubs (WP_Error, etc.).
require_once __DIR__ . '/stubs.php';

// Define WordPress constants that the plugin code expects.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Load plugin files that don't have side effects on include.
$plugin_path = dirname( __DIR__, 2 ) . '/includes/';

// Load the settings stub before classes that reference WC_AI_Syndication statically.
require_once __DIR__ . '/stubs/class-wc-ai-syndication-stub.php';

require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-llms-txt.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-ucp.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-bot-manager.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-rate-limiter.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-catalog-api.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-attribution.php';
require_once $plugin_path . 'ai-syndication/class-wc-ai-syndication-cache-invalidator.php';
