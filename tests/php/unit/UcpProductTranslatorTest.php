<?php
/**
 * Tests for WC_AI_Syndication_UCP_Product_Translator.
 *
 * v1 scope (task 5): simple product translation. The translator
 * ALWAYS emits one synthesized default variant via
 * UcpVariantTranslator::synthesize_default, regardless of whether
 * the WC product is simple or variable. Task 7 will replace this
 * for variable products with real variation expansion.
 *
 * Tests here only cover the simple-product path. Variable-product
 * variant expansion tests come with task 7.
 *
 * @package WooCommerce_AI_Syndication
 */

class UcpProductTranslatorTest extends \PHPUnit\Framework\TestCase {

	// ------------------------------------------------------------------
	// Fixtures
	// ------------------------------------------------------------------

	/**
	 * A minimally-populated WC Store API response for a simple product.
	 *
	 * @return array<string, mixed>
	 */
	private function simple_product_fixture(): array {
		return [
			'id'                => 123,
			'name'              => 'Widget',
			'slug'              => 'widget',
			'permalink'         => 'https://example.com/product/widget/',
			'short_description' => '<p>A simple <em>widget</em>.</p>',
			'is_in_stock'       => true,
			'prices'            => [
				'price'               => '2500',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
			],
			'categories'        => [
				[ 'id' => 5, 'name' => 'Widgets', 'slug' => 'widgets' ],
				[ 'id' => 12, 'name' => 'Gadgets', 'slug' => 'gadgets' ],
			],
			'images'            => [
				[ 'src' => 'https://example.com/widget.jpg', 'alt' => 'A widget' ],
			],
		];
	}

	/**
	 * A WC Store API response for a variable product with price range.
	 *
	 * v1 still synthesizes a single default variant for this — task 7
	 * replaces that behavior. Tests here only assert price_range is
	 * derived from price_range.min/max_amount when present.
	 */
	private function variable_product_fixture(): array {
		return [
			'id'                => 789,
			'name'              => 'T-Shirt',
			'prices'            => [
				'price'               => '1000',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
				'price_range'         => [
					'min_amount' => '1000',
					'max_amount' => '1500',
				],
			],
		];
	}

	// ------------------------------------------------------------------
	// Required UCP fields
	// ------------------------------------------------------------------

	public function test_id_prefixed_with_prod(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'prod_123', $result['id'] );
	}

	public function test_title_from_wc_name(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'Widget', $result['title'] );
	}

	public function test_description_strips_html_tags(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals(
			'A simple widget.',
			$result['description']['plain']
		);
	}

	public function test_description_decodes_html_entities(): void {
		$fixture                      = $this->simple_product_fixture();
		$fixture['short_description'] = 'Joe&#039;s widgets &amp; gadgets';

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertEquals(
			"Joe's widgets & gadgets",
			$result['description']['plain']
		);
	}

	public function test_emits_at_least_one_variant_per_schema_minitems(): void {
		// UCP schema: variants minItems: 1. Every product must have
		// at least one variant — even simple products. This test
		// locks in the synthesized-default behavior.
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertNotEmpty( $result['variants'] );
		$this->assertGreaterThanOrEqual( 1, count( $result['variants'] ) );
	}

	public function test_synthesized_variant_has_default_suffix(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'var_123_default', $result['variants'][0]['id'] );
	}

	// ------------------------------------------------------------------
	// Price range
	// ------------------------------------------------------------------

	public function test_simple_product_price_range_min_equals_max(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertSame( 2500, $result['price_range']['min']['amount'] );
		$this->assertSame( 2500, $result['price_range']['max']['amount'] );
		$this->assertEquals( 'USD', $result['price_range']['min']['currency'] );
	}

	public function test_price_amounts_are_integers_not_strings(): void {
		// WC returns prices as STRINGS in minor units. UCP wants INTEGERS.
		// Test the explicit cast — if we ever accidentally forwarded the
		// string, JSON consumers would get a typing error.
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertIsInt( $result['price_range']['min']['amount'] );
		$this->assertIsInt( $result['price_range']['max']['amount'] );
	}

	public function test_variable_product_price_range_spans_min_to_max(): void {
		// When WC supplies `prices.price_range` (variable product),
		// the UCP price_range uses min_amount and max_amount as
		// separate values. A $10-15 variable product has
		// price_range.min = 1000, price_range.max = 1500.
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->variable_product_fixture()
		);

		$this->assertSame( 1000, $result['price_range']['min']['amount'] );
		$this->assertSame( 1500, $result['price_range']['max']['amount'] );
	}

	public function test_currency_passes_through_unchanged(): void {
		// Currency code is opaque to us — whatever WC says, we echo.
		$fixture                         = $this->simple_product_fixture();
		$fixture['prices']['currency_code'] = 'GBP';

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertEquals( 'GBP', $result['price_range']['min']['currency'] );
	}

	// ------------------------------------------------------------------
	// Optional fields — emit only when present
	// ------------------------------------------------------------------

	public function test_handle_from_slug_when_present(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'widget', $result['handle'] );
	}

	public function test_handle_omitted_when_slug_absent(): void {
		$fixture = $this->simple_product_fixture();
		unset( $fixture['slug'] );

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'handle', $result );
	}

	public function test_url_from_permalink(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals(
			'https://example.com/product/widget/',
			$result['url']
		);
	}

	// ------------------------------------------------------------------
	// Categories
	// ------------------------------------------------------------------

	public function test_categories_tagged_with_merchant_taxonomy(): void {
		// WC categories are business-defined; UCP expects taxonomy
		// tagging so agents can distinguish "this is the merchant's
		// own categorization" from standardized taxonomies like
		// google_product_category.
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertCount( 2, $result['categories'] );
		$this->assertEquals(
			[ 'value' => 'Widgets', 'taxonomy' => 'merchant' ],
			$result['categories'][0]
		);
		$this->assertEquals(
			[ 'value' => 'Gadgets', 'taxonomy' => 'merchant' ],
			$result['categories'][1]
		);
	}

	public function test_categories_omitted_when_product_uncategorized(): void {
		$fixture = $this->simple_product_fixture();
		unset( $fixture['categories'] );

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'categories', $result );
	}

	public function test_categories_skip_entries_without_name(): void {
		$fixture               = $this->simple_product_fixture();
		$fixture['categories'] = [
			[ 'id' => 5, 'name' => 'Widgets' ],
			[ 'id' => 6 ],  // malformed — no name
			[ 'id' => 7, 'name' => 'Other' ],
		];

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertCount( 2, $result['categories'] );
	}

	// ------------------------------------------------------------------
	// Media
	// ------------------------------------------------------------------

	public function test_media_from_images_array(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals(
			[
				'type'     => 'image',
				'url'      => 'https://example.com/widget.jpg',
				'alt_text' => 'A widget',
			],
			$result['media'][0]
		);
	}

	public function test_media_omits_alt_text_when_absent(): void {
		$fixture           = $this->simple_product_fixture();
		$fixture['images'] = [ [ 'src' => 'https://example.com/img.jpg' ] ];

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'alt_text', $result['media'][0] );
	}

	public function test_media_omitted_when_product_has_no_images(): void {
		$fixture = $this->simple_product_fixture();
		unset( $fixture['images'] );

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'media', $result );
	}

	public function test_media_skips_image_entries_without_src(): void {
		$fixture           = $this->simple_product_fixture();
		$fixture['images'] = [
			[ 'src' => 'https://example.com/a.jpg' ],
			[],  // malformed
			[ 'src' => 'https://example.com/b.jpg' ],
		];

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertCount( 2, $result['media'] );
	}
}
