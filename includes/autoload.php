<?php
/**
 * Classmap autoloader for WooCommerce AI Storefront.
 *
 * Generated from the autoload.classmap entries in composer.json.
 * Update this file when adding or removing plugin classes, then run
 * `composer dump-autoload` to verify the classmap stays in sync.
 *
 * Kept as a standalone file so the plugin works on a fresh clone
 * without running `composer install` (dev tooling only, no production
 * Composer dependencies).
 *
 * @package WooCommerce_AI_Storefront
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	function ( string $class_name ): void {
		static $classmap = array(
			'WC_AI_Storefront'                        => '/class-wc-ai-storefront.php',
			'WC_AI_Storefront_Updater'                => '/class-wc-ai-storefront-updater.php',
			'WC_AI_Storefront_Admin_Controller'       => '/admin/class-wc-ai-storefront-admin-controller.php',
			'WC_AI_Storefront_Product_Meta_Box'       => '/admin/class-wc-ai-storefront-product-meta-box.php',
			'WC_AI_Storefront_Attribution'            => '/ai-storefront/class-wc-ai-storefront-attribution.php',
			'WC_AI_Storefront_Cache_Invalidator'      => '/ai-storefront/class-wc-ai-storefront-cache-invalidator.php',
			'WC_AI_Storefront_JsonLd'                 => '/ai-storefront/class-wc-ai-storefront-jsonld.php',
			'WC_AI_Storefront_Llms_Txt'               => '/ai-storefront/class-wc-ai-storefront-llms-txt.php',
			'WC_AI_Storefront_Logger'                 => '/ai-storefront/class-wc-ai-storefront-logger.php',
			'WC_AI_Storefront_Return_Policy'          => '/ai-storefront/class-wc-ai-storefront-return-policy.php',
			'WC_AI_Storefront_Robots'                 => '/ai-storefront/class-wc-ai-storefront-robots.php',
			'WC_AI_Storefront_Store_Api_Rate_Limiter' => '/ai-storefront/class-wc-ai-storefront-store-api-rate-limiter.php',
			'WC_AI_Storefront_Ucp'                    => '/ai-storefront/class-wc-ai-storefront-ucp.php',
			'WC_AI_Storefront_Store_Api_Extension'    => '/ai-storefront/ucp-rest/class-wc-ai-storefront-store-api-extension.php',
			'WC_AI_Storefront_UCP_Agent_Header'       => '/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-agent-header.php',
			'WC_AI_Storefront_UCP_Dispatch_Context'   => '/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-dispatch-context.php',
			'WC_AI_Storefront_UCP_Envelope'           => '/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-envelope.php',
			'WC_AI_Storefront_UCP_Error_Codes'        => '/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-error-codes.php',
			'WC_AI_Storefront_UCP_Product_Translator' => '/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-product-translator.php',
			'WC_AI_Storefront_UCP_Request_Context'    => '/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-request-context.php',
			'WC_AI_Storefront_UCP_REST_Controller'    => '/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php',
			'WC_AI_Storefront_UCP_Store_API_Filter'   => '/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-store-api-filter.php',
			'WC_AI_Storefront_UCP_Variant_Translator' => '/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-variant-translator.php',
		);

		if ( isset( $classmap[ $class_name ] ) ) {
			require_once __DIR__ . $classmap[ $class_name ];
		}
	},
	false, // do not throw on registration failure
	false  // append, not prepend
);
