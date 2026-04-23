<?php
/**
 * Minimal WC_AI_Storefront stub for unit tests.
 *
 * Provides a controllable get_settings() that tests can configure
 * via the $test_settings static property.
 *
 * @package WooCommerce_AI_Storefront
 */

class WC_AI_Storefront {

	/**
	 * Test-controllable settings. Set this in your test before calling
	 * code that uses WC_AI_Storefront::get_settings().
	 *
	 * @var array
	 */
	public static array $test_settings = [];

	const SETTINGS_OPTION = 'wc_ai_storefront_settings';

	public static function get_settings(): array {
		return array_merge(
			[
				'enabled'                => 'yes',
				'product_selection_mode' => 'all',
				'selected_categories'    => [],
				'selected_products'      => [],
				'rate_limit_rpm'         => 25,
			],
			self::$test_settings
		);
	}

	/**
	 * Minimal stub of the production `is_product_syndicated()` logic,
	 * sufficient for exercising the JSON-LD enhancer. Only covers the
	 * 'all' and 'selected' modes — 'categories' is harder to fake
	 * without category fixtures and isn't exercised in the current
	 * unit tests.
	 */
	public static function is_product_syndicated( $product, ?array $settings = null ): bool {
		$settings = $settings ?? self::get_settings();

		$mode = $settings['product_selection_mode'] ?? 'all';

		if ( 'selected' === $mode ) {
			return in_array(
				$product->get_id(),
				$settings['selected_products'] ?? [],
				true
			);
		}

		return true;
	}
}
