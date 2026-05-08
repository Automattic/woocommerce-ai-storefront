<?php
/**
 * Tests for WC_AI_Storefront_UCP_REST_Controller::fetch_variations_batched().
 *
 * The batched helper replaces per-variation N+1 fan-out with a single
 * (or few) `?parent_includes=<csv>&per_page=100` collection dispatch.
 * These tests cover the dispatch shape, pagination at the 100-cap,
 * partial-failure detection, the WP_Error degradation policy, the
 * MAX_VARIATIONS_PER_PRODUCT cap, the empty-input guard, and filter
 * compatibility — without exercising the search/lookup wiring (those
 * tests live in UcpCatalogSearchTest / UcpCatalogLookupTest).
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpVariationBatchTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Captured `rest_do_request` calls. Each entry is the params
	 * snapshot of one dispatched WP_REST_Request, in order. Tests
	 * assert against this to verify the batched-dispatch contract
	 * (one request per page, correct query params).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $captured_dispatches = [];

	/**
	 * Canned variation responses indexed by their order in the
	 * collection response. Tests can either return the full set in
	 * one response or split across multiple pages.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $canned_pages = [];

	/**
	 * If true, the `rest_do_request` stub returns WP_Error to exercise
	 * the batch-fail degradation policy.
	 */
	private bool $force_wp_error = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->captured_dispatches = [];
		$this->canned_pages        = [];
		$this->force_wp_error      = false;

		// Wire rest_do_request to capture + return canned pages.
		$captured     = &$this->captured_dispatches;
		$pages        = &$this->canned_pages;
		$wp_error_ref = &$this->force_wp_error;
		Functions\when( 'rest_do_request' )->alias(
			static function ( WP_REST_Request $request ) use ( &$captured, &$pages, &$wp_error_ref ) {
				$captured[] = $request->get_params();

				if ( $wp_error_ref ) {
					return new WP_Error( 'forced_error', 'Test forced WP_Error.' );
				}

				$page = (int) $request->get_param( 'page' );
				$body = $pages[ $page ] ?? array();
				return new WP_REST_Response( $body, 200 );
			}
		);

		// Reset settings between tests so any disabled-state behavior
		// doesn't leak. Stub defaults to enabled.
		WC_AI_Storefront::$test_settings = [];
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoke fetch_variations_batched() via reflection (private method).
	 *
	 * @param array<int, array<string, mixed>> $wc_products
	 * @return array<int, array{variations: array<int, array<string, mixed>>, skipped: int}>
	 */
	private function call_batched( array $wc_products ): array {
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$method     = ( new \ReflectionClass( $controller ) )->getMethod( 'fetch_variations_batched' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $wc_products );
	}

	/**
	 * Build a parent-product fixture pointing at the given variation IDs.
	 *
	 * @param array<int, int> $variation_ids
	 * @return array<string, mixed>
	 */
	private function variable_product( int $parent_id, array $variation_ids ): array {
		return array(
			'id'         => $parent_id,
			'type'       => 'variable',
			'variations' => array_map(
				static fn( int $id ): array => array( 'id' => $id ),
				$variation_ids
			),
		);
	}

	/**
	 * Build a canned variation response carrying the parent linkage
	 * the binning step uses.
	 *
	 * @return array<string, mixed>
	 */
	private function variation( int $id, int $parent_id ): array {
		return array(
			'id'     => $id,
			'parent' => $parent_id,
			'name'   => "Variation $id",
		);
	}

	// ------------------------------------------------------------------
	// Edge cases — input shape
	// ------------------------------------------------------------------

	public function test_empty_input_skips_dispatch(): void {
		$result = $this->call_batched( array() );

		$this->assertSame( array(), $result );
		$this->assertCount( 0, $this->captured_dispatches );
	}

	public function test_all_simple_input_skips_dispatch(): void {
		$result = $this->call_batched(
			array(
				array( 'id' => 1, 'type' => 'simple' ),
				array( 'id' => 2, 'type' => 'simple' ),
			)
		);

		$this->assertSame( array(), $result );
		$this->assertCount( 0, $this->captured_dispatches );
	}

	public function test_variable_with_empty_variations_array_skips_parent(): void {
		// A variable product whose `variations[]` pointer list is
		// empty (data anomaly) contributes nothing to the work list.
		$result = $this->call_batched(
			array(
				array( 'id' => 1, 'type' => 'variable', 'variations' => array() ),
			)
		);

		$this->assertSame( array(), $result );
		$this->assertCount( 0, $this->captured_dispatches );
	}

	// ------------------------------------------------------------------
	// Happy path — single + multi parent
	// ------------------------------------------------------------------

	public function test_single_parent_dispatches_once_with_correct_params(): void {
		$this->canned_pages = array(
			1 => array(
				$this->variation( 101, 35 ),
				$this->variation( 102, 35 ),
				$this->variation( 103, 35 ),
			),
		);

		$result = $this->call_batched(
			array( $this->variable_product( 35, array( 101, 102, 103 ) ) )
		);

		$this->assertCount( 1, $this->captured_dispatches );
		$this->assertSame( '35', $this->captured_dispatches[0]['parent_includes'] );
		$this->assertSame( 100, $this->captured_dispatches[0]['per_page'] );
		$this->assertSame( 1, $this->captured_dispatches[0]['page'] );

		$this->assertCount( 3, $result[35]['variations'] );
		$this->assertSame( 0, $result[35]['skipped'] );
	}

	public function test_multi_parent_dispatches_once_with_csv_of_parents(): void {
		// Three variable parents in one search result page → one
		// batched dispatch with comma-separated parent_includes.
		$this->canned_pages = array(
			1 => array(
				$this->variation( 101, 35 ),
				$this->variation( 102, 35 ),
				$this->variation( 201, 36 ),
				$this->variation( 301, 37 ),
				$this->variation( 302, 37 ),
			),
		);

		$result = $this->call_batched(
			array(
				$this->variable_product( 35, array( 101, 102 ) ),
				$this->variable_product( 36, array( 201 ) ),
				$this->variable_product( 37, array( 301, 302 ) ),
			)
		);

		$this->assertCount( 1, $this->captured_dispatches );
		$this->assertSame( '35,36,37', $this->captured_dispatches[0]['parent_includes'] );

		$this->assertCount( 2, $result[35]['variations'] );
		$this->assertCount( 1, $result[36]['variations'] );
		$this->assertCount( 2, $result[37]['variations'] );
		$this->assertSame( 0, $result[35]['skipped'] );
		$this->assertSame( 0, $result[36]['skipped'] );
		$this->assertSame( 0, $result[37]['skipped'] );
	}

	public function test_mixed_simple_and_variable_only_batches_variable_parents(): void {
		$this->canned_pages = array(
			1 => array( $this->variation( 101, 35 ) ),
		);

		$result = $this->call_batched(
			array(
				array( 'id' => 22, 'type' => 'simple' ),
				$this->variable_product( 35, array( 101 ) ),
				array( 'id' => 23, 'type' => 'simple' ),
			)
		);

		$this->assertCount( 1, $this->captured_dispatches );
		$this->assertSame( '35', $this->captured_dispatches[0]['parent_includes'] );
		$this->assertArrayHasKey( 35, $result );
		$this->assertArrayNotHasKey( 22, $result );
		$this->assertArrayNotHasKey( 23, $result );
	}

	// ------------------------------------------------------------------
	// Pagination at the 100-item cap
	// ------------------------------------------------------------------

	public function test_combined_count_above_100_paginates_correctly(): void {
		// Three parents × 50 variations = 150 total. Page 1 returns
		// 100 (full), page 2 returns 50 (short — terminates the walk).
		$page_1 = array();
		$page_2 = array();
		$expected_per_parent = array();
		foreach ( array( 35, 36, 37 ) as $parent ) {
			$expected_per_parent[ $parent ] = array();
			for ( $i = 1; $i <= 50; $i++ ) {
				$variation_id = ( $parent * 1000 ) + $i;
				$expected_per_parent[ $parent ][] = $variation_id;
			}
		}
		// Spread the 150 variations across two pages: 100 then 50.
		$flat = array();
		foreach ( $expected_per_parent as $parent => $ids ) {
			foreach ( $ids as $id ) {
				$flat[] = $this->variation( $id, $parent );
			}
		}
		$page_1 = array_slice( $flat, 0, 100 );
		$page_2 = array_slice( $flat, 100, 50 );
		$this->canned_pages = array( 1 => $page_1, 2 => $page_2 );

		$result = $this->call_batched(
			array(
				$this->variable_product( 35, $expected_per_parent[35] ),
				$this->variable_product( 36, $expected_per_parent[36] ),
				$this->variable_product( 37, $expected_per_parent[37] ),
			)
		);

		$this->assertCount( 2, $this->captured_dispatches );
		$this->assertSame( 1, $this->captured_dispatches[0]['page'] );
		$this->assertSame( 2, $this->captured_dispatches[1]['page'] );

		$this->assertCount( 50, $result[35]['variations'] );
		$this->assertCount( 50, $result[36]['variations'] );
		$this->assertCount( 50, $result[37]['variations'] );
	}

	public function test_short_first_page_terminates_walk(): void {
		// Only 5 variations across all parents — first page returns
		// 5 (short), no second dispatch.
		$this->canned_pages = array(
			1 => array(
				$this->variation( 101, 35 ),
				$this->variation( 102, 35 ),
				$this->variation( 103, 35 ),
				$this->variation( 201, 36 ),
				$this->variation( 202, 36 ),
			),
		);

		$this->call_batched(
			array(
				$this->variable_product( 35, array( 101, 102, 103 ) ),
				$this->variable_product( 36, array( 201, 202 ) ),
			)
		);

		$this->assertCount( 1, $this->captured_dispatches );
	}

	// ------------------------------------------------------------------
	// Partial failure (some variations missing from response)
	// ------------------------------------------------------------------

	public function test_partial_response_emits_skipped_count(): void {
		// Parent 35 declares 3 variations; only 2 come back.
		$this->canned_pages = array(
			1 => array(
				$this->variation( 101, 35 ),
				$this->variation( 102, 35 ),
				// 103 is missing — fetch failed or scope-filtered
			),
		);

		$result = $this->call_batched(
			array( $this->variable_product( 35, array( 101, 102, 103 ) ) )
		);

		$this->assertCount( 2, $result[35]['variations'] );
		$this->assertSame( 1, $result[35]['skipped'] );
	}

	public function test_orphan_variation_with_unknown_parent_is_dropped(): void {
		// An orphaned variation whose parent isn't in the request
		// shouldn't appear under any bin. Defensive — should never
		// happen with `parent_includes=<csv>` but the binning code
		// has the guard so we test it.
		$this->canned_pages = array(
			1 => array(
				$this->variation( 101, 35 ),
				$this->variation( 999, 9999 ), // orphan
			),
		);

		$result = $this->call_batched(
			array( $this->variable_product( 35, array( 101 ) ) )
		);

		$this->assertCount( 1, $result[35]['variations'] );
		$this->assertSame( 101, $result[35]['variations'][0]['id'] );
		$this->assertArrayNotHasKey( 9999, $result );
	}

	// ------------------------------------------------------------------
	// Batch-call failure (WP_Error / 5xx)
	// ------------------------------------------------------------------

	public function test_wp_error_degrades_all_parents_to_empty_with_skipped(): void {
		$this->force_wp_error = true;

		$result = $this->call_batched(
			array(
				$this->variable_product( 35, array( 101, 102, 103 ) ),
				$this->variable_product( 36, array( 201, 202 ) ),
			)
		);

		$this->assertCount( 1, $this->captured_dispatches ); // tried once, WP_Error broke the loop
		$this->assertSame( array(), $result[35]['variations'] );
		$this->assertSame( 3, $result[35]['skipped'] );
		$this->assertSame( array(), $result[36]['variations'] );
		$this->assertSame( 2, $result[36]['skipped'] );
	}

	// ------------------------------------------------------------------
	// MAX_VARIATIONS_PER_PRODUCT cap
	// ------------------------------------------------------------------

	public function test_max_cap_truncates_expected_set_per_parent(): void {
		// Parent declares 60 variations; cap is 50. The CSV must NOT
		// expect IDs 51-60, and skipped count is 0 if all 50 capped
		// IDs return — cap-truncated entries don't contribute to skipped
		// (matches fetch_variations_for() semantics).
		$declared_ids = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$declared_ids[] = 1000 + $i;
		}

		// Only return the first 50 — any "expected" 51-60 would be
		// silently included and skipped is the only way to detect it.
		$page_1 = array();
		for ( $i = 1; $i <= 50; $i++ ) {
			$page_1[] = $this->variation( 1000 + $i, 35 );
		}
		$this->canned_pages = array( 1 => $page_1 );

		$result = $this->call_batched(
			array( $this->variable_product( 35, $declared_ids ) )
		);

		$this->assertCount( 50, $result[35]['variations'] );
		// No skipped — cap-truncated entries weren't expected.
		$this->assertSame( 0, $result[35]['skipped'] );
	}

	// ------------------------------------------------------------------
	// Filter compatibility
	// ------------------------------------------------------------------

	public function test_wc_ai_storefront_ucp_store_api_args_filter_applied(): void {
		// A merchant filter that adds a `category` constraint must
		// flow into the batched dispatch params just as it does for
		// the search collection dispatch.
		$this->canned_pages = array(
			1 => array( $this->variation( 101, 35 ) ),
		);

		Monkey\Filters\expectApplied( 'wc_ai_storefront_ucp_store_api_args' )
			->once()
			->with(
				\Mockery::on( static fn( $params ): bool => is_array( $params ) ),
				'/wc/store/v1/products'
			)
			->andReturnUsing(
				static function ( array $params ): array {
					$params['category'] = 'invisible-scope';
					return $params;
				}
			);

		$this->call_batched(
			array( $this->variable_product( 35, array( 101 ) ) )
		);

		$this->assertCount( 1, $this->captured_dispatches );
		$this->assertSame( 'invisible-scope', $this->captured_dispatches[0]['category'] );
		// Pagination keys were restored — filter MUST NOT override them.
		$this->assertSame( 100, $this->captured_dispatches[0]['per_page'] );
		$this->assertSame( 1, $this->captured_dispatches[0]['page'] );
		$this->assertSame( '35', $this->captured_dispatches[0]['parent_includes'] );
	}

	public function test_filter_returning_non_array_falls_back_safely(): void {
		// Misbehaving filter callbacks return non-arrays; guard
		// preserves original params so dispatch isn't broken.
		$this->canned_pages = array(
			1 => array( $this->variation( 101, 35 ) ),
		);

		Monkey\Filters\expectApplied( 'wc_ai_storefront_ucp_store_api_args' )
			->once()
			->andReturn( 'not-an-array' );

		$this->call_batched(
			array( $this->variable_product( 35, array( 101 ) ) )
		);

		$this->assertCount( 1, $this->captured_dispatches );
		$this->assertSame( '35', $this->captured_dispatches[0]['parent_includes'] );
	}
}
