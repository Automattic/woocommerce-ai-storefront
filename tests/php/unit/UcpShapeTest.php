<?php
/**
 * UCP wire-format shape validation against the canonical schemas.
 *
 * This test class validates representative output of our translators
 * and REST handlers against the UCP `release/2026-04-08` JSON Schemas
 * vendored at `tests/fixtures/ucp-schemas/`. Failures here indicate
 * that we've drifted from the spec we publicly declare in our
 * manifest's `version` field — strict-validating agents would reject
 * the affected response.
 *
 * Vendored schema pin: see `tests/fixtures/ucp-schemas/README.md`.
 *
 * @package WooCommerce_AI_Storefront
 */

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Validator;

class UcpShapeTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Pre-built validator with the vendored UCP schemas registered
	 * at their canonical `https://ucp.dev/...` $id paths so $ref
	 * resolution finds them locally without network.
	 */
	private static ?Validator $validator = null;

	private static function get_validator(): Validator {
		if ( null !== self::$validator ) {
			return self::$validator;
		}

		$validator = new Validator();
		$resolver  = $validator->resolver();
		if ( ! $resolver instanceof SchemaResolver ) {
			throw new \RuntimeException( 'opis/json-schema validator returned unexpected resolver type.' );
		}

		// Register the vendored directory so any $ref pointing at
		// `https://ucp.dev/schemas/shopping/...` resolves locally.
		// `registerPrefix( $prefix, $path )` maps URL prefix → filesystem dir.
		$schemas_dir = realpath( __DIR__ . '/../../fixtures/ucp-schemas' );
		if ( false === $schemas_dir ) {
			throw new \RuntimeException( 'Vendored UCP schemas directory not found.' );
		}
		$resolver->registerPrefix( 'https://ucp.dev/schemas/shopping/', $schemas_dir . DIRECTORY_SEPARATOR );

		self::$validator = $validator;
		return $validator;
	}

	/**
	 * Assert a JSON-decoded payload validates against a vendored schema.
	 *
	 * @param array<string, mixed>|object $payload     The shape to validate (as decoded from JSON).
	 * @param string                      $schema_uri  URI of the schema to validate against
	 *                                                 (e.g. `https://ucp.dev/schemas/shopping/types/variant.json`).
	 * @param string                      $context     Human-readable label for failure messages.
	 */
	private function assertMatchesUcpSchema( $payload, string $schema_uri, string $context = '' ): void {
		$validator = self::get_validator();
		// Helper::toJSON converts assoc arrays to objects so the
		// validator distinguishes properties from arrays correctly.
		$result    = $validator->validate( Helper::toJSON( $payload ), $schema_uri );

		if ( $result->isValid() ) {
			$this->assertTrue( true );
			return;
		}

		$formatter = new ErrorFormatter();
		$errors    = $formatter->format( $result->error(), true );
		$this->fail(
			"UCP schema violation against {$schema_uri}"
			. ( '' !== $context ? " [{$context}]" : '' )
			. ":\n" . wp_json_encode( $errors, JSON_PRETTY_PRINT )
		);
	}

	// ------------------------------------------------------------------
	// Variant shape — both translate() and synthesize_default()
	// ------------------------------------------------------------------

	public function test_variant_translate_output_validates(): void {
		// Realistic Store API variation shape: empty `attributes[]`
		// plus a populated `variation` string (the WC 9.x default for
		// every variable-product variation).
		$wc_variation = [
			'id'                => 456,
			'name'              => 'Leather Shoes',
			'sku'               => 'SHOE-TAN-9',
			'is_in_stock'       => true,
			'prices'            => [
				'price'         => '15000',
				'regular_price' => '20000',
				'currency_code' => 'USD',
			],
			'on_sale'           => true,
			'attributes'        => [],
			'variation'         => 'Color: Tan, Size: 9',
			'short_description' => '<p>Tan leather shoe, size 9.</p>',
			'images'            => [
				[ 'src' => 'https://example.com/shoe.jpg', 'alt' => 'Tan shoe' ],
			],
		];

		$variant = WC_AI_Storefront_UCP_Variant_Translator::translate(
			$wc_variation,
			[ 'Color', 'Size' ],
			[ 'name' => 'Example Store' ]
		);

		$this->assertMatchesUcpSchema(
			$variant,
			'https://ucp.dev/schemas/shopping/types/variant.json',
			'translate() output'
		);
	}

	public function test_variant_synthesize_default_output_validates(): void {
		$wc_product = [
			'id'                => 22,
			'name'              => 'Sunglasses',
			'sku'               => 'SUN-001',
			'is_in_stock'       => true,
			'prices'            => [
				'price'         => '9000',
				'currency_code' => 'USD',
			],
			'short_description' => 'Stylish sunglasses.',
		];

		$variant = WC_AI_Storefront_UCP_Variant_Translator::synthesize_default(
			$wc_product,
			[ 'name' => 'Example Store' ]
		);

		$this->assertMatchesUcpSchema(
			$variant,
			'https://ucp.dev/schemas/shopping/types/variant.json',
			'synthesize_default() output'
		);
	}

	// ------------------------------------------------------------------
	// Product shape — both simple and variable
	// ------------------------------------------------------------------

	public function test_product_translate_simple_validates(): void {
		$wc_product = [
			'id'                => 22,
			'name'              => 'Sunglasses',
			'slug'              => 'sunglasses',
			'permalink'         => 'https://example.com/product/sunglasses/',
			'short_description' => '<p>Stylish.</p>',
			'is_in_stock'       => true,
			'prices'            => [
				'price'               => '9000',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
			],
			'categories'        => [
				[ 'id' => 19, 'name' => 'Accessories', 'slug' => 'accessories', 'taxonomy' => 'merchant' ],
			],
			'images'            => [
				[ 'src' => 'https://example.com/sun.jpg', 'alt' => 'Sun' ],
			],
		];

		$product = WC_AI_Storefront_UCP_Product_Translator::translate(
			$wc_product,
			[],
			[ 'name' => 'Example Store' ]
		);

		$this->assertMatchesUcpSchema(
			$product,
			'https://ucp.dev/schemas/shopping/types/product.json',
			'simple-product translate() output'
		);
	}

	public function test_product_translate_variable_validates(): void {
		$wc_product = [
			'id'         => 35,
			'name'       => 'T-Shirt with Logo',
			'type'       => 'variable',
			'prices'     => [
				'price'               => '1500',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
				'price_range'         => [
					'min_amount' => '1500',
					'max_amount' => '2500',
				],
			],
			'attributes' => [
				[
					'name'           => 'Color',
					'taxonomy'       => 'pa_color',
					'has_variations' => true,
					'terms'          => [
						[ 'name' => 'Black', 'slug' => 'black' ],
						[ 'name' => 'Green', 'slug' => 'green' ],
					],
				],
			],
			'variations' => [
				[ 'id' => 105 ],
				[ 'id' => 106 ],
			],
		];

		$wc_variations = [
			[
				'id'                => 105,
				'name'              => 'T-Shirt with Logo',
				'sku'               => 'TEE-BLACK',
				'is_in_stock'       => true,
				'short_description' => '',
				'prices'            => [
					'price'         => '1500',
					'currency_code' => 'USD',
				],
				'attributes'        => [],
				'variation'         => 'Color: Black',
			],
			[
				'id'                => 106,
				'name'              => 'T-Shirt with Logo',
				'sku'               => 'TEE-GREEN',
				'is_in_stock'       => true,
				'short_description' => '',
				'prices'            => [
					'price'         => '2500',
					'currency_code' => 'USD',
				],
				'attributes'        => [],
				'variation'         => 'Color: Green',
			],
		];

		$product = WC_AI_Storefront_UCP_Product_Translator::translate(
			$wc_product,
			$wc_variations,
			[ 'name' => 'Example Store' ]
		);

		$this->assertMatchesUcpSchema(
			$product,
			'https://ucp.dev/schemas/shopping/types/product.json',
			'variable-product translate() output'
		);
	}

	// ------------------------------------------------------------------
	// Sub-type shapes — option_value, selected_option, rating
	// ------------------------------------------------------------------

	public function test_option_value_shape_validates(): void {
		$this->assertMatchesUcpSchema(
			[ 'label' => 'Black' ],
			'https://ucp.dev/schemas/shopping/types/option_value.json',
			'bare option_value'
		);
	}

	public function test_selected_option_shape_validates(): void {
		$this->assertMatchesUcpSchema(
			[ 'name' => 'Color', 'label' => 'Black' ],
			'https://ucp.dev/schemas/shopping/types/selected_option.json',
			'bare selected_option'
		);
	}

	public function test_rating_shape_validates(): void {
		$this->assertMatchesUcpSchema(
			[
				'value'     => 4.67,
				'scale_min' => 1,
				'scale_max' => 5,
				'count'     => 42,
			],
			'https://ucp.dev/schemas/shopping/types/rating.json',
			'bare rating'
		);
	}

	// ------------------------------------------------------------------
	// Message shapes — error, warning, info
	// ------------------------------------------------------------------

	public function test_error_message_shape_validates(): void {
		$this->assertMatchesUcpSchema(
			[
				'type'     => 'error',
				'code'     => 'not_found',
				'content'  => 'Input did not resolve.',
				'severity' => 'unrecoverable',
				'path'     => '$.inputs[0]',
			],
			'https://ucp.dev/schemas/shopping/types/message_error.json',
			'error message'
		);
	}

	public function test_warning_message_shape_validates(): void {
		$this->assertMatchesUcpSchema(
			[
				'type'    => 'warning',
				'code'    => 'partial_variants',
				'content' => 'Variants list is incomplete.',
			],
			'https://ucp.dev/schemas/shopping/types/message_warning.json',
			'warning message'
		);
	}

	public function test_info_message_shape_validates(): void {
		$this->assertMatchesUcpSchema(
			[
				'type'    => 'info',
				'code'    => 'total_is_provisional',
				'content' => 'Total may change at checkout.',
			],
			'https://ucp.dev/schemas/shopping/types/message_info.json',
			'info message'
		);
	}

	// ------------------------------------------------------------------
	// Lookup envelope — lookup_variant requires per-variant inputs[]
	// ------------------------------------------------------------------

	public function test_lookup_variant_with_inputs_validates(): void {
		// Build a minimal variant via translate() then attach inputs[]
		// the same way the lookup handler does (post-translation).
		$variant = WC_AI_Storefront_UCP_Variant_Translator::translate(
			[
				'id'                => 456,
				'name'              => 'Sunglasses',
				'is_in_stock'       => true,
				'prices'            => [ 'price' => '9000', 'currency_code' => 'USD' ],
				'short_description' => '',
			],
			null,
			[ 'name' => 'Example Store' ]
		);
		$variant['inputs'] = [
			[ 'id' => 'prod_22', 'match' => 'featured' ],
		];

		$this->assertMatchesUcpSchema(
			$variant,
			'https://ucp.dev/schemas/shopping/catalog_lookup.json#/$defs/lookup_variant',
			'lookup_variant'
		);
	}

	public function test_lookup_variant_missing_inputs_fails(): void {
		// Sanity check the validator actually rejects: a variant without
		// inputs[] on the lookup_variant ref must fail (pre-0.12.0 shape).
		$variant = WC_AI_Storefront_UCP_Variant_Translator::translate(
			[
				'id'                => 456,
				'name'              => 'Sunglasses',
				'is_in_stock'       => true,
				'prices'            => [ 'price' => '9000', 'currency_code' => 'USD' ],
				'short_description' => '',
			]
		);

		$validator = self::get_validator();
		$result    = $validator->validate(
			Helper::toJSON( $variant ),
			'https://ucp.dev/schemas/shopping/catalog_lookup.json#/$defs/lookup_variant'
		);

		$this->assertFalse(
			$result->isValid(),
			'lookup_variant without inputs[] should fail validation — confirms the validator is wired correctly.'
		);
	}
}
