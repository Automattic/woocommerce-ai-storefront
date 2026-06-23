<?php
/**
 * Tests for WC_AI_Storefront_IndexNow.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

if ( ! class_exists( 'WC_AI_Storefront_IndexNow_Exit' ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test double
	class WC_AI_Storefront_IndexNow_Exit extends \RuntimeException {}
}

class IndexNowTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_IndexNow $indexnow;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'home_url' )->alias( static fn( $p = '/' ) => 'https://shop.test' . ( '' === $p ? '/' : $p ) );
		$this->indexnow = new class() extends WC_AI_Storefront_IndexNow {
			protected function terminate(): void {
				throw new \WC_AI_Storefront_IndexNow_Exit();
			}
		};
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_key_generates_and_persists_hex_key(): void {
		$stored = null;
		Functions\when( 'get_option' )->justReturn( array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes' ) );
		Functions\expect( 'update_option' )->once()->andReturnUsing(
			function ( $name, $value ) use ( &$stored ) {
				$stored = $value['indexnow_key'] ?? null;
				return true;
			}
		);
		$key = $this->indexnow->get_key();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key );
		$this->assertSame( $key, $stored );
	}

	public function test_get_key_returns_existing_without_regenerating(): void {
		WC_AI_Storefront::$test_settings['indexnow_key'] = 'abc123abc123abc123abc123abc12300';
		Functions\expect( 'update_option' )->never();
		$this->assertSame( 'abc123abc123abc123abc123abc12300', $this->indexnow->get_key() );
	}

	public function test_is_enabled_requires_both_flags(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'no' );
		$this->assertFalse( $this->indexnow->is_enabled() );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no', 'indexnow_enabled' => 'yes' );
		$this->assertFalse( $this->indexnow->is_enabled() );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes' );
		$this->assertTrue( $this->indexnow->is_enabled() );
	}

	public function test_serve_key_file_outputs_key_on_match(): void {
		WC_AI_Storefront::$test_settings['indexnow_key'] = 'abcabcabcabcabcabcabcabcabcabc99';
		Functions\when( 'get_option' )->justReturn(
			array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'indexnow_key' => 'abcabcabcabcabcabcabcabcabcabc99' )
		);
		Functions\when( 'get_query_var' )->justReturn( 'abcabcabcabcabcabcabcabcabcabc99' );
		Functions\expect( 'status_header' )->once()->with( 200 );
		ob_start();
		try {
			$this->indexnow->serve_key_file();
		} catch ( \WC_AI_Storefront_IndexNow_Exit $e ) {
			// serve_key_file() calls $this->terminate() which throws in tests.
		}
		$this->assertSame( 'abcabcabcabcabcabcabcabcabcabc99', ob_get_clean() );
	}

	public function test_serve_key_file_404_on_mismatch(): void {
		WC_AI_Storefront::$test_settings['indexnow_key'] = 'realkeyrealkeyrealkeyrealkey0001';
		Functions\when( 'get_option' )->justReturn(
			array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'indexnow_key' => 'realkeyrealkeyrealkeyrealkey0001' )
		);
		Functions\when( 'get_query_var' )->justReturn( 'gibberishgibberishgibberish00000' );
		Functions\expect( 'status_header' )->once()->with( 404 );
		try {
			$this->indexnow->serve_key_file();
		} catch ( \WC_AI_Storefront_IndexNow_Exit $e ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_serve_key_file_noop_when_no_query_var(): void {
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\expect( 'status_header' )->never();
		$this->indexnow->serve_key_file(); // returns without terminating
		$this->addToAssertionCount( 1 );
	}

	public function test_surface_urls_includes_home_shop_llms_and_feed(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		$urls = $this->indexnow->surface_urls();
		$this->assertContains( 'https://shop.test/', $urls );
		$this->assertContains( 'https://shop.test/shop/', $urls );
		$this->assertContains( 'https://shop.test/llms.txt', $urls );
		$this->assertContains( 'https://shop.test/products.json', $urls );
	}

	public function test_is_product_indexable_true_for_published_visible_syndicated(): void {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$product->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		// is_product_syndicated() is a static on WC_AI_Storefront; settings mode 'all' => true.
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'product_selection_mode' => 'all' );
		$this->assertTrue( $this->indexnow->is_product_indexable( $product ) );
	}

	public function test_is_product_indexable_false_for_hidden_or_draft(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'product_selection_mode' => 'all' );
		$draft = \Mockery::mock( 'WC_Product' );
		$draft->shouldReceive( 'get_id' )->andReturn( 42 );
		$draft->shouldReceive( 'get_status' )->andReturn( 'draft' );
		$draft->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		$this->assertFalse( $this->indexnow->is_product_indexable( $draft ) );

		$hidden = \Mockery::mock( 'WC_Product' );
		$hidden->shouldReceive( 'get_id' )->andReturn( 43 );
		$hidden->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$hidden->shouldReceive( 'get_catalog_visibility' )->andReturn( 'hidden' );
		$this->assertFalse( $this->indexnow->is_product_indexable( $hidden ) );
	}

	public function test_enqueue_dedupes_and_take_pending_clears(): void {
		$store = array();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$store ) {
				return $store[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$store ) {
				$store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$store ) {
				unset( $store[ $name ] );
				return true;
			}
		);
		$this->indexnow->enqueue( array( 'https://shop.test/a', 'https://shop.test/b' ) );
		$this->indexnow->enqueue( array( 'https://shop.test/b', 'https://shop.test/c' ) );
		$pending = $this->indexnow->take_pending();
		sort( $pending );
		$this->assertSame( array( 'https://shop.test/a', 'https://shop.test/b', 'https://shop.test/c' ), $pending );
		$this->assertSame( array(), $this->indexnow->take_pending() ); // cleared
	}

	private function indexable_product( int $id ): \Mockery\MockInterface {
		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$p->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		return $p;
	}

	public function test_schedule_flush_guards_against_double_scheduling(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturnUsing(
			function ( $ts, $hook ) {
				$this->assertSame( WC_AI_Storefront_IndexNow::FLUSH_HOOK, $hook );
				return true;
			}
		);
		$this->indexnow->schedule_flush();
	}

	public function test_schedule_flush_noop_when_already_scheduled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 30 );
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->indexnow->schedule_flush();
	}

	public function test_on_product_change_enqueues_product_and_surfaces_when_indexable(): void {
		$captured = array();
		$store    = array();
		Functions\when( 'get_option' )->alias( static fn( $n, $d = false ) => $store[ $n ] ?? $d );
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$store, &$captured ) {
				$store[ $n ] = $v;
				$captured    = $v;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 30 );
		Functions\when( 'wc_get_product' )->justReturn( $this->indexable_product( 42 ) );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/x/' );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'product_selection_mode' => 'all' );
		$this->indexnow->on_product_change( 42 );
		$this->assertContains( 'https://shop.test/product/x/', $captured );
		$this->assertContains( 'https://shop.test/llms.txt', $captured );
	}

	public function test_on_product_change_skips_when_disabled(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no', 'indexnow_enabled' => 'yes' );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->indexnow->on_product_change( 42 );
		$this->addToAssertionCount( 1 );
	}

	public function test_on_product_removed_enqueues_permalink_unconditionally(): void {
		$captured = array();
		$store    = array();
		Functions\when( 'get_option' )->alias( static fn( $n, $d = false ) => $store[ $n ] ?? $d );
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$store, &$captured ) {
				$store[ $n ] = $v;
				$captured    = $v;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 30 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/removed/' );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		$this->indexnow->on_product_removed( 99 );
		$this->assertContains( 'https://shop.test/product/removed/', $captured );
		$this->assertContains( 'https://shop.test/llms.txt', $captured );
	}

	public function test_on_product_removed_noop_when_disabled(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no', 'indexnow_enabled' => 'yes' );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->indexnow->on_product_removed( 99 );
		$this->addToAssertionCount( 1 );
	}

	public function test_on_term_change_enqueues_term_link_and_surfaces(): void {
		$captured = array();
		$store    = array();
		Functions\when( 'get_option' )->alias( static fn( $n, $d = false ) => $store[ $n ] ?? $d );
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$store, &$captured ) {
				$store[ $n ] = $v;
				$captured    = $v;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 30 );
		Functions\when( 'get_term_link' )->justReturn( 'https://shop.test/product-category/gadgets/' );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		$this->indexnow->on_term_change( 7 );
		$this->assertContains( 'https://shop.test/product-category/gadgets/', $captured );
		$this->assertContains( 'https://shop.test/llms.txt', $captured );
	}

	public function test_on_term_change_noop_when_disabled(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no', 'indexnow_enabled' => 'yes' );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->indexnow->on_term_change( 7 );
		$this->addToAssertionCount( 1 );
	}
}
