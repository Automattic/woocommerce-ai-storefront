<?php
/**
 * UCP Error Codes
 *
 * Centralises every UCP error-code string as a typed constant so static
 * analysis (PHPStan) can catch typos at analysis-time rather than at
 * runtime, and so any future rename propagates from one place.
 *
 * Usage:
 *
 *   WC_AI_Storefront_UCP_Error_Codes::UCP_DISABLED
 *   WC_AI_Storefront_UCP_Error_Codes::INVALID_INPUT
 *
 * @package WooCommerce_AI_Storefront
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * String constants for every UCP error code emitted by the plugin.
 *
 * Constants are grouped by origin:
 *   - UCP-level codes (ucp_*): top-level protocol rejections.
 *   - Checkout codes: per-line-item and session-level errors/info returned
 *     inside the checkout-sessions response body.
 *   - Catalog codes: errors returned inside catalog search/lookup responses.
 *
 * @since 1.0.0
 */
final class WC_AI_Storefront_UCP_Error_Codes {

	// -----------------------------------------------------------------------
	// UCP-level codes
	// -----------------------------------------------------------------------

	/**
	 * The AI Storefront feature is disabled for this store.
	 */
	const UCP_DISABLED = 'ucp_disabled';

	/**
	 * The incoming request failed validation or is malformed.
	 */
	const INVALID_INPUT = 'invalid_input';

	/**
	 * The caller has exceeded the configured request rate limit.
	 */
	const UCP_RATE_LIMIT_EXCEEDED = 'ucp_rate_limit_exceeded';

	/**
	 * An internal Store API error prevented the catalog from being fetched.
	 */
	const UCP_INTERNAL_ERROR = 'ucp_internal_error';

	// -----------------------------------------------------------------------
	// Checkout session codes
	// -----------------------------------------------------------------------

	/**
	 * A line item has a quantity value that is out of the allowed range.
	 */
	const INVALID_QUANTITY = 'invalid_quantity';

	/**
	 * A requested product is currently out of stock.
	 */
	const OUT_OF_STOCK = 'out_of_stock';

	/**
	 * Duplicate line items targeting the same product were merged.
	 */
	const MERGED_DUPLICATE_ITEMS = 'merged_duplicate_items';

	/**
	 * The checkout requires escalation to the merchant site (happy-path redirect).
	 */
	const BUYER_HANDOFF_REQUIRED = 'buyer_handoff_required';

	/**
	 * A line item shape is invalid (missing item.id, wrong type, etc.).
	 */
	const INVALID_LINE_ITEM = 'invalid_line_item';

	/**
	 * A product ID was not found in the catalog.
	 */
	const NOT_FOUND = 'not_found';

	/**
	 * The product type cannot be added via the Shareable Checkout URL.
	 */
	const PRODUCT_TYPE_UNSUPPORTED = 'product_type_unsupported';

	/**
	 * A variable product was referenced without specifying a variation.
	 */
	const VARIATION_REQUIRED = 'variation_required';

	/**
	 * The order subtotal is below the merchant-configured minimum.
	 */
	const MINIMUM_NOT_MET = 'minimum_not_met';

	/**
	 * The total shown is provisional (tax and shipping are computed at merchant checkout).
	 */
	const TOTAL_IS_PROVISIONAL = 'total_is_provisional';

	/**
	 * The HTTP method used on a checkout-sessions URL is not supported.
	 */
	const UNSUPPORTED_OPERATION = 'unsupported_operation';

	/**
	 * A unit price has changed since the agent last saw the catalog.
	 */
	const PRICE_CHANGED = 'price_changed';

	/**
	 * The store's privacy-policy page URL is not configured.
	 */
	const PRIVACY_POLICY_UNCONFIGURED = 'privacy_policy_unconfigured';

	/**
	 * The store's terms-and-conditions page URL is not configured.
	 */
	const TERMS_UNCONFIGURED = 'terms_unconfigured';

	// -----------------------------------------------------------------------
	// Catalog codes
	// -----------------------------------------------------------------------

	/**
	 * Only a partial set of variants could be returned for a product.
	 */
	const PARTIAL_VARIANTS = 'partial_variants';

	/**
	 * The pagination parameter has an invalid shape.
	 */
	const INVALID_PAGINATION_SHAPE = 'invalid_pagination_shape';

	/**
	 * The requested pagination limit was clamped to the allowed maximum.
	 */
	const PAGINATION_LIMIT_CLAMPED = 'pagination_limit_clamped';

	/**
	 * The pagination cursor value is invalid or unrecognised.
	 */
	const INVALID_CURSOR = 'invalid_cursor';

	/**
	 * The sort parameter has an invalid shape.
	 */
	const INVALID_SORT_SHAPE = 'invalid_sort_shape';

	/**
	 * The requested sort field is not sortable.
	 */
	const INVALID_SORT_FIELD = 'invalid_sort_field';

	/**
	 * The requested category was not found.
	 */
	const CATEGORY_NOT_FOUND = 'category_not_found';

	/**
	 * The requested tag was not found.
	 */
	const TAG_NOT_FOUND = 'tag_not_found';

	/**
	 * The requested brand taxonomy term was not found.
	 */
	const BRAND_NOT_FOUND = 'brand_not_found';

	/**
	 * The requested attribute was not found.
	 */
	const ATTRIBUTE_NOT_FOUND = 'attribute_not_found';

	/**
	 * The filter value list was truncated to the per-request maximum.
	 */
	const FILTER_TRUNCATED = 'filter_truncated';

	/**
	 * Currency conversion is not supported for the requested currency pair.
	 */
	const CURRENCY_CONVERSION_UNSUPPORTED = 'currency_conversion_unsupported';
}
