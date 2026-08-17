<?php
/**
 * Tests for the v2 collection-list endpoint /collections.json in
 * WC_AI_Storefront_Products_Feed.
 *
 * Covers the wiring, the serve gate/OPTIONS/early-return branches, and
 * build_collections_json()'s shape, the POST-GATE products_count (must equal
 * what the per-collection endpoint would return, not the raw term count), the
 * non-empty-after-gate filtering, and the empty-store case.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ProductsFeedCollectionsTest extends \PHPUnit\Framework\TestCase {

	private WC_AI_Storefront_Products_Feed $feed;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->feed = new WC_AI_Storefront_Products_Feed();

		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'products_json_enabled'  => 'yes',
			'product_selection_mode' => 'all',
		);

		$_GET = array();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, $options = 0, $depth = 512 ) => json_encode( $data, $options, $depth )
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		$_GET                            = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string $method  Private method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function invoke_private( string $method, ...$args ) {
		$reflection = new ReflectionClass( $this->feed );
		$m          = $reflection->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $this->feed, ...$args );
	}

	// ------------------------------------------------------------------
	// Wiring
	// ------------------------------------------------------------------

	public function test_query_var_collections_constant_defined(): void {
		$this->assertSame( 'wc_ai_storefront_collections_json', WC_AI_Storefront_Products_Feed::QUERY_VAR_COLLECTIONS );
	}

	public function test_collections_rewrite_rule_registered(): void {
		$rules = array();
		Functions\when( 'add_rewrite_rule' )->alias(
			static function ( $regex, $query, $after ) use ( &$rules ) {
				$rules[ $regex ] = array(
					'query' => $query,
					'after' => $after,
				);
			}
		);
		$this->feed->add_rewrite_rules();

		$this->assertArrayHasKey( '^collections\.json$', $rules );
		$this->assertStringContainsString(
			WC_AI_Storefront_Products_Feed::QUERY_VAR_COLLECTIONS . '=1',
			$rules['^collections\.json$']['query']
		);
	}

	public function test_collections_query_var_registered(): void {
		$vars = $this->feed->add_query_vars( array() );
		$this->assertContains( WC_AI_Storefront_Products_Feed::QUERY_VAR_COLLECTIONS, $vars );
	}

	public function test_canonical_redirect_suppressed_for_collections_query_var(): void {
		Functions\when( 'get_query_var' )->alias(
			static fn( $var ) => WC_AI_Storefront_Products_Feed::QUERY_VAR_COLLECTIONS === $var ? 1 : 0
		);
		$this->assertFalse(
			$this->feed->suppress_canonical_redirect( 'https://example.com/collections.json/' )
		);
	}

	// ------------------------------------------------------------------
	// serve_collections() — gate/OPTIONS/early-return
	// ------------------------------------------------------------------

	public function test_serve_collections_returns_early_when_query_var_not_set(): void {
		Functions\when( 'get_query_var' )->justReturn( 0 );

		ob_start();
		$this->feed->serve_collections();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_collections_returns_404_when_feed_disabled(): void {
		Functions\when( 'get_query_var' )->justReturn( 1 );
		WC_AI_Storefront::$test_settings['products_json_enabled'] = 'no';
		Functions\when( 'status_header' )->alias(
			static function ( $code ) {
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->feed->serve_collections();
			$this->fail( 'Expected serve_collections() to 404 when the feed toggle is off.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'status_header:404', $e->getMessage() );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_collections_returns_204_on_options_request(): void {
		Functions\when( 'get_query_var' )->justReturn( 1 );
		$_SERVER['REQUEST_METHOD'] = 'OPTIONS';
		Functions\when( 'status_header' )->alias(
			static function ( $code ) {
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->feed->serve_collections();
			$this->fail( 'Expected serve_collections() to 204 on an OPTIONS preflight.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'status_header:204', $e->getMessage() );
		} finally {
			unset( $_SERVER['REQUEST_METHOD'] );
		}
	}

	// ------------------------------------------------------------------
	// build_collections_json() — shape, post-gate count, filtering
	// ------------------------------------------------------------------

	public function test_build_collections_empty_when_no_terms(): void {
		Functions\when( 'get_terms' )->justReturn( array() );

		$json    = $this->invoke_private( 'build_collections_json' );
		$decoded = json_decode( (string) $json, true );

		$this->assertSame( array( 'collections' => array() ), $decoded );
	}

	public function test_build_collections_shape(): void {
		Functions\when( 'get_terms' )->justReturn(
			array( $this->term( 7, 'hoodies', 'Hoodies', 'Cozy <strong>hoodies</strong>.' ) )
		);
		// 'all' mode → every product syndicated; 2 ids → count 2.
		Functions\when( 'wc_get_products' )->justReturn( array( 11, 12 ) );

		$json    = $this->invoke_private( 'build_collections_json' );
		$decoded = json_decode( (string) $json, true );

		$this->assertArrayHasKey( 'collections', $decoded );
		$this->assertCount( 1, $decoded['collections'] );
		$c = $decoded['collections'][0];
		$this->assertSame( 7, $c['id'] );
		$this->assertSame( 'hoodies', $c['handle'] );
		$this->assertSame( 'Hoodies', $c['title'] );
		$this->assertSame( 'Cozy <strong>hoodies</strong>.', $c['body_html'] );
		$this->assertSame( 2, $c['products_count'] );
		// wp_terms has no timestamps → keys present but null, never fabricated.
		$this->assertNull( $c['published_at'] );
		$this->assertNull( $c['updated_at'] );
	}

	public function test_build_collections_products_count_is_post_gate(): void {
		// The category's raw membership is 3 products, but only 2 are
		// syndicated ('selected' mode with ids 11,12). products_count MUST be
		// the post-gate 2, never the raw 3 — otherwise /collections.json and
		// the per-collection endpoint disagree.
		Functions\when( 'get_terms' )->justReturn(
			array( $this->term( 7, 'hoodies', 'Hoodies', '' ) )
		);
		Functions\when( 'wc_get_products' )->justReturn( array( 11, 12, 13 ) );
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'products_json_enabled'  => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => array( 11, 12 ), // 13 excluded.
		);

		$json    = $this->invoke_private( 'build_collections_json' );
		$decoded = json_decode( (string) $json, true );

		$this->assertSame( 2, $decoded['collections'][0]['products_count'] );
	}

	public function test_build_collections_omits_category_with_no_syndicated_products(): void {
		// 'hoodies' has syndicated products; 'secret' has products but none
		// syndicated (not selected). 'secret' must be omitted entirely so the
		// list never advertises a category the per-collection endpoint returns
		// empty for.
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->term( 7, 'hoodies', 'Hoodies', '' ),
				$this->term( 8, 'secret', 'Secret', '' ),
			)
		);
		Functions\when( 'wc_get_products' )->alias(
			static function ( $query ) {
				$slug = $query['category'][0] ?? '';
				return 'hoodies' === $slug ? array( 11, 12 ) : array( 90, 91 );
			}
		);
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'products_json_enabled'  => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => array( 11, 12 ), // none of secret's 90,91.
		);

		$json    = $this->invoke_private( 'build_collections_json' );
		$decoded = json_decode( (string) $json, true );

		$this->assertCount( 1, $decoded['collections'] );
		$this->assertSame( 'hoodies', $decoded['collections'][0]['handle'] );
		$handles = array_column( $decoded['collections'], 'handle' );
		$this->assertNotContains( 'secret', $handles );
	}

	public function test_build_collections_count_query_restricts_to_catalog_visibility(): void {
		// LEAK GUARD: the per-category count query MUST carry visibility =>
		// 'catalog' so Hidden / Search-only products are not counted (which
		// would inflate products_count above what the per-collection endpoint
		// returns).
		Functions\when( 'get_terms' )->justReturn(
			array( $this->term( 7, 'hoodies', 'Hoodies', '' ) )
		);
		$captured = null;
		Functions\when( 'wc_get_products' )->alias(
			static function ( $query ) use ( &$captured ) {
				$captured = $query;
				return array();
			}
		);

		$this->invoke_private( 'build_collections_json' );

		$this->assertIsArray( $captured );
		$this->assertSame( 'catalog', $captured['visibility'] ?? null );
		$this->assertSame( 'publish', $captured['status'] ?? null );
		$this->assertSame( 'ids', $captured['return'] ?? null );
	}

	/**
	 * A product_cat WP_Term.
	 *
	 * @param int    $id   Term id.
	 * @param string $slug Slug.
	 * @param string $name Name.
	 * @param string $desc Description (collection body_html).
	 * @return \WP_Term
	 */
	private function term( int $id, string $slug, string $name, string $desc ): \WP_Term {
		$t              = new \WP_Term();
		$t->term_id     = $id;
		$t->slug        = $slug;
		$t->name        = $name;
		$t->description = $desc;
		return $t;
	}
}
