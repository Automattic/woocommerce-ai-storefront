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
	 * Stub of `is_product_syndicated()` mirroring the production logic
	 * for all modes that have unit tests. 'categories' falls through to
	 * `return true` (no category-fixture infrastructure in unit tests).
	 *
	 * Keep in sync with `includes/class-wc-ai-storefront.php` when
	 * adding new `product_selection_mode` values.
	 */
	public static function is_product_syndicated( $product, ?array $settings = null ): bool {
		$settings = $settings ?? self::get_settings();
		$mode     = $settings['product_selection_mode'] ?? 'all';

		if ( 'all' === $mode ) {
			return true;
		}

		if ( 'tags' === $mode ) {
			if ( empty( $settings['selected_tags'] ) ) {
				return false;
			}
			$product_tags = wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'ids' ] );
			if ( is_wp_error( $product_tags ) ) {
				return false;
			}
			return ! empty( array_intersect( $product_tags, array_map( 'absint', $settings['selected_tags'] ) ) );
		}

		if ( 'brands' === $mode ) {
			if ( ! taxonomy_exists( 'product_brand' ) ) {
				return true;
			}
			if ( empty( $settings['selected_brands'] ) ) {
				return false;
			}
			$product_brands = wp_get_post_terms( $product->get_id(), 'product_brand', [ 'fields' => 'ids' ] );
			if ( is_wp_error( $product_brands ) ) {
				return false;
			}
			return ! empty( array_intersect( $product_brands, array_map( 'absint', $settings['selected_brands'] ) ) );
		}

		if ( 'selected' === $mode ) {
			return in_array(
				$product->get_id(),
				$settings['selected_products'] ?? [],
				true
			);
		}

		return true; // 'categories' + unknown modes — no fixture infra needed yet
	}
}
