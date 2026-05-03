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
		// taxonomy matching override get_taxonomies / get_terms inline.
		Functions\when( 'get_taxonomies' )->justReturn( array() );
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
		// "100% cotton!" → "100", "cotton".
		$terms = \WC_AI_Storefront_UCP_Store_API_Filter::extract_search_terms( '100% cotton!' );
		$this->assertContains( 'cotton', $terms );
		$this->assertNotContains( '%', implode( '', $terms ) );
		$this->assertNotContains( '!', implode( '', $terms ) );
	}

	// ---------------------------------------------------------------
	// resolve_taxonomy_terms() — taxonomy lookup
	// ---------------------------------------------------------------

	public function test_resolve_returns_empty_when_no_taxonomies(): void {
		// get_taxonomies already stubbed to return [] in setUp.
		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'hoodie' ) );
		$this->assertEmpty( $result );
	}

	public function test_resolve_exact_name_match(): void {
		Functions\when( 'get_taxonomies' )->justReturn( array( 'product_cat' => 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn( array(
			$this->fake_term( 5, 'Hoodies', 'product_cat' ),
		) );

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'hoodies' ) );

		$this->assertArrayHasKey( 'hoodies', $result );
		$this->assertContains( 5, $result['hoodies'] );
	}

	public function test_resolve_plural_to_singular(): void {
		// Signal term "hoodies" should match category named "Hoodie".
		Functions\when( 'get_taxonomies' )->justReturn( array( 'product_cat' => 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn( array(
			$this->fake_term( 5, 'Hoodie', 'product_cat' ),
		) );

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'hoodies' ) );

		$this->assertArrayHasKey( 'hoodies', $result );
		$this->assertContains( 5, $result['hoodies'] );
	}

	public function test_resolve_singular_to_plural(): void {
		// Signal term "shoe" should match category named "Shoes".
		Functions\when( 'get_taxonomies' )->justReturn( array( 'product_cat' => 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn( array(
			$this->fake_term( 3, 'Shoes', 'product_cat' ),
		) );

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'shoe' ) );

		$this->assertArrayHasKey( 'shoe', $result );
		$this->assertContains( 3, $result['shoe'] );
	}

	public function test_resolve_unmatched_term_absent_from_result(): void {
		Functions\when( 'get_taxonomies' )->justReturn( array( 'product_cat' => 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn( array(
			$this->fake_term( 5, 'Hoodies', 'product_cat' ),
		) );

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'logo' ) );

		$this->assertArrayNotHasKey( 'logo', $result );
	}

	public function test_resolve_matches_across_multiple_taxonomies(): void {
		Functions\when( 'get_taxonomies' )->justReturn( array(
			'product_cat' => 'product_cat',
			'product_tag' => 'product_tag',
		) );
		Functions\when( 'get_terms' )->justReturn( array(
			$this->fake_term( 5, 'Hoodies', 'product_cat' ),
			$this->fake_term( 12, 'Men', 'product_tag' ),
		) );

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'hoodies', 'men' ) );

		$this->assertArrayHasKey( 'hoodies', $result );
		$this->assertArrayHasKey( 'men', $result );
		$this->assertContains( 5, $result['hoodies'] );
		$this->assertContains( 12, $result['men'] );
	}

	public function test_resolve_ches_es_to_ch(): void {
		// "watches" → "watch" via {ch}es → ch rule.
		Functions\when( 'get_taxonomies' )->justReturn( array( 'product_cat' => 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn( array(
			$this->fake_term( 8, 'Watch', 'product_cat' ),
		) );

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'watches' ) );

		$this->assertArrayHasKey( 'watches', $result );
		$this->assertContains( 8, $result['watches'] );
	}

	public function test_resolve_ies_to_y(): void {
		// "accessories" → "accessory" via ies → y rule.
		Functions\when( 'get_taxonomies' )->justReturn( array( 'product_cat' => 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn( array(
			$this->fake_term( 9, 'Accessory', 'product_cat' ),
		) );

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'accessories' ) );

		$this->assertArrayHasKey( 'accessories', $result );
		$this->assertContains( 9, $result['accessories'] );
	}

	public function test_resolve_y_to_ies(): void {
		// "accessory" → "accessories" via y → ies rule (singular query, plural category).
		Functions\when( 'get_taxonomies' )->justReturn( array( 'product_cat' => 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn( array(
			$this->fake_term( 9, 'Accessories', 'product_cat' ),
		) );

		$result = \WC_AI_Storefront_UCP_Store_API_Filter::resolve_taxonomy_terms( array( 'accessory' ) );

		$this->assertArrayHasKey( 'accessory', $result );
		$this->assertContains( 9, $result['accessory'] );
	}

	public function test_resolve_returns_empty_on_wp_error(): void {
		Functions\when( 'get_taxonomies' )->justReturn( array( 'product_cat' => 'product_cat' ) );
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
		$wp_query = new WP_Query( array( 'search' => 'hoodie' ) );
		$args     = array( 'where' => '', 'join' => '' );

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertSame( '', $result['where'], 'WHERE must be untouched outside dispatch' );
		// Re-enter so tearDown exit_ucp_dispatch() doesn't underflow.
		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
	}

	public function test_noop_when_search_is_empty(): void {
		$this->make_wpdb();

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query( array( 'search' => '' ) );
		$args     = array( 'where' => '', 'join' => '' );

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertSame( '', $result['where'] );
	}

	public function test_all_stopwords_leaves_search_var_intact(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query( array( 'search' => 'for the a' ) );
		$args     = array( 'where' => '', 'join' => '' );

		$filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertSame( 'for the a', $wp_query->get( 'search' ), 'Stopword-only query must not zero out search' );
	}

	public function test_zeroes_search_var_for_valid_query(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query( array( 'search' => 'blue shirt' ) );
		$args     = array( 'where' => '', 'join' => '' );

		$filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertSame( '', $wp_query->get( 'search' ), 'search var must be zeroed so WC phrase-LIKE is suppressed' );
	}

	public function test_unmatched_terms_produce_title_like_per_word(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		// No taxonomy terms → all signal words fall back to title LIKE.

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query( array( 'search' => 'Hoodie with logo' ) );
		$args     = array( 'where' => '', 'join' => '' );

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( '%hoodie%', $result['where'] );
		$this->assertStringContainsString( '%logo%', $result['where'] );
		$this->assertStringNotContainsString( '%with%', $result['where'] );
	}

	public function test_title_like_clauses_joined_with_and(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query( array( 'search' => 'blue shirt' ) );
		$args     = array( 'where' => '', 'join' => '' );

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertMatchesRegularExpression( '/LIKE.*AND.*LIKE/i', $result['where'] );
	}

	public function test_taxonomy_matched_term_emits_exists_subquery(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );
		Functions\when( 'get_taxonomies' )->justReturn( array( 'product_cat' => 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn( array(
			$this->fake_term( 5, 'Hoodies', 'product_cat' ),
		) );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		// "hoodies" matches the "Hoodies" category via plural→singular; "logo" does not.
		$wp_query = new WP_Query( array( 'search' => 'hoodies logo' ) );
		$args     = array( 'where' => '', 'join' => '' );

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
		Functions\when( 'get_taxonomies' )->justReturn( array( 'product_cat' => 'product_cat' ) );
		Functions\when( 'get_terms' )->justReturn( array(
			$this->fake_term( 7, 'Running', 'product_cat' ),
		) );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query( array( 'search' => 'running' ) );
		$args     = array( 'where' => '', 'join' => '' );

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
		$wp_query = new WP_Query( array( 'search' => 'hoodie' ) );
		$args     = array( 'where' => '', 'join' => '' );

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringContainsString( 'wc_product_meta_lookup', $result['join'] );
	}

	public function test_sku_join_not_duplicated_when_already_present(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( true );

		$filter        = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query      = new WP_Query( array( 'search' => 'hoodie' ) );
		$existing_join = 'LEFT JOIN wp_wc_product_meta_lookup ON wp_posts.ID = wp_wc_product_meta_lookup.product_id';
		$args          = array( 'where' => '', 'join' => $existing_join );

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertSame( $existing_join, $result['join'], 'JOIN must not be modified when already present' );
	}

	public function test_no_join_when_sku_disabled(): void {
		$this->make_wpdb();
		Functions\when( 'wc_product_sku_enabled' )->justReturn( false );

		$filter   = new \WC_AI_Storefront_UCP_Store_API_Filter();
		$wp_query = new WP_Query( array( 'search' => 'hoodie' ) );
		$args     = array( 'where' => '', 'join' => '' );

		$result = $filter->on_posts_clauses_search( $args, $wp_query );

		$this->assertStringNotContainsString( 'wc_product_meta_lookup', $result['join'] );
	}
}
