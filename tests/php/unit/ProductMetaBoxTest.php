<?php
/**
 * Tests for WC_AI_Storefront_Product_Meta_Box.
 *
 * Covers the per-product final-sale checkbox lifecycle:
 *   - reading the meta via the canonical `is_final_sale()` helper
 *   - persisting the checkbox state on product save
 *   - hook registration in `init()`
 *
 * The checkbox-render path is intentionally NOT unit-tested here:
 * `render_checkbox` shells out to WC core's `woocommerce_wp_checkbox()`
 * helper, which constructs HTML using internal WC layout classes.
 * Brain Monkey can't meaningfully exercise that path without
 * standing up the full WC editor environment, and the assertion
 * value would be limited to "did we call the WC helper" — which
 * the hook-registration test already covers transitively.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class ProductMetaBoxTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Product_Meta_Box $meta_box;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->meta_box = new WC_AI_Storefront_Product_Meta_Box();

		// Clear $_POST between tests.
		$_POST = [];

		// save_meta() reads `$_POST` through `wp_unslash` +
		// `sanitize_text_field` (per WP conventions), then calls
		// `get_post_meta` (to disambiguate "no-op write" from "real
		// failure" on the update_post_meta return). All three are
		// stubbed here as identity passthroughs / empty defaults.
		// Individual tests override `get_post_meta` when they need to
		// test the disambiguate-against-existing branch.
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_post_meta' )->justReturn( '' );

		// FIND-S03 fix: save_meta() now verifies the WC product-save
		// nonce before reading $_POST. Tests simulate a valid form submit
		// by seeding the nonce field and stubbing wp_verify_nonce to pass.
		$_POST['woocommerce_meta_nonce'] = 'test_nonce';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
	}

	protected function tearDown(): void {
		$_POST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// is_final_sale — the canonical meta read
	// ------------------------------------------------------------------

	public function test_is_final_sale_returns_true_when_meta_is_yes(): void {
		// Happy path: merchant checked the box, saved, post-meta now
		// reads 'yes'. Reader returns true.
		Functions\when( 'get_post_meta' )->justReturn( 'yes' );

		$this->assertTrue( WC_AI_Storefront_Product_Meta_Box::is_final_sale( 42 ) );
	}

	public function test_is_final_sale_returns_false_when_meta_is_no(): void {
		// Merchant explicitly unchecked the box (or never checked it
		// and saved). Reader returns false. The strict 'yes' === guard
		// is the contract — anything other than the literal 'yes'
		// string is "not flagged".
		Functions\when( 'get_post_meta' )->justReturn( 'no' );

		$this->assertFalse( WC_AI_Storefront_Product_Meta_Box::is_final_sale( 42 ) );
	}

	public function test_is_final_sale_returns_false_when_meta_is_unset(): void {
		// Pre-PR-D products have no meta set. WP returns '' (or the
		// default arg value from `get_post_meta($id, $key, true)`).
		// Reader returns false — products without the flag default to
		// "store-wide policy applies."
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$this->assertFalse( WC_AI_Storefront_Product_Meta_Box::is_final_sale( 42 ) );
	}

	public function test_is_final_sale_returns_false_for_invalid_product_id(): void {
		// Bad-input guard: zero / negative product IDs short-circuit
		// to false without hitting the database. Saves a `get_post_meta`
		// query and signals "not flagged" cleanly. `Functions\expect`
		// with `never()` would also catch a regression that called
		// `get_post_meta` despite the guard, but `justReturn` is
		// sufficient — the assertion below is the contract.
		Functions\when( 'get_post_meta' )->justReturn( 'yes' );

		$this->assertFalse( WC_AI_Storefront_Product_Meta_Box::is_final_sale( 0 ) );
		$this->assertFalse( WC_AI_Storefront_Product_Meta_Box::is_final_sale( -5 ) );
	}

	public function test_is_final_sale_strict_match_rejects_non_yes_values(): void {
		// The strict 'yes' === guard prevents truthy non-canonical
		// values from being interpreted as "flagged". `1`, `true`,
		// `'true'`, `'YES'` — anything other than the literal 'yes'
		// string returns false. Locks the contract so a future
		// change to WC's boolean meta convention doesn't silently
		// flip the override on for existing product data.
		foreach ( [ '1', 'true', 'YES', 'Yes', 'on', 'enabled' ] as $value ) {
			Functions\when( 'get_post_meta' )->justReturn( $value );
			$this->assertFalse(
				WC_AI_Storefront_Product_Meta_Box::is_final_sale( 42 ),
				"Value `{$value}` must be rejected by the strict 'yes' === guard."
			);
		}
	}

	// ------------------------------------------------------------------
	// save_meta — POST → meta persistence
	// ------------------------------------------------------------------

	public function test_save_persists_yes_when_checkbox_present_in_post(): void {
		// Merchant checked the box; HTML form posts the input's value
		// (typically 'yes', though we don't inspect the value — only
		// presence). update_post_meta is called with 'yes'.
		$_POST[ WC_AI_Storefront_Product_Meta_Box::META_KEY ] = 'yes';

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 100, WC_AI_Storefront_Product_Meta_Box::META_KEY, 'yes' );

		$this->meta_box->save_meta( 100 );
	}

	public function test_save_persists_no_when_checkbox_absent_from_post(): void {
		// Merchant unchecked the box; HTML form omits the key entirely
		// from $_POST. save_meta normalizes to 'no' so the meta key
		// is always present (avoids the "unset means default" trap
		// that would force every reader to probe two states).
		// $_POST has no META_KEY.

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 200, WC_AI_Storefront_Product_Meta_Box::META_KEY, 'no' );

		$this->meta_box->save_meta( 200 );
	}

	public function test_save_handles_invalid_product_id_gracefully(): void {
		// Bad-input guard: save_meta returns early for zero/negative
		// product IDs without calling update_post_meta. Defensive
		// against edge cases where the WC save handler somehow fires
		// with an invalid product context.
		$_POST[ WC_AI_Storefront_Product_Meta_Box::META_KEY ] = 'yes';

		Functions\expect( 'update_post_meta' )->never();

		$this->meta_box->save_meta( 0 );
		$this->meta_box->save_meta( -1 );
	}

	public function test_save_rejects_non_yes_post_value_as_no(): void {
		// HTML checkboxes always POST their `value=` attribute when
		// checked. WC's `woocommerce_wp_checkbox()` helper renders
		// `value="yes"`, so a legitimate checked submission lands as
		// the literal string `'yes'`. A tampered payload that sets
		// the value to something else (`'no'`, `''`, garbage) — even
		// while the key is present in POST — must NOT be smuggled
		// into the meta as `'yes'`. The strict `'yes' ===` value
		// check rejects these and writes `'no'`. Without this gate,
		// a forged `<input value="no">` POST would still flip the
		// flag to `'yes'` because `isset()` alone treats presence as
		// "checked" regardless of value.
		$_POST[ WC_AI_Storefront_Product_Meta_Box::META_KEY ] = 'something-else';

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 100, WC_AI_Storefront_Product_Meta_Box::META_KEY, 'no' );

		$this->meta_box->save_meta( 100 );
	}

	public function test_save_reaches_failure_branch_without_throwing(): void {
		// `update_post_meta` returns false either when the value is
		// unchanged OR when the DB write actually failed. We
		// disambiguate by reading the existing value first: if the
		// new value differs from the old AND update_post_meta
		// returned false, that's a real failure — production code
		// emits a debug log via `WC_AI_Storefront_Logger::debug`.
		//
		// The logger emits via `error_log()`, which is a PHP internal
		// function. Brain Monkey + Patchwork intercept of internals
		// is brittle (requires patchwork.json's redefinable-internals
		// AND timing-sensitive bootstrap loading), so this test
		// settles for a smoke-test contract: the failure branch must
		// be REACHED without throwing. The Logger interface itself is
		// covered by its own unit tests; that the failure branch
		// invokes the logger is documented in the production code's
		// inline comment.
		//
		// Functions\expect on update_post_meta with the expected
		// args proves we reached save_meta's mutation path with the
		// right value, before the disambiguation logic decides
		// whether to log.
		$_POST[ WC_AI_Storefront_Product_Meta_Box::META_KEY ] = 'yes';

		Functions\when( 'get_post_meta' )->justReturn( 'no' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 100, WC_AI_Storefront_Product_Meta_Box::META_KEY, 'yes' )
			->andReturn( false );

		$this->meta_box->save_meta( 100 );
	}

	public function test_save_reaches_no_op_branch_without_throwing(): void {
		// update_post_meta returns false when the value IS the same
		// as what's already stored — that's a no-op, not a failure.
		// The disambiguation guard ($existing === $value) skips the
		// log call. Companion smoke test to the failure-branch case
		// above; same caveat about logger-spying brittleness applies.
		$_POST[ WC_AI_Storefront_Product_Meta_Box::META_KEY ] = 'yes';

		Functions\when( 'get_post_meta' )->justReturn( 'yes' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 100, WC_AI_Storefront_Product_Meta_Box::META_KEY, 'yes' )
			->andReturn( false );

		$this->meta_box->save_meta( 100 );
	}

	public function test_save_aborts_when_nonce_missing(): void {
		// FIND-S03 regression: save_meta() must NOT write meta when the
		// WC nonce field is absent — guards against a plugin calling
		// do_action('woocommerce_process_product_meta', $id) directly
		// without going through WC's nonce-verified save flow.
		unset( $_POST['woocommerce_meta_nonce'] );
		$_POST[ WC_AI_Storefront_Product_Meta_Box::META_KEY ] = 'yes';

		Functions\expect( 'update_post_meta' )->never();

		$this->meta_box->save_meta( 100 );
	}

	public function test_save_aborts_when_nonce_invalid(): void {
		// Counterpart to the missing-nonce test: an invalid nonce token
		// (e.g., expired or forged) must also abort the save.
		$_POST['woocommerce_meta_nonce']                      = 'bad_nonce';
		$_POST[ WC_AI_Storefront_Product_Meta_Box::META_KEY ] = 'yes';

		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\expect( 'update_post_meta' )->never();

		$this->meta_box->save_meta( 100 );
	}

	// ------------------------------------------------------------------
	// init — hook registration
	// ------------------------------------------------------------------

	public function test_init_registers_inventory_render_hook(): void {
		// init() must register the render callback against the
		// Inventory tab's data hook. A regression that registers
		// against a different tab (e.g. General, Advanced) would
		// hide the checkbox from merchants but pass every other
		// test.
		$hooks = [];
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
			}
		);

		$this->meta_box->init();

		$this->assertContains(
			'woocommerce_product_options_inventory_product_data',
			$hooks,
			'Render hook must be registered on the Inventory tab.'
		);
	}

	public function test_init_registers_save_hook(): void {
		// init() must register the save callback. Without this, the
		// checkbox would render correctly but never persist.
		$hooks = [];
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
			}
		);

		$this->meta_box->init();

		$this->assertContains(
			'woocommerce_process_product_meta',
			$hooks,
			'Save hook must be registered on the product save action.'
		);
	}

	public function test_meta_key_constant_is_underscore_prefixed(): void {
		// The `_` prefix is load-bearing: WP's Custom Fields panel
		// filters out underscore-prefixed keys, so this meta won't
		// appear in the merchant's Custom Fields UI alongside their
		// own custom fields. Adding/removing the underscore would
		// surface (or hide) the field unexpectedly.
		$this->assertStringStartsWith(
			'_',
			WC_AI_Storefront_Product_Meta_Box::META_KEY,
			'META_KEY must be underscore-prefixed (WP hidden-meta convention).'
		);
	}
}
