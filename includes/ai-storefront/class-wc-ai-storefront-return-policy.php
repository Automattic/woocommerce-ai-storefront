<?php
/**
 * Return-policy sanitizer.
 *
 * Pure helper extracted from `WC_AI_Storefront::sanitize_return_policy()`
 * so both production and the unit-test stub of `WC_AI_Storefront`
 * delegate to the same code path. Before this change, the test stub
 * hand-mirrored the production rules — sanitization tests passed even
 * when the production sanitizer was broken. Centralizing the rules in
 * one class eliminates that drift.
 *
 * Mode-aware shape: only the fields that are meaningful for the
 * resolved mode are persisted. `unconfigured` stores `mode` only;
 * `link` stores `mode` + `page_id`; `details` + `final_sale` stores
 * `mode` + `category`; `details` + `returns_accepted` stores the full
 * shape. This prevents stale `days`/`fees`/`methods` from lingering
 * when a merchant flips modes.
 *
 * @package WooCommerce_AI_Storefront
 * @since 0.1.15
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sanitize the return-policy settings object.
 */
class WC_AI_Storefront_Return_Policy {

	/**
	 * Sanitize a raw return-policy input.
	 *
	 * Field rules:
	 *   - `mode`: one of `unconfigured`, `link`, `details`. Default `unconfigured`.
	 *   - `page_id`: WP page ID. Used iff mode === 'link'. Must point to an
	 *     existing, published `page` post. Otherwise reset to 0.
	 *   - `category`: one of `returns_accepted`, `final_sale`. Used iff
	 *     mode === 'details'. Unknown/missing → fails closed to `unconfigured`.
	 *   - `days`, `fees`, `methods`: used iff mode === 'details' &&
	 *     category === 'returns_accepted'. Same rules as before.
	 *
	 * Mode-aware persistence:
	 *   - `unconfigured` → `{ mode }` only.
	 *   - `link`         → `{ mode, page_id }`.
	 *   - `details` + `final_sale`      → `{ mode, category }`.
	 *   - `details` + `returns_accepted` → `{ mode, category, days, fees, methods }`.
	 *   Unknown `mode` or `category` → `{ mode: 'unconfigured' }`.
	 *
	 * @param mixed $policy Raw return-policy input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $policy ): array {
		if ( ! is_array( $policy ) ) {
			$policy = array();
		}

		$allowed_modes = array( 'unconfigured', 'link', 'details' );
		$mode          = isset( $policy['mode'] ) && in_array( $policy['mode'], $allowed_modes, true )
			? $policy['mode']
			: 'unconfigured';

		if ( 'unconfigured' === $mode ) {
			return array( 'mode' => 'unconfigured' );
		}

		if ( 'link' === $mode ) {
			$page_id = isset( $policy['page_id'] ) ? self::absint( $policy['page_id'] ) : 0;
			if ( $page_id > 0 ) {
				$status = function_exists( 'get_post_status' ) ? get_post_status( $page_id ) : false;
				$type   = function_exists( 'get_post_type' ) ? get_post_type( $page_id ) : false;
				if ( 'publish' !== $status || 'page' !== $type ) {
					$page_id = 0;
				}
			}
			return array(
				'mode'    => 'link',
				'page_id' => $page_id,
			);
		}

		// mode === 'details': requires a valid category.
		$allowed_categories = array( 'returns_accepted', 'final_sale' );
		$category           = isset( $policy['category'] ) && in_array( $policy['category'], $allowed_categories, true )
			? $policy['category']
			: null;

		if ( null === $category ) {
			// Unknown/missing category: fail closed.
			return array( 'mode' => 'unconfigured' );
		}

		if ( 'final_sale' === $category ) {
			// Only mode + category are meaningful for final_sale.
			return array(
				'mode'     => 'details',
				'category' => 'final_sale',
			);
		}

		// details + returns_accepted: full 5-field shape (no page_id).

		// `days` accepts integer 0–365 OR null (no window configured).
		// Null is the canonical "unset" representation; legacy 0 is
		// still tolerated as input and mapped to null on persistence
		// so the stored shape doesn't carry a magic value.
		$days = null;
		if ( array_key_exists( 'days', $policy ) && null !== $policy['days'] ) {
			$days = self::absint( $policy['days'] );
			if ( $days > 365 ) {
				$days = 365;
			}
			if ( 0 === $days ) {
				$days = null;
			}
		}

		$allowed_fees = array(
			'FreeReturn',
			'ReturnFeesCustomerResponsibility',
			'OriginalShippingFees',
			'RestockingFees',
		);
		$fees         = isset( $policy['fees'] ) && in_array( $policy['fees'], $allowed_fees, true )
			? $policy['fees']
			: 'FreeReturn';

		$allowed_methods = array( 'ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk' );
		$methods_input   = isset( $policy['methods'] ) && is_array( $policy['methods'] )
			? $policy['methods']
			: array();
		$methods         = array();
		foreach ( $methods_input as $method ) {
			if ( is_string( $method ) && in_array( $method, $allowed_methods, true ) ) {
				$methods[] = $method;
			}
		}
		$methods = array_values( array_unique( $methods ) );

		return array(
			'mode'     => 'details',
			'category' => 'returns_accepted',
			'days'     => $days,
			'fees'     => $fees,
			'methods'  => $methods,
		);
	}

	/**
	 * Clamp a value to a non-negative integer.
	 *
	 * Standalone (does NOT delegate to WP's `absint()`) because WP's
	 * `absint()` takes the absolute value — `absint( -5 )` returns
	 * `5`, not `0`. That contradicts the sanitizer's "negative →
	 * unset" contract: a merchant typing `-5` for days should produce
	 * the null sentinel (no window configured), not 5 days. Casting
	 * via `max( 0, (int) $v )` clamps negatives to 0 cleanly,
	 * which the days/page_id branches above interpret as "no value."
	 * Same logic applies in test (no WP) and production (with WP)
	 * — the contract doesn't bend on environment.
	 *
	 * @param mixed $v Input value.
	 * @return int
	 */
	private static function absint( $v ): int {
		return max( 0, (int) $v );
	}
}
