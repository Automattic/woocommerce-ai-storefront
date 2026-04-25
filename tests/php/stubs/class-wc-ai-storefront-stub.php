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
				'return_policy'          => [ 'mode' => 'unconfigured' ],
			],
			self::$test_settings
		);
	}

	/**
	 * Mirror of production `sanitize_return_policy()`. Keep in sync
	 * with `includes/class-wc-ai-storefront.php` when adding new
	 * fields or enum values.
	 */
	public static function sanitize_return_policy( $policy ): array {
		if ( ! is_array( $policy ) ) {
			$policy = [];
		}

		$allowed_modes = [ 'unconfigured', 'returns_accepted', 'final_sale' ];
		$mode          = isset( $policy['mode'] ) && in_array( $policy['mode'], $allowed_modes, true )
			? $policy['mode']
			: 'unconfigured';

		$page_id = isset( $policy['page_id'] ) ? (int) $policy['page_id'] : 0;
		if ( $page_id < 0 ) {
			$page_id = 0;
		}
		if ( $page_id > 0 ) {
			$status = function_exists( 'get_post_status' ) ? get_post_status( $page_id ) : false;
			$type   = function_exists( 'get_post_type' ) ? get_post_type( $page_id ) : false;
			if ( 'publish' !== $status || 'page' !== $type ) {
				$page_id = 0;
			}
		}

		$days = isset( $policy['days'] ) ? (int) $policy['days'] : 0;
		if ( $days < 0 ) {
			$days = 0;
		}
		if ( $days > 365 ) {
			$days = 365;
		}

		$allowed_fees = [
			'FreeReturn',
			'ReturnFeesCustomerResponsibility',
			'OriginalShippingFees',
			'RestockingFees',
		];
		$fees         = isset( $policy['fees'] ) && in_array( $policy['fees'], $allowed_fees, true )
			? $policy['fees']
			: 'FreeReturn';

		$allowed_methods = [ 'ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk' ];
		$methods_input   = isset( $policy['methods'] ) && is_array( $policy['methods'] )
			? $policy['methods']
			: [];
		$methods         = [];
		foreach ( $methods_input as $method ) {
			if ( is_string( $method ) && in_array( $method, $allowed_methods, true ) ) {
				$methods[] = $method;
			}
		}
		$methods = array_values( array_unique( $methods ) );

		return [
			'mode'    => $mode,
			'page_id' => $page_id,
			'days'    => $days,
			'fees'    => $fees,
			'methods' => $methods,
		];
	}

	/**
	 * Stub of `is_product_syndicated()` mirroring the production
	 * UNION logic for 0.1.5+. Legacy modes (`categories`/`tags`/
	 * `brands`) route through `by_taxonomy` via the same defensive
	 * fallback as production. The `by_taxonomy` category match uses
	 * `wp_get_post_terms` (the production code uses
	 * `wc_get_product_cat_ids`, but that helper isn't stubbed in
	 * this unit-test harness — `wp_get_post_terms` is).
	 *
	 * Keep in sync with
	 * `WC_AI_Storefront::is_product_syndicated()` when adding new
	 * `product_selection_mode` values or changing the enforcement
	 * semantics.
	 */
	public static function is_product_syndicated( $product, ?array $settings = null ): bool {
		$settings = $settings ?? self::get_settings();
		$mode     = $settings['product_selection_mode'] ?? 'all';

		// Accept int OR WC_Product-like object — mirrors the
		// production refactor in 0.1.7 that lets UCP REST callers
		// pass a raw ID without paying for `wc_get_product()`.
		$product_id = is_int( $product )
			? $product
			: ( is_object( $product ) && method_exists( $product, 'get_id' )
				? (int) $product->get_id()
				: 0 );

		if ( $product_id <= 0 ) {
			return false;
		}

		if ( in_array( $mode, [ 'categories', 'tags', 'brands' ], true ) ) {
			$mode = 'by_taxonomy';
		}

		if ( 'all' === $mode ) {
			return true;
		}

		if ( 'selected' === $mode ) {
			if ( empty( $settings['selected_products'] ) ) {
				return false;
			}
			return in_array(
				$product_id,
				array_map( 'absint', $settings['selected_products'] ),
				true
			);
		}

		if ( 'by_taxonomy' === $mode ) {
			$selected_categories = array_map( 'absint', $settings['selected_categories'] ?? [] );
			$selected_tags       = array_map( 'absint', $settings['selected_tags'] ?? [] );
			$selected_brands     = array_map( 'absint', $settings['selected_brands'] ?? [] );

			$brands_supported = taxonomy_exists( 'product_brand' );

			$has_cats   = ! empty( $selected_categories );
			$has_tags   = ! empty( $selected_tags );
			$has_brands = ! empty( $selected_brands ) && $brands_supported;

			// Brand-downgrade exception: only brands set, taxonomy
			// missing → show all (preserves merchant intent across
			// an environment change).
			if ( ! $has_cats && ! $has_tags && ! $brands_supported && ! empty( $selected_brands ) ) {
				return true;
			}

			// Empty-selection policy.
			if ( ! $has_cats && ! $has_tags && ! $has_brands ) {
				return false;
			}

			// `$product_id` is already resolved at the top of this
			// method.

			if ( $has_cats ) {
				$product_cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
				if ( ! is_wp_error( $product_cats ) && ! empty( array_intersect( $product_cats, $selected_categories ) ) ) {
					return true;
				}
			}

			if ( $has_tags ) {
				$product_tags = wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'ids' ] );
				if ( ! is_wp_error( $product_tags ) && ! empty( array_intersect( $product_tags, $selected_tags ) ) ) {
					return true;
				}
			}

			if ( $has_brands ) {
				$product_brands = wp_get_post_terms( $product_id, 'product_brand', [ 'fields' => 'ids' ] );
				if ( ! is_wp_error( $product_brands ) && ! empty( array_intersect( $product_brands, $selected_brands ) ) ) {
					return true;
				}
			}

			return false;
		}

		return false;
	}

	/**
	 * Stub of `update_settings()` mirroring the production sanitization
	 * for `product_selection_mode`. Stores the result back into
	 * `$test_settings` so subsequent `get_settings()` calls reflect it.
	 *
	 * Keep in sync with `includes/class-wc-ai-storefront.php` when the
	 * allowed `product_selection_mode` values change.
	 *
	 * @param array<string, mixed> $settings Partial settings to merge in.
	 */
	public static function update_settings( array $settings ): void {
		$current = self::get_settings();
		$merged  = array_merge( $current, $settings );

		$sanitized_mode = in_array(
			$merged['product_selection_mode'],
			[ 'all', 'by_taxonomy', 'categories', 'tags', 'brands', 'selected' ],
			true
		) ? $merged['product_selection_mode'] : 'all';

		$overrides = [ 'product_selection_mode' => $sanitized_mode ];

		// If a return_policy was passed in, route it through the
		// production-mirrored sanitizer so tests against the REST
		// surface assert real sanitized values.
		if ( array_key_exists( 'return_policy', $settings ) ) {
			$overrides['return_policy'] = self::sanitize_return_policy( $settings['return_policy'] );
		}

		self::$test_settings = array_merge(
			self::$test_settings,
			$settings,
			$overrides
		);
	}
}
