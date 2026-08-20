<?php
/**
 * Tests for WC_AI_Storefront_UCP_Store_API_Filter search preprocessing.
 *
 * Covers extract_search_terms() (pure), resolve_taxonomy_terms()
 * (taxonomy lookup), and on_posts_clauses_search() (SQL generation).
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpSearchPreprocessorTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();

		// Default: no product taxonomies registered. Tests that need
		// taxonomy matching override get_object_taxonomies / get_terms inline.
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'get_terms' )->justReturn( array() );
		// is_wp_error() is defined in stubs.php before Patchwork loads,
		// so it cannot be stubbed — use the real implementation instead.
	}

	protected function tearDown(): void {
		\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		global $wpdb;
		$wpdb = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	private function make_wpdb(): void {
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static fn( string $s ) => addcslashes( $s, '_%\\' )
		);
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $sql, ...$args ) {
				$i = 0;
				return (string) preg_replace_callback(
					'/%s/',
					static function () use ( &$i, $args ) {
						return "'" . addslashes( (string) ( $args[ $i++ ] ?? '' ) ) . "'";
					},
					$sql
				);
			}
		);
	}

	/** Build a fake WP_Term-like object for get_terms() stubs. */
	private function fake_term( int $id, string $name, string $taxonomy ): object {
		$t           = new \stdClass();
		$t->term_id  = $id;
		$t->name     = $name;
		$t->slug     = strtolower( str_replace( ' ', '-', $name ) );
		$t->taxonomy = $taxonomy;
		return $t;
	}

	// ---------------------------------------------------------------
	// extract_search_terms() — pure function
	// ---------------------------------------------------------------

	public function test_single_word_passes_through(): void {
		$this->assertSame(
			array( 'hoodie' ),
			\WC_AI_Storefront_UCP_Store_API_Filter::extract_search_terms( 'Hoodie' )
		);
	}

	public function test_lowercases_terms(): void {
		$this->assertSame(
			array( 'running', 'shoes' ),
			\WC_AI_Storefront_UCP_Store_API_Filter::extract_search_terms( 'Running Shoes' )
		);
	}

	public function test_strips_common_stopwords(): void {
		$this->assertSame(
			array( 'hoodie', 'logo' ),
			\WC_AI_Storefront_UCP_Store_API_Filter::extract_search_terms( 'Hoodie with logo' )
		);
	}

	public function test_strips_article_and_prepositions(): void {
		$this->assertSame(
			array( 'running', 'shoes', 'men' ),
			\WC_AI_Storefront_UCP_Store_API_Filter::extract_search_terms( 'Running shoes for men' )
		);
	}

	public function test_strips_single_char_tokens(): void {
		$terms = \WC_AI_Storefront_UCP_Store_API_Filter::extract_search_terms( 'a hoodie' );
		$this->assertNotContains( 'a', $terms );
		$this->assertContains( 'hoodie', $terms );
	}

	public function test_all_stopwords_returns_empty(): void {
		$this->assertEmpty(
			\WC_AI_Storefront_UCP_Store_API_Filter::extract_search_terms( 'for the a an' )
		);
	}

	public function test_empty_string_returns_empty(): void {
		$this->assertEmpty(
			\WC_AI_Storefront_UCP_Store_API_Filter::extract_search_terms( '' )
		);
	}

	public function test_extra_whitespace_is_normalised(): void {
		$this->assertSame(
			array( 'blue', 'shirt' ),
			\WC_AI_Storefront_UCP_Store_API_Filter::extract_search_terms( '  blue   shirt  ' )
		);
	}

	public function test_apostrophe_stripped_in_place(): void {
		// "women's" → "womens" (not split into two tokens).
		$this->assertSame(
			array( 'womens', 'jacket' ),
			\WC_AI_Storefront_UCP_Store_API_Filter::extract_search_terms( "Women's jacket" )
		);
	}

	public function test_hyphen_splits_into_tokens(): void {
		// "mid-layer" → "mid", "layer".
		$this->assertSame(
			array( 'mid', 'layer' ),
			\WC_AI_Storefront_UCP_Store_API_Filter::extract_search_terms( 'mid-layer' )
		);
	}

	public function test_punctuation_other_than_apostrophe_and_hyphen_dropped(): void {
		// "100% cotton!" → "100", "cotton" — percent and exclamation stripped.
		$terms     = \WC_AI_Storefront_UCP_Store_API_Filter::extract_search_terms( '100% cotton!' );
		$terms_str = implode( ' ', $terms );
		$this->assertContains( 'cotton', $terms );
		$this->assertStringNotContainsString( '%', $terms_str );
		$this->assertStringNotContainsString( '!', $terms_str );
	}

	// ---------------------------------------------------------------
	// resolve_taxonomy_terms() — taxonomy lookup
	// ---------------------------------------------------------------

	public function test_resolve_returns_empty_when_no_taxonomies(): void {
		// get_object_taxonomies already stubbed to return [] in setUp.
		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'hoodie' ) );
		$this->assertEmpty( $result );
	}

	public function test_resolve_exact_name_match(): void {
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 5, 'Hoodies', 'product_cat' ),
			)
		);

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'hoodies' ) );

		$this->assertArrayHasKey( 'hoodies', $result );
		$this->assertContains( 5, $result['hoodies'] );
	}

	public function test_resolve_plural_to_singular(): void {
		// Signal term "hoodies" should match category named "Hoodie".
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 5, 'Hoodie', 'product_cat' ),
			)
		);

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'hoodies' ) );

		$this->assertArrayHasKey( 'hoodies', $result );
		$this->assertContains( 5, $result['hoodies'] );
	}

	public function test_resolve_singular_to_plural(): void {
		// Signal term "shoe" should match category named "Shoes".
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 3, 'Shoes', 'product_cat' ),
			)
		);

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'shoe' ) );

		$this->assertArrayHasKey( 'shoe', $result );
		$this->assertContains( 3, $result['shoe'] );
	}

	public function test_resolve_unmatched_term_absent_from_result(): void {
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 5, 'Hoodies', 'product_cat' ),
			)
		);

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'logo' ) );

		$this->assertArrayNotHasKey( 'logo', $result );
	}

	public function test_resolve_matches_across_multiple_taxonomies(): void {
		Functions\when( 'get_object_taxonomies' )->justReturn(
			array(
				'product_cat',
				'product_tag',
			)
		);
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 5, 'Hoodies', 'product_cat' ),
				$this->fake_term( 12, 'Men', 'product_tag' ),
			)
		);

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'hoodies', 'men' ) );

		$this->assertArrayHasKey( 'hoodies', $result );
		$this->assertArrayHasKey( 'men', $result );
		$this->assertContains( 5, $result['hoodies'] );
		$this->assertContains( 12, $result['men'] );
	}

	public function test_resolve_ches_es_to_ch(): void {
		// "watches" → "watch" via {ch}es → ch rule.
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 8, 'Watch', 'product_cat' ),
			)
		);

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'watches' ) );

		$this->assertArrayHasKey( 'watches', $result );
		$this->assertContains( 8, $result['watches'] );
	}

	public function test_resolve_ies_to_y(): void {
		// "accessories" → "accessory" via ies → y rule.
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 9, 'Accessory', 'product_cat' ),
			)
		);

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'accessories' ) );

		$this->assertArrayHasKey( 'accessories', $result );
		$this->assertContains( 9, $result['accessories'] );
	}

	public function test_resolve_y_to_ies(): void {
		// "accessory" → "accessories" via y → ies rule (singular query, plural category).
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 9, 'Accessories', 'product_cat' ),
			)
		);

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'accessory' ) );

		$this->assertArrayHasKey( 'accessory', $result );
		$this->assertContains( 9, $result['accessory'] );
	}

	public function test_resolve_matches_by_slug(): void {
		// slug "hooded-jacket" → lookup indexes by slug; "hooded-jacket" signal matches.
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_tag' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 20, 'Hooded Jacket', 'product_tag' ),
			)
		);

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'hooded-jacket' ) );

		// slug is "hooded-jacket"; signal is "hooded-jacket" — direct slug hit.
		$this->assertArrayHasKey( 'hooded-jacket', $result );
		$this->assertContains( 20, $result['hooded-jacket'] );
	}

	public function test_resolve_slug_only_match_when_name_differs(): void {
		// Real-world: term named "Women's" has slug "womens" (apostrophe
		// stripped by WP). Signal "womens" must resolve via slug__in
		// because name__in candidates ('womens', 'women', 'womenss') won't
		// match the literal name "Women's". Locks the two-query
		// (name__in + slug__in) approach in resolve_taxonomy_terms().
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );

		$term           = new \stdClass();
		$term->term_id  = 50;
		$term->name     = "Women's";
		$term->slug     = 'womens';
		$term->taxonomy = 'product_cat';

		// Capture get_terms() args to prove both name__in and slug__in
		// queries actually run, and dispatch the right return per call.
		$received_args = array();
		Functions\when( 'get_terms' )->alias(
			function ( $args ) use ( &$received_args, $term ) {
				$received_args[] = $args;
				if ( isset( $args['slug__in'] ) ) {
					return array( $term );
				}
				return array();
			}
		);

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'womens' ) );

		// Both queries must have run.
		$this->assertCount( 2, $received_args, 'Expected one name__in + one slug__in get_terms() call' );
		$has_name_in_call = false;
		$has_slug_in_call = false;
		foreach ( $received_args as $a ) {
			if ( isset( $a['name__in'] ) ) {
				$has_name_in_call = true;
				$this->assertContains( 'womens', $a['name__in'] );
			}
			if ( isset( $a['slug__in'] ) ) {
				$has_slug_in_call = true;
				$this->assertContains( 'womens', $a['slug__in'] );
			}
		}
		$this->assertTrue( $has_name_in_call, 'A get_terms() call with name__in must fire' );
		$this->assertTrue( $has_slug_in_call, 'A get_terms() call with slug__in must fire' );

		// And the slug-only term must resolve.
		$this->assertArrayHasKey( 'womens', $result );
		$this->assertContains( 50, $result['womens'] );
	}

	public function test_resolve_includes_product_brand_taxonomy(): void {
		// product_brand is registered when WC 9.5+ or a brand plugin is active.
		// Locks the contract in get_product_taxonomy_names() so a regression
		// dropping 'product_brand' from the allowlist fails this test.
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_brand' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 40, 'Nike', 'product_brand' ),
			)
		);

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'nike' ) );

		$this->assertArrayHasKey( 'nike', $result );
		$this->assertContains( 40, $result['nike'] );
	}

	public function test_resolve_includes_pa_attribute_taxonomy(): void {
		// pa_color is a product attribute taxonomy — should be included in resolution.
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'pa_color' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 30, 'Blue', 'pa_color' ),
			)
		);

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'blue' ) );

		$this->assertArrayHasKey( 'blue', $result );
		$this->assertContains( 30, $result['blue'] );
	}

	public function test_resolve_returns_empty_on_wp_error(): void {
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		// Return a real WP_Error instance; the real is_wp_error() stub detects it.
		Functions\when( 'get_terms' )->justReturn( new \WP_Error() );

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'hoodie' ) );

		$this->assertEmpty( $result );
	}

	// ---------------------------------------------------------------
	// on_posts_clauses_search() — dispatch gate + SQL shape
	// ---------------------------------------------------------------

	public function test_noop_outside_ucp_dispatch(): void {
		\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'hoodie',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertSame( '', $result['where'], 'WHERE must be untouched outside dispatch' );
		// Re-enter so tearDown exit_ucp_dispatch() doesn't underflow.
		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
	}

	public function test_noop_when_search_is_empty(): void {
		$this->make_wpdb();

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => '',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertSame( '', $result['where'] );
	}

	public function test_all_stopwords_leaves_search_var_intact(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'for the a',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertSame( 'for the a', $wp_query->get( 'search' ), 'Stopword-only query must not zero out search' );
	}

	public function test_zeroes_search_var_for_valid_query(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'blue shirt',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertSame( '', $wp_query->get( 'search' ), 'search var must be zeroed so WC phrase-LIKE is suppressed' );
	}

	public function test_unmatched_terms_produce_title_like_per_word(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		// No taxonomy terms → all signal words fall back to title LIKE.

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'Hoodie with logo',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( '%hoodie%', $result['where'] );
		$this->assertStringContainsString( '%logo%', $result['where'] );
		$this->assertStringNotContainsString( '%with%', $result['where'] );
	}

	public function test_title_like_clauses_joined_with_and(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'blue shirt',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertMatchesRegularExpression( '/LIKE.*AND.*LIKE/i', $result['where'] );
	}

	public function test_taxonomy_matched_term_emits_exists_subquery(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 5, 'Hoodies', 'product_cat' ),
			)
		);

		$filter = new \WC_AI_Storefront_UCP_Store_API_Filter();
		// "hoodies" matches the "Hoodies" category via plural→singular; "logo" does not.
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'hoodies logo',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		// EXISTS subquery should reference term_id 5.
		$this->assertStringContainsString( 'EXISTS', $result['where'] );
		$this->assertStringContainsString( '5', $result['where'] );
		// The unmatched signal term still gets a title LIKE.
		$this->assertStringContainsString( '%logo%', $result['where'] );
	}

	public function test_taxonomy_clause_ored_with_title_like(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 7, 'Running', 'product_cat' ),
			)
		);

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'running',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		// The clause for "running" is (EXISTS … OR title LIKE).
		$this->assertStringContainsString( 'EXISTS', $result['where'] );
		$this->assertStringContainsString( '%running%', $result['where'] );
		// They must be OR-combined so either route finds the product.
		$this->assertMatchesRegularExpression( '/EXISTS.*OR.*LIKE/is', $result['where'] );
	}

	public function test_sku_join_added_when_sku_enabled(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( true );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'hoodie',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( 'wc_product_meta_lookup', $result['join'] );
	}

	public function test_sku_join_not_duplicated_when_already_present(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( true );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'hoodie',
			)
		);
		// WC core's actual JOIN form (table AS the `wc_product_meta_lookup`
		// alias) — already present, so our filter must not add a second one.
		$existing_join = 'LEFT JOIN wp_wc_product_meta_lookup wc_product_meta_lookup ON wp_posts.ID = wc_product_meta_lookup.product_id';
		$args          = array(
			'where' => '',
			'join'  => $existing_join,
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertSame( $existing_join, $result['join'], 'JOIN must not be modified when already present' );
	}

	public function test_sku_join_aliases_table_for_wc_core_price_filter_compat(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( true );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'hoodie',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		// Regression: the JOIN must alias the lookup table AS
		// `wc_product_meta_lookup` (WC core's alias). A bare
		// `LEFT JOIN wp_wc_product_meta_lookup` (no alias) tripped core's
		// `strstr( $join, 'wc_product_meta_lookup' )` dedup, so core skipped its
		// own aliased JOIN and then referenced a missing alias in its price /
		// sort WHERE clause — an "Unknown column" error that silently broke
		// search + price-filter and search + sort-by-price.
		$this->assertMatchesRegularExpression(
			'/LEFT JOIN\s+wp_wc_product_meta_lookup\s+wc_product_meta_lookup\s+ON/i',
			$result['join']
		);
		// The SKU clause must reference the alias, never the bare table name
		// (which is invalid SQL once the table is aliased).
		$this->assertStringContainsString( 'wc_product_meta_lookup.sku', $result['where'] );
		$this->assertStringNotContainsString( 'wp_wc_product_meta_lookup.sku', $result['where'] );
	}

	public function test_no_join_when_sku_disabled(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'hoodie',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringNotContainsString( 'wc_product_meta_lookup', $result['join'] );
	}

	// ---------------------------------------------------------------
	// Title LIKE stem expansion (get_title_like_forms via SQL output)
	// ---------------------------------------------------------------

	public function test_title_like_includes_singular_form_for_plural_signal(): void {
		// "hoodies" → title LIKE must contain both '%hoodies%' and '%hoodie%'.
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'hoodies',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( '%hoodies%', $result['where'] );
		$this->assertStringContainsString( '%hoodie%', $result['where'] );
	}

	public function test_title_like_includes_plural_form_for_singular_signal(): void {
		// "shoe" → title LIKE must contain both '%shoe%' and '%shoes%'.
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'shoe',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( '%shoe%', $result['where'] );
		$this->assertStringContainsString( '%shoes%', $result['where'] );
	}

	public function test_title_like_single_form_when_no_variant_applies(): void {
		// "logo" has no applicable suffix rule — only '%logo%' should appear,
		// not '%logos%' (which would widen recall without justification).
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'logo',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( '%logo%', $result['where'] );
		$this->assertStringNotContainsString( '%logos%', $result['where'] );
	}

	// ---------------------------------------------------------------
	// AND vs OR join logic for multi-category queries
	// ---------------------------------------------------------------

	public function test_and_connector_with_all_taxonomy_matched_joins_with_or(): void {
		// "Hoodies and Belts" — both terms resolve to taxonomy → OR so each
		// category's products are returned independently.
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 10, 'Hoodies', 'product_cat' ),
				$this->fake_term( 11, 'Belts', 'product_cat' ),
			)
		);

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'Hoodies and Belts',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( ') OR (', $result['where'] );
		$this->assertStringNotContainsString( ') AND (', $result['where'] );
	}

	public function test_and_connector_with_unresolved_term_keeps_and(): void {
		// "blue and hat" — $has_and_connector is true but "blue" does not resolve to a
		// taxonomy term, so $all_taxonomy_matched is false and AND is kept.
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 12, 'Hats', 'product_cat' ),
			)
		);

		$filter = new \WC_AI_Storefront_UCP_Store_API_Filter();
		// "blue" won't match the "Hats" term; only "hat"/"hats" will — partial match → AND.
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'blue and hat',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		// Top-level join is AND — partial taxonomy resolution keeps narrowing mode.
		$this->assertStringContainsString( ') AND (', $result['where'] );
		$this->assertStringNotContainsString( ') OR (', $result['where'] );
		// "hat" resolved to taxonomy term 12 → EXISTS subquery present.
		$this->assertStringContainsString( 'EXISTS', $result['where'] );
		$this->assertStringContainsString( '12', $result['where'] );
		// "blue" did not resolve → title LIKE fallback present.
		$this->assertStringContainsString( '%blue%', $result['where'] );
	}

	public function test_and_connector_uppercase_still_joins_with_or(): void {
		// "Hoodies AND Belts" — the /i flag on the regex must handle uppercase connectors.
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 13, 'Hoodies', 'product_cat' ),
				$this->fake_term( 14, 'Belts', 'product_cat' ),
			)
		);

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'Hoodies AND Belts',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( ') OR (', $result['where'] );
		$this->assertStringNotContainsString( ') AND (', $result['where'] );
	}

	public function test_comma_separated_query_joins_with_or(): void {
		// "Hoodies, Belts" — comma is a multi-item connector just like "and"; both terms
		// resolve to taxonomy → OR so each category's products are returned independently.
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 15, 'Hoodies', 'product_cat' ),
				$this->fake_term( 16, 'Belts', 'product_cat' ),
			)
		);

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'Hoodies, Belts',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( ') OR (', $result['where'] );
		$this->assertStringNotContainsString( ') AND (', $result['where'] );
	}

	public function test_comma_separated_with_unresolved_term_keeps_and(): void {
		// "blue, Hats" — "blue" does not resolve to a taxonomy term, so AND is kept
		// even though a comma connector is present.
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 20, 'Hats', 'product_cat' ),
			)
		);

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'blue, Hats',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( ') AND (', $result['where'] );
	}

	public function test_comma_no_space_joins_with_or(): void {
		// "Hoodies,Belts" (no space after comma) — commas are now converted to spaces
		// in extract_search_terms() before the punctuation-drop pass, so the pair splits
		// into ["hoodies", "belts"] and both resolve to taxonomy terms → OR join.
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 21, 'Hoodies', 'product_cat' ),
				$this->fake_term( 22, 'Belts', 'product_cat' ),
			)
		);

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'Hoodies,Belts',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( ') OR (', $result['where'] );
		$this->assertStringNotContainsString( ') AND (', $result['where'] );
	}

	public function test_or_connector_all_taxonomy_matched_joins_with_or(): void {
		// "Hat or Shoes" — explicit "or" means unambiguous choice; OR-join even without
		// needing the $all_taxonomy_matched guard.
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 23, 'Hats', 'product_cat' ),
				$this->fake_term( 24, 'Shoes', 'product_cat' ),
			)
		);

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'Hat or Shoes',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( ') OR (', $result['where'] );
		$this->assertStringNotContainsString( ') AND (', $result['where'] );
	}

	public function test_or_connector_with_unresolved_term_still_joins_with_or(): void {
		// "blue or Shoes" — "or" is unambiguous even when one term is unresolved;
		// unlike "and", the $all_taxonomy_matched guard does not apply.
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 25, 'Shoes', 'product_cat' ),
			)
		);

		$filter = new \WC_AI_Storefront_UCP_Store_API_Filter();
		// "blue" won't resolve to a taxonomy term; "shoes" will.
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'blue or Shoes',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( ') OR (', $result['where'] );
		$this->assertStringNotContainsString( ') AND (', $result['where'] );
	}

	public function test_three_way_and_query_all_matched_joins_with_or(): void {
		// "Hoodies and Belts and Caps" — all three terms resolve to taxonomy → OR,
		// producing three EXISTS subqueries joined with OR.
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->fake_term( 17, 'Hoodies', 'product_cat' ),
				$this->fake_term( 18, 'Belts', 'product_cat' ),
				$this->fake_term( 19, 'Caps', 'product_cat' ),
			)
		);

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query(
			array(
				'post_type' => 'product',
				'search'    => 'Hoodies and Belts and Caps',
			)
		);
		$args     = array(
			'where' => '',
			'join'  => '',
		);

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		// All three EXISTS clauses must be OR-joined — matches two OR separators.
		$this->assertMatchesRegularExpression( '/EXISTS.*OR.*EXISTS.*OR.*EXISTS/is', $result['where'] );
		$this->assertStringNotContainsString( ') AND (', $result['where'] );
	}
}
