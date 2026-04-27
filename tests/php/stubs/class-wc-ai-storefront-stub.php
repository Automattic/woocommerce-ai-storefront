<?php
/**
 * Minimal WC_AI_Storefront stub for unit tests.
 *
 * Provides a controllable get_settings() that tests can configure
 * via the $test_settings static property.
 *
 * Sanitization rules are NOT mirrored here — `sanitize_return_policy()`
 * delegates to `WC_AI_Storefront_Return_Policy::sanitize()`, the same
 * helper production calls. Tests therefore exercise real production
 * sanitization, eliminating the historical drift risk where the
 * stub's hand-mirrored rules masked production regressions.
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

	/**
	 * Test-controllable variation → parent map. Mirrors the production
	 * `wp_get_post_parent_id()` semantic that the production
	 * `is_product_syndicated()` uses for the variation-redirect block.
	 *
	 * Why a dedicated map instead of stubbing `get_post_type`/
	 * `wp_get_post_parent_id` via Brain Monkey: Brain Monkey makes
	 * `function_exists()` return true for every WP-preset name globally,
	 * but throws `MissingFunctionExpectations` when the function is
	 * called without a stub set in THIS test. That means the production
	 * `function_exists` guard fires in every test that touches
	 * `is_product_syndicated()` — even ones that have nothing to do
	 * with variations — and they all blow up. A purpose-built map keeps
	 * variation tests explicit and leaves every other test untouched.
	 *
	 * Keys are variation IDs, values are parent IDs. A MISSING key
	 * means "treat as a regular product, no redirect." A PRESENT
	 * entry with a non-positive parent ID (`0` or `-1`) exercises
	 * the orphaned-variation path (parent deleted but variation row
	 * lingers) and resolves as out-of-scope — the implementation
	 * branches on `array_key_exists($id, $test_variations)` first
	 * and then on whether the resolved parent is positive.
	 *
	 * @var array<int, int>
	 */
	public static array $test_variations = [];

	const SETTINGS_OPTION = 'wc_ai_storefront_settings';

	public static function get_settings(): array {
		return array_merge(
			[
				'enabled'                  => 'yes',
				'product_selection_mode'   => 'all',
				'selected_categories'      => [],
				'selected_products'        => [],
				'rate_limit_rpm'           => 25,
				'return_policy'            => [ 'mode' => 'unconfigured' ],
				// Mirror production default. The gate consumer only
				// reads this with `isset() && 'yes' ===`, so a missing
				// key behaves identically to `'no'` today — but the
				// stub doc above (lines 8–12) explicitly warns about
				// silent drift between the stub's defaults and the
				// production defaults, and that's the failure mode
				// every test in this codebase is trying to avoid.
				'allow_unknown_ucp_agents' => 'no',
			],
			self::$test_settings
		);
	}

	/**
	 * Sanitize a return-policy payload. Delegates to the production
	 * helper so tests assert real sanitization behavior.
	 */
	public static function sanitize_return_policy( $policy ): array {
		return WC_AI_Storefront_Return_Policy::sanitize( $policy );
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

		// Variations inherit their parent's syndication status. See
		// production `WC_AI_Storefront::is_product_syndicated()` for
		// the full rationale — `selected_products` stores parent IDs,
		// `selected_categories`/`tags`/`brands` attach to parents, and
		// variation posts have no term memberships of their own.
		// Without this redirect every variation reads as out-of-scope
		// and UCP catalog/lookup falls through to a synthesized
		// `var_{parent_id}_default` placeholder.
		//
		// In this stub the variation lookup goes through `$test_variations`
		// rather than `get_post_type` + `wp_get_post_parent_id`. See
		// the property docblock above for why — short version: Brain
		// Monkey's WP preset registers those functions globally, so
		// the production `function_exists` guard always fires under
		// test, and unrelated tests would crash with
		// `MissingFunctionExpectations`.
		if ( array_key_exists( $product_id, self::$test_variations ) ) {
			$parent_id = (int) self::$test_variations[ $product_id ];
			if ( $parent_id <= 0 ) {
				// Orphaned variation. No parent to inherit from —
				// treat as out-of-scope rather than silently leak.
				return false;
			}
			$product_id = $parent_id;
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

		// Mirror production: strict yes/no enum, anything else falls
		// back to `'no'`. Documented at
		// `includes/class-wc-ai-storefront.php::update_settings()`.
		// Coalesce ONCE into the local — see production for the
		// explicit-null hole that made the inline shape unsafe.
		$sanitized_unknown = $merged['allow_unknown_ucp_agents'] ?? 'no';
		if ( ! in_array( $sanitized_unknown, [ 'yes', 'no' ], true ) ) {
			$sanitized_unknown = 'no';
		}

		$overrides = [
			'product_selection_mode'   => $sanitized_mode,
			'allow_unknown_ucp_agents' => $sanitized_unknown,
		];

		// If a return_policy was passed in, route it through the
		// production sanitizer so tests against the REST surface
		// assert real sanitized values.
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
