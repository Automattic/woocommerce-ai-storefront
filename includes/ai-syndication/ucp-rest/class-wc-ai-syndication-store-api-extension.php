<?php
/**
 * AI Syndication: Store API Extension
 *
 * Registers a `barcodes` field on the WooCommerce Store API product
 * response, surfacing WC core's native `global_unique_id` (introduced
 * in WC 9.4) as a structured `[{type, value}]` list that our UCP
 * variant translator can forward to the UCP `variants[].barcodes`
 * field.
 *
 * ## Why this extension exists
 *
 * WC 9.4+ stores a product's GTIN / UPC / EAN / MPN / ISBN
 * identifier on every product via `get_global_unique_id()` — but the
 * Store API `/products` schema does NOT expose this field yet (see
 * the enhancement request we filed against woocommerce/woocommerce).
 * Until core picks it up, this extension bridges the gap via the
 * officially-supported `woocommerce_store_api_register_endpoint_data`
 * hook: we read the product meta server-side and emit it under
 * `extensions.{namespace}.barcodes` on every product/variation
 * response, exactly where UCP consumers expect extension-provided
 * data to land.
 *
 * ## Why `barcodes` plural
 *
 * WC's `global_unique_id` is a single string, not typed — the
 * merchant enters whatever identifier fits their products (GTIN-13
 * for retail, ISBN for books, MPN for manufacturer-specific, etc.).
 * UCP's `variants[].barcodes` is an array of typed objects
 * (`{type, value}`) because a single product can have multiple
 * parallel identifiers (GTIN + MPN, for example). Emitting an
 * array with one detected-type entry today leaves room to add
 * more sources later (third-party barcode plugins) without
 * reshaping the API contract.
 *
 * ## Type detection
 *
 * We detect the barcode type from the value's length and character
 * set using the conventional GTIN heuristic — 8/12/13/14 digit
 * numeric strings map to GTIN-8/12/13/14 respectively. Anything
 * that doesn't match a standard length is emitted as type `other`
 * so agents can still match by raw value without the type claim.
 * ISBN/MPN would require explicit type hints from another source
 * since they can be any length.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers extra product data on the WC Store API response.
 */
class WC_AI_Syndication_Store_Api_Extension {

	/**
	 * Namespace under which the extension data appears in the Store
	 * API response. Accessed by consumers as
	 * `response.extensions[self::NAMESPACE]`. Matches the UCP
	 * extension capability identifier we publish in the manifest so
	 * the "namespace" concept is consistent across surfaces.
	 */
	const NAMESPACE = 'com-woocommerce-ai-syndication';

	/**
	 * Register the barcodes field on the Store API product endpoint.
	 *
	 * MUST be called on the `woocommerce_blocks_loaded` action (WC's
	 * documented trigger for extension registration). Running earlier
	 * risks the Store API infrastructure not being ready yet;
	 * running later risks missing the initial request of the
	 * lifecycle.
	 *
	 * Early-returns when the helper function doesn't exist. The
	 * plugin's declared minimum WC (9.9) ships this helper, so the
	 * guard is defensive belt-and-suspenders rather than a real
	 * compatibility path — but if an install somehow loses the
	 * helper (partial WC upgrade, WC disabled mid-request, etc.),
	 * we no-op cleanly. UCP variant translator's `extract_barcodes`
	 * tolerates an absent extensions payload, so the degradation
	 * is silent.
	 */
	public function init(): void {
		add_action( 'woocommerce_blocks_loaded', [ $this, 'register_endpoint_data' ] );
	}

	/**
	 * Invoke the Store API extension registration.
	 *
	 * Separated from `init()` for testability.
	 */
	public function register_endpoint_data(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		// Register on the ProductSchema identifier. The string literal
		// `'product'` is what `\Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema::IDENTIFIER`
		// evaluates to — we use the literal rather than the constant
		// so this code doesn't trigger a fatal on PHP autoload if WC
		// Blocks aren't initialized yet at this point in the lifecycle.
		woocommerce_store_api_register_endpoint_data(
			[
				'endpoint'        => 'product',
				'namespace'       => self::NAMESPACE,
				'data_callback'   => [ $this, 'get_product_data' ],
				'schema_callback' => [ $this, 'get_schema' ],
				'schema_type'     => ARRAY_A,
			]
		);
	}

	/**
	 * Build the extension data payload for a given product or
	 * variation. Called by the Store API per-request for each product
	 * in the response.
	 *
	 * Signature: Store API invokes the data_callback with the
	 * WC_Product instance (or the variation object for variation
	 * endpoints). We return the shape declared by `get_schema()`.
	 *
	 * @param \WC_Product|null $product The product/variation object.
	 * @return array{
	 *     barcodes: list<array{type: string, value: string}>,
	 *     date_created: string|null,
	 *     date_modified: string|null,
	 * }
	 */
	public function get_product_data( $product ): array {
		if ( ! $product instanceof \WC_Product ) {
			return [
				'barcodes'      => [],
				'date_created'  => null,
				'date_modified' => null,
			];
		}

		$barcodes = [];

		// Native WC 9.4+ field. Older WC versions don't implement
		// the method — guard with method_exists so the extension
		// quietly returns empty rather than fatal-erroring.
		//
		// Trim whitespace: merchants occasionally paste GTINs with
		// trailing spaces. A whitespace-only value would pass a naive
		// `'' !== $gtin` check but emit a meaningless `value` with a
		// misleading `type` from detect_gtin_type. Checking emptiness
		// on the trimmed form (and using it for both storage and type
		// detection) keeps the output honest.
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$gtin = trim( (string) $product->get_global_unique_id() );
			if ( '' !== $gtin ) {
				$barcodes[] = [
					'type'  => self::detect_gtin_type( $gtin ),
					'value' => $gtin,
				];
			}
		}

		// ISO 8601 / RFC 3339 timestamps — UCP core product shape
		// expects `published_at` / `updated_at` but WC Store API
		// strips date fields from product responses entirely (verified
		// against WC 9.5+ on live store). Rather than patching every
		// Store API consumer, we expose the dates via this extension
		// so our own UCP translator can read them — the only consumer
		// that currently needs them. Keeps the fix contained to one
		// namespace.
		//
		// `get_date_created()` / `get_date_modified()` return
		// `WC_DateTime|null`. When present, `getTimestamp()` + ISO
		// format gives us RFC 3339 in UTC (`Z` suffix) — the format
		// UCP's core `published_at` / `updated_at` expect. `null` is
		// defensive: brand-new products in a migration window may
		// briefly lack these meta rows.
		//
		// `instanceof \DateTimeInterface` — tightened from the earlier
		// duck-typed `method_exists(getTimestamp)` check. WC_DateTime
		// extends PHP's DateTime which implements DateTimeInterface,
		// so real WC values always pass. Test doubles extend
		// DateTimeImmutable (also implements DateTimeInterface) to
		// satisfy the check without loading WC_DateTime.
		//
		// `$ts > 0` guard — `getTimestamp()` can return 0 (uninitialized
		// WC_DateTime) or negative (pre-epoch). Either would render as
		// a valid-looking RFC 3339 string that agents would treat as
		// authoritative, poisoning diff-sync cursors ("this product was
		// created in 1970 → always older than my last sync → never
		// refresh"). Guard with `> 0` so the bad value omits cleanly
		// as null rather than misleading downstream consumers.
		$date_created  = null;
		$date_modified = null;
		if ( method_exists( $product, 'get_date_created' ) ) {
			$dt = $product->get_date_created();
			if ( $dt instanceof \DateTimeInterface ) {
				$ts = $dt->getTimestamp();
				if ( $ts > 0 ) {
					$date_created = gmdate( 'Y-m-d\TH:i:s\Z', $ts );
				}
			}
		}
		if ( method_exists( $product, 'get_date_modified' ) ) {
			$dt = $product->get_date_modified();
			if ( $dt instanceof \DateTimeInterface ) {
				$ts = $dt->getTimestamp();
				if ( $ts > 0 ) {
					$date_modified = gmdate( 'Y-m-d\TH:i:s\Z', $ts );
				}
			}
		}

		return [
			'barcodes'      => $barcodes,
			'date_created'  => $date_created,
			'date_modified' => $date_modified,
		];
	}

	/**
	 * JSON Schema describing the extension payload. Store API uses
	 * this to validate and document the shape in its OpenAPI-adjacent
	 * self-description endpoint.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return [
			'barcodes'      => [
				'description' => __(
					'Product identifiers (GTIN, UPC, EAN, MPN, ISBN). Each entry is a typed barcode.',
					'woocommerce-ai-syndication'
				),
				'type'        => 'array',
				'context'     => [ 'view' ],
				'readonly'    => true,
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'type'  => [
							'type'        => 'string',
							'description' => __(
								'Barcode type (gtin8, gtin12, gtin13, gtin14, other).',
								'woocommerce-ai-syndication'
							),
						],
						'value' => [
							'type'        => 'string',
							'description' => __(
								'The barcode value as stored by the merchant.',
								'woocommerce-ai-syndication'
							),
						],
					],
				],
			],
			'date_created'  => [
				'description' => __(
					'RFC 3339 / ISO 8601 timestamp (UTC, `Z`-suffixed) when the product was created. Null when not available. Exposed here because Store API strips product date fields from responses by default; our UCP translator consumes this to populate `product.published_at` per the UCP core shape.',
					'woocommerce-ai-syndication'
				),
				'type'        => [ 'string', 'null' ],
				'context'     => [ 'view' ],
				'readonly'    => true,
			],
			'date_modified' => [
				'description' => __(
					'RFC 3339 / ISO 8601 timestamp (UTC, `Z`-suffixed) of the product\'s last modification. Null when not available. Consumed by the UCP translator for `product.updated_at`.',
					'woocommerce-ai-syndication'
				),
				'type'        => [ 'string', 'null' ],
				'context'     => [ 'view' ],
				'readonly'    => true,
			],
		];
	}

	/**
	 * Infer the GTIN sub-type from the value's length.
	 *
	 * Conventional GTIN lengths (GS1 standard):
	 *   - GTIN-8  — 8 digits, commonly EAN-8
	 *   - GTIN-12 — 12 digits, commonly UPC-A (North America retail)
	 *   - GTIN-13 — 13 digits, commonly EAN-13 (global retail)
	 *   - GTIN-14 — 14 digits, commonly ITF-14 (wholesale/case-level)
	 *
	 * Anything outside these lengths or non-numeric (MPN, ISBN-10,
	 * custom SKU-ish identifiers) is flagged `other`. Agents can
	 * match against the raw value even without a precise type claim.
	 *
	 * @param string $value Raw identifier string from product meta.
	 * @return string       One of: gtin8, gtin12, gtin13, gtin14, other.
	 */
	private static function detect_gtin_type( string $value ): string {
		if ( ! ctype_digit( $value ) ) {
			return 'other';
		}
		switch ( strlen( $value ) ) {
			case 8:
				return 'gtin8';
			case 12:
				return 'gtin12';
			case 13:
				return 'gtin13';
			case 14:
				return 'gtin14';
			default:
				return 'other';
		}
	}
}
