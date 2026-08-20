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
 * @since   0.6.7
 */

defined( 'ABSPATH' ) || exit;

/**
 * String constants for UCP error codes emitted by the plugin.
 *
 * Constants are grouped by origin:
 *   - UCP-level codes (ucp_*): top-level protocol rejections.
 *   - Checkout codes: per-line-item and session-level errors/info returned
 *     inside the checkout-sessions response body.
 *   - Catalog codes: errors returned inside catalog search/lookup responses.
 *
 * @since 0.6.7
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
	 * Batch request exceeds the implementation's per-request size limit
	 * (e.g. catalog/lookup `ids` over MAX_IDS_PER_LOOKUP). UCP REST
	 * conformance maps this to HTTP 400.
	 *
	 * @see https://ucp.dev/latest/specification/catalog/ (UCP catalog REST conformance).
	 */
	const REQUEST_TOO_LARGE = 'request_too_large';

	/**
	 * The caller has exceeded the configured request rate limit.
	 */
	const UCP_RATE_LIMIT_EXCEEDED = 'ucp_rate_limit_exceeded';

	/**
	 * An internal Store API error prevented the catalog from being fetched.
	 */
	const UCP_INTERNAL_ERROR = 'ucp_internal_error';

	/**
	 * An unknown agent was blocked because this store has not enabled access for unknown agents.
	 */
	const AGENT_UNKNOWN_BLOCKED = 'ucp_unknown_agent_blocked';

	/**
	 * A known agent brand was blocked because this store has not added it to the allow-list.
	 */
	const AGENT_BLOCKED = 'ucp_agent_blocked';

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
	 * A requested product is not purchasable (e.g. missing price,
	 * misconfigured variation, hidden from catalog). Distinct from
	 * `OUT_OF_STOCK`: the product may have inventory but WC refuses
	 * to add it to a cart. (#373)
	 *
	 * Emitted on two surfaces: per line item at checkout, and per
	 * requested ID at `catalog/lookup` when the product has no price
	 * configured (#658). Same condition, same code, so an agent that
	 * hits it while browsing reads the same answer it would at
	 * checkout.
	 */
	const ITEM_UNPURCHASABLE = 'item_unpurchasable';

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

	/**
	 * Spec-defined error code (UCP `error_code.json` examples / checkout
	 * error-handling standard codes). Marks "a required input is
	 * missing" without prescribing how the requestor recovers — the
	 * recovery path is communicated via the message's `severity` field
	 * (per `message_error.json`):
	 *
	 *   - `recoverable` — platform can resolve by modifying inputs and
	 *     retrying via API. Used for the bundle mixed-cart case where
	 *     the agent splits the request into separate /checkout-sessions
	 *     calls.
	 *   - `requires_buyer_input` — merchant requires information their
	 *     API doesn't support collecting programmatically. Used for the
	 *     configurable-single-bundle case where the buyer must pick
	 *     variations / optional toggles on the merchant PDP.
	 *
	 * The same code can pair with either severity depending on the
	 * specific failure mode.
	 *
	 * @see https://ucp.dev/latest/specification/checkout/#error-handling
	 */
	const FIELD_REQUIRED = 'field_required';

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

	/**
	 * The request carried parameter names this implementation doesn't
	 * recognize; they were ignored.
	 *
	 * Plugin-specific, not a UCP-defined code. The REST transport reports
	 * the same condition out-of-band in the
	 * `X-WC-AI-Storefront-Unknown-Params` response header; MCP has no
	 * response headers, so it carries the advisory in `messages[]`
	 * instead. Same detection, same key list, two transports.
	 */
	const UNKNOWN_PARAMS = 'unknown_params';
}
