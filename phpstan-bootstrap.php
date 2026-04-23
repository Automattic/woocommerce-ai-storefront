<?php
/**
 * PHPStan bootstrap — declares plugin constants for static analysis.
 *
 * At runtime these are defined in woocommerce-ai-syndication.php,
 * but PHPStan analyses files in isolation and doesn't execute the
 * main bootstrap. Declaring them here lets the analyzer see that
 * they exist (and their types) without inventing values.
 *
 * @package WooCommerce_AI_Storefront
 */

if ( ! defined( 'WC_AI_STOREFRONT_VERSION' ) ) {
	define( 'WC_AI_STOREFRONT_VERSION', '0.0.0' );
}
if ( ! defined( 'WC_AI_STOREFRONT_PLUGIN_FILE' ) ) {
	define( 'WC_AI_STOREFRONT_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WC_AI_STOREFRONT_PLUGIN_PATH' ) ) {
	define( 'WC_AI_STOREFRONT_PLUGIN_PATH', __DIR__ );
}
if ( ! defined( 'WC_AI_STOREFRONT_PLUGIN_URL' ) ) {
	define( 'WC_AI_STOREFRONT_PLUGIN_URL', 'https://example.com' );
}
