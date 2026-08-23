<?php
/**
 * Tests for WC_AI_Storefront_IndexNow.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

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
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'yes',
			'indexnow_enabled' => 'yes',
		);
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
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'yes',
			'indexnow_enabled' => 'no',
		);
		$this->assertFalse( $this->indexnow->is_enabled() );
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'no',
			'indexnow_enabled' => 'yes',
		);
		$this->assertFalse( $this->indexnow->is_enabled() );
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'yes',
			'indexnow_enabled' => 'yes',
		);
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

	public function test_surface_urls_are_pages_meant_for_organic_search(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		$urls = $this->indexnow->surface_urls();
		$this->assertContains( 'https://shop.test/', $urls );
		$this->assertContains( 'https://shop.test/shop/', $urls );
		$this->assertContains( 'https://shop.test/llms.txt', $urls );

		// products.json is deliberately absent, and putting it back is a
		// regression rather than a fix. The feed serves
		// `X-Robots-Tag: noindex` when it is enabled, so submitting it asks
		// engines to re-crawl a URL we then tell them not to index; and when
		// the merchant switches the feed off it is a hard 404, so we would be
		// submitting a known-dead URL on every catalog change (#694).
		$this->assertNotContains( 'https://shop.test/products.json', $urls );
	}

	public function test_is_product_indexable_true_for_published_visible_syndicated(): void {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$product->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		// is_product_syndicated() is a static on WC_AI_Storefront; settings mode 'all' => true.
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'indexnow_enabled'       => 'yes',
			'product_selection_mode' => 'all',
		);
		$this->assertTrue( $this->indexnow->is_product_indexable( $product ) );
	}

	public function test_is_product_indexable_false_for_hidden_or_draft(): void {
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'indexnow_enabled'       => 'yes',
			'product_selection_mode' => 'all',
		);
		$draft                           = \Mockery::mock( 'WC_Product' );
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
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'indexnow_enabled'       => 'yes',
			'product_selection_mode' => 'all',
		);
		$this->indexnow->on_product_change( 42 );
		$this->assertContains( 'https://shop.test/product/x/', $captured );
		$this->assertContains( 'https://shop.test/llms.txt', $captured );
	}

	public function test_on_product_change_skips_when_disabled(): void {
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'no',
			'indexnow_enabled' => 'yes',
		);
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
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'no',
			'indexnow_enabled' => 'yes',
		);
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
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'no',
			'indexnow_enabled' => 'yes',
		);
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
		// Stateful delete, so take_batch() can actually drain the queue. As a
		// no-op the store never emptied and flush() then reached the unstubbed
		// wp_next_scheduled() (#698).
		Functions\when( 'delete_option' )->alias(
			static function ( $n ) use ( &$store ) {
				unset( $store[ $n ] );
				return true;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		$posted = null;
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
			function ( $url, $args ) use ( &$posted ) {
				$posted = array(
					'url'  => $url,
					'body' => json_decode( $args['body'], true ),
				);
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
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'no',
			'indexnow_enabled' => 'yes',
		);
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
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'no',
			'indexnow_enabled' => 'yes',
		);
		$store                           = array( 'wc_ai_storefront_indexnow_pending' => array( 'https://shop.test/a' ) );
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
		// Stateful delete, so take_batch() can actually drain the queue. As a
		// no-op the store never emptied and flush() then reached the unstubbed
		// wp_next_scheduled() (#698).
		Functions\when( 'delete_option' )->alias(
			static function ( $n ) use ( &$store ) {
				unset( $store[ $n ] );
				return true;
			}
		);
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
		$product                         = \Mockery::mock( 'WC_Product' );
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
				if ( 'wc_ai_storefront_indexnow_last_result' === $n ) {
					$recorded = $v; }
				return true;
			}
		);
		// Stateful delete, so take_batch() can actually drain the queue. As a
		// no-op the store never emptied and flush() then reached the unstubbed
		// wp_next_scheduled() (#698).
		Functions\when( 'delete_option' )->alias(
			static function ( $n ) use ( &$store ) {
				unset( $store[ $n ] );
				return true;
			}
		);
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
				if ( 'wc_ai_storefront_indexnow_last_result' === $n ) {
					$recorded = $v; }
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
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'no',
			'indexnow_enabled' => 'yes',
		);
		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'update_option' )->never();
		$this->indexnow->submit_all();
		$this->addToAssertionCount( 1 );
	}

	public function test_submit_all_noop_when_indexnow_toggle_off(): void {
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'yes',
			'indexnow_enabled' => 'no',
		);
		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'update_option' )->never();
		$this->indexnow->submit_all();
		$this->addToAssertionCount( 1 );
	}

	public function test_submit_all_gathers_product_category_and_surface_urls_and_posts(): void {
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'indexnow_enabled'       => 'yes',
			'product_selection_mode' => 'all',
		);

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

	// B2a — deactivate() clears BOTH cron hooks.
	public function test_deactivate_clears_both_flush_and_submit_all_hooks(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()->with( WC_AI_Storefront_IndexNow::FLUSH_HOOK );
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()->with( WC_AI_Storefront_IndexNow::SUBMIT_ALL_HOOK );
		WC_AI_Storefront_IndexNow::deactivate();
	}

	// B2c — all_product_urls(): wc_get_products returns false (non-array) on first page.
	public function test_submit_all_handles_non_array_wc_get_products(): void {
		Functions\when( 'wc_get_products' )->justReturn( false );
		// surface_urls() needs these helpers.
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'get_terms' )->justReturn( array() );
		// Still expect enqueue+flush with just the surface URLs.
		$store  = array( 'wc_ai_storefront_indexnow_key' => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0' );
		$posted = null;
		Functions\when( 'get_option' )->alias(
			function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $n, $v ) use ( &$store ) {
				$store[ $n ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $n ) use ( &$store ) {
				unset( $store[ $n ] );
				return true;
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$posted ) {
				$posted = json_decode( $args['body'], true );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		$this->indexnow->submit_all();
		// POST still fires with at least the home surface URL; no crash.
		$this->assertNotNull( $posted );
		$this->assertContains( 'https://shop.test/', $posted['urlList'] );
	}

	// B2d — all_category_urls(): get_terms returns WP_Error — contributes nothing, no crash.
	public function test_submit_all_handles_wp_error_from_get_terms(): void {
		Functions\when( 'wc_get_products' )->justReturn( array() );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'get_terms' )->justReturn( new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ) );
		$store  = array( 'wc_ai_storefront_indexnow_key' => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0' );
		$posted = null;
		Functions\when( 'get_option' )->alias(
			function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $n, $v ) use ( &$store ) {
				$store[ $n ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $n ) use ( &$store ) {
				unset( $store[ $n ] );
				return true;
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$posted ) {
				$posted = json_decode( $args['body'], true );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		$this->indexnow->submit_all();
		// POST fires with surfaces only (WP_Error contributes no category URLs).
		$this->assertNotNull( $posted );
		// Verify no category URL leaked in. wc_get_page_id is 0 in this test,
		// so surface_urls() skips the shop branch: home and llms.txt only.
		foreach ( $posted['urlList'] as $url ) {
			$this->assertStringNotContainsString( 'product-category', $url );
		}
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
		WC_AI_Storefront::$test_settings             = array(
			'enabled'          => 'yes',
			'indexnow_enabled' => 'no',
		);
		// Prevent unexpected-call errors for Brain Monkey WP presets not called here.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		WC_AI_Storefront::update_settings( array( 'indexnow_enabled' => 'yes' ) );
		$this->assertTrue( WC_AI_Storefront::$_seed_transition_detected );
	}

	public function test_stub_no_transition_when_already_yes(): void {
		WC_AI_Storefront::$_seed_transition_detected = false;
		WC_AI_Storefront::$test_settings             = array(
			'enabled'          => 'yes',
			'indexnow_enabled' => 'yes',
		);
		WC_AI_Storefront::update_settings( array( 'indexnow_enabled' => 'yes' ) );
		$this->assertFalse( WC_AI_Storefront::$_seed_transition_detected );
	}

	public function test_stub_no_transition_when_remains_no(): void {
		WC_AI_Storefront::$_seed_transition_detected = false;
		WC_AI_Storefront::$test_settings             = array(
			'enabled'          => 'yes',
			'indexnow_enabled' => 'no',
		);
		WC_AI_Storefront::update_settings( array( 'indexnow_enabled' => 'no' ) );
		$this->assertFalse( WC_AI_Storefront::$_seed_transition_detected );
	}

	// --- Canonical-redirect suppression for the key file (#542). IndexNow
	// validators fetch the exact /{key}.txt; without this, trailing-slash
	// permalink sites 301 it to /{key}.txt/ and validation fails. ---

	public function test_canonical_redirect_suppressed_for_key_file(): void {
		Functions\when( 'get_query_var' )->alias(
			static fn( $var ) => 'wc_ai_storefront_indexnow_key' === $var
				? 'abc123abc123abc123abc123abc12300'
				: ''
		);
		$this->assertFalse(
			$this->indexnow->suppress_canonical_redirect( 'https://example.com/abc123abc123abc123abc123abc12300.txt/' )
		);
	}

	public function test_canonical_redirect_untouched_when_key_var_not_set(): void {
		Functions\when( 'get_query_var' )->justReturn( '' );
		$this->assertSame(
			'https://example.com/some-page/',
			$this->indexnow->suppress_canonical_redirect( 'https://example.com/some-page/' )
		);
	}

	// --- #569: brand archive URL support ---
	//
	// IndexNow submits products + product-category URLs but not brand
	// (product_brand) archives. These tests mirror the category-parity
	// behavior: enumeration via all_brand_urls(), inclusion in submit_all(),
	// and per-term change detection via on_brand_change().

	public function test_submit_all_includes_brand_urls(): void {
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'indexnow_enabled'       => 'yes',
			'product_selection_mode' => 'all',
		);

		$store = array( 'wc_ai_storefront_indexnow_key' => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0' );
		// get_option MUST capture $store by reference (`use ( &$store )`), not
		// via an arrow fn — arrow functions capture by value, so a snapshot of
		// the empty $store would be read back and enqueue()'s writes (which use
		// &$store) would be invisible to take_pending(), emptying the queue.
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
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'wc_get_products' )->justReturn( array() );

		// get_terms() branches on taxonomy so category and brand return
		// distinct terms — proving both taxonomies are queried.
		$cat_term             = new stdClass();
		$cat_term->term_id    = 5;
		$cat_term->taxonomy   = 'product_cat';
		$brand_term           = new stdClass();
		$brand_term->term_id  = 8;
		$brand_term->taxonomy = 'product_brand';
		Functions\when( 'get_terms' )->alias(
			static function ( array $args ) use ( $cat_term, $brand_term ) {
				if ( 'product_brand' === $args['taxonomy'] ) {
					return array( $brand_term );
				}
				return array( $cat_term );
			}
		);
		Functions\when( 'get_term_link' )->alias(
			static function ( $term ) {
				if ( is_object( $term ) && 'product_brand' === ( $term->taxonomy ?? '' ) ) {
					return 'https://shop.test/brand/acme/';
				}
				return 'https://shop.test/product-category/gadgets/';
			}
		);

		$posted = null;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$posted ) {
				$posted = json_decode( $args['body'], true );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$this->indexnow->submit_all();

		$url_list = $posted['urlList'] ?? array();
		$this->assertContains( 'https://shop.test/brand/acme/', $url_list, 'brand archive link should be in urlList' );
		$this->assertContains( 'https://shop.test/product-category/gadgets/', $url_list, 'category link should still be in urlList' );
	}

	public function test_all_brand_urls_returns_empty_on_wp_error(): void {
		// A store without the product_brand taxonomy: get_terms() yields a
		// WP_Error, which the is_wp_error() guard converts to no brand URLs.
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'indexnow_enabled'       => 'yes',
			'product_selection_mode' => 'all',
		);

		$store = array( 'wc_ai_storefront_indexnow_key' => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0' );
		// get_option MUST capture $store by reference (`use ( &$store )`), not
		// via an arrow fn — arrow functions capture by value, so a snapshot of
		// the empty $store would be read back and enqueue()'s writes (which use
		// &$store) would be invisible to take_pending(), emptying the queue.
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
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'wc_get_products' )->justReturn( array() );
		// Both category and brand get_terms() return WP_Error (taxonomy absent).
		Functions\when( 'get_terms' )->justReturn( new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ) );

		$posted = null;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$posted ) {
				$posted = json_decode( $args['body'], true );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$this->indexnow->submit_all();

		$this->assertNotNull( $posted );
		// No brand URL leaked in (WP_Error contributes nothing); only surfaces.
		foreach ( $posted['urlList'] as $url ) {
			$this->assertStringNotContainsString( '/brand/', $url );
		}
	}

	public function test_on_brand_change_enqueues_term_link_and_surfaces(): void {
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
		Functions\when( 'get_term_link' )->justReturn( 'https://shop.test/brand/acme/' );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		$this->indexnow->on_brand_change( 8 );
		$this->assertContains( 'https://shop.test/brand/acme/', $captured );
		$this->assertContains( 'https://shop.test/llms.txt', $captured );
	}

	public function test_on_brand_change_noop_when_disabled(): void {
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'no',
			'indexnow_enabled' => 'yes',
		);
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->indexnow->on_brand_change( 8 );
		$this->addToAssertionCount( 1 );
	}

	public function test_on_brand_change_drops_wp_error_link(): void {
		// get_term_link() returns WP_Error for an invalid term id; the
		// is_string() guard drops it so no bad URL is enqueued (only surfaces).
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
		Functions\when( 'get_term_link' )->justReturn( new WP_Error( 'invalid_term', 'Empty Term.' ) );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		$this->indexnow->on_brand_change( 999 );
		// Surfaces still enqueued, but no brand URL.
		$this->assertContains( 'https://shop.test/llms.txt', $captured );
		foreach ( $captured as $url ) {
			$this->assertStringNotContainsString( '/brand/', $url );
		}
	}

	public function test_init_registers_the_term_relationship_hook(): void {
		// The ONLY wiring that makes a term change reach
		// on_product_terms_changed() in production. Every other test in this
		// group calls the handler directly, so without this a dropped
		// add_action line or a typo ships the feature dead with a green suite
		// (#695 review). Same reasoning as the brand test below.
		//
		// The accepted-argument count is asserted, not just the callback, and
		// that assertion is load-bearing: the handler takes six REQUIRED
		// parameters, so registering it for fewer would throw an uncaught
		// ArgumentCountError on every wp_set_object_terms() call site-wide —
		// a fatal on every product save, every import, every term edit.
		$indexnow = new WC_AI_Storefront_IndexNow();

		\Brain\Monkey\Actions\expectAdded( 'set_object_terms' )
			->once()
			->with(
				\Mockery::on(
					static function ( $callback ) use ( $indexnow ): bool {
						return is_array( $callback )
							&& $callback[0] === $indexnow
							&& 'on_product_terms_changed' === $callback[1];
					}
				),
				10,
				6
			);

		$indexnow->init();

		$this->addToAssertionCount( 1 );
	}

	public function test_seen_marker_is_per_product_not_per_request(): void {
		// Keying the marker by anything constant would make the FIRST product
		// in a request suppress every other one. A bulk term assignment across
		// 50 products would submit one URL and silently drop 49 (#695 review).
		$this->stub_term_change_product();

		$make = static function ( int $id ) {
			$product = \Mockery::mock( 'WC_Product' );
			$product->shouldReceive( 'get_id' )->andReturn( $id );
			$product->shouldReceive( 'get_status' )->andReturn( 'publish' );
			$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
			return $product;
		};
		Functions\when( 'wc_get_product' )->alias( static fn( $id ) => $make( (int) $id ) );
		Functions\when( 'get_permalink' )->alias(
			static function ( $id = 0 ) {
				if ( 5 === (int) $id ) {
					return 'https://shop.test/shop/';
				}
				return 'https://shop.test/product/p' . (int) $id . '/';
			}
		);

		$urls = $this->capture_enqueued(
			function () {
				$this->indexnow->on_product_terms_changed( 14, array( 'a' ), array( 22 ), 'product_cat', false, array() );
				$this->indexnow->on_product_terms_changed( 15, array( 'a' ), array( 22 ), 'product_cat', false, array() );
			}
		);

		$this->assertContains( 'https://shop.test/product/p14/', $urls );
		$this->assertContains( 'https://shop.test/product/p15/', $urls );
	}

	public function test_a_guard_failure_does_not_mark_the_product_seen(): void {
		// The marker is set AFTER the guards on purpose. Set it earlier and a
		// product that fails one guard on its first fire swallows a genuine
		// change later in the same request — and WooCommerce writes
		// product_visibility terms during the very save that publishes a
		// product, so draft-then-publish is a real trigger (#695 review).
		$this->stub_term_change_product();

		$draft = \Mockery::mock( 'WC_Product' );
		$draft->shouldReceive( 'get_id' )->andReturn( 14 );
		$draft->shouldReceive( 'get_status' )->andReturn( 'draft' );
		$draft->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );

		$live = \Mockery::mock( 'WC_Product' );
		$live->shouldReceive( 'get_id' )->andReturn( 14 );
		$live->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$live->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );

		$call = 0;
		Functions\when( 'wc_get_product' )->alias(
			static function () use ( &$call, $draft, $live ) {
				++$call;
				return 1 === $call ? $draft : $live;
			}
		);

		$urls = $this->capture_enqueued(
			function () {
				$this->indexnow->on_product_terms_changed( 14, array( 'a' ), array( 22 ), 'product_visibility', false, array() );
				$this->indexnow->on_product_terms_changed( 14, array( 'b' ), array( 31 ), 'product_cat', false, array() );
			}
		);

		$this->assertContains( 'https://shop.test/product/hoodie/', $urls );
	}

	/**
	 * A stateful option store shared by get/update/delete.
	 *
	 * Batching is only testable if the queue actually changes between calls.
	 *
	 * @param array $initial Seed options.
	 * @return array{0:\ArrayObject} The shared store, held by object handle
	 *                               rather than by reference, which is what
	 *                               lets this drop the `use ( &$store )`
	 *                               pattern the older stubs still need.
	 */
	private function stub_option_store( array $initial = array() ): array {
		$box = new \ArrayObject( $initial );
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( $box ) {
				return $box->offsetExists( $n ) ? $box->offsetGet( $n ) : $d;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( $box ) {
				$box->offsetSet( $n, $v );
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $n ) use ( $box ) {
				if ( $box->offsetExists( $n ) ) {
					$box->offsetUnset( $n );
				}
				return true;
			}
		);
		return array( $box );
	}

	/**
	 * @param int $count How many synthetic URLs.
	 * @return string[]
	 */
	private function synthetic_urls( int $count ): array {
		$urls = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$urls[] = 'https://shop.test/product/p' . $i . '/';
		}
		return $urls;
	}

	public function test_enqueue_no_longer_caps_at_the_per_post_batch_size(): void {
		// Replaces test_enqueue_caps_the_pending_set_and_keeps_the_oldest, which
		// pinned the behaviour #698 removes. 10,000 is the spec's limit PER POST,
		// not a ceiling on the queue, so 25,000 URLs must all survive enqueue and
		// go out as three requests.
		list( $box ) = $this->stub_option_store();

		$this->indexnow->enqueue( $this->synthetic_urls( 25000 ) );

		$this->assertCount( 25000, $box->offsetGet( 'wc_ai_storefront_indexnow_pending' ) );
	}

	public function test_enqueue_still_caps_at_the_runaway_guard_and_records_the_drop(): void {
		// The guard survives, at MAX_PENDING rather than BATCH_SIZE, and the
		// drop is now recorded where a human can see it. A debug log defaults to
		// off, which is how this stayed invisible.
		list( $box ) = $this->stub_option_store();

		$this->indexnow->enqueue( $this->synthetic_urls( 25005 ) );

		$pending = $box->offsetGet( 'wc_ai_storefront_indexnow_pending' );
		$this->assertCount( 25000, $pending );
		$this->assertSame( 5, $box->offsetGet( 'wc_ai_storefront_indexnow_dropped' ) );

		// WHICH end is cut is behaviour, not an accident, and the test this
		// replaced was the only thing pinning it. Without these two the
		// direction could flip to keep-newest and stay green, leaving a code
		// comment as the sole statement of it (#699 review).
		$this->assertContains( 'https://shop.test/product/p0/', $pending, 'oldest kept' );
		$this->assertNotContains( 'https://shop.test/product/p25004/', $pending, 'newest dropped' );
	}

	public function test_a_large_queue_drains_in_batches_of_the_spec_limit(): void {
		// 25,000 queued URLs must leave as three POSTs of at most 10,000, one
		// per flush, rescheduling between. No batching loop exists anywhere;
		// this falls out of flush() taking one batch and rescheduling.
		list( $box ) = $this->stub_option_store(
			array(
				'wc_ai_storefront_indexnow_pending' => $this->synthetic_urls( 25000 ),
				'wc_ai_storefront_indexnow_key'     => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
			)
		);
		$sizes       = array();
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$sizes ) {
				$sizes[] = count( json_decode( $args['body'], true )['urlList'] );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		$scheduled = 0;
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function () use ( &$scheduled ) {
				++$scheduled;
				return true;
			}
		);

		$this->indexnow->flush();
		$this->assertSame( array( 10000 ), $sizes, 'first flush sends exactly one batch' );
		$this->assertCount( 15000, $box->offsetGet( 'wc_ai_storefront_indexnow_pending' ), 'the rest stays queued' );
		// The reschedule IS the mechanism. Without it a large queue sends one
		// batch and then stalls until some unrelated change happens to schedule
		// a flush, which is the whole bug wearing a different hat.
		$this->assertSame( 1, $scheduled, 'more remains, so another flush must be scheduled' );

		$this->indexnow->flush();
		$this->assertSame( 2, $scheduled );

		$this->indexnow->flush();

		$this->assertSame( array( 10000, 10000, 5000 ), $sizes );
		$this->assertFalse( $box->offsetExists( 'wc_ai_storefront_indexnow_pending' ), 'queue drained' );
		$this->assertSame( 2, $scheduled, 'nothing left, so the chain stops' );
	}

	public function test_a_429_requeues_its_batch_without_disturbing_the_rest(): void {
		// The reason no batching loop exists: a 429 partway through a large
		// queue cannot cascade into the remaining chunks, because there is no
		// loop to carry on with.
		list( $box ) = $this->stub_option_store(
			array(
				'wc_ai_storefront_indexnow_pending' => $this->synthetic_urls( 25000 ),
				'wc_ai_storefront_indexnow_key'     => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
			)
		);
		$posts       = 0;
		Functions\when( 'wp_remote_post' )->alias(
			static function () use ( &$posts ) {
				++$posts;
				return array( 'response' => array( 'code' => 429 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		$scheduled = 0;
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function () use ( &$scheduled ) {
				++$scheduled;
				return true;
			}
		);

		$this->indexnow->flush();

		// The re-queue and the retry are what this test is for. The POST count
		// was the old headline assertion and proved nothing: flush() makes
		// exactly one POST for any status code, so it passed with the stub set
		// to 200 (#699 review). No-cascade is pinned by the drain test's size
		// sequence instead.
		$this->assertCount( 25000, $box->offsetGet( 'wc_ai_storefront_indexnow_pending' ), 'nothing lost' );
		$this->assertSame( 1, $scheduled, 'the re-queued batch must actually get retried' );
		$this->assertSame( 1, $posts );
	}

	public function test_a_403_clears_the_whole_queue_rather_than_orphaning_it(): void {
		// 403 and 422 are conditions of the SITE, so the next batch fails the
		// same way: retrying is pointless. But the earlier version of this test
		// asserted the remainder simply STAYED queued with nothing scheduled,
		// and called that success. Before batching that state could not exist,
		// because take_pending() emptied the queue; take_batch() made it
		// reachable and this test codified it (#699 review).
		list( $box ) = $this->stub_option_store(
			array(
				'wc_ai_storefront_indexnow_pending' => $this->synthetic_urls( 15000 ),
				'wc_ai_storefront_indexnow_key'     => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
			)
		);
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 403 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		$scheduled = 0;
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function () use ( &$scheduled ) {
				++$scheduled;
				return true;
			}
		);

		$this->indexnow->flush();

		$this->assertSame( 0, $scheduled, 'a structurally invalid request must not be retried' );
		$this->assertFalse(
			$box->offsetExists( 'wc_ai_storefront_indexnow_pending' ),
			'the remainder must be cleared, not left queued with nothing scheduled to send it'
		);
	}

	public function test_take_batch_of_zero_leaves_the_queue_alone(): void {
		// A caller asking for nothing says nothing about the queue. Grouped
		// with the unusable-queue conditions, $size < 1 deleted every queued
		// URL and returned an empty batch indistinguishable from a drained one.
		// Unreachable today, and one `apply_filters` on the batch size away
		// from a merchant snippet returning 0 to "pause submissions" wiping
		// 25,000 URLs instead (#699 review).
		list( $box ) = $this->stub_option_store(
			array( 'wc_ai_storefront_indexnow_pending' => $this->synthetic_urls( 5 ) )
		);

		// No setAccessible(): it has been a no-op since PHP 8.1 and calling it
		// raises a deprecation on 8.5.
		$method = new \ReflectionMethod( $this->indexnow, 'take_batch' );

		foreach ( array( 0, -1 ) as $size ) {
			$this->assertSame( array(), $method->invoke( $this->indexnow, $size ) );
			$this->assertCount(
				5,
				$box->offsetGet( 'wc_ai_storefront_indexnow_pending' ),
				"asking for $size URLs must not destroy the ones that are queued"
			);
		}
	}

	public function test_the_drop_count_survives_every_batch_and_clears_only_on_drain(): void {
		// The counter was written and then never checked again. Six ways of
		// breaking everything downstream of enqueue() survived mutation,
		// including deleting `dropped` from the recorded result entirely, which
		// would leave the card showing the cheerful line this exists to replace
		// (#699 review).
		list( $box ) = $this->stub_option_store(
			array( 'wc_ai_storefront_indexnow_key' => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0' )
		);
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 200 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		$this->indexnow->enqueue( $this->synthetic_urls( 25005 ) );
		$this->assertSame( 5, $box->offsetGet( 'wc_ai_storefront_indexnow_dropped' ) );

		// Three batches. The count must ride through all of them, not be
		// swallowed by whichever finishes first.
		$this->indexnow->flush();
		$this->assertSame( 5, $this->indexnow->last_result()['dropped'], 'after batch 1' );
		$this->assertTrue( $box->offsetExists( 'wc_ai_storefront_indexnow_dropped' ) );

		$this->indexnow->flush();
		$this->assertSame( 5, $this->indexnow->last_result()['dropped'], 'after batch 2' );

		$this->indexnow->flush();
		$this->assertSame( 5, $this->indexnow->last_result()['dropped'], 'after the final batch' );
		$this->assertFalse(
			$box->offsetExists( 'wc_ai_storefront_indexnow_dropped' ),
			'released only once the queue has drained'
		);
	}

	public function test_repeated_overflows_accumulate_rather_than_overwrite(): void {
		// A bare assignment instead of read-add-write survived: only one
		// overflowing enqueue() ever ran in a test. submit_all() overflowing and
		// then an ordinary product save overflowing again is an ordinary
		// sequence (#699 review).
		list( $box ) = $this->stub_option_store();

		$this->indexnow->enqueue( $this->synthetic_urls( 25003 ) );
		$this->indexnow->enqueue( array( 'https://shop.test/extra-a/', 'https://shop.test/extra-b/' ) );

		$this->assertSame( 5, $box->offsetGet( 'wc_ai_storefront_indexnow_dropped' ) );
	}

	public function test_a_422_clears_the_queue_like_a_403_and_a_500_does_not(): void {
		// Only 403 was exercised, so narrowing the branch to `403 === $code`
		// survived — a 422 would then be retried every 60 seconds forever. And
		// widening it to catch 500 survived too, which would wipe the queue on
		// exactly the transient failure the 5xx tail exists to retry (#699
		// review).
		foreach ( array(
			422 => true,
			500 => false,
		) as $code => $should_clear ) {
			list( $box ) = $this->stub_option_store(
				array(
					'wc_ai_storefront_indexnow_pending' => $this->synthetic_urls( 15000 ),
					'wc_ai_storefront_indexnow_key'     => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
				)
			);
			Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => $code ) ) );
			Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
			Functions\when( 'wp_next_scheduled' )->justReturn( false );
			Functions\when( 'wp_schedule_single_event' )->justReturn( true );

			$this->indexnow->flush();

			$this->assertSame(
				! $should_clear,
				$box->offsetExists( 'wc_ai_storefront_indexnow_pending' ),
				"HTTP $code queue handling"
			);
		}
	}

	public function test_a_failed_shrink_write_does_not_resend_the_batch(): void {
		// update_option() answers false BEFORE touching the object cache, so a
		// failed write leaves the whole queue readable. Ignoring the return
		// meant take_batch() handed back a batch it had not dequeued and flush()
		// POSTed the identical payload every 60 seconds forever. No test stubbed
		// a failing write (#699 review).
		$store = array(
			'wc_ai_storefront_indexnow_pending' => $this->synthetic_urls( 25000 ),
			'wc_ai_storefront_indexnow_key'     => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
		);
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$store ) {
				if ( 'wc_ai_storefront_indexnow_pending' === $n ) {
					return false; // the shrink fails; the queue is untouched.
				}
				$store[ $n ] = $v;
				return true;
			}
		);
		$posts = 0;
		Functions\when( 'wp_remote_post' )->alias(
			static function () use ( &$posts ) {
				++$posts;
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		$this->indexnow->flush();
		$this->indexnow->flush();

		$this->assertSame( 0, $posts, 'a batch that could not be dequeued must not be sent' );
		$this->assertCount( 25000, $store['wc_ai_storefront_indexnow_pending'], 'queue intact' );
	}

	public function test_a_corrupt_queue_is_discarded_rather_than_fatal(): void {
		// Removing the recovery block survived, and array_slice() on a string is
		// a TypeError inside a cron callback. Reachable exactly where the
		// max_allowed_packet reasoning points: a truncated write leaves an
		// unserializable blob that get_option() hands back as a raw string
		// (#699 review).
		list( $box ) = $this->stub_option_store(
			array( 'wc_ai_storefront_indexnow_pending' => 'not-an-array' )
		);

		$method = new \ReflectionMethod( $this->indexnow, 'take_batch' );

		$this->assertSame( array(), $method->invoke( $this->indexnow, 10000 ) );
		$this->assertFalse( $box->offsetExists( 'wc_ai_storefront_indexnow_pending' ) );
	}

	public function test_a_remainder_of_one_url_still_gets_scheduled(): void {
		// `> 0` narrowed to `> 1` survived: the drain test uses three clean
		// batches, so the boundary was never approached. A 10,001-URL queue
		// would strand its last URL (#699 review).
		list( $box ) = $this->stub_option_store(
			array(
				'wc_ai_storefront_indexnow_pending' => $this->synthetic_urls( 10001 ),
				'wc_ai_storefront_indexnow_key'     => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
			)
		);
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 200 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		$scheduled = 0;
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function () use ( &$scheduled ) {
				++$scheduled;
				return true;
			}
		);

		$this->indexnow->flush();

		$this->assertCount( 1, $box->offsetGet( 'wc_ai_storefront_indexnow_pending' ) );
		$this->assertSame( 1, $scheduled, 'one URL left is still work to do' );
	}

	public function test_disabling_mid_flight_clears_the_drop_counter_with_the_queue(): void {
		// The counter goes with the queue it described. Left behind, it lands on
		// the first unrelated submission after the feature is switched back on
		// (#699 review).
		list( $box )                     = $this->stub_option_store(
			array(
				'wc_ai_storefront_indexnow_pending' => $this->synthetic_urls( 3 ),
				'wc_ai_storefront_indexnow_dropped' => 800,
			)
		);
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'yes',
			'indexnow_enabled' => 'no',
		);

		$this->indexnow->flush();

		$this->assertFalse( $box->offsetExists( 'wc_ai_storefront_indexnow_pending' ) );
		$this->assertFalse( $box->offsetExists( 'wc_ai_storefront_indexnow_dropped' ) );
	}

	public function test_a_5xx_requeues_and_reschedules_instead_of_stranding_the_queue(): void {
		// This used to fall into the same branch as 403, which caught every
		// non-200/202/429 code. A transient 503 on the first batch of a large
		// drain therefore dropped that batch and left the rest with no cron
		// event pointing at it (#699 review).
		list( $box ) = $this->stub_option_store(
			array(
				'wc_ai_storefront_indexnow_pending' => $this->synthetic_urls( 15000 ),
				'wc_ai_storefront_indexnow_key'     => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
			)
		);
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 503 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		$scheduled = 0;
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function () use ( &$scheduled ) {
				++$scheduled;
				return true;
			}
		);

		$this->indexnow->flush();

		$this->assertSame( 1, $scheduled, 'a transient failure must be retried' );
		$this->assertCount( 15000, $box->offsetGet( 'wc_ai_storefront_indexnow_pending' ), 'nothing lost' );
	}

	public function test_an_empty_permalink_is_dropped_rather_than_submitted(): void {
		// Nothing expected reaches this branch: the product is published,
		// visible and syndicated. But a `post_link` filter from a permalink or
		// multilingual plugin can return '' for products missing its own meta.
		// Without the guard, false flows into the JSON urlList, IndexNow
		// answers 422, and flush() drops the WHOLE batch (#695 review).
		$this->stub_term_change_product();
		Functions\when( 'get_permalink' )->alias(
			static function ( $id = 0 ) {
				return 5 === (int) $id ? 'https://shop.test/shop/' : false;
			}
		);

		$urls = $this->capture_enqueued(
			fn() => $this->indexnow->on_product_terms_changed( 14, array( 'a' ), array( 22 ), 'pa_color', false, array() )
		);

		$this->assertSame( array(), $urls );
	}

	public function test_is_product_indexable_rejects_a_non_product(): void {
		// wc_get_product() returns false, not null, when it cannot load one —
		// and all_product_urls() passes whatever wc_get_products() returned.
		// Without the instanceof guard this is a fatal on false->get_status().
		$this->assertFalse( $this->indexnow->is_product_indexable( false ) );
	}

	public function test_init_registers_brand_term_hooks(): void {
		// The three `*_product_brand` term hooks are the ONLY wiring that
		// makes brand edits reach on_brand_change() in production — every
		// other brand test calls on_brand_change() directly, so without
		// this test a dropped `add_action` line or a typo (e.g.
		// `edited_product_brands`, plural) would leave brand change-
		// detection silently dead while the whole suite stays green. Assert
		// each hook is registered exactly once, bound to on_brand_change.
		// Mirrors the `expectAdded` pattern in UcpStoreApiPreGetPostsTest.
		//
		// A REAL instance is used here (not the setUp() anonymous subclass
		// that overrides terminate()): init() never calls terminate(), and
		// Brain Monkey's callback-argument matcher stringifies the callback
		// via get_class(), which rejects an anonymous class's synthetic
		// name. A named instance keeps the precise "bound to on_brand_change"
		// assertion working.
		$indexnow = new WC_AI_Storefront_IndexNow();

		$binds_to_on_brand_change = static function ( $callback ) use ( $indexnow ): bool {
			return is_array( $callback )
				&& $callback[0] === $indexnow
				&& 'on_brand_change' === $callback[1];
		};

		foreach ( array( 'created_product_brand', 'edited_product_brand', 'delete_product_brand' ) as $hook ) {
			\Brain\Monkey\Actions\expectAdded( $hook )
				->once()
				->with( \Mockery::on( $binds_to_on_brand_change ) );
		}

		$indexnow->init();

		// Brain Monkey verifies expectations during tearDown; PHPUnit
		// doesn't count those as native assertions, so acknowledge them
		// explicitly to avoid a "risky test" flag.
		$this->addToAssertionCount( 3 );
	}

	// --- #694: terms changed without the product being saved ---

	/**
	 * Stubs shared by the set_object_terms tests.
	 *
	 * @param bool $indexable Whether is_product_indexable() should pass.
	 */
	private function stub_term_change_product( bool $indexable = true ): void {
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		// ID-aware, and that matters. surface_urls() calls get_permalink() for
		// the shop page too, so a single-value stub made the shop URL and the
		// product URL the same string — and every assertion below passed
		// whether or not the handler appended anything (#695 review).
		Functions\when( 'get_permalink' )->alias(
			static function ( $id = 0 ) {
				return 5 === (int) $id ? 'https://shop.test/shop/' : 'https://shop.test/product/hoodie/';
			}
		);
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 14 );
		$product->shouldReceive( 'get_status' )->andReturn( $indexable ? 'publish' : 'draft' );
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		Functions\when( 'wc_get_product' )->justReturn( $product );
	}

	/**
	 * The pending set after running $act, with option state that persists.
	 *
	 * get_option and update_option share one backing array, so a second call
	 * sees what the first wrote. Stubbing get_option to a flat `array()` made
	 * every call start from empty, which stubbed cross-call dedupe out of
	 * existence and left the dedupe test asserting nothing (#695 review).
	 *
	 * @param callable $act Runs the handler(s) under test.
	 * @return string[] The pending URL set.
	 */
	private function capture_enqueued( callable $act ): array {
		$store = array();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$store ) {
				return 'wc_ai_storefront_indexnow_pending' === $name ? $store : array();
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$store ) {
				if ( 'wc_ai_storefront_indexnow_pending' === $name ) {
					$store = (array) $value;
				}
				return true;
			}
		);
		$act();
		return $store;
	}

	public function test_term_change_without_a_product_save_enqueues_the_product(): void {
		// Measured on a live store: assigning a term outside a product save
		// leaves post_modified untouched and fires no product hook, while the
		// page goes on rendering the new value (#694).
		$this->stub_term_change_product();

		$flushed = 0;
		// Captured BEFORE the handler runs. Calling time() inside the stub
		// races the time() inside schedule_flush(): cross a second boundary
		// between the two and the assertion fails on correct code.
		$before = time();
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $timestamp, $hook ) use ( &$flushed, $before ) {
				if ( 'wc_ai_storefront_indexnow_flush' === $hook ) {
					++$flushed;
					// The debounce is what makes this a batch rather than one
					// submission per save. A zero delay would defeat it and no
					// test noticed (#695 review).
					TestCase::assertGreaterThanOrEqual( $before + 60, $timestamp );
				}
				return true;
			}
		);

		$urls = $this->capture_enqueued(
			fn() => $this->indexnow->on_product_terms_changed( 14, array( 'blue' ), array( 22 ), 'pa_color', false, array( 21 ) )
		);

		$this->assertContains( 'https://shop.test/product/hoodie/', $urls );

		// The surfaces travel with it. A category edit is exactly the kind of
		// change that alters the shop listing and llms.txt, and every sibling
		// handler asserts this (#695 review).
		$this->assertContains( 'https://shop.test/llms.txt', $urls );
		$this->assertContains( 'https://shop.test/', $urls );

		// Without this, term changes pile into the pending option and sit
		// there until some unrelated save happens to schedule a flush.
		$this->assertSame( 1, $flushed, 'the term change must schedule a flush' );
	}

	public function test_no_op_term_assignment_enqueues_nothing(): void {
		// WordPress and WooCommerce both call wp_set_object_terms() on saves
		// where the set did not actually change. Order must not matter.
		$this->stub_term_change_product();

		// Deliberately mixed types and reversed order. $old_tt_ids comes from
		// wp_get_object_terms() while $tt_ids is accumulated in core's insert
		// loop, and the two are not reliably the same type — so the intval
		// normalisation has to do real work here, not just pass ints through
		// (#695 review).
		$urls = $this->capture_enqueued(
			fn() => $this->indexnow->on_product_terms_changed( 14, array( 'blue' ), array( '22', '23' ), 'pa_color', false, array( 23, 22 ) )
		);

		$this->assertSame( array(), $urls );
	}

	public function test_term_change_on_a_non_product_enqueues_nothing(): void {
		$this->stub_term_change_product();
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		$urls = $this->capture_enqueued(
			fn() => $this->indexnow->on_product_terms_changed( 99, array( 'news' ), array( 5 ), 'category', false, array() )
		);

		$this->assertSame( array(), $urls );
	}

	public function test_term_change_enqueues_nothing_when_indexnow_is_off(): void {
		WC_AI_Storefront::$test_settings = array(
			'enabled'          => 'yes',
			'indexnow_enabled' => 'no',
		);
		$this->stub_term_change_product();

		$urls = $this->capture_enqueued(
			fn() => $this->indexnow->on_product_terms_changed( 14, array( 'blue' ), array( 22 ), 'pa_color', false, array() )
		);

		$this->assertSame( array(), $urls );
	}

	public function test_term_change_on_a_non_indexable_product_enqueues_nothing(): void {
		// Same rule on_product_change() applies: never advertise a draft or
		// catalog-hidden product.
		$this->stub_term_change_product( false );

		$urls = $this->capture_enqueued(
			fn() => $this->indexnow->on_product_terms_changed( 14, array( 'blue' ), array( 22 ), 'pa_color', false, array() )
		);

		$this->assertSame( array(), $urls );
	}

	public function test_a_no_op_append_still_enqueues_because_core_hides_the_old_set(): void {
		// Documents a real limitation rather than pretending it away. Core
		// hardcodes `$old_tt_ids = array()` in the append branch of
		// wp_set_object_terms(), so the no-op guard has nothing to compare
		// against and always falls through on an append. That is the path
		// `wp post term add` takes.
		//
		// The direction is safe — a false positive, deduped downstream, never
		// a missed submission. If core ever starts passing the real prior set
		// on appends this test will fail, which is the moment to tighten the
		// guard.
		$this->stub_term_change_product();

		$urls = $this->capture_enqueued(
			fn() => $this->indexnow->on_product_terms_changed( 14, array( 'blue' ), array( 22 ), 'pa_color', true, array() )
		);

		$this->assertContains( 'https://shop.test/product/hoodie/', $urls );
	}

	public function test_repeat_fires_for_one_product_do_the_work_once(): void {
		// set_object_terms fires several times per product save: WooCommerce
		// rewrites product_type and product_visibility every time, plus one
		// taxonomy per pa_* attribute. enqueue() dedupes the URL but not the
		// work behind it, and that work is a full wc_get_product() read plus
		// an option read-modify-write each time (#695 review).
		$this->stub_term_change_product();

		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 14 );
		$product->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );

		$loads = 0;
		Functions\when( 'wc_get_product' )->alias(
			static function () use ( &$loads, $product ) {
				++$loads;
				return $product;
			}
		);

		$urls = $this->capture_enqueued(
			function () {
				$this->indexnow->on_product_terms_changed( 14, array( 'a' ), array( 22 ), 'product_cat', false, array() );
				$this->indexnow->on_product_terms_changed( 14, array( 'b' ), array( 31 ), 'pa_color', false, array() );
			}
		);

		$this->assertSame( 1, $loads, 'the product should be loaded once per request, not once per taxonomy' );
		$this->assertContains( 'https://shop.test/product/hoodie/', $urls );
	}

	public function test_term_change_alongside_a_product_save_does_not_duplicate(): void {
		// This hook also fires during ordinary saves, beside
		// woocommerce_update_product. enqueue() dedupes, so the permalink must
		// appear exactly once.
		$this->stub_term_change_product();

		$urls = $this->capture_enqueued(
			function () {
				$this->indexnow->on_product_change( 14 );
				$this->indexnow->on_product_terms_changed( 14, array( 'blue' ), array( 22 ), 'pa_color', false, array() );
			}
		);

		$this->assertSame(
			1,
			count( array_keys( $urls, 'https://shop.test/product/hoodie/', true ) )
		);
	}
}
