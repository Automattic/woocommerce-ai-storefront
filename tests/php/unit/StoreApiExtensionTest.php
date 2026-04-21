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

	public function test_returns_empty_shape_for_non_product_input(): void {
		// Defensive: Store API invokes the data_callback with the
		// product object, but if something else ever calls it with
		// null or a plain array we should return the empty shape,
		// not fatal. Every declared key is present with a sane
		// default so strict consumers see the full shape even in
		// the non-product edge case.
		$result = $this->extension->get_product_data( null );

		$this->assertSame(
			[
				'barcodes'      => [],
				'date_created'  => null,
				'date_modified' => null,
			],
			$result
		);
	}

	public function test_returns_empty_barcodes_when_product_lacks_global_unique_id_method(): void {
		// Older WC versions (< 9.4) don't implement get_global_unique_id().
		// We guard via method_exists so older installs produce empty
		// barcodes rather than fatal. Dates may still emit from the
		// date_created / date_modified getters which predate the
		// barcode API.
		$old_product = new class() extends \WC_Product {};

		$result = $this->extension->get_product_data( $old_product );

		$this->assertSame( [], $result['barcodes'] );
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

	public function test_emits_empty_barcodes_when_value_is_whitespace_only(): void {
		// Regression: merchants occasionally paste GTINs with trailing
		// whitespace. A "   " value would pass a naive `!== ''` check
		// and emit {type: "other", value: "   "} — meaningless and
		// misleading. Trim should collapse whitespace-only values to
		// empty before the emptiness check.
		$product = $this->make_product_with_gtin( "   \n\t" );

		$result = $this->extension->get_product_data( $product );

		$this->assertSame( [], $result['barcodes'] );
	}

	public function test_trims_whitespace_around_gtin_before_type_detection(): void {
		// A 13-digit GTIN with surrounding whitespace should still
		// detect as gtin13 (trim applied before length-based type
		// detection) and emit the trimmed value.
		$product = $this->make_product_with_gtin( '  1234567890123  ' );

		$result = $this->extension->get_product_data( $product );

		$this->assertSame( 'gtin13', $result['barcodes'][0]['type'] );
		$this->assertSame( '1234567890123', $result['barcodes'][0]['value'] );
	}

	// ------------------------------------------------------------------
	// Date emission (WC 9.5+ Store API strips top-level dates)
	// ------------------------------------------------------------------

	public function test_emits_rfc3339_utc_date_created_from_wc_datetime(): void {
		// Our extension is the sole source of truth for product dates
		// in Store API responses (WC 9.5+ strips top-level `date_created`
		// / `date_modified` from product bodies entirely). Format must
		// be RFC 3339 / ISO 8601 UTC with `Z` suffix so the UCP
		// translator can pass it through to `product.published_at`
		// without further normalization.
		$ts      = 1_737_017_400; // 2025-01-16T08:50:00Z
		$product = $this->make_product_with_dates( $ts, $ts );

		$result = $this->extension->get_product_data( $product );

		$this->assertSame( '2025-01-16T08:50:00Z', $result['date_created'] );
	}

	public function test_emits_null_date_when_wc_datetime_missing(): void {
		// Brand-new products in a migration window can briefly lack
		// these meta rows; returning null (rather than a synthesized
		// current-timestamp) is the correct signal for "unknown".
		$product = new class() extends \WC_Product {};

		$result = $this->extension->get_product_data( $product );

		$this->assertNull( $result['date_created'] );
		$this->assertNull( $result['date_modified'] );
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

	public function test_schema_callback_declares_date_fields(): void {
		// Dates are typed `[string, null]` because the null case is
		// legitimate (brand-new products pre-meta-write window).
		// Declaring the union explicitly keeps strict schema validators
		// from rejecting the null case.
		$schema = $this->extension->get_schema();

		$this->assertArrayHasKey( 'date_created', $schema );
		$this->assertSame( [ 'string', 'null' ], $schema['date_created']['type'] );
		$this->assertTrue( $schema['date_created']['readonly'] );

		$this->assertArrayHasKey( 'date_modified', $schema );
		$this->assertSame( [ 'string', 'null' ], $schema['date_modified']['type'] );
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

	/**
	 * Build an anonymous WC_Product whose `get_date_created` /
	 * `get_date_modified` return `DateTimeImmutable` objects.
	 *
	 * `DateTimeImmutable` implements `DateTimeInterface` — the exact
	 * type the extension's production guard checks for — so the test
	 * exercises the real type contract, not a duck-typed shortcut.
	 * WC_DateTime extends PHP's DateTime (also DateTimeInterface), so
	 * production and tests converge on the same interface check.
	 */
	private function make_product_with_dates( int $created_ts, int $modified_ts ): \WC_Product {
		$dt = static function ( int $ts ): \DateTimeImmutable {
			return ( new \DateTimeImmutable( '@' . $ts ) )
				->setTimezone( new \DateTimeZone( 'UTC' ) );
		};

		return new class( $dt( $created_ts ), $dt( $modified_ts ) ) extends \WC_Product {
			private \DateTimeImmutable $created;
			private \DateTimeImmutable $modified;

			public function __construct( \DateTimeImmutable $created, \DateTimeImmutable $modified ) {
				$this->created  = $created;
				$this->modified = $modified;
			}

			public function get_date_created(): \DateTimeImmutable {
				return $this->created;
			}

			public function get_date_modified(): \DateTimeImmutable {
				return $this->modified;
			}
		};
	}

}
