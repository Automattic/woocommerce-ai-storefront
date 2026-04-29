<?php
/**
 * Tests for WC_AI_Storefront_UCP_REST_Controller::handle_catalog_search.
 *
 * Focuses on UCP → WC Store API query-param mapping and the
 * surrounding response-assembly logic. Uses Brain\Monkey to stub:
 *
 *   - rest_do_request       — captures the dispatched Store API
 *                             request + returns canned product lists
 *   - get_terms             — batch resolves category/tag/brand slug/name → IDs
 *   - wc_get_price_decimals — drives minor→presentment conversion
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpCatalogSearchTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Captured params on the outgoing GET /wc/store/v1/products request.
	 * Populated by the rest_do_request stub so tests can assert how
	 * UCP fields mapped onto Store API query params.
	 *
	 * @var array<string, mixed>
	 */
	private array $captured_store_params = [];

	/**
	 * Canned list response for GET /wc/store/v1/products. Tests set
	 * this to the Store API product array they want the handler to
	 * process.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $fake_product_list = [];

	/**
	 * HTTP status to return from the Store API list dispatch. Tests
	 * set this to 500 to exercise the internal-error path.
	 */
	private int $fake_list_status = 200;

	/**
	 * Response headers attached to the Store API list dispatch.
	 * Used to exercise the pagination-response-mapping logic
	 * (X-WP-Total / X-WP-TotalPages → UCP pagination shape). Tests
	 * set `X-WP-Total` / `X-WP-TotalPages` to simulate multi-page
	 * catalogs; empty headers mean "one page of results."
	 *
	 * @var array<string, string>
	 */
	private array $fake_list_headers = [];

	/**
	 * Per-ID canned responses for individual product fetches (used
	 * when the handler pre-fetches variations for a variable product).
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $fake_store_api = [];

	/**
	 * Fake get_terms store. Key = 'field:value' (e.g. 'slug:tops') or
	 * 'taxonomy:field:value' (e.g. 'product_tag:slug:summer').
	 * Value = fake term object with term_id, slug, and name properties.
	 *
	 * @var array<string, object>
	 */
	private array $fake_terms = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset settings between tests so disabled-state tests don't
		// leak into subsequent tests that assume enabled. The stub's
		// defaults include `enabled => yes`, so an empty array here
		// means the handler sees enabled syndication.
		WC_AI_Storefront::$test_settings = [];

		$this->captured_store_params = [];
		$this->fake_product_list     = [];
		$this->fake_list_status      = 200;
		$this->fake_list_headers     = [];
		$this->fake_store_api        = [];
		$this->fake_terms            = [];

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias(
			static fn( string $single, string $plural, int $number ): string => $number === 1 ? $single : $plural
		);
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		// Default stub for the `seller.name` in the seller block that
		// every product emits (see build_seller()). `wp_strip_all_tags`
		// and `html_entity_decode` aren't stubbed — the bootstrap
		// polyfill covers the former, and html_entity_decode is a
		// native PHP function that runs fine on the stubbed name.
		// Tests that care about seller content can override in-body.
		Functions\when( 'get_bloginfo' )->alias(
			static fn( string $key = '' ): string => 'name' === $key ? 'Example Store' : ''
		);

		// Simplified sanitize_title stub with a slightly-stricter
		// character class than default WP: we collapse underscores
		// AND whitespace AND other non-alphanumerics to dashes. WP
		// core actually preserves underscores, but the behavior is
		// hookable via the `sanitize_title` filter — plugins/themes
		// can make it aggressive. Our production code handles the
		// `pa_` prefix explicitly (strips + re-adds) so it's
		// underscore-agnostic; running tests against the stricter
		// stub gives us a safety margin that catches regressions if
		// someone refactors the prefix handling to rely on WP's
		// default behavior.
		Functions\when( 'sanitize_title' )->alias(
			static function ( $title ): string {
				$title = strtolower( trim( (string) $title ) );
				$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
				return trim( (string) $title, '-' );
			}
		);

		// taxonomy_exists stub — default behavior is permissive
		// (all pa_* taxonomies exist) so attribute filter tests that
		// don't specifically test the not-found path pass. Tests that
		// want to exercise `attribute_not_found` override this with
		// their own Functions\when() call to return false for specific
		// taxonomy names.
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( string $taxonomy ): bool => 0 === strpos( $taxonomy, 'pa_' )
				|| 'product_cat' === $taxonomy
				|| 'product_tag' === $taxonomy
				|| 'product_brand' === $taxonomy
		);

		$terms = &$this->fake_terms;
		Functions\when( 'get_terms' )->alias(
			static function ( array $args ) use ( &$terms ): array {
				// Simulates WP's batch term lookup. The production code
				// issues two calls per taxonomy: one for slugs (Pass 1)
				// and one for the slug-miss names (Pass 2). We look up
				// from $this->fake_terms using the same key scheme that
				// seed_term / seed_tag_term populate.
				$taxonomy = isset( $args['taxonomy'] ) ? (string) $args['taxonomy'] : '';
				$results  = array();
				$seen_ids = array();

				if ( ! empty( $args['slug'] ) && is_array( $args['slug'] ) ) {
					foreach ( $args['slug'] as $raw ) {
						// Production code pre-sanitizes slug inputs with
						// sanitize_title() before calling get_terms(), so
						// slugs here are already in canonical form. Apply
						// sanitize_title() defensively to keep the stub
						// correct even if the caller changes.
						$slug = sanitize_title( (string) $raw );
						// Taxonomy-namespaced key (product_tag, product_brand).
						$key = "{$taxonomy}:slug:{$slug}";
						if ( isset( $terms[ $key ] ) ) {
							$term = $terms[ $key ];
							if ( ! in_array( $term->term_id, $seen_ids, true ) ) {
								$results[]  = $term;
								$seen_ids[] = $term->term_id;
							}
							continue;
						}
						// Back-compat: product_cat terms stored un-namespaced
						// by seed_term().
						$key = "slug:{$slug}";
						if ( isset( $terms[ $key ] ) ) {
							$term = $terms[ $key ];
							if ( ! in_array( $term->term_id, $seen_ids, true ) ) {
								$results[]  = $term;
								$seen_ids[] = $term->term_id;
							}
						}
					}
				}

				if ( ! empty( $args['name'] ) && is_array( $args['name'] ) ) {
					foreach ( $args['name'] as $name ) {
						// MySQL name comparison uses utf8mb4_unicode_ci which
						// is case-insensitive. Simulate by trying both the
						// exact name and the lowercase form so a query for
						// "tops" correctly returns the term stored as "Tops".
						$names_to_try = array_unique( [ $name, ucfirst( mb_strtolower( $name ) ), mb_strtolower( $name ) ] );
						$found        = false;
						foreach ( $names_to_try as $try ) {
							// Taxonomy-namespaced key (product_tag, product_brand).
							$key = "{$taxonomy}:name:{$try}";
							if ( isset( $terms[ $key ] ) ) {
								$term  = $terms[ $key ];
								$found = true;
								if ( ! in_array( $term->term_id, $seen_ids, true ) ) {
									$results[]  = $term;
									$seen_ids[] = $term->term_id;
								}
								break;
							}
							// Back-compat: product_cat terms stored un-namespaced
							// by seed_term().
							$key = "name:{$try}";
							if ( isset( $terms[ $key ] ) ) {
								$term  = $terms[ $key ];
								$found = true;
								if ( ! in_array( $term->term_id, $seen_ids, true ) ) {
									$results[]  = $term;
									$seen_ids[] = $term->term_id;
								}
								break;
							}
						}
						unset( $found ); // Suppress unused-var lint.
					}
				}

				return $results;
			}
		);

		$captured_params = &$this->captured_store_params;
		$list            = &$this->fake_product_list;
		$list_status     = &$this->fake_list_status;
		$list_headers    = &$this->fake_list_headers;
		$api             = &$this->fake_store_api;

		Functions\when( 'rest_do_request' )->alias(
			static function ( WP_REST_Request $request ) use (
				&$captured_params,
				&$list,
				&$list_status,
				&$list_headers,
				&$api
			) {
				$route = $request->get_route();

				if ( '/wc/store/v1/products' === $route ) {
					// Regression guard: the controller must NEVER use the
					// collection endpoint to fetch variation IDs. Variations
					// have post_type='product_variation'; the collection
					// endpoint filters to post_type='product' and silently
					// drops them. Variation fetches must use the single-item
					// route (/wc/store/v1/products/{id}) instead.
					$include = $request->get_param( 'include' );
					if ( is_array( $include ) && ! empty( $include ) ) {
						throw new \LogicException(
							'Controller must not use the collection endpoint with ?include for variations. ' .
							'Use the single-item route /wc/store/v1/products/{id} instead.'
						);
					}

					// List dispatch — capture the params so tests can
					// assert on the UCP→Store-API mapping, then return
					// the canned product list. 1.6.0 added `page` +
					// `per_page` to the captured list for pagination
					// mapping assertions.
					foreach (
						[
							'search',
							'category',
							'min_price',
							'max_price',
							'page',
							'per_page',
							'tag',
							'on_sale',
							'stock_status',
							'featured',
							'rating',
							'attributes',
							'orderby',
							'order',
							'brand',
						] as $key
					) {
						$val = $request->get_param( $key );
						if ( null !== $val ) {
							$captured_params[ $key ] = $val;
						}
					}
					$response = new WP_REST_Response( $list, $list_status );
					foreach ( $list_headers as $name => $value ) {
						$response->header( $name, $value );
					}
					return $response;
				}

				if ( preg_match( '#^/wc/store/v1/products/(\d+)$#', $route, $m ) ) {
					$id = (int) $m[1];
					if ( ! array_key_exists( $id, $api ) || null === $api[ $id ] ) {
						return new WP_REST_Response( null, 404 );
					}
					return new WP_REST_Response( $api[ $id ], 200 );
				}

				return new WP_REST_Response( null, 500 );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Test helpers
	// ------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $body
	 */
	private function search_request( array $body = [] ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/ucp/v1/catalog/search' );
		$request->set_json_params( $body );
		return $request;
	}

	/**
	 * Build a minimal Store API product fixture for the list response.
	 *
	 * @return array<string, mixed>
	 */
	private function make_simple_product( int $id, string $name ): array {
		return [
			'id'                => $id,
			'name'              => $name,
			'type'              => 'simple',
			'short_description' => '',
			'is_in_stock'       => true,
			'prices'            => [
				'price'               => '2500',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
			],
		];
	}

	/**
	 * Register a fake product_cat term that `get_terms` batch lookups
	 * will resolve. Un-namespaced keys preserved for back-compat with
	 * the original pre-1.8 tests.
	 */
	private function seed_term( int $term_id, string $slug, string $name ): void {
		$term                              = (object) [
			'term_id' => $term_id,
			'slug'    => $slug,
			'name'    => $name,
		];
		$this->fake_terms[ "slug:{$slug}" ] = $term;
		$this->fake_terms[ "name:{$name}" ] = $term;
	}

	/**
	 * Register a fake product_tag term that `get_terms` batch lookups
	 * against the `product_tag` taxonomy will resolve. Namespaced
	 * by taxonomy so cat and tag lookups don't collide when the
	 * same slug is used in both taxonomies (merchants do this).
	 */
	private function seed_tag_term( int $term_id, string $slug, string $name ): void {
		$term                                                 = (object) [
			'term_id' => $term_id,
			'slug'    => $slug,
			'name'    => $name,
		];
		$this->fake_terms[ "product_tag:slug:{$slug}" ]      = $term;
		$this->fake_terms[ "product_tag:name:{$name}" ]      = $term;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function successful_search( array $body ): array {
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_catalog_search( $this->search_request( $body ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );

		return $response->get_data();
	}

	// ------------------------------------------------------------------
	// Happy path + response shape
	// ------------------------------------------------------------------

	public function test_empty_body_returns_all_products_with_envelope(): void {
		$this->fake_product_list = [
			$this->make_simple_product( 1, 'Alpha' ),
			$this->make_simple_product( 2, 'Beta' ),
		];

		$body = $this->successful_search( [] );

		$this->assertCount( 2, $body['products'] );
		$this->assertEquals( 'prod_1', $body['products'][0]['id'] );
		$this->assertArrayHasKey(
			'dev.ucp.shopping.catalog.search',
			$body['ucp']['capabilities']
		);
	}

	public function test_search_stamps_seller_name_on_every_product(): void {
		// Integration test for the build_seller() thread-through:
		// the controller computes seller once and passes it into
		// each translate() call. We assert every product carries
		// the same seller block — catching a regression where the
		// threading accidentally drops or diverges across the loop.
		$this->fake_product_list = [
			$this->make_simple_product( 1, 'Alpha' ),
			$this->make_simple_product( 2, 'Beta' ),
		];

		$body = $this->successful_search( [] );

		foreach ( $body['products'] as $product ) {
			$this->assertArrayHasKey( 'seller', $product );
			$this->assertSame( 'Example Store', $product['seller']['name'] );
		}
	}

	public function test_empty_result_returns_200_with_empty_products_array(): void {
		// No canned products → Store API returns `[]` → response body
		// has products = []. Still 200 (no error), still has envelope.
		$this->fake_product_list = [];

		$body = $this->successful_search( [] );

		$this->assertEquals( [], $body['products'] );
		$this->assertArrayHasKey( 'ucp', $body );
	}

	// ------------------------------------------------------------------
	// Query mapping
	// ------------------------------------------------------------------

	public function test_query_field_maps_to_store_api_search_param(): void {
		$this->successful_search( [ 'query' => 'blue shirt' ] );

		$this->assertEquals( 'blue shirt', $this->captured_store_params['search'] );
	}

	public function test_empty_query_string_is_not_forwarded(): void {
		// An empty query isn't the same as "search for empty string" —
		// WC would treat "" as a no-op anyway, but keeping it out of
		// the param list is cleaner.
		$this->successful_search( [ 'query' => '' ] );

		$this->assertArrayNotHasKey( 'search', $this->captured_store_params );
	}

	public function test_non_string_query_is_ignored(): void {
		// Defensive: agents sending wrong types should not leak into
		// the Store API params.
		$this->successful_search( [ 'query' => 123 ] );

		$this->assertArrayNotHasKey( 'search', $this->captured_store_params );
	}

	// ------------------------------------------------------------------
	// Category mapping
	// ------------------------------------------------------------------

	public function test_category_slug_resolves_to_term_id(): void {
		$this->seed_term( 42, 'tops', 'Tops' );

		$this->successful_search(
			[ 'filters' => [ 'categories' => [ 'tops' ] ] ]
		);

		$this->assertEquals( '42', $this->captured_store_params['category'] );
	}

	public function test_category_name_falls_back_when_slug_missing(): void {
		// The translator currently emits category names, so an agent
		// echoing back what it received should still work — we try slug
		// first, then name as a fallback.
		$this->seed_term( 99, 'clothing-tops', 'Tops' );
		// Wipe the slug index to simulate an agent using the name only.
		unset( $this->fake_terms['slug:clothing-tops'] );

		$this->successful_search(
			[ 'filters' => [ 'categories' => [ 'Tops' ] ] ]
		);

		$this->assertEquals( '99', $this->captured_store_params['category'] );
	}

	public function test_category_name_fallback_is_case_insensitive(): void {
		// Regression guard: the DB comparison for name is case-insensitive
		// (MySQL utf8mb4_unicode_ci), but PHP array key lookups are case-
		// sensitive. Without mb_strtolower() normalisation on both sides,
		// an agent sending "tops" when the stored name is "Tops" would
		// fail to resolve the term even though the DB returned it.
		$this->seed_term( 99, 'clothing-tops', 'Tops' );
		unset( $this->fake_terms['slug:clothing-tops'] );

		// Lowercase input — must still resolve.
		$this->successful_search(
			[ 'filters' => [ 'categories' => [ 'tops' ] ] ]
		);
		$this->assertEquals( '99', $this->captured_store_params['category'] );
	}

	public function test_slug_pass_pre_sanitizes_input_before_db_query(): void {
		// Regression guard: get_terms() does NOT call sanitize_title() on
		// the slug array internally. Without pre-sanitization, "Women's
		// Clothing" never resolves to slug "womens-clothing". The fix
		// maps all slug inputs through sanitize_title() before the query.
		$this->seed_term( 77, 'womens-clothing', "Women's Clothing" );

		// Agent input with apostrophe — sanitize_title() normalises it to
		// the canonical slug form used in the DB.
		$this->successful_search(
			[ 'filters' => [ 'categories' => [ "Women's Clothing" ] ] ]
		);
		$this->assertEquals( '77', $this->captured_store_params['category'] );
	}

	public function test_multiple_categories_join_as_comma_separated_ids(): void {
		$this->seed_term( 5, 'tops', 'Tops' );
		$this->seed_term( 7, 'bottoms', 'Bottoms' );

		$this->successful_search(
			[ 'filters' => [ 'categories' => [ 'tops', 'bottoms' ] ] ]
		);

		$this->assertEquals( '5,7', $this->captured_store_params['category'] );
	}

	public function test_unresolvable_categories_forward_only_resolvable_ones(): void {
		// A single unresolvable category + one resolvable = only the
		// resolvable one flows through to Store API.
		$this->seed_term( 5, 'tops', 'Tops' );

		$this->successful_search(
			[ 'filters' => [ 'categories' => [ 'tops', 'nonexistent' ] ] ]
		);

		$this->assertEquals( '5', $this->captured_store_params['category'] );
	}

	public function test_unresolvable_category_produces_category_not_found_warning(): void {
		// Agent sending an unknown category must see a warning that
		// their filter was ignored — otherwise they'd receive the
		// unfiltered catalog (the OPPOSITE of what they asked for)
		// with no signal that anything went wrong. This was flagged
		// as a silent-failure bug in the pre-1.3 review.
		$this->seed_term( 5, 'tops', 'Tops' );

		$body = $this->successful_search(
			[ 'filters' => [ 'categories' => [ 'tops', 'nonexistent' ] ] ]
		);

		$this->assertArrayHasKey( 'messages', $body );
		$not_found = array_filter(
			$body['messages'],
			static fn( array $m ): bool => 'category_not_found' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $not_found );

		$message = array_values( $not_found )[0];
		$this->assertEquals( 'warning', $message['type'] );
		$this->assertEquals( 'advisory', $message['severity'] );
		// JSONPath points at the exact offending input index.
		$this->assertEquals( '$.filters.categories[1]', $message['path'] );
	}

	public function test_all_unresolvable_categories_omits_param_and_warns(): void {
		// When EVERY category fails to resolve, Store API gets no
		// `category` param (would otherwise be `category=` which is
		// invalid). Response still includes one warning per missing
		// input so the agent knows their whole filter was dropped.
		$body = $this->successful_search(
			[ 'filters' => [ 'categories' => [ 'nope', 'also-nope' ] ] ]
		);

		$this->assertArrayNotHasKey( 'category', $this->captured_store_params );

		$warnings = array_filter(
			$body['messages'],
			static fn( array $m ): bool => 'category_not_found' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 2, $warnings );
	}

	public function test_duplicate_category_slug_and_name_dedupe_to_single_id(): void {
		// If both slug and name point at the same term (common when
		// agents echo back categories from our search response, which
		// emits names), the handler must send a single term ID to
		// Store API — not `category=123,123` which is ugly and, on
		// some WC versions, caches differently than `category=123`.
		$this->seed_term( 42, 'tops', 'Tops' );

		$this->successful_search(
			[ 'filters' => [ 'categories' => [ 'tops', 'Tops' ] ] ]
		);

		$this->assertEquals( '42', $this->captured_store_params['category'] );
	}

	// ------------------------------------------------------------------
	// 1.8.0: Tag filter mapping
	// ------------------------------------------------------------------

	public function test_tag_slug_resolves_and_forwards_to_store_api(): void {
		$this->seed_tag_term( 55, 'summer', 'Summer' );

		$this->successful_search(
			[ 'filters' => [ 'tags' => [ 'summer' ] ] ]
		);

		$this->assertEquals( '55', $this->captured_store_params['tag'] );
	}

	public function test_multiple_tags_comma_separate(): void {
		$this->seed_tag_term( 11, 'summer', 'Summer' );
		$this->seed_tag_term( 12, 'eco', 'Eco' );

		$this->successful_search(
			[ 'filters' => [ 'tags' => [ 'summer', 'eco' ] ] ]
		);

		$this->assertEquals( '11,12', $this->captured_store_params['tag'] );
	}

	public function test_unresolvable_tag_produces_tag_not_found_warning(): void {
		// Symmetric with the category_not_found warning behavior —
		// agents must see a signal that their filter was ignored.
		$this->seed_tag_term( 11, 'summer', 'Summer' );

		$body = $this->successful_search(
			[ 'filters' => [ 'tags' => [ 'summer', 'winter' ] ] ]
		);

		$not_found = array_filter(
			$body['messages'] ?? [],
			static fn( array $m ): bool => 'tag_not_found' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $not_found );
	}

	// ------------------------------------------------------------------
	// 1.8.0: on_sale filter
	// ------------------------------------------------------------------

	public function test_on_sale_filter_forwards_boolean_true(): void {
		$this->successful_search(
			[ 'filters' => [ 'on_sale' => true ] ]
		);

		$this->assertTrue( $this->captured_store_params['on_sale'] );
	}

	public function test_on_sale_filter_accepts_string_true_from_json(): void {
		// Some REST clients (historically, the WP REST API itself)
		// pass booleans as strings; accepting "true" keeps the API
		// forgiving without losing the opt-in intent.
		$this->successful_search(
			[ 'filters' => [ 'on_sale' => 'true' ] ]
		);

		$this->assertTrue( $this->captured_store_params['on_sale'] );
	}

	public function test_on_sale_filter_false_does_not_forward(): void {
		// `on_sale: false` is the equivalent of "don't filter" — WC
		// Store API treats an absent param as "return everything,"
		// so we don't forward an explicit false.
		$this->successful_search(
			[ 'filters' => [ 'on_sale' => false ] ]
		);

		$this->assertArrayNotHasKey( 'on_sale', $this->captured_store_params );
	}

	// ------------------------------------------------------------------
	// 1.9.0: in_stock filter
	// ------------------------------------------------------------------

	public function test_in_stock_filter_forwards_instock_stock_status(): void {
		$this->successful_search(
			[ 'filters' => [ 'in_stock' => true ] ]
		);

		$this->assertSame( [ 'instock' ], $this->captured_store_params['stock_status'] );
	}

	public function test_in_stock_filter_accepts_string_true(): void {
		$this->successful_search(
			[ 'filters' => [ 'in_stock' => 'true' ] ]
		);

		$this->assertSame( [ 'instock' ], $this->captured_store_params['stock_status'] );
	}

	public function test_in_stock_filter_false_does_not_forward(): void {
		$this->successful_search(
			[ 'filters' => [ 'in_stock' => false ] ]
		);

		$this->assertArrayNotHasKey( 'stock_status', $this->captured_store_params );
	}

	// ------------------------------------------------------------------
	// 1.9.0: featured filter
	// ------------------------------------------------------------------

	public function test_featured_filter_forwards_boolean_true(): void {
		$this->successful_search(
			[ 'filters' => [ 'featured' => true ] ]
		);

		$this->assertTrue( $this->captured_store_params['featured'] );
	}

	public function test_featured_filter_false_does_not_forward(): void {
		$this->successful_search(
			[ 'filters' => [ 'featured' => false ] ]
		);

		$this->assertArrayNotHasKey( 'featured', $this->captured_store_params );
	}

	public function test_featured_filter_accepts_string_true(): void {
		// Symmetric with on_sale / in_stock — stringy "true" from
		// JSON-to-PHP round trips should be honored so the contract
		// is consistent across boolean-flag filters.
		$this->successful_search(
			[ 'filters' => [ 'featured' => 'true' ] ]
		);

		$this->assertTrue( $this->captured_store_params['featured'] );
	}

	// ------------------------------------------------------------------
	// 1.9.0: min_rating filter
	// ------------------------------------------------------------------

	public function test_min_rating_4_expands_to_ratings_4_and_5(): void {
		// Store API's `rating` param is an array of acceptable
		// ratings (set inclusion), not a floor. `min_rating: 4`
		// must expand to [4, 5] for "4 stars and above."
		$this->successful_search(
			[ 'filters' => [ 'min_rating' => 4 ] ]
		);

		$this->assertSame( [ 4, 5 ], $this->captured_store_params['rating'] );
	}

	public function test_min_rating_1_expands_to_full_range(): void {
		$this->successful_search(
			[ 'filters' => [ 'min_rating' => 1 ] ]
		);

		$this->assertSame( [ 1, 2, 3, 4, 5 ], $this->captured_store_params['rating'] );
	}

	public function test_min_rating_out_of_range_is_clamped(): void {
		// Values above 5 clamp to 5 (produces [5]); values below 1
		// clamp to 1 (full range). Keeps the array non-empty and
		// the filter semantically coherent.
		$this->successful_search(
			[ 'filters' => [ 'min_rating' => 99 ] ]
		);

		$this->assertSame( [ 5 ], $this->captured_store_params['rating'] );
	}

	// ------------------------------------------------------------------
	// 1.9.0: attribute filters
	// ------------------------------------------------------------------

	public function test_attribute_filter_prefixes_bare_labels_with_pa(): void {
		$this->successful_search(
			[ 'filters' => [ 'attributes' => [ 'color' => [ 'red' ] ] ] ]
		);

		$this->assertSame(
			[
				[
					'attribute' => 'pa_color',
					'slug'      => [ 'red' ],
					'operator'  => 'in',
				],
			],
			$this->captured_store_params['attributes']
		);
	}

	public function test_attribute_filter_preserves_pa_prefix_when_already_present(): void {
		$this->successful_search(
			[ 'filters' => [ 'attributes' => [ 'pa_brand' => [ 'nike' ] ] ] ]
		);

		$this->assertSame( 'pa_brand', $this->captured_store_params['attributes'][0]['attribute'] );
	}

	public function test_attribute_filter_lowercases_slug_values(): void {
		$this->successful_search(
			[ 'filters' => [ 'attributes' => [ 'size' => [ 'M', 'XL' ] ] ] ]
		);

		$this->assertSame( [ 'm', 'xl' ], $this->captured_store_params['attributes'][0]['slug'] );
	}

	public function test_attribute_filter_emits_multiple_taxonomy_entries(): void {
		$this->successful_search(
			[
				'filters' => [
					'attributes' => [
						'color' => [ 'red' ],
						'size'  => [ 'M' ],
					],
				],
			]
		);

		// Assert full shape of both entries (not just count) so a
		// regression that swapped slugs between axes or defaulted
		// the second entry's operator to something other than 'in'
		// would be caught.
		$this->assertCount( 2, $this->captured_store_params['attributes'] );

		$by_attribute = [];
		foreach ( $this->captured_store_params['attributes'] as $entry ) {
			$by_attribute[ $entry['attribute'] ] = $entry;
		}
		$this->assertArrayHasKey( 'pa_color', $by_attribute );
		$this->assertArrayHasKey( 'pa_size', $by_attribute );
		$this->assertSame( [ 'red' ], $by_attribute['pa_color']['slug'] );
		$this->assertSame( [ 'm' ], $by_attribute['pa_size']['slug'] );
		$this->assertSame( 'in', $by_attribute['pa_color']['operator'] );
		$this->assertSame( 'in', $by_attribute['pa_size']['operator'] );
	}

	public function test_attribute_filter_normalizes_mixed_case_pa_prefix(): void {
		// Regression: a case-sensitive `strpos($raw_key, 'pa_')` check
		// on mixed-case input like `"PA_Color"` would fall into the
		// else branch and produce taxonomy `pa_pa_color` (double-
		// prefixed) — silent zero-results misrouting. Fix: run
		// sanitize_title() before the prefix check so we compare
		// against the lowercased form.
		$this->successful_search(
			[
				'filters' => [
					'attributes' => [
						'PA_Color' => [ 'red' ],
					],
				],
			]
		);

		$this->assertSame( 'pa_color', $this->captured_store_params['attributes'][0]['attribute'] );
	}

	public function test_attribute_filter_skips_empty_arrays(): void {
		// Malformed input — one axis has values, another is empty.
		// The empty axis should be dropped rather than poison the
		// whole filter list.
		$this->successful_search(
			[
				'filters' => [
					'attributes' => [
						'color' => [ 'red' ],
						'size'  => [],
					],
				],
			]
		);

		$this->assertCount( 1, $this->captured_store_params['attributes'] );
		$this->assertSame( 'pa_color', $this->captured_store_params['attributes'][0]['attribute'] );
	}

	public function test_attribute_filter_deduplicates_slugs(): void {
		$this->successful_search(
			[
				'filters' => [
					'attributes' => [
						'color' => [ 'red', 'RED', 'Red' ],
					],
				],
			]
		);

		$this->assertSame( [ 'red' ], $this->captured_store_params['attributes'][0]['slug'] );
	}

	public function test_attribute_filter_skips_non_scalar_slug_values(): void {
		// Defensive: a client sending a nested array as a slug value
		// would coerce to "Array" via (string) cast and silently
		// forward as a bogus slug. Skip non-scalar entries entirely.
		$this->successful_search(
			[
				'filters' => [
					'attributes' => [
						'color' => [ 'red', [ 'nested' ], null, 'blue' ],
					],
				],
			]
		);

		$this->assertSame(
			[ 'red', 'blue' ],
			$this->captured_store_params['attributes'][0]['slug']
		);
	}

	public function test_attribute_filter_skips_empty_taxonomy_key(): void {
		// Empty/whitespace-only keys would collapse to taxonomy `pa_`
		// which Store API silently accepts as unknown and returns no
		// results — leaving the agent with no signal that their input
		// was malformed. Drop the axis entirely.
		$this->successful_search(
			[
				'filters' => [
					'attributes' => [
						''    => [ 'red' ],
						' '   => [ 'blue' ],
						'size' => [ 'M' ],
					],
				],
			]
		);

		$this->assertCount( 1, $this->captured_store_params['attributes'] );
		$this->assertSame( 'pa_size', $this->captured_store_params['attributes'][0]['attribute'] );
	}

	public function test_attribute_filter_skips_numeric_keys_from_list_shaped_input(): void {
		// Regression: a malformed list-shaped input like
		// `filters.attributes: [["red"], ["M"]]` has integer keys
		// (0, 1, ...) which would cast to strings and forward as
		// taxonomies `pa_0`, `pa_1`. Those match nothing and would
		// silently restrict the catalog to zero results. Drop
		// numeric keys entirely since attribute axes are always
		// named strings.
		$this->successful_search(
			[
				'filters' => [
					'attributes' => [
						0       => [ 'red' ],
						1       => [ 'M' ],
						'color' => [ 'blue' ],
					],
				],
			]
		);

		$this->assertCount( 1, $this->captured_store_params['attributes'] );
		$this->assertSame( 'pa_color', $this->captured_store_params['attributes'][0]['attribute'] );
	}

	public function test_attribute_filter_skips_bare_pa_prefix_key(): void {
		// A key of exactly "pa_" is either a typo or a crafted
		// poison input — either way it's not a real taxonomy and
		// shouldn't be forwarded.
		$this->successful_search(
			[
				'filters' => [
					'attributes' => [
						'pa_' => [ 'red' ],
					],
				],
			]
		);

		$this->assertArrayNotHasKey( 'attributes', $this->captured_store_params );
	}

	public function test_attribute_filter_sanitize_title_normalizes_multiword_slugs(): void {
		// Multi-word attribute values like "Light Blue" should
		// collapse to the WP-canonical slug "light-blue" (the form
		// WC stores in the DB), not the naive strtolower "light blue"
		// which is an invalid slug.
		$this->successful_search(
			[
				'filters' => [
					'attributes' => [
						'color' => [ 'Light Blue', 'Navy Blue' ],
					],
				],
			]
		);

		$this->assertSame(
			[ 'light-blue', 'navy-blue' ],
			$this->captured_store_params['attributes'][0]['slug']
		);
	}

	public function test_attribute_filter_sanitize_title_normalizes_multiword_taxonomy_keys(): void {
		// Same as slug normalization but for the taxonomy key — a
		// merchant label like "Fabric Type" should produce
		// `pa_fabric-type`, not `pa_fabric type` (invalid) or
		// `pa_Fabric Type` (case-sensitive mismatch).
		$this->successful_search(
			[
				'filters' => [
					'attributes' => [
						'Fabric Type' => [ 'cotton' ],
					],
				],
			]
		);

		$this->assertSame(
			'pa_fabric-type',
			$this->captured_store_params['attributes'][0]['attribute']
		);
	}

	public function test_attribute_filter_unknown_taxonomy_emits_attribute_not_found_warning(): void {
		// Agent sends a filter on `nonexistent` — no `pa_nonexistent`
		// taxonomy registered on the store. Symmetric with
		// `category_not_found` / `tag_not_found`: emit a warning
		// pointing at the offending axis + drop the filter rather
		// than forward a nonsense taxonomy that would silently
		// return zero results.
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( string $taxonomy ): bool => 'pa_color' === $taxonomy
		);

		$body = $this->successful_search(
			[
				'filters' => [
					'attributes' => [
						'color'       => [ 'red' ],
						'nonexistent' => [ 'anything' ],
					],
				],
			]
		);

		// Known taxonomy forwarded.
		$this->assertCount( 1, $this->captured_store_params['attributes'] );
		$this->assertSame( 'pa_color', $this->captured_store_params['attributes'][0]['attribute'] );

		// Unknown taxonomy surfaces as a warning.
		$warnings = array_filter(
			$body['messages'] ?? [],
			static fn( array $m ): bool => 'attribute_not_found' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $warnings );
		$warning = array_values( $warnings )[0];
		$this->assertStringContainsString( 'pa_nonexistent', $warning['content'] );
		// JSONPath bracket notation — identifier-style keys still work
		// with quoted brackets, just more verbose than dot notation.
		$this->assertSame( "\$.filters.attributes['nonexistent']", $warning['path'] );
	}

	public function test_attribute_not_found_path_uses_bracket_notation_for_non_identifier_keys(): void {
		// Regression: dot notation `$.filters.attributes.Fabric Type`
		// is invalid JSONPath — agents that parse the path
		// machine-side need bracket notation for keys with spaces,
		// hyphens, quotes, or other non-identifier characters.
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		$body = $this->successful_search(
			[
				'filters' => [
					'attributes' => [
						'Fabric Type' => [ 'cotton' ],
					],
				],
			]
		);

		$warnings = array_values(
			array_filter(
				$body['messages'] ?? [],
				static fn( array $m ): bool => 'attribute_not_found' === ( $m['code'] ?? '' )
			)
		);
		$this->assertCount( 1, $warnings );
		$this->assertSame( "\$.filters.attributes['Fabric Type']", $warnings[0]['path'] );
	}

	public function test_attribute_not_found_path_escapes_single_quotes_in_keys(): void {
		// Defensive: a key containing a single quote (`"foo's"`) would
		// break the bracket-notation string without escaping. Escape
		// backslashes first, then single quotes — standard JSONPath
		// quoted-string conventions.
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		$body = $this->successful_search(
			[
				'filters' => [
					'attributes' => [
						"foo's bar" => [ 'value' ],
					],
				],
			]
		);

		$warnings = array_values(
			array_filter(
				$body['messages'] ?? [],
				static fn( array $m ): bool => 'attribute_not_found' === ( $m['code'] ?? '' )
			)
		);
		$this->assertCount( 1, $warnings );
		// The \' is a literal backslash-quote in the JSONPath string,
		// which resolves back to the original `'` when parsed.
		$this->assertSame( "\$.filters.attributes['foo\\'s bar']", $warnings[0]['path'] );
	}

	// ------------------------------------------------------------------
	// 1.9.0: Brand filter
	// ------------------------------------------------------------------

	public function test_brand_filter_forwards_resolved_term_ids(): void {
		// Parallel to tags: slug → term ID resolution, comma-joined
		// when multiple, forwarded as `brand` on the Store API.
		$term                                      = (object) [
			'term_id' => 88,
			'slug'    => 'acme',
			'name'    => 'ACME',
		];
		$this->fake_terms['product_brand:slug:acme'] = $term;

		$this->successful_search(
			[ 'filters' => [ 'brand' => [ 'acme' ] ] ]
		);

		$this->assertSame( '88', $this->captured_store_params['brand'] );
	}

	public function test_brand_filter_unresolvable_produces_brand_not_found_warning(): void {
		// Symmetric with `category_not_found` / `tag_not_found` —
		// agents must see a signal that their filter was ignored.
		$term                                      = (object) [
			'term_id' => 88,
			'slug'    => 'acme',
			'name'    => 'ACME',
		];
		$this->fake_terms['product_brand:slug:acme'] = $term;

		$body = $this->successful_search(
			[ 'filters' => [ 'brand' => [ 'acme', 'unknown-brand' ] ] ]
		);

		$not_found = array_filter(
			$body['messages'] ?? [],
			static fn( array $m ): bool => 'brand_not_found' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $not_found );
	}

	// ------------------------------------------------------------------
	// 1.9.0: sort order
	// ------------------------------------------------------------------

	public function test_sort_price_asc_forwards_orderby_and_order(): void {
		$this->successful_search(
			[ 'sort' => [ 'field' => 'price', 'direction' => 'asc' ] ]
		);

		$this->assertSame( 'price', $this->captured_store_params['orderby'] );
		$this->assertSame( 'asc', $this->captured_store_params['order'] );
	}

	public function test_sort_newest_maps_to_date_desc_regardless_of_direction(): void {
		// `newest` is an alias that implies descending. Even if the
		// caller passes `direction: asc` we normalize to desc — the
		// concept "newest ascending" is self-contradicting.
		$this->successful_search(
			[ 'sort' => [ 'field' => 'newest', 'direction' => 'asc' ] ]
		);

		$this->assertSame( 'date', $this->captured_store_params['orderby'] );
		$this->assertSame( 'desc', $this->captured_store_params['order'] );
	}

	public function test_sort_popularity_is_supported(): void {
		$this->successful_search(
			[ 'sort' => [ 'field' => 'popularity', 'direction' => 'desc' ] ]
		);

		$this->assertSame( 'popularity', $this->captured_store_params['orderby'] );
	}

	public function test_sort_unknown_field_emits_warning_and_does_not_forward(): void {
		$body = $this->successful_search(
			[ 'sort' => [ 'field' => 'bogus', 'direction' => 'asc' ] ]
		);

		$this->assertArrayNotHasKey( 'orderby', $this->captured_store_params );
		$warnings = array_filter(
			$body['messages'] ?? [],
			static fn( array $m ): bool => 'invalid_sort_field' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $warnings );
	}

	public function test_sort_defaults_direction_to_asc_when_unspecified(): void {
		$this->successful_search(
			[ 'sort' => [ 'field' => 'title' ] ]
		);

		$this->assertSame( 'asc', $this->captured_store_params['order'] );
	}

	public function test_sort_empty_field_silently_ignored(): void {
		// Empty-string field is a legitimate-but-meaningless input
		// (e.g. `sort: {direction: "asc"}` with field omitted serializes
		// that way from some JSON clients). The handler's contract is
		// silent-ignore, not warning — forwarding a bogus
		// `invalid_sort_field: ""` warning on every such request would
		// spam agents. A future refactor that drops the `'' !== $field`
		// guard would break this contract; this test locks it in.
		$body = $this->successful_search(
			[ 'sort' => [ 'field' => '', 'direction' => 'asc' ] ]
		);

		$this->assertArrayNotHasKey( 'orderby', $this->captured_store_params );
		$codes = array_column( $body['messages'] ?? [], 'code' );
		$this->assertNotContains( 'invalid_sort_field', $codes );
		$this->assertNotContains( 'invalid_sort_shape', $codes );
	}

	public function test_combined_filters_and_sort_all_forward(): void {
		// Most realistic agent shape: multiple filters + a sort in a
		// single request. Each feature has isolated tests; this one
		// catches cross-feature regressions (accidental key overwrite,
		// early return, loop-ordering bug in `map_ucp_search_to_store_api`)
		// that isolated tests would miss.
		$this->successful_search(
			[
				'filters' => [
					'in_stock'   => true,
					'featured'   => true,
					'attributes' => [ 'color' => [ 'red' ] ],
				],
				'sort'    => [ 'field' => 'price', 'direction' => 'asc' ],
			]
		);

		$this->assertSame( [ 'instock' ], $this->captured_store_params['stock_status'] );
		$this->assertTrue( $this->captured_store_params['featured'] );
		$this->assertSame( 'pa_color', $this->captured_store_params['attributes'][0]['attribute'] );
		$this->assertSame( 'price', $this->captured_store_params['orderby'] );
		$this->assertSame( 'asc', $this->captured_store_params['order'] );
	}

	public function test_sort_non_scalar_field_emits_invalid_sort_shape_warning(): void {
		// Regression: a non-scalar `sort.field` (e.g. an array) would
		// coerce to "Array" via (string) cast and trigger a misleading
		// `invalid_sort_field` warning with value "array". The shape
		// check now catches this early and emits `invalid_sort_shape`
		// so agents can distinguish "unknown field" from "malformed
		// input".
		$body = $this->successful_search(
			[ 'sort' => [ 'field' => [ 'price' ], 'direction' => 'asc' ] ]
		);

		$this->assertArrayNotHasKey( 'orderby', $this->captured_store_params );
		$warnings = array_filter(
			$body['messages'] ?? [],
			static fn( array $m ): bool => 'invalid_sort_shape' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $warnings );
	}

	public function test_sort_non_scalar_direction_emits_invalid_sort_shape_warning(): void {
		$body = $this->successful_search(
			[ 'sort' => [ 'field' => 'price', 'direction' => [ 'asc' ] ] ]
		);

		$this->assertArrayNotHasKey( 'orderby', $this->captured_store_params );
		$warnings = array_filter(
			$body['messages'] ?? [],
			static fn( array $m ): bool => 'invalid_sort_shape' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $warnings );
	}

	public function test_sort_invalid_sort_field_content_uses_original_raw_value(): void {
		// When emitting the `invalid_sort_field` warning content for an
		// unrecognized but legitimately-scalar field, we echo back the
		// original string (preserving case/whitespace the agent sent)
		// rather than the trimmed/lowercased form we used for lookup.
		// Makes the warning easier to correlate with the agent's
		// source input.
		$body = $this->successful_search(
			[ 'sort' => [ 'field' => '  BoGuS  ', 'direction' => 'asc' ] ]
		);

		$warnings = array_filter(
			$body['messages'] ?? [],
			static fn( array $m ): bool => 'invalid_sort_field' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $warnings );
		$warning = array_values( $warnings )[0];
		$this->assertStringContainsString( '  BoGuS  ', $warning['content'] );
	}

	// ------------------------------------------------------------------
	// Price mapping (minor units → presentment units)
	// ------------------------------------------------------------------

	public function test_min_price_converts_minor_units_to_decimal_string(): void {
		// UCP 1000 minor units at 2 decimals → "10.00" presentment.
		// WC Store API expects the decimal-string format.
		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'min' => 1000 ] ] ]
		);

		$this->assertEquals( '10.00', $this->captured_store_params['min_price'] );
	}

	public function test_max_price_converts_to_decimal_string(): void {
		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'max' => 5000 ] ] ]
		);

		$this->assertEquals( '50.00', $this->captured_store_params['max_price'] );
	}

	public function test_price_range_with_min_and_max_forwards_both(): void {
		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'min' => 1000, 'max' => 5000 ] ] ]
		);

		$this->assertEquals( '10.00', $this->captured_store_params['min_price'] );
		$this->assertEquals( '50.00', $this->captured_store_params['max_price'] );
	}

	public function test_zero_decimal_currency_produces_integer_string(): void {
		// JPY has 0 decimals. UCP 5000 at 0 decimals → "5000" (no
		// trailing decimal point). Verifies number_format() behaves
		// under zero-decimal currencies.
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );

		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'min' => 5000 ] ] ]
		);

		$this->assertEquals( '5000', $this->captured_store_params['min_price'] );
	}

	public function test_negative_prices_are_ignored(): void {
		// Defensive: a negative price is nonsense; ignore rather than
		// coercing to 0 (which would silently match all products) or
		// aborting with 400.
		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'min' => -100, 'max' => 5000 ] ] ]
		);

		$this->assertArrayNotHasKey( 'min_price', $this->captured_store_params );
		$this->assertEquals( '50.00', $this->captured_store_params['max_price'] );
	}

	public function test_non_numeric_price_is_ignored(): void {
		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'min' => 'cheap' ] ] ]
		);

		$this->assertArrayNotHasKey( 'min_price', $this->captured_store_params );
	}

	public function test_non_integer_numeric_prices_are_ignored(): void {
		// UCP minor-unit amounts must be integers. `is_numeric()`
		// would accept decimals ("25.00" → (int) 25, silently
		// dropping cents) and scientific notation ("1e3"), so we
		// use strict integer-shape validation. Regression guard:
		// these shapes should NOT produce min_price/max_price
		// params on the Store API call.
		$decimal_shaped = [
			[ 'min' => '25.00' ],       // decimal string — loses cents on (int) cast
			[ 'min' => 25.5 ],          // native float — same problem
			[ 'max' => '1e3' ],         // scientific notation
			[ 'max' => '  100' ],       // whitespace-padded
			[ 'min' => '-50' ],         // negative string — ctype_digit false
			[ 'min' => str_repeat( '9', 30 ) ], // overflow — would saturate to PHP_INT_MAX on (int) cast
		];
		foreach ( $decimal_shaped as $price ) {
			$this->captured_store_params = [];
			$this->successful_search(
				[ 'filters' => [ 'price' => $price ] ]
			);
			// Use native json_encode in assertion messages — wp_json_encode
			// is a WP core function not reliably stubbed in this unit
			// test environment (see `wp_json_encode_or_native` helper in
			// UcpTest.php for the historical rationale). Assertion
			// messages run on failure, so an unstubbed call would fatal
			// the test runner instead of showing the actual mismatch.
			$this->assertArrayNotHasKey(
				'min_price',
				$this->captured_store_params,
				'min_price should not be forwarded for non-integer price: ' . json_encode( $price )
			);
			$this->assertArrayNotHasKey(
				'max_price',
				$this->captured_store_params,
				'max_price should not be forwarded for non-integer price: ' . json_encode( $price )
			);
		}

		// Positive control: native int + digit-only string both
		// accepted — confirms the validator isn't over-strict.
		$this->captured_store_params = [];
		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'min' => 1000, 'max' => '5000' ] ] ]
		);
		$this->assertArrayHasKey( 'min_price', $this->captured_store_params );
		$this->assertArrayHasKey( 'max_price', $this->captured_store_params );
	}

	// ------------------------------------------------------------------
	// context.currency + price filter (PR J)
	// ------------------------------------------------------------------

	public function test_price_filter_applied_when_context_currency_matches_store(): void {
		// Spec: "When context.currency matches the presentment
		// currency, businesses apply the filter directly." Store is
		// stubbed as USD; agent sends USD → filter applies.
		$this->successful_search(
			[
				'context' => [ 'currency' => 'USD' ],
				'filters' => [ 'price' => [ 'min' => 1000, 'max' => 5000 ] ],
			]
		);

		$this->assertArrayHasKey( 'min_price', $this->captured_store_params );
		$this->assertArrayHasKey( 'max_price', $this->captured_store_params );
	}

	public function test_price_filter_applied_when_context_currency_absent(): void {
		// Spec: "When context.currency is absent, filter denomination
		// is ambiguous and businesses MAY ignore it." We choose the
		// lenient interpretation — apply in the store's currency, since
		// our price_range responses always carry the store currency
		// and agents derive filter bounds from them.
		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'min' => 1000 ] ] ]
		);

		$this->assertArrayHasKey( 'min_price', $this->captured_store_params );
	}

	public function test_price_filter_dropped_with_warning_when_context_currency_mismatches(): void {
		// Spec: "When [currency] differs, businesses SHOULD convert
		// filter values... if conversion is not supported, businesses
		// MAY ignore the filter and SHOULD indicate this via a message."
		// We don't carry FX rates → drop + warn.
		$body = $this->successful_search(
			[
				'context' => [ 'currency' => 'EUR' ],
				'filters' => [ 'price' => [ 'min' => 1000, 'max' => 5000 ] ],
			]
		);

		// Price filter NOT forwarded to Store API.
		$this->assertArrayNotHasKey( 'min_price', $this->captured_store_params );
		$this->assertArrayNotHasKey( 'max_price', $this->captured_store_params );

		// Warning emitted with spec-conformant code + path.
		$codes = array_column( $body['messages'] ?? [], 'code' );
		$this->assertContains( 'currency_conversion_unsupported', $codes );
	}

	public function test_currency_comparison_is_case_insensitive(): void {
		// Spec doesn't require case-normalization, but agents may send
		// "usd" or "Usd" from loose clients. Store-side we use
		// `strtoupper` before comparing so either form works.
		$this->successful_search(
			[
				'context' => [ 'currency' => 'usd' ],
				'filters' => [ 'price' => [ 'min' => 1000 ] ],
			]
		);

		$this->assertArrayHasKey( 'min_price', $this->captured_store_params );
	}

	public function test_malformed_currency_is_treated_as_absent(): void {
		// Empty string, too-short, too-long, or non-alpha currency
		// values are invalid per ISO 4217. We treat them as "absent"
		// rather than "mismatched" — so the price filter applies in
		// the store's currency instead of being dropped. This
		// prevents a hostile agent from bloating warning payloads
		// with a giant "currency" value and prevents an empty string
		// (falsy but string-typed) from silently dropping valid
		// filters.
		foreach ( [ '', 'US', 'USDX', '123', str_repeat( 'A', 500 ) ] as $bad ) {
			$this->captured_store_params = [];
			$this->successful_search(
				[
					'context' => [ 'currency' => $bad ],
					'filters' => [ 'price' => [ 'min' => 1000 ] ],
				]
			);
			$this->assertArrayHasKey(
				'min_price',
				$this->captured_store_params,
				"Malformed currency (" . var_export( $bad, true ) . ") should be treated as absent and allow the price filter"
			);
		}
	}

	public function test_currency_mismatch_warning_suppressed_when_price_has_no_usable_bounds(): void {
		// A price object with no numeric min/max (empty object or
		// non-numeric values) is effectively a no-op filter. Emitting
		// a `currency_conversion_unsupported` warning for it produces
		// noise the agent can't act on — no bounds got skipped, so
		// there's nothing to convert or drop. The mismatch check runs
		// only when at least one bound would otherwise be applied.
		$no_op_price_payloads = [
			[],
			[ 'min' => 'cheap' ],
			[ 'max' => null ],
			[ 'min' => -5, 'max' => 'expensive' ],
		];
		foreach ( $no_op_price_payloads as $price ) {
			$body = $this->successful_search(
				[
					'context' => [ 'currency' => 'EUR' ],
					'filters' => [ 'price' => $price ],
				]
			);

			$codes = array_column( $body['messages'] ?? [], 'code' );
			$this->assertNotContains(
				'currency_conversion_unsupported',
				$codes,
				'No-op price filter ' . json_encode( $price ) . ' should not produce a currency warning'
			);
			$this->assertArrayNotHasKey( 'min_price', $this->captured_store_params );
			$this->assertArrayNotHasKey( 'max_price', $this->captured_store_params );
		}
	}

	// ------------------------------------------------------------------
	// Malformed input is ignored, not an error
	// ------------------------------------------------------------------

	public function test_non_array_filters_is_ignored(): void {
		// filters as a string or scalar is garbled input — don't
		// 400 the request, just treat it as "no filters." Since
		// 1.6.0 `page` + `per_page` are always set (pagination is
		// unconditional), so we assert on the filter-specific
		// params being absent rather than the whole map being empty.
		$body = $this->successful_search( [ 'filters' => 'garbage' ] );

		$this->assertArrayNotHasKey( 'search', $this->captured_store_params );
		$this->assertArrayNotHasKey( 'category', $this->captured_store_params );
		$this->assertArrayNotHasKey( 'min_price', $this->captured_store_params );
		$this->assertArrayNotHasKey( 'max_price', $this->captured_store_params );
		$this->assertIsArray( $body['products'] );
	}

	public function test_non_array_categories_is_ignored(): void {
		$this->successful_search(
			[ 'filters' => [ 'categories' => 'tops' ] ]
		);

		$this->assertArrayNotHasKey( 'category', $this->captured_store_params );
	}

	// ------------------------------------------------------------------
	// Variable product expansion (integration with product translator + fetch_variations_for)
	// ------------------------------------------------------------------

	public function test_variable_products_in_search_results_get_variations_prefetched(): void {
		// Search returns a variable product. The handler must then
		// fire extra rest_do_request calls per variation so the
		// translator emits real variants, not synthesized defaults.
		$this->fake_product_list = [
			[
				'id'                => 789,
				'name'              => 'T-Shirt',
				'type'              => 'variable',
				'short_description' => '',
				'prices'            => [
					'price'         => '1000',
					'currency_code' => 'USD',
					'price_range'   => [ 'min_amount' => '1000', 'max_amount' => '2000' ],
				],
				'variations'        => [
					[ 'id' => 101, 'attributes' => [ [ 'name' => 'Size', 'value' => 'Small' ] ] ],
					[ 'id' => 102, 'attributes' => [ [ 'name' => 'Size', 'value' => 'Large' ] ] ],
				],
			],
		];

		$this->fake_store_api[101] = [
			'id'                => 101,
			'name'              => 'T-Shirt',
			'short_description' => '',
			'is_in_stock'       => true,
			'prices'            => [ 'price' => '1000', 'currency_code' => 'USD' ],
			'attributes'        => [ [ 'name' => 'Size', 'value' => 'Small' ] ],
		];
		$this->fake_store_api[102] = [
			'id'                => 102,
			'name'              => 'T-Shirt',
			'short_description' => '',
			'is_in_stock'       => true,
			'prices'            => [ 'price' => '2000', 'currency_code' => 'USD' ],
			'attributes'        => [ [ 'name' => 'Size', 'value' => 'Large' ] ],
		];

		$body = $this->successful_search( [] );

		$this->assertCount( 1, $body['products'] );
		$variants = $body['products'][0]['variants'];
		$this->assertCount( 2, $variants );
		$this->assertEquals( 'var_101', $variants[0]['id'] );
		$this->assertEquals( 'Small', $variants[0]['title'] );
		$this->assertEquals( 'var_102', $variants[1]['id'] );
		$this->assertSame( 2000, $variants[1]['list_price']['amount'] );
	}

	public function test_search_emits_partial_variants_warning_when_variation_fetch_fails(): void {
		// Search's variation-expansion branch is a duplicate of lookup's
		// at the CODE level (both call fetch_variations_for via the
		// same helper). But the handler-level logic of emitting
		// `partial_variants` warnings is in each handler separately —
		// a regression in either won't be caught by the other's tests.
		// This test is the search-side mirror of
		// UcpCatalogLookupTest::test_variable_product_skips_variations_that_fail_to_fetch.
		$this->fake_product_list = [
			[
				'id'                => 789,
				'name'              => 'T-Shirt',
				'type'              => 'variable',
				'short_description' => '',
				'prices'            => [
					'price'         => '1000',
					'currency_code' => 'USD',
					'price_range'   => [ 'min_amount' => '1000', 'max_amount' => '2000' ],
				],
				'variations'        => [
					[ 'id' => 101, 'attributes' => [ [ 'name' => 'Size', 'value' => 'Small' ] ] ],
					[ 'id' => 102, 'attributes' => [ [ 'name' => 'Size', 'value' => 'Large' ] ] ],
				],
			],
		];

		// Seed only the Small variation; Large fetch will 404.
		$this->fake_store_api[101] = [
			'id'                => 101,
			'name'              => 'T-Shirt',
			'short_description' => '',
			'is_in_stock'       => true,
			'prices'            => [ 'price' => '1000', 'currency_code' => 'USD' ],
			'attributes'        => [ [ 'name' => 'Size', 'value' => 'Small' ] ],
		];
		// Leave 102 unseeded → fake returns 404.

		$body = $this->successful_search( [] );

		// Product still rendered with the variations that fetched OK.
		$this->assertCount( 1, $body['products'] );
		$variants = $body['products'][0]['variants'];
		$this->assertCount( 1, $variants );
		$this->assertEquals( 'var_101', $variants[0]['id'] );

		// Warning surfaces the partial-variants condition.
		$this->assertArrayHasKey( 'messages', $body );
		$partial = array_filter(
			$body['messages'],
			static fn( array $m ): bool => 'partial_variants' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $partial );
	}

	// ------------------------------------------------------------------
	// Store API error handling
	// ------------------------------------------------------------------

	/**
	 * Invoke the handler expecting an error UCP response.
	 *
	 * @param array<string, mixed> $body
	 */
	private function assert_search_error( array $body, int $expected_status, string $expected_code ): array {
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_catalog_search( $this->search_request( $body ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( $expected_status, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'ucp', $data );
		$this->assertSame( [], $data['products'] );
		$this->assertArrayHasKey( 'messages', $data );

		$codes = array_column( $data['messages'], 'code' );
		$this->assertContains( $expected_code, $codes );

		return $data;
	}

	public function test_store_api_5xx_returns_ucp_internal_error(): void {
		// If WC's Store API blows up, surface it as a UCP internal_error
		// with 500. Agents see a consistent UCP-envelope error shape
		// regardless of the underlying failure mode.
		$this->fake_list_status = 500;

		$this->assert_search_error( [], 500, 'ucp_internal_error' );
	}

	public function test_store_api_400_returns_ucp_internal_error(): void {
		// 400 from Store API means WE constructed a bad request (bad
		// min_price format, malformed category IDs, etc.) — that's a
		// bug in our translation layer, not an agent error. Surface
		// as internal_error so the merchant notices instead of
		// silently returning empty results.
		$this->fake_list_status = 400;

		$this->assert_search_error( [], 500, 'ucp_internal_error' );
	}

	public function test_store_api_404_treated_as_empty_result(): void {
		// 404 is the only 4xx that legitimately means "no products
		// match the filter" — agents see 200 with products: [].
		$this->fake_list_status = 404;

		$body = $this->successful_search( [] );

		$this->assertEquals( [], $body['products'] );
	}

	// ------------------------------------------------------------------
	// Syndication-disabled gate
	// ------------------------------------------------------------------

	public function test_disabled_syndication_returns_503_ucp_disabled(): void {
		// Merchant has paused syndication via the admin UI. Routes stay
		// registered (to avoid rewrite-flush churn on every toggle), but
		// the handler must refuse to serve catalog data — otherwise the
		// "pause" control silently fails open.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];

		$this->assert_search_error(
			[ 'query' => 'anything' ],
			503,
			'ucp_disabled'
		);

		// Critical: must short-circuit BEFORE dispatching anything to
		// the Store API. If the disabled check is ordered wrong, we'd
		// still do internal dispatch work before rejecting.
		$this->assertEmpty(
			$this->captured_store_params,
			'Handler must short-circuit before dispatching when disabled'
		);
	}

	// ------------------------------------------------------------------
	// Pagination (1.6.0+)
	// ------------------------------------------------------------------
	//
	// The handler translates UCP's cursor-based pagination to Store
	// API's page-based pagination. These tests lock in:
	//   - Default page size (10, matching UCP spec `types/pagination.json`)
	//   - Limit clamping to the configured max (100)
	//   - Cursor round-trip: server-issued cursor on response N maps
	//     back to page N+1 on the next request
	//   - Malformed cursors fall back to page 1 without error
	//   - Response shape: always emits has_next_page; emits cursor
	//     only when has_next_page is true; emits total_count when
	//     Store API provides X-WP-Total

	public function test_default_page_size_is_ten(): void {
		$this->fake_product_list = [];

		( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [] )
		);

		// No pagination in request → defaults per UCP spec.
		$this->assertSame( 10, $this->captured_store_params['per_page'] );
		$this->assertSame( 1, $this->captured_store_params['page'] );
	}

	public function test_pagination_limit_is_passed_through(): void {
		$this->fake_product_list = [];

		( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'limit' => 25 ],
			] )
		);

		$this->assertSame( 25, $this->captured_store_params['per_page'] );
	}

	public function test_pagination_limit_is_clamped_to_max(): void {
		// Spec permits implementations to clamp. Agents asking for
		// 500 get 100 (MAX_SEARCH_LIMIT) silently — they'll just
		// see slightly fewer products per page than requested and
		// page through cursors normally.
		$this->fake_product_list = [];

		( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'limit' => 500 ],
			] )
		);

		$this->assertSame( 100, $this->captured_store_params['per_page'] );
	}

	public function test_response_pagination_indicates_no_next_page_when_single_page(): void {
		$this->fake_product_list  = [];
		$this->fake_list_headers  = [
			'X-WP-Total'      => '3',
			'X-WP-TotalPages' => '1',
		];

		$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [] )
		);

		$body = $response->get_data();
		$this->assertArrayHasKey( 'pagination', $body );
		$this->assertFalse( $body['pagination']['has_next_page'] );
		$this->assertArrayNotHasKey(
			'cursor',
			$body['pagination'],
			'cursor MUST be absent when has_next_page is false (spec requirement)'
		);
		$this->assertSame( 3, $body['pagination']['total_count'] );
	}

	public function test_response_emits_cursor_when_more_pages_exist(): void {
		$this->fake_product_list = [];
		$this->fake_list_headers = [
			'X-WP-Total'      => '47',
			'X-WP-TotalPages' => '5',
		];

		$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [] )
		);

		$body = $response->get_data();
		$this->assertTrue( $body['pagination']['has_next_page'] );
		$this->assertArrayHasKey( 'cursor', $body['pagination'] );
		$this->assertIsString( $body['pagination']['cursor'] );
		$this->assertSame( 47, $body['pagination']['total_count'] );
	}

	public function test_cursor_round_trip_advances_to_next_page(): void {
		// Request page 1, capture the cursor, resubmit — the handler
		// should decode that cursor to page 2 and pass it to Store API.
		$this->fake_product_list = [];
		$this->fake_list_headers = [
			'X-WP-Total'      => '50',
			'X-WP-TotalPages' => '5',
		];

		$first = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [] )
		);
		$cursor = $first->get_data()['pagination']['cursor'];

		$this->captured_store_params = [];

		( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'cursor' => $cursor ],
			] )
		);

		$this->assertSame( 2, $this->captured_store_params['page'] );
	}

	public function test_malformed_cursor_falls_back_to_page_one(): void {
		// Agents may carry cursors across catalog mutations that
		// invalidate them; surfacing an error would make pagination
		// brittle. Silent fallback to page 1 is the intentional
		// behavior — the agent gets a fresh valid page.
		$this->fake_product_list = [];

		( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'cursor' => 'not-a-valid-cursor' ],
			] )
		);

		$this->assertSame( 1, $this->captured_store_params['page'] );
	}

	public function test_empty_string_cursor_falls_back_to_page_one(): void {
		// Edge case: explicit empty cursor. Treat same as missing.
		$this->fake_product_list = [];

		( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'cursor' => '' ],
			] )
		);

		$this->assertSame( 1, $this->captured_store_params['page'] );
	}

	// ------------------------------------------------------------------
	// Pagination edge cases + warning emission (1.6.0 review additions)
	// ------------------------------------------------------------------

	public function test_limit_zero_clamps_to_one_and_emits_warning(): void {
		// Lower-bound clamp: `max( 1, min( 100, $limit ) )`. A prior
		// version that reordered the arguments could silently pass
		// `per_page=0` to Store API (zero-result bug). Also locks in
		// the `pagination_limit_clamped` warning emission.
		$this->fake_product_list = [];

		$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'limit' => 0 ],
			] )
		);

		$this->assertSame( 1, $this->captured_store_params['per_page'] );
		$this->assertWarning( $response->get_data(), 'pagination_limit_clamped' );
	}

	public function test_limit_non_integer_shape_falls_back_to_default_and_warns(): void {
		// Strict integer-shape validation (same helper as price
		// bounds) — `is_numeric` accepts decimals and scientific
		// notation, `ctype_digit` accepts overflow strings that
		// silently saturate to PHP_INT_MAX. All invalid shapes
		// should fall back to the default limit with a warning
		// pointing at `$.pagination.limit`.
		$invalid_shapes = [
			'50.5',                        // decimal string
			25.5,                          // native float
			'1e3',                         // scientific notation
			'  50',                        // whitespace-padded
			str_repeat( '9', 30 ),         // overflow
			'abc',                         // non-numeric
		];
		foreach ( $invalid_shapes as $bad_limit ) {
			$this->captured_store_params = [];
			$this->fake_product_list     = [];

			$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
				$this->search_request( [
					'pagination' => [ 'limit' => $bad_limit ],
				] )
			);

			$this->assertSame(
				10,
				$this->captured_store_params['per_page'],
				'Invalid limit ' . var_export( $bad_limit, true ) . ' should fall back to default'
			);
			$this->assertWarning( $response->get_data(), 'pagination_limit_clamped' );
		}
	}

	public function test_limit_negative_falls_back_to_default_and_emits_warning(): void {
		// Post-strict-validation: negative limit is invalid shape
		// (not `is_integer_like_non_negative`) → fall back to
		// `DEFAULT_SEARCH_LIMIT` (10) rather than clamping to 1.
		// The warning still fires so agents with retry logic on
		// `pagination_limit_clamped` get the "unusable value"
		// signal they used to get on the clamp path.
		$this->fake_product_list = [];

		$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'limit' => -5 ],
			] )
		);

		$this->assertSame( 10, $this->captured_store_params['per_page'] );
		$this->assertWarning( $response->get_data(), 'pagination_limit_clamped' );
	}

	public function test_limit_over_max_clamps_and_emits_warning(): void {
		// Already covered behaviorally in `test_pagination_limit_is_clamped_to_max`
		// but that one doesn't check for the warning message. Lock in
		// the warning emission too — agents pagination-math around
		// limits and need the signal.
		$this->fake_product_list = [];

		$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'limit' => 500 ],
			] )
		);

		$this->assertWarning( $response->get_data(), 'pagination_limit_clamped' );
	}

	public function test_limit_numeric_string_accepted(): void {
		// JSON APIs sometimes coerce numbers to strings (URL-ish form
		// bodies, some client libraries). The code uses is_numeric()
		// which accepts "25". A future tightening to is_int() would
		// silently break string-sending clients.
		$this->fake_product_list = [];

		( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'limit' => '25' ],
			] )
		);

		$this->assertSame( 25, $this->captured_store_params['per_page'] );
	}

	public function test_valid_in_range_limit_emits_no_clamped_warning(): void {
		// Inverse of the clamp tests: if the limit is valid, no warning
		// should fire. Prevents a regression where we always emit the
		// warning regardless of whether clamping happened.
		$this->fake_product_list = [];

		$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'limit' => 50 ],
			] )
		);

		$body     = $response->get_data();
		$messages = $body['messages'] ?? [];

		$clamped_codes = array_column( $messages, 'code' );
		$this->assertNotContains(
			'pagination_limit_clamped',
			$clamped_codes,
			'No clamping warning should fire when limit is in range'
		);
	}

	public function test_malformed_cursor_emits_invalid_cursor_warning(): void {
		// Distinguishes the client-bug case (garbled cursor) from
		// the stale-cursor case (valid page, beyond current total).
		// The former gets a warning the agent can surface as a
		// debugging hint; the latter doesn't (it's expected drift
		// that silent fallback handles gracefully).
		$this->fake_product_list = [];

		$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'cursor' => 'not-a-valid-cursor' ],
			] )
		);

		$this->assertSame( 1, $this->captured_store_params['page'] );
		$this->assertWarning( $response->get_data(), 'invalid_cursor' );
	}

	public function test_forged_cursor_zero_page_rejected_as_malformed(): void {
		// Cursor encoding page 0 (or negative) is forged input —
		// the server never emits these. Treat as malformed, not
		// as a stale-but-well-formed cursor.
		$cursor = base64_encode( 'p0' );
		$this->fake_product_list = [];

		$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'cursor' => $cursor ],
			] )
		);

		$this->assertSame( 1, $this->captured_store_params['page'] );
		$this->assertWarning( $response->get_data(), 'invalid_cursor' );
	}

	public function test_forged_cursor_huge_page_rejected(): void {
		// Integer-overflow guard: without the upper bound in
		// `decode_cursor`, a forged `p99999999999999999` cursor
		// would pass through to Store API where `(page-1) * per_page`
		// overflows on 32-bit MySQL builds, producing negative
		// OFFSET values and SQL errors. 100,000 is already 10M
		// products at max limit — beyond any real catalog.
		$cursor = base64_encode( 'p99999999999999999' );
		$this->fake_product_list = [];

		$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => [ 'cursor' => $cursor ],
			] )
		);

		$this->assertSame( 1, $this->captured_store_params['page'] );
		$this->assertWarning( $response->get_data(), 'invalid_cursor' );
	}

	public function test_non_array_pagination_emits_warning_and_uses_defaults(): void {
		// `pagination: "next"` or `pagination: 42` — garbled but
		// not a total blocker. Apply defaults and warn.
		$this->fake_product_list = [];

		$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'pagination' => 'next',
			] )
		);

		$this->assertSame( 10, $this->captured_store_params['per_page'] );
		$this->assertSame( 1, $this->captured_store_params['page'] );
		$this->assertWarning( $response->get_data(), 'invalid_pagination_shape' );
	}

	public function test_total_count_absent_when_store_api_header_missing(): void {
		// `X-WP-Total` is not required by the UCP spec. If Store
		// API ever strips it (cache plugins, REST middleware), the
		// field must be omitted rather than defaulted to 0 — agents
		// reading `body.pagination.total_count || 0` would misreport.
		$this->fake_product_list = [];
		$this->fake_list_headers = []; // No X-WP-* headers at all.

		$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [] )
		);

		$pagination = $response->get_data()['pagination'];
		$this->assertArrayNotHasKey(
			'total_count',
			$pagination,
			'total_count MUST be absent when Store API does not provide X-WP-Total'
		);
		$this->assertFalse( $pagination['has_next_page'], 'Missing headers → treat as single page' );
	}

	public function test_pagination_header_lookup_is_case_insensitive(): void {
		// `WP_REST_Response::get_headers()` preserves producer's
		// casing. Any middleware normalizing to lowercase (or
		// uppercase) would silently break the pagination response
		// without this case-insensitive lookup.
		$this->fake_product_list = [];
		$this->fake_list_headers = [
			'x-wp-total'      => '47',   // lowercase — not the default casing
			'x-wp-totalpages' => '5',
		];

		$response = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [] )
		);

		$pagination = $response->get_data()['pagination'];
		$this->assertTrue( $pagination['has_next_page'] );
		$this->assertSame( 47, $pagination['total_count'] );
	}

	public function test_cursor_and_filters_both_forwarded_to_store_api(): void {
		// Integration: pagination params must not collide with
		// filter params. A refactor that moves pagination mapping
		// before or after filter mapping (or uses the same $params
		// array slot) could silently drop either side.
		$this->fake_product_list = [];
		$this->fake_list_headers = [
			'X-WP-Total'      => '50',
			'X-WP-TotalPages' => '5',
		];
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );

		$first = ( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'query'      => 'shirt',
				'filters'    => [ 'price' => [ 'min' => 1000, 'max' => 5000 ] ],
				'pagination' => [ 'limit' => 20 ],
			] )
		);
		$cursor = $first->get_data()['pagination']['cursor'];

		$this->captured_store_params = [];

		( new WC_AI_Storefront_UCP_REST_Controller() )->handle_catalog_search(
			$this->search_request( [
				'query'      => 'shirt',
				'filters'    => [ 'price' => [ 'min' => 1000, 'max' => 5000 ] ],
				'pagination' => [ 'limit' => 20, 'cursor' => $cursor ],
			] )
		);

		// All four must round-trip independently.
		$this->assertSame( 'shirt', $this->captured_store_params['search'] );
		$this->assertSame( 20, $this->captured_store_params['per_page'] );
		$this->assertSame( 2, $this->captured_store_params['page'] );
		$this->assertArrayHasKey( 'min_price', $this->captured_store_params );
		$this->assertArrayHasKey( 'max_price', $this->captured_store_params );
	}

	// ------------------------------------------------------------------
	// Security hardening (PR L): DoS caps + response-body reflection
	// ------------------------------------------------------------------

	public function test_oversized_categories_filter_is_capped_with_warning(): void {
		// Agent sends 60 categories; MAX_FILTER_VALUES = 50.
		// Handler should truncate to the first 50 and emit a
		// `filter_truncated` advisory with `$.filters.categories`
		// path. Mitigates DoS via unbounded term-query fan-out.
		$many = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$many[] = 'cat-' . $i;
		}
		$body = $this->successful_search(
			[ 'filters' => [ 'categories' => $many ] ]
		);

		$this->assertWarning( $body, 'filter_truncated' );

		// The tail (entries 50-59) must not appear in `unresolved`
		// warnings — truncation happens before resolution, so we
		// don't even attempt to resolve past the cap.
		$codes        = array_column( $body['messages'] ?? [], 'code' );
		$not_found_ct = count( array_filter( $codes, static fn( $c ) => 'category_not_found' === $c ) );
		$this->assertLessThanOrEqual(
			50,
			$not_found_ct,
			'category_not_found warnings must be bounded by MAX_FILTER_VALUES'
		);
	}

	public function test_oversized_tags_filter_is_capped_with_warning(): void {
		$many = array_fill( 0, 60, 'tag-x' );
		$body = $this->successful_search( [ 'filters' => [ 'tags' => $many ] ] );
		$this->assertWarning( $body, 'filter_truncated' );
	}

	public function test_oversized_brand_filter_is_capped_with_warning(): void {
		$many = array_fill( 0, 60, 'brand-x' );
		$body = $this->successful_search( [ 'filters' => [ 'brand' => $many ] ] );
		$this->assertWarning( $body, 'filter_truncated' );
	}

	public function test_oversized_attributes_map_is_capped_with_warning(): void {
		// 60 distinct attribute keys → truncate to 50 + warn with
		// `$.filters.attributes` path.
		$many = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$many[ 'attr-' . $i ] = [ 'red' ];
		}
		$body = $this->successful_search(
			[ 'filters' => [ 'attributes' => $many ] ]
		);
		$this->assertWarning( $body, 'filter_truncated' );
	}

	public function test_reflected_category_name_is_stripped_of_html_in_response(): void {
		// Agent-supplied category strings get reflected into the
		// `content` field of a `category_not_found` warning. Without
		// sanitization, a string like `<script>alert(1)</script>`
		// would be returned verbatim to any merchant admin UI that
		// renders message content as HTML — stored/reflected XSS.
		// `sanitize_reflected_value` strips tags before reflection.
		$body = $this->successful_search(
			[ 'filters' => [ 'categories' => [ '<script>alert(1)</script>', '<img src=x onerror=alert(1)>' ] ] ]
		);

		$messages = $body['messages'] ?? [];
		$not_founds = array_filter(
			$messages,
			static fn( $m ) => 'category_not_found' === ( $m['code'] ?? null )
		);
		$this->assertCount( 2, $not_founds );
		foreach ( $not_founds as $m ) {
			$this->assertStringNotContainsString( '<script>', $m['content'] ?? '' );
			$this->assertStringNotContainsString( '<img', $m['content'] ?? '' );
			$this->assertStringNotContainsString( 'onerror', $m['content'] ?? '' );
		}
	}

	public function test_reflected_value_length_is_capped(): void {
		// 10KB agent-supplied string reflected into response would
		// balloon the payload. Cap at 200 chars (enough for context
		// in log output, bounded for DoS / cache-key attacks).
		$huge = str_repeat( 'x', 10000 );
		$body = $this->successful_search(
			[ 'filters' => [ 'categories' => [ $huge ] ] ]
		);
		$messages               = $body['messages'] ?? [];
		$found_category_not_found = false;
		foreach ( $messages as $m ) {
			if ( 'category_not_found' === ( $m['code'] ?? null ) ) {
				$found_category_not_found = true;
				$content                  = $m['content'] ?? '';
				$this->assertSame(
					1,
					preg_match( '/"([^"]*)"/', $content, $matches ),
					'Expected category_not_found content to include a quoted reflected value.'
				);
				$this->assertLessThanOrEqual( 200, strlen( $matches[1] ) );
			}
		}
		$this->assertTrue( $found_category_not_found, 'Expected at least one category_not_found warning.' );
	}

	/**
	 * Assert the response body includes a warning with the given code.
	 *
	 * @param array<string, mixed> $body UCP response body.
	 * @param string               $code Expected `messages[].code`.
	 */
	private function assertWarning( array $body, string $code ): void {
		$messages = $body['messages'] ?? [];
		foreach ( $messages as $m ) {
			if ( ( $m['code'] ?? null ) === $code ) {
				$this->assertSame( 'warning', $m['type'] );
				return;
			}
		}
		$this->fail( "Expected warning with code '{$code}' in response messages." );
	}

	// ------------------------------------------------------------------
	// UCP-dispatch scope wrapping (controller seam)
	// ------------------------------------------------------------------
	//
	// The Store API list dispatch is wrapped in
	// `enter_ucp_dispatch()` / `exit_ucp_dispatch()` (try/finally).
	// The other tests in this file stub `rest_do_request` directly, so
	// the wrapping is invisible to them. The two tests below assert
	// (1) the scope IS active inside the dispatch, and (2) the
	// try/finally cleanup fires even when the dispatch throws.

	public function test_search_dispatch_wraps_store_api_request_in_ucp_scope(): void {
		// Override rest_do_request with a callback that asserts the
		// UCP scope flag is set during the dispatch. The default
		// stub from setUp doesn't check this; we replace it for
		// this test.
		$saw_in_scope = false;
		Functions\when( 'rest_do_request' )->alias(
			static function ( WP_REST_Request $request ) use ( &$saw_in_scope ) {
				if ( '/wc/store/v1/products' === $request->get_route() ) {
					$saw_in_scope = WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch();
					return new WP_REST_Response( [], 200 );
				}
				return new WP_REST_Response( null, 500 );
			}
		);

		// Sanity: we are NOT in scope before the controller runs.
		$this->assertFalse(
			WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch(),
			'Pre-condition: dispatch scope must be inactive before controller invocation.'
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_catalog_search( $this->search_request( [] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue(
			$saw_in_scope,
			'Inside rest_do_request, is_in_ucp_dispatch() must return true.'
		);

		// Post-condition: scope was exited cleanly.
		$this->assertFalse(
			WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch(),
			'Post-condition: dispatch scope must be inactive after controller returns.'
		);
	}

	public function test_dispatch_scope_exits_when_store_api_throws(): void {
		// Make rest_do_request throw — exercise the `finally` branch
		// of the controller's try/finally so we know the dispatch
		// scope is decremented even when the Store API blows up.
		Functions\when( 'rest_do_request' )->alias(
			static function ( WP_REST_Request $request ): WP_REST_Response {
				throw new RuntimeException( 'Simulated Store API failure' );
			}
		);

		$this->assertFalse(
			WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch(),
			'Pre-condition: dispatch scope must be inactive before controller invocation.'
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$threw      = false;
		try {
			$controller->handle_catalog_search( $this->search_request( [] ) );
		} catch ( RuntimeException $e ) {
			$threw = true;
		}

		$this->assertTrue( $threw, 'Expected exception to propagate through controller.' );

		// The crux of the test: the try/finally in the controller
		// must have run `exit_ucp_dispatch()` despite the throw.
		$this->assertFalse(
			WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch(),
			'finally must decrement dispatch depth even when rest_do_request throws.'
		);
	}
}
