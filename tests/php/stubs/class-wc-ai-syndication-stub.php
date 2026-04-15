<?php
/**
 * Minimal WC_AI_Syndication stub for unit tests.
 *
 * Provides a controllable get_settings() that tests can configure
 * via the $test_settings static property.
 *
 * @package WooCommerce_AI_Syndication
 */

class WC_AI_Syndication {

	/**
	 * Test-controllable settings. Set this in your test before calling
	 * code that uses WC_AI_Syndication::get_settings().
	 *
	 * @var array
	 */
	public static array $test_settings = [];

	const SETTINGS_OPTION = 'wc_ai_syndication_settings';

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
}
