<?php
/**
 * Tests for WC_AI_Storefront_UCP_REST_Controller::fetch_category_terms().
 *
 * The category-terms helper supports `build_category_paths_map()`
 * (issue #350) by batching `GET /wc/store/v1/products/categories?
 * include=<csv>&per_page=100` calls. These tests cover the chunking
 * loop (>100 IDs split into multiple dispatches), stdClass response
 * normalization (internal-dispatch responses sometimes nest as
 * objects), partial-failure semantics (one chunk errors, prior chunks'
 * data survives), and the empty-input + WP_Error guards.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpCategoryTermsFetchTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Captured `rest_do_request` calls. Each entry snapshots the
	 * dispatched `include` + `per_page` params (the only ones the
	 * helper sets), in dispatch order.
	 *
	 * @var array<int, array{include: string, per_page: int}>
	 */
	private array $captured_dispatches = array();

	/**
	 * Canned per-call responses indexed by 1-based call number. Each
	 * entry is the body the stub returns for that call. Values may be
	 * arrays-of-arrays OR arrays-of-stdClass (to exercise the
	 * normalize_store_api_data() path).
	 *
	 * @var array<int, mixed>
	 */
	private array $canned_calls = array();

	/**
	 * Set of 1-indexed call numbers where the stub should return a
	 * WP_Error. Used to exercise the partial-failure path where an
	 * earlier chunk succeeded and a later chunk errors.
	 *
	 * @var array<int, bool>
	 */
	private array $error_calls = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->captured_dispatches = array();
		$this->canned_calls        = array();
		$this->error_calls         = array();

		$captured = &$this->captured_dispatches;
		$canned   = &$this->canned_calls;
		$errors   = &$this->error_calls;
		Functions\when( 'rest_do_request' )->alias(
			static function ( WP_REST_Request $request ) use ( &$captured, &$canned, &$errors ) {
				$captured[]  = array(
					'include'  => (string) $request->get_param( 'include' ),
					'per_page' => (int) $request->get_param( 'per_page' ),
				);
				$call_number = count( $captured );
				if ( ! empty( $errors[ $call_number ] ) ) {
					return new WP_Error( 'forced', 'Test forced WP_Error.' );
				}
				$body = $canned[ $call_number ] ?? array();
				return new WP_REST_Response( $body, 200 );
			}
		);

		WC_AI_Storefront::$test_settings = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoke fetch_category_terms() via reflection (private method).
	 *
	 * @param array<int, int> $ids
	 * @return array<int, array{name: string, parent: int}>
	 */
	private function call_fetch( array $ids ): array {
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$method     = ( new \ReflectionClass( $controller ) )->getMethod( 'fetch_category_terms' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $ids );
	}

	private function array_term( int $id, string $name, int $parent ): array {
		return array(
			'id'     => $id,
			'name'   => $name,
			'parent' => $parent,
		);
	}

	private function object_term( int $id, string $name, int $parent ): \stdClass {
		$o         = new \stdClass();
		$o->id     = $id;
		$o->name   = $name;
		$o->parent = $parent;
		return $o;
	}

	// ------------------------------------------------------------------
	// Guards
	// ------------------------------------------------------------------

	public function test_empty_input_skips_dispatch(): void {
		$result = $this->call_fetch( array() );

		$this->assertSame( array(), $result );
		$this->assertCount( 0, $this->captured_dispatches );
	}

	// ------------------------------------------------------------------
	// Happy path — single chunk
	// ------------------------------------------------------------------

	public function test_single_chunk_dispatches_with_csv_include_and_per_page_100(): void {
		$this->canned_calls = array(
			1 => array(
				$this->array_term( 10, 'Tops', 0 ),
				$this->array_term( 11, 'Tees', 10 ),
			),
		);

		$result = $this->call_fetch( array( 10, 11 ) );

		$this->assertCount( 1, $this->captured_dispatches );
		$this->assertSame( '10,11', $this->captured_dispatches[0]['include'] );
		$this->assertSame( 100, $this->captured_dispatches[0]['per_page'] );

		$this->assertSame(
			array(
				10 => array(
					'name'   => 'Tops',
					'parent' => 0,
				),
				11 => array(
					'name'   => 'Tees',
					'parent' => 10,
				),
			),
			$result
		);
	}

	public function test_duplicate_input_ids_dedupe_in_dispatch(): void {
		$this->canned_calls = array(
			1 => array( $this->array_term( 10, 'Tops', 0 ) ),
		);

		$this->call_fetch( array( 10, 10, 10 ) );

		$this->assertSame( '10', $this->captured_dispatches[0]['include'] );
	}

	// ------------------------------------------------------------------
	// stdClass normalization (regression for review #3210733073)
	// ------------------------------------------------------------------

	public function test_stdclass_response_terms_are_normalized(): void {
		// Internal dispatch returns stdClass nodes for nested terms;
		// pre-fix the is_array guard silently dropped each one and
		// callers degraded to bare leaf names. Post-fix, the
		// normalize_store_api_data() pass casts them to arrays so the
		// iteration succeeds.
		$this->canned_calls = array(
			1 => array(
				$this->object_term( 10, 'Tops', 0 ),
				$this->object_term( 11, 'Tees', 10 ),
			),
		);

		$result = $this->call_fetch( array( 10, 11 ) );

		$this->assertSame(
			array(
				10 => array(
					'name'   => 'Tops',
					'parent' => 0,
				),
				11 => array(
					'name'   => 'Tees',
					'parent' => 10,
				),
			),
			$result
		);
	}

	// ------------------------------------------------------------------
	// Chunking (regression for review #3210733099)
	// ------------------------------------------------------------------

	public function test_more_than_100_ids_splits_into_multiple_chunks(): void {
		// 150 IDs → 2 chunks (100 + 50). Without chunking, WC Store
		// API's per_page cap would silently truncate the response to
		// the first 100 terms.
		$ids       = range( 1, 150 );
		$chunk_one = array();
		$chunk_two = array();
		foreach ( range( 1, 100 ) as $id ) {
			$chunk_one[] = $this->array_term( $id, "Cat $id", 0 );
		}
		foreach ( range( 101, 150 ) as $id ) {
			$chunk_two[] = $this->array_term( $id, "Cat $id", 0 );
		}
		$this->canned_calls = array(
			1 => $chunk_one,
			2 => $chunk_two,
		);

		$result = $this->call_fetch( $ids );

		$this->assertCount( 2, $this->captured_dispatches );

		$first_csv  = explode( ',', (string) $this->captured_dispatches[0]['include'] );
		$second_csv = explode( ',', (string) $this->captured_dispatches[1]['include'] );
		$this->assertCount( 100, $first_csv );
		$this->assertCount( 50, $second_csv );
		$this->assertSame( '1', $first_csv[0] );
		$this->assertSame( '100', $first_csv[99] );
		$this->assertSame( '101', $second_csv[0] );
		$this->assertSame( '150', $second_csv[49] );

		$this->assertCount( 150, $result );
		$this->assertArrayHasKey( 1, $result );
		$this->assertArrayHasKey( 150, $result );
	}

	public function test_partial_chunk_failure_returns_succeeded_chunks(): void {
		// Chunk 1 succeeds, chunk 2 fails with WP_Error. The caller
		// degrades only the still-missing parents to bare names — the
		// already-fetched terms are preserved so deeply-seeded leaves
		// don't lose path data because of one chunk failure.
		$ids       = range( 1, 150 );
		$chunk_one = array();
		foreach ( range( 1, 100 ) as $id ) {
			$chunk_one[] = $this->array_term( $id, "Cat $id", 0 );
		}
		$this->canned_calls = array( 1 => $chunk_one );
		$this->error_calls  = array( 2 => true );

		$result = $this->call_fetch( $ids );

		$this->assertCount( 100, $result );
		$this->assertArrayHasKey( 1, $result );
		$this->assertArrayHasKey( 100, $result );
		$this->assertArrayNotHasKey( 101, $result );
	}

	public function test_first_chunk_wp_error_returns_empty(): void {
		$this->error_calls = array( 1 => true );

		$result = $this->call_fetch( array( 10, 11, 12 ) );

		$this->assertSame( array(), $result );
		$this->assertCount( 1, $this->captured_dispatches );
	}
}
