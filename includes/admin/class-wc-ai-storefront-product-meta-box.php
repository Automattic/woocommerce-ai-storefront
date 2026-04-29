<?php
/**
 * Per-product final-sale meta box.
 *
 * Adds a single checkbox to the WC product editor's Inventory tab that
 * lets merchants flag individual products as "no returns accepted",
 * overriding the store-wide return policy in JSON-LD.
 *
 * Design (Pattern A — single boolean override):
 *
 *   The store-wide return policy in the Policies tab covers the
 *   dominant case (every product follows the same rule). But ~70% of
 *   merchants who want any per-product override want it for one
 *   specific reason: "this clearance / sale / custom item is final
 *   sale, even though the rest of my store accepts returns."
 *
 *   This meta box adds the lightest-possible per-product override
 *   surface: a single checkbox. When checked, the product's JSON-LD
 *   `hasMerchantReturnPolicy` flips to `MerchantReturnNotPermitted`
 *   regardless of what the store-wide setting says. When unchecked
 *   (the default), the store-wide setting applies as before.
 *
 *   Pattern B (full per-product custom policies — different days, fees,
 *   methods per product) and Pattern C (field-level override flags)
 *   are deferred until real demand emerges.
 *
 * Variable products: parent-product level only; variants inherit from
 * the parent. Per-variant override is Pattern B and deferred.
 *
 * Meta convention:
 *   - Key: `_wc_ai_storefront_final_sale` — underscore prefix marks it
 *     as hidden from WC's Custom Fields panel; `_wc_ai_storefront_*`
 *     namespace matches the rest of the plugin's order meta.
 *   - Value: `'yes'` / `'no'` strings, matching WC core's existing
 *     boolean meta convention (`_manage_stock`, `_sold_individually`,
 *     `_virtual`, `_downloadable`). Avoids null/empty-string ambiguity.
 *
 * Quick Edit + Bulk Edit support are explicitly NOT in v1 — adds
 * modest complexity that should wait on real merchant demand. Same
 * reasoning for variants and a list-table column.
 *
 * @package WooCommerce_AI_Storefront
 * @since 0.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders + persists the per-product "Final sale (no returns)" checkbox.
 */
class WC_AI_Storefront_Product_Meta_Box {

	/**
	 * Hidden post meta key holding the per-product override.
	 *
	 * Underscore prefix conforms to WC's hidden-meta convention — the
	 * Custom Fields panel filters out underscore-prefixed keys, so
	 * merchants don't see this in the post-meta UI alongside their
	 * own custom fields.
	 *
	 * Value semantics:
	 *   'yes' → product is flagged final-sale; JSON-LD emits
	 *           MerchantReturnNotPermitted regardless of store-wide mode.
	 *   'no'  → store-wide setting applies (the default for new products).
	 *   ''    → never set; treated identically to 'no' by the reader.
	 */
	const META_KEY = '_wc_ai_storefront_final_sale';

	/**
	 * Initialize hooks.
	 *
	 * Called from `WC_AI_Storefront::init_components()` only when in
	 * wp-admin — the meta box has no frontend purpose, so we save the
	 * hook-registration cost for store visitors.
	 */
	public function init(): void {
		// Render the checkbox in the Inventory tab. WC fires
		// `woocommerce_product_options_inventory_product_data` inside
		// the panel's <div> after the built-in fields, so our checkbox
		// appears at the bottom of the Inventory tab. The 30 priority
		// keeps us out of the way of WC's own ordering (1-20).
		add_action(
			'woocommerce_product_options_inventory_product_data',
			[ $this, 'render_checkbox' ],
			30
		);

		// Persist the value on product save. WC fires
		// `woocommerce_process_product_meta` after capabilities + nonce
		// have been verified by core's product save handler, so we can
		// safely read $_POST without an additional nonce check at this
		// layer. (PHPCS still flags it; suppress with a clear inline
		// comment at the read site.)
		add_action(
			'woocommerce_process_product_meta',
			[ $this, 'save_meta' ],
			10,
			1
		);
	}

	/**
	 * Render the "AI: Final sale" checkbox inside the Inventory tab.
	 *
	 * Uses WC core's `woocommerce_wp_checkbox()` helper so the visual
	 * treatment, label width, and description-tooltip behavior match
	 * the surrounding Inventory checkboxes (Manage stock, Sold
	 * individually, etc.) — important for visual consistency in the
	 * editor where mixing styling looks like a UI bug.
	 *
	 * The `AI:` label prefix scans visually as "this isn't a core WC
	 * field" so merchants browsing the Inventory tab can tell at a
	 * glance which checkboxes are this plugin vs. WC core. Same
	 * pattern other commerce plugins follow.
	 */
	public function render_checkbox(): void {
		// Bail early if WC's helper isn't loaded — defensive against
		// edge cases where this class somehow gets instantiated outside
		// the WC product-edit context (custom post-type plugins doing
		// their own meta boxes, etc.). Without this guard the call
		// would fatal with "undefined function".
		if ( ! function_exists( 'woocommerce_wp_checkbox' ) ) {
			return;
		}

		woocommerce_wp_checkbox(
			[
				'id'          => self::META_KEY,
				'label'       => __( 'AI: Final sale', 'woocommerce-ai-storefront' ),
				'description' => __(
					'Override the store-wide return policy for this product. AI agents will see "no returns" regardless of your store policy. Use for clearance items, custom orders, or any product whose return terms diverge from the store default.',
					'woocommerce-ai-storefront'
				),
				'desc_tip'    => true,
			]
		);
	}

	/**
	 * Persist the checkbox state on product save.
	 *
	 * WC's product save handler calls this with the product post ID
	 * after running its own nonce + capability checks. We sanitize the
	 * incoming POST value to a strict 'yes'/'no' to keep the meta
	 * value normalized — only the literal `'yes'` string flips the
	 * flag on; anything else (forged `value="no"`, `value=""`, junk,
	 * or absent key) writes the literal `'no'`. Two layers of
	 * defense:
	 *
	 *   - `isset()` ensures the key was present in POST.
	 *   - `'yes' === $_POST[key]` ensures the value is the canonical
	 *     literal. A tampered payload like
	 *     `<input name="…" value="no">` (still in POST so isset()
	 *     would be true) is correctly rejected by the value check
	 *     and written as `'no'` rather than smuggled in as `'yes'`.
	 *
	 * `update_post_meta` returns false on DB failure OR when the
	 * value is unchanged. We disambiguate by reading the current
	 * value first: a `false` return when the value DID change is a
	 * real write failure and gets logged so the merchant has a
	 * trail (the WC admin UI renders "Update successful" no matter
	 * what we return here).
	 *
	 * @param int $product_id Product post ID being saved.
	 */
	public function save_meta( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		// Verify our own nonce even though WC core fires this hook only after
		// checking `woocommerce_meta_nonce` in its save_post handler. The hook
		// is a public WordPress action that any plugin or CLI call can fire
		// directly, bypassing WC's auth gate (CWE-352 / FIND-S03).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_verify_nonce handles both.
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) ) {
			return;
		}

		// HTML checkboxes that are unchecked send NO key in the POST
		// payload; checked sends the input's value (literally `'yes'`
		// per WC's `woocommerce_wp_checkbox()` helper).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$posted_value = isset( $_POST[ self::META_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_KEY ] ) ) : '';
		$value        = 'yes' === $posted_value ? 'yes' : 'no';

		$existing = get_post_meta( $product_id, self::META_KEY, true );
		$result   = update_post_meta( $product_id, self::META_KEY, $value );

		// `update_post_meta` returns truthy on success and `false`
		// either when the write failed OR when the value was
		// unchanged. Disambiguate using the pre-read: if the value
		// actually changed and the call returned `false`, log it.
		// Without this distinction we'd either spam logs on every
		// save-with-no-changes or miss real failures entirely.
		if ( false === $result && $existing !== $value ) {
			WC_AI_Storefront_Logger::debug(
				'failed to persist final-sale flag — product=%d key=%s value=%s',
				$product_id,
				self::META_KEY,
				$value
			);
		}
	}

	/**
	 * Whether the given product is flagged as final-sale.
	 *
	 * Single source of truth for the meta read. Both the JSON-LD
	 * emission layer and any future consumer (admin column,
	 * CSV-export integration, etc.) should call this rather than
	 * reading the meta directly — keeps the value normalization
	 * (string `'yes'` → bool `true`) in one place, so a future change
	 * to the storage representation doesn't require touching every
	 * call site.
	 *
	 * Returns false for invalid product IDs (zero, negative) so
	 * callers don't need to pre-validate — the bool result already
	 * encodes "not flagged" for both the unset-meta and bad-input
	 * cases.
	 *
	 * @param int $product_id Product post ID.
	 * @return bool True when the product is flagged final-sale.
	 */
	public static function is_final_sale( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		$value = get_post_meta( $product_id, self::META_KEY, true );

		// Strict 'yes' comparison: anything else (`''`, `'no'`,
		// `null`, `false`, future garbage) is "not flagged". WC core
		// uses the same idiom for its own option/meta reads — see
		// `'yes' === get_option( 'woocommerce_manage_stock' )` in
		// `abstract-wc-product.php`. (`WC_Product::get_manage_stock()`
		// itself returns the prop-bag value pre-coerced via
		// `wc_string_to_bool()`, but the option-level reads still use
		// the strict-string idiom and predate the boolean migration.)
		return 'yes' === $value;
	}
}
