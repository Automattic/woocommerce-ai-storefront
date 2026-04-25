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
 * `final_sale` stores `mode` + `page_id`; `returns_accepted` stores
 * the full 5-field shape. This prevents stale `days`/`fees`/`methods`
 * from lingering when a merchant flips modes.
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
	 *   - `mode`: one of `unconfigured`, `returns_accepted`,
	 *     `final_sale`. Default `unconfigured` (emit no policy).
	 *   - `page_id`: WP page ID. Must point to an existing, published
	 *     `page` post. Otherwise reset to 0 (omit `merchantReturnLink`).
	 *   - `days`: integer 0–365, OR null to mean "no days configured" —
	 *     emission smart-degrades to `MerchantReturnUnspecified` rather
	 *     than emit a `FiniteReturnWindow` without `merchantReturnDays`.
	 *   - `fees`: one of `FreeReturn`, `ReturnFeesCustomerResponsibility`,
	 *     `OriginalShippingFees`, `RestockingFees`. Default `FreeReturn`.
	 *   - `methods`: array of `ReturnByMail`, `ReturnInStore`,
	 *     `ReturnAtKiosk`. Deduped and reindexed. Empty array allowed
	 *     (omits the field).
	 *
	 * Mode-aware persistence:
	 *   - `unconfigured` → returns `[ 'mode' => 'unconfigured' ]` only.
	 *   - `final_sale` → returns `[ 'mode', 'page_id' ]`. The other
	 *     three fields are nonsensical when returns are not permitted,
	 *     so we drop them rather than carry stale values forward.
	 *   - `returns_accepted` → returns all 5 fields.
	 *
	 * @param mixed $policy Raw return-policy input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $policy ): array {
		if ( ! is_array( $policy ) ) {
			$policy = [];
		}

		$allowed_modes = [ 'unconfigured', 'returns_accepted', 'final_sale' ];
		$mode          = isset( $policy['mode'] ) && in_array( $policy['mode'], $allowed_modes, true )
			? $policy['mode']
			: 'unconfigured';

		if ( 'unconfigured' === $mode ) {
			return [ 'mode' => 'unconfigured' ];
		}

		// Page ID validation — accept only IDs that resolve to a
		// published `page` post. A draft / trashed / deleted page
		// would otherwise emit a broken `merchantReturnLink`.
		$page_id = isset( $policy['page_id'] ) ? self::absint( $policy['page_id'] ) : 0;
		if ( $page_id > 0 ) {
			$status = function_exists( 'get_post_status' ) ? get_post_status( $page_id ) : false;
			$type   = function_exists( 'get_post_type' ) ? get_post_type( $page_id ) : false;
			if ( 'publish' !== $status || 'page' !== $type ) {
				$page_id = 0;
			}
		}

		if ( 'final_sale' === $mode ) {
			// Only `mode` + `page_id` are meaningful for final_sale.
			// Drop `days` / `fees` / `methods` — they don't apply when
			// returns aren't permitted, and persisting them would
			// resurrect stale state if the merchant flipped back.
			return [
				'mode'    => 'final_sale',
				'page_id' => $page_id,
			];
		}

		// Mode: returns_accepted — full 5-field shape.

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
			'mode'    => 'returns_accepted',
			'page_id' => $page_id,
			'days'    => $days,
			'fees'    => $fees,
			'methods' => $methods,
		];
	}

	/**
	 * `absint`-equivalent helper. Standalone to keep the sanitizer a
	 * pure function callable from the test harness without pulling in
	 * the WP `absint` stub. Falls back to `absint()` when WP is loaded.
	 *
	 * @param mixed $v Input value.
	 * @return int
	 */
	private static function absint( $v ): int {
		if ( function_exists( 'absint' ) ) {
			return absint( $v );
		}
		return max( 0, (int) $v );
	}
}
