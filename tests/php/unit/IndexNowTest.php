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
		$stored_key  = null;
		$option_name = null;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = '' ) {
				// Dedicated key option is empty — triggers generation.
				if ( 'wc_ai_storefront_indexnow_key' === $name ) {
					return '';
				}
				return $default;
			}
		);
		Functions\expect( 'update_option' )->once()->andReturnUsing(
			function ( $name, $value ) use ( &$stored_key, &$option_name ) {
				$option_name = $name;
				$stored_key  = $value;
				return true;
			}
		);
		$key = $this->indexnow->get_key();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key );
		$this->assertSame( 'wc_ai_storefront_indexnow_key', $option_name );
		$this->assertSame( $key, $stored_key );
	}

	public function test_get_key_returns_existing_without_regenerating(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = '' ) {
				if ( 'wc_ai_storefront_indexnow_key' === $name ) {
					return 'abc123abc123abc123abc123abc12300';
				}
				return $default;
			}
		);
		Functions\expect( 'update_option' )->never();
		$this->assertSame( 'abc123abc123abc123abc123abc12300', $this->indexnow->get_key() );
	}

	public function test_peek_key_returns_stored_value_without_generating(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = '' ) {
				if ( 'wc_ai_storefront_indexnow_key' === $name ) {
					return 'peek000peek000peek000peek000peek0';
				}
				return $default;
			}
		);
		Functions\expect( 'update_option' )->never();
		$this->assertSame( 'peek000peek000peek000peek000peek0', $this->indexnow->peek_key() );
	}

	public function test_peek_key_returns_empty_string_when_unset(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = '' ) {
				return $default; // nothing stored
			}
		);
		Functions\expect( 'update_option' )->never();
		$this->assertSame( '', $this->indexnow->peek_key() );
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
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = '' ) {
				if ( 'wc_ai_storefront_indexnow_key' === $name ) {
					return 'abcabcabcabcabcabcabcabcabc99';
				}
				return $default;
			}
		);
		Functions\when( 'get_query_var' )->justReturn( 'abcabcabcabcabcabcabcabcabc99' );
		Functions\expect( 'status_header' )->once()->with( 200 );
		ob_start();
		try {
			$this->indexnow->serve_key_file();
		} catch ( \WC_AI_Storefront_IndexNow_Exit $e ) {
			// serve_key_file() calls $this->terminate() which throws in tests.
		}
		$this->assertSame( 'abcabcabcabcabcabcabcabcabc99', ob_get_clean() );
	}

	public function test_serve_key_file_404_on_mismatch(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = '' ) {
				if ( 'wc_ai_storefront_indexnow_key' === $name ) {
					return 'realkeyrealkeyrealkeyrealkey0001';
				}
				return $default;
			}
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

	public function test_flush_posts_payload_with_host_key_and_urls(): void {
		$store = array(
			'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a' ),
			'wc_ai_storefront_indexnow_key'     => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
		);
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		$posted = null;
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
			function ( $url, $args ) use ( &$posted ) {
				$posted = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		// is_wp_error() and wp_parse_url() are defined in stubs.php before Patchwork
		// loads, so they cannot be redefined via Brain Monkey. The stubs work correctly:
		// is_wp_error() returns false for plain arrays; wp_parse_url() extracts the host.
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		$this->indexnow->flush();
		$this->assertSame( 'https://api.indexnow.org/indexnow', $posted['url'] );
		$this->assertSame( 'shop.test', $posted['body']['host'] );
		$this->assertSame( 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0', $posted['body']['key'] );
		$this->assertSame( array( 'https://shop.test/a' ), $posted['body']['urlList'] );
	}

	public function test_flush_noop_when_disabled(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no', 'indexnow_enabled' => 'yes' );
		Functions\when( 'get_option' )->justReturn( array( 'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a' ) ) );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\expect( 'wp_remote_post' )->never();
		$this->indexnow->flush();
		$this->addToAssertionCount( 1 );
	}

	public function test_flush_noop_when_queue_empty(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $n, $d = false ) => 'wc_ai_storefront_indexnow_key' === $n
				? 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0'
				: ( $d )
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\expect( 'wp_remote_post' )->never();
		$this->indexnow->flush();
		$this->addToAssertionCount( 1 );
	}

	public function test_flush_requeues_and_reschedules_on_429(): void {
		$store = array(
			'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a' ),
			'wc_ai_storefront_indexnow_key'     => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
		);
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		$requeued = null;
		$recorded = null;
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$requeued, &$recorded ) {
				if ( 'wc_ai_storefront_indexnow_pending' === $n ) {
					$requeued = $v;
				} elseif ( 'wc_ai_storefront_indexnow_last_result' === $n ) {
					$recorded = $v;
				}
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		// wp_parse_url() and is_wp_error() are defined in stubs.php before Patchwork
		// loads and cannot be redefined. The stubs work correctly for these inputs.
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 429 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );
		$this->indexnow->flush();
		$this->assertSame( array( 'https://shop.test/a' ), $requeued );
		// record_result fired for the throttled batch: count, HTTP 429, not ok.
		$this->assertNotNull( $recorded );
		$this->assertSame( 1, $recorded['count'] );
		$this->assertSame( 429, $recorded['code'] );
		$this->assertFalse( $recorded['ok'] );
	}

	public function test_flush_drops_without_requeue_on_403(): void {
		$store = array(
			'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a' ),
			'wc_ai_storefront_indexnow_key'     => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
		);
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		$requeued = null;
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$requeued ) {
				if ( 'wc_ai_storefront_indexnow_pending' === $n ) {
					$requeued = $v;
				}
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		// wp_parse_url() and is_wp_error() are defined in stubs.php before Patchwork
		// loads and cannot be redefined. The stubs work correctly for these inputs.
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 403 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		// Never schedule a retry on 403 (non-retryable, drop the URLs).
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->indexnow->flush();
		// Assert that the URLs were NOT re-queued (remain null, never set).
		$this->assertNull( $requeued );
	}

	public function test_flush_noop_when_disabled_clears_queue(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no', 'indexnow_enabled' => 'yes' );
		$store = array( 'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a' ) );
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		Functions\expect( 'delete_option' )->once()->with( 'wc_ai_storefront_indexnow_pending' );
		Functions\expect( 'wp_remote_post' )->never();
		$this->indexnow->flush();
		$this->addToAssertionCount( 1 );
	}

	public function test_flush_requeues_and_reschedules_on_transport_error(): void {
		$store = array(
			'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a' ),
			'wc_ai_storefront_indexnow_key'     => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
		);
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		$requeued = null;
		$recorded = null;
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$requeued, &$recorded ) {
				if ( 'wc_ai_storefront_indexnow_pending' === $n ) {
					$requeued = $v;
				} elseif ( 'wc_ai_storefront_indexnow_last_result' === $n ) {
					$recorded = $v;
				}
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		// is_wp_error() in stubs.php checks instanceof WP_Error.
		$wp_error = new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' );
		Functions\when( 'wp_remote_post' )->justReturn( $wp_error );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );
		$this->indexnow->flush();
		$this->assertSame( array( 'https://shop.test/a' ), $requeued );
		// record_result fired for the transport error: count, code 0, not ok.
		$this->assertNotNull( $recorded );
		$this->assertSame( 1, $recorded['count'] );
		$this->assertSame( 0, $recorded['code'] );
		$this->assertFalse( $recorded['ok'] );
	}

	public function test_flush_treats_202_as_success(): void {
		$store = array(
			'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a' ),
			'wc_ai_storefront_indexnow_key'     => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
		);
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		$requeued = null;
		$recorded = null;
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$requeued, &$recorded ) {
				if ( 'wc_ai_storefront_indexnow_pending' === $n ) {
					$requeued = $v;
				} elseif ( 'wc_ai_storefront_indexnow_last_result' === $n ) {
					$recorded = $v;
				}
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 202 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 202 );
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->indexnow->flush();
		$this->assertNull( $requeued );
		// 202 is recorded as a success: count, HTTP 202, ok.
		$this->assertNotNull( $recorded );
		$this->assertSame( 1, $recorded['count'] );
		$this->assertSame( 202, $recorded['code'] );
		$this->assertTrue( $recorded['ok'] );
	}

	public function test_is_product_indexable_false_when_out_of_scope(): void {
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'indexnow_enabled'       => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => array( 999 ), // different ID — product 42 is not in scope
		);
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$product->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		$this->assertFalse( $this->indexnow->is_product_indexable( $product ) );
	}

	public function test_serve_key_file_does_not_mint_key_when_no_key_exists(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = '' ) {
				// KEY_OPTION returns empty — no key persisted yet.
				if ( 'wc_ai_storefront_indexnow_key' === $name ) {
					return '';
				}
				return $default;
			}
		);
		Functions\when( 'get_query_var' )->justReturn( 'abcabcabcabcabcabcabcabcabc99' );
		// Feature is enabled — the empty-key guard must still fire before
		// is_enabled() is even checked, and must NOT trigger key generation.
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'status_header' )->once()->with( 404 );
		try {
			$this->indexnow->serve_key_file();
		} catch ( \WC_AI_Storefront_IndexNow_Exit $e ) {
			$this->addToAssertionCount( 1 );
		}
	}

	// --- Task 2: last_result tracking (#534) ---

	public function test_last_result_empty_when_unset(): void {
		// `false` (the raw get_option miss) exercises the is_array() guard.
		Functions\when( 'get_option' )->justReturn( false );
		$this->assertSame( array(), $this->indexnow->last_result() );
	}

	public function test_flush_records_success_result(): void {
		$store = array( 'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a', 'https://shop.test/b' ) );
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		$recorded = null;
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$recorded ) {
				if ( 'wc_ai_storefront_indexnow_last_result' === $n ) { $recorded = $v; }
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 200 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		// time() is a PHP internal Patchwork can't redefine here, so bracket the
		// flush and assert the stamp is the real current time, not 0/omitted.
		$before = time();
		$this->indexnow->flush();
		$after = time();
		$this->assertSame( 2, $recorded['count'] );
		$this->assertSame( 200, $recorded['code'] );
		$this->assertTrue( $recorded['ok'] );
		$this->assertGreaterThanOrEqual( $before, $recorded['time'] );
		$this->assertLessThanOrEqual( $after, $recorded['time'] );
	}

	public function test_flush_records_failure_result_on_403(): void {
		$store = array( 'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a' ) );
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		$recorded = null;
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$recorded ) {
				if ( 'wc_ai_storefront_indexnow_last_result' === $n ) { $recorded = $v; }
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 403 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		$before = time();
		$this->indexnow->flush();
		$after = time();
		$this->assertSame( 1, $recorded['count'] );
		$this->assertSame( 403, $recorded['code'] );
		$this->assertFalse( $recorded['ok'] );
		$this->assertGreaterThanOrEqual( $before, $recorded['time'] );
		$this->assertLessThanOrEqual( $after, $recorded['time'] );
	}

	// --- Task #540: submit_all() + schedule_submit_all() + SUBMIT_ALL_HOOK ---

	public function test_submit_all_noop_when_disabled(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no', 'indexnow_enabled' => 'yes' );
		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'update_option' )->never();
		$this->indexnow->submit_all();
		$this->addToAssertionCount( 1 );
	}

	public function test_submit_all_gathers_product_category_and_surface_urls_and_posts(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'product_selection_mode' => 'all' );

		// Option store for pending + key.
		$store = array(
			'wc_ai_storefront_indexnow_key' => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
		);
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$store ) {
				$store[ $n ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $n ) use ( &$store ) {
				unset( $store[ $n ] );
				return true;
			}
		);

		// surface_urls() needs wc_get_page_id.
		Functions\when( 'wc_get_page_id' )->justReturn( 0 ); // skip shop URL.

		// all_product_urls(): first page returns 1 product, second page 0 (stop).
		$product = $this->indexable_product( 77 );
		Functions\when( 'wc_get_products' )->alias(
			static function ( array $args ) use ( $product ) {
				return 1 === $args['page'] ? array( $product ) : array();
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static function ( $id ) {
				return 'https://shop.test/product/p' . $id . '/';
			}
		);

		// all_category_urls(): one term.
		$term             = new stdClass();
		$term->term_id    = 5;
		$term->name       = 'Gadgets';
		$term->slug       = 'gadgets';
		$term->count      = 3;
		$term->parent     = 0;
		$term->taxonomy   = 'product_cat';
		$term->term_group = 0;
		Functions\when( 'get_terms' )->justReturn( array( $term ) );
		Functions\when( 'get_term_link' )->justReturn( 'https://shop.test/product-category/gadgets/' );

		// flush() will POST.
		$posted = null;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$posted ) {
				$posted = json_decode( $args['body'], true );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$this->indexnow->submit_all();

		$this->assertNotNull( $posted, 'wp_remote_post should have been called' );
		$url_list = $posted['urlList'] ?? array();
		$this->assertContains( 'https://shop.test/', $url_list, 'home_url(/) should be in urlList' );
		$this->assertContains( 'https://shop.test/product/p77/', $url_list, 'product permalink should be in urlList' );
		$this->assertContains( 'https://shop.test/product-category/gadgets/', $url_list, 'category link should be in urlList' );

		// last_result() should have recorded the count.
		$result = $this->indexnow->last_result();
		$this->assertNotEmpty( $result );
		$this->assertSame( count( $url_list ), $result['count'] );
	}

	public function test_schedule_submit_all_schedules_hook_once(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturnUsing(
			function ( $ts, $hook ) {
				$this->assertSame( WC_AI_Storefront_IndexNow::SUBMIT_ALL_HOOK, $hook );
				$this->assertGreaterThanOrEqual( time(), $ts );
				return true;
			}
		);
		$this->indexnow->schedule_submit_all();
	}

	public function test_schedule_submit_all_noop_when_already_scheduled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 5 );
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->indexnow->schedule_submit_all();
	}

	// --- Seed-on-enable: update_settings() no→yes transition (#540) ---
	//
	// The stub records the transition in $_seed_transition_detected (a flag) rather
	// than calling real WP cron functions, because UpdateSettingsSanitizationTest
	// does not set up Brain Monkey. The production path (production class
	// WC_AI_Storefront::update_settings()) calls schedule_submit_all() directly;
	// the tests below verify (a) the stub detects the transition correctly and
	// (b) schedule_submit_all() itself behaves correctly (already covered above).

	public function test_stub_detects_seed_transition_no_to_yes(): void {
		WC_AI_Storefront::$_seed_transition_detected = false;
		WC_AI_Storefront::$test_settings             = array( 'enabled' => 'yes', 'indexnow_enabled' => 'no' );
		// Prevent unexpected-call errors for Brain Monkey WP presets not called here.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		WC_AI_Storefront::update_settings( array( 'indexnow_enabled' => 'yes' ) );
		$this->assertTrue( WC_AI_Storefront::$_seed_transition_detected );
	}

	public function test_stub_no_transition_when_already_yes(): void {
		WC_AI_Storefront::$_seed_transition_detected = false;
		WC_AI_Storefront::$test_settings             = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes' );
		WC_AI_Storefront::update_settings( array( 'indexnow_enabled' => 'yes' ) );
		$this->assertFalse( WC_AI_Storefront::$_seed_transition_detected );
	}

	public function test_stub_no_transition_when_remains_no(): void {
		WC_AI_Storefront::$_seed_transition_detected = false;
		WC_AI_Storefront::$test_settings             = array( 'enabled' => 'yes', 'indexnow_enabled' => 'no' );
		WC_AI_Storefront::update_settings( array( 'indexnow_enabled' => 'no' ) );
		$this->assertFalse( WC_AI_Storefront::$_seed_transition_detected );
	}
}
