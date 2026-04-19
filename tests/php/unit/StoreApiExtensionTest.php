<?php
/**
 * Tests for WC_AI_Syndication_Store_Api_Extension.
 *
 * The extension surfaces WC core's `global_unique_id` product field
 * (GTIN/UPC/EAN/MPN/ISBN) on the Store API `/products` response
 * under `extensions.com-woocommerce-ai-syndication.barcodes`.
 *
 * WC core's Store API schema doesn't include `global_unique_id` yet
 * (enhancement request filed against woocommerce/woocommerce). Until
 * it does, this extension is the bridge: it reads the native
 * WC_Product field server-side and emits a typed `{type, value}`
 * pair that our UCP variant translator consumes.
 *
 * @package WooCommerce_AI_Syndication
 */

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class StoreApiExtensionTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Syndication_Store_Api_Extension $extension;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// i18n functions are stubbed as passthroughs — the extension uses
		// __() for schema descriptions, but those strings aren't under test.
		Monkey\Functions\when( '__' )->returnArg();
		$this->extension = new WC_AI_Syndication_Store_Api_Extension();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Namespace constant
	// ------------------------------------------------------------------

	public function test_namespace_matches_extension_capability(): void {
		// The namespace is shared with the UCP extension capability
		// we publish in the manifest — keeps the "namespace" concept
		// consistent across surfaces (Store API extension + UCP
		// product.extensions + llms.txt attribution table).
		//
		// This is a hyphenated variant of the UCP
		// `com.woocommerce.ai_syndication` namespace because Store
		// API namespace values are used as URL path segments in
		// some response shapes and shouldn't contain dots.
		$this->assertSame(
			'com-woocommerce-ai-syndication',
			WC_AI_Syndication_Store_Api_Extension::NAMESPACE
		);
	}

	// ------------------------------------------------------------------
	// get_product_data: the per-product callback
	// ------------------------------------------------------------------

	public function test_returns_empty_barcodes_for_non_product_input(): void {
		// Defensive: Store API invokes the data_callback with the
		// product object, but if something else ever calls it with
		// null or a plain array we should return the empty shape,
		// not fatal.
		$result = $this->extension->get_product_data( null );

		$this->assertSame( [ 'barcodes' => [] ], $result );
	}

	public function test_returns_empty_barcodes_when_product_lacks_global_unique_id_method(): void {
		// Older WC versions (< 9.4) don't implement get_global_unique_id().
		// We guard via method_exists so older installs produce empty
		// barcodes rather than fatal.
		$old_product = new class() extends \WC_Product {};

		$result = $this->extension->get_product_data( $old_product );

		$this->assertSame( [ 'barcodes' => [] ], $result );
	}

	public function test_emits_gtin13_for_13_digit_value(): void {
		$product = $this->make_product_with_gtin( '1234567890123' );

		$result = $this->extension->get_product_data( $product );

		$this->assertCount( 1, $result['barcodes'] );
		$this->assertSame( 'gtin13', $result['barcodes'][0]['type'] );
		$this->assertSame( '1234567890123', $result['barcodes'][0]['value'] );
	}

	public function test_emits_gtin8_for_8_digit_value(): void {
		$product = $this->make_product_with_gtin( '12345678' );

		$result = $this->extension->get_product_data( $product );

		$this->assertSame( 'gtin8', $result['barcodes'][0]['type'] );
	}

	public function test_emits_gtin12_for_12_digit_value(): void {
		$product = $this->make_product_with_gtin( '123456789012' );

		$result = $this->extension->get_product_data( $product );

		$this->assertSame( 'gtin12', $result['barcodes'][0]['type'] );
	}

	public function test_emits_gtin14_for_14_digit_value(): void {
		$product = $this->make_product_with_gtin( '12345678901234' );

		$result = $this->extension->get_product_data( $product );

		$this->assertSame( 'gtin14', $result['barcodes'][0]['type'] );
	}

	public function test_emits_other_for_non_numeric_value(): void {
		// MPN or custom identifier — not a digit-only GTIN sub-type.
		// Agents can still match on the raw value; we just don't
		// make a false type claim.
		$product = $this->make_product_with_gtin( 'MPN-ABC-123' );

		$result = $this->extension->get_product_data( $product );

		$this->assertSame( 'other', $result['barcodes'][0]['type'] );
		$this->assertSame( 'MPN-ABC-123', $result['barcodes'][0]['value'] );
	}

	public function test_emits_other_for_non_standard_digit_length(): void {
		// An 11-digit numeric string isn't a standard GTIN sub-type.
		// Type `other` is honest; emitting gtin12 would misrepresent
		// the identifier.
		$product = $this->make_product_with_gtin( '12345678901' );

		$result = $this->extension->get_product_data( $product );

		$this->assertSame( 'other', $result['barcodes'][0]['type'] );
	}

	public function test_emits_empty_barcodes_when_value_is_empty_string(): void {
		$product = $this->make_product_with_gtin( '' );

		$result = $this->extension->get_product_data( $product );

		$this->assertSame( [], $result['barcodes'] );
	}

	// ------------------------------------------------------------------
	// Schema callback
	// ------------------------------------------------------------------

	public function test_schema_callback_returns_valid_barcodes_schema(): void {
		$schema = $this->extension->get_schema();

		$this->assertArrayHasKey( 'barcodes', $schema );
		$this->assertSame( 'array', $schema['barcodes']['type'] );
		$this->assertTrue( $schema['barcodes']['readonly'] );
		$this->assertArrayHasKey( 'items', $schema['barcodes'] );
		$this->assertSame( 'object', $schema['barcodes']['items']['type'] );

		// The items shape must include type + value — that's what
		// the variant translator reads.
		$item_props = $schema['barcodes']['items']['properties'];
		$this->assertArrayHasKey( 'type', $item_props );
		$this->assertArrayHasKey( 'value', $item_props );
	}

	// ------------------------------------------------------------------
	// init: hook registration
	// ------------------------------------------------------------------

	public function test_init_registers_on_woocommerce_blocks_loaded(): void {
		// Invariant: the extension registration MUST be gated on
		// `woocommerce_blocks_loaded` per WC's documented extension
		// lifecycle. Running earlier risks the Store API plumbing
		// not being ready; running later risks missing the first
		// request.
		$hooks = [];
		\Brain\Monkey\Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback ) use ( &$hooks ) {
				$hooks[] = $hook;
			}
		);

		$this->extension->init();

		$this->assertContains( 'woocommerce_blocks_loaded', $hooks );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Build an anonymous WC_Product subclass whose `get_global_unique_id`
	 * returns the configured value.
	 */
	private function make_product_with_gtin( string $gtin ): \WC_Product {
		return new class( $gtin ) extends \WC_Product {
			private string $gtin;

			public function __construct( string $gtin ) {
				$this->gtin = $gtin;
			}

			public function get_global_unique_id() {
				return $this->gtin;
			}
		};
	}
}
