<?php
/**
 * Tests for WC_AI_Syndication_UCP_Envelope.
 *
 * The envelope is the `ucp:` wrapper object that surrounds every
 * UCP response body. Spec schemas:
 *
 *   - response_catalog_schema (ucp.json)
 *   - response_checkout_schema (ucp.json)
 *
 * Tests verify the envelope shape matches what strict UCP consumers
 * expect, including the easy-to-miss {} vs [] distinction on
 * `payment_handlers`.
 *
 * @package WooCommerce_AI_Syndication
 */

class UcpEnvelopeTest extends \PHPUnit\Framework\TestCase {

	// ------------------------------------------------------------------
	// Catalog envelope
	// ------------------------------------------------------------------

	public function test_catalog_envelope_includes_version(): void {
		$env = WC_AI_Syndication_UCP_Envelope::catalog_envelope(
			'dev.ucp.shopping.catalog.search'
		);

		$this->assertArrayHasKey( 'version', $env );
		$this->assertEquals(
			WC_AI_Syndication_Ucp::PROTOCOL_VERSION,
			$env['version']
		);
	}

	public function test_catalog_envelope_keys_capabilities_by_passed_name(): void {
		// Different catalog operations (search, lookup) get their own
		// capability key in the envelope so agents can confirm the
		// response matches the operation they invoked.
		$env = WC_AI_Syndication_UCP_Envelope::catalog_envelope(
			'dev.ucp.shopping.catalog.lookup'
		);

		$this->assertArrayHasKey(
			'dev.ucp.shopping.catalog.lookup',
			$env['capabilities']
		);
	}

	public function test_catalog_envelope_capability_value_is_array_with_version(): void {
		// Per UCP schema: capabilities.X is an array of binding objects,
		// never a bare object. The array wrapper leaves room to declare
		// multiple implementation versions concurrently.
		$env = WC_AI_Syndication_UCP_Envelope::catalog_envelope(
			'dev.ucp.shopping.catalog.search'
		);
		$cap = $env['capabilities']['dev.ucp.shopping.catalog.search'];

		$this->assertIsArray( $cap );
		$this->assertCount( 1, $cap );
		$this->assertEquals(
			WC_AI_Syndication_Ucp::PROTOCOL_VERSION,
			$cap[0]['version']
		);
	}

	// ------------------------------------------------------------------
	// Checkout envelope
	// ------------------------------------------------------------------

	public function test_checkout_envelope_includes_version(): void {
		$env = WC_AI_Syndication_UCP_Envelope::checkout_envelope();

		$this->assertEquals(
			WC_AI_Syndication_Ucp::PROTOCOL_VERSION,
			$env['version']
		);
	}

	public function test_checkout_envelope_declares_checkout_capability(): void {
		$env = WC_AI_Syndication_UCP_Envelope::checkout_envelope();

		$this->assertArrayHasKey(
			'dev.ucp.shopping.checkout',
			$env['capabilities']
		);
	}

	public function test_checkout_envelope_has_payment_handlers_key(): void {
		// Schema: response_checkout_schema has
		// `required: ["payment_handlers"]`. The key MUST be present
		// even when we declare no handlers.
		$env = WC_AI_Syndication_UCP_Envelope::checkout_envelope();

		$this->assertArrayHasKey( 'payment_handlers', $env );
	}

	public function test_payment_handlers_serializes_as_object_not_array(): void {
		// JSON spec distinguishes `{}` (object) from `[]` (array).
		// UCP schema requires object here. PHP `[]` serializes as
		// JSON array; `(object) []` serializes as JSON object.
		// The envelope must always produce object shape.
		$env  = WC_AI_Syndication_UCP_Envelope::checkout_envelope();
		$json = json_encode( $env['payment_handlers'] );

		$this->assertEquals( '{}', $json );
	}

	public function test_full_checkout_envelope_round_trips_as_valid_json(): void {
		// End-to-end sanity: the whole envelope should serialize
		// and deserialize cleanly without fabricating array/object
		// confusion.
		$env        = WC_AI_Syndication_UCP_Envelope::checkout_envelope();
		$serialized = json_encode( $env );
		$decoded    = json_decode( $serialized, true );

		$this->assertEquals(
			WC_AI_Syndication_Ucp::PROTOCOL_VERSION,
			$decoded['version']
		);
		// Decoded payment_handlers round-trips as empty array in PHP
		// (json_decode default associative = true), but the serialized
		// form is still `{}` not `[]` — that's what the previous test
		// verifies.
		$this->assertArrayHasKey( 'payment_handlers', $decoded );
	}
}
