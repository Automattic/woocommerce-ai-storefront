<?php
/**
 * IndexNow instant-indexing integration.
 *
 * On catalog change, submits affected URLs plus the AI-discovery surfaces to
 * IndexNow (Bing/Yandex/Seznam/Naver/Yep/Internet Archive/Amazonbot), so the
 * Bing-backed AI assistants re-crawl quickly. Google does not consume IndexNow.
 * See docs/superpowers/specs/2026-06-22-indexnow-instant-indexing-design.md.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * IndexNow submitter.
 */
class WC_AI_Storefront_IndexNow {

	/**
	 * Shared submission endpoint (propagates to all participants).
	 */
	private const ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * Settings key holding the generated IndexNow key.
	 */
	private const KEY_SETTING = 'indexnow_key';

	/**
	 * Option holding the deduped pending-URL set between debounce windows.
	 */
	private const PENDING_OPTION = 'wc_ai_storefront_indexnow_pending';

	/**
	 * Query var for the virtual {key}.txt route.
	 */
	private const KEY_QUERY_VAR = 'wc_ai_storefront_indexnow_key';

	/**
	 * Cron hook for the debounced flush.
	 */
	public const FLUSH_HOOK = 'wc_ai_storefront_indexnow_flush';

	/**
	 * Debounce window before a queued batch is flushed (seconds).
	 */
	private const FLUSH_DELAY = 60;

	/**
	 * Max URLs per submission (IndexNow spec limit).
	 */
	private const MAX_URLS = 10000;

	/**
	 * Whether IndexNow submission is active: syndication on AND the toggle on.
	 */
	public function is_enabled(): bool {
		$settings = WC_AI_Storefront::get_settings();
		return 'yes' === ( $settings['enabled'] ?? 'no' )
			&& 'yes' === ( $settings['indexnow_enabled'] ?? 'no' );
	}

	/**
	 * The IndexNow key, generating and persisting one on first use.
	 */
	public function get_key(): string {
		$settings = WC_AI_Storefront::get_settings();
		$key      = (string) ( $settings[ self::KEY_SETTING ] ?? '' );
		if ( '' !== $key ) {
			return $key;
		}
		return $this->regenerate_key();
	}

	/**
	 * Generate a fresh key, persist it, and return it.
	 */
	public function regenerate_key(): string {
		$key      = bin2hex( random_bytes( 16 ) ); // 32 lowercase hex chars.
		$settings = get_option( WC_AI_Storefront::SETTINGS_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ self::KEY_SETTING ] = $key;
		update_option( WC_AI_Storefront::SETTINGS_OPTION, $settings );
		return $key;
	}

	/**
	 * Register the {key}.txt rewrite rule. The hex-only pattern cannot shadow
	 * robots.txt / llms.txt / ads.txt (those names contain non-hex letters);
	 * serve_key_file() additionally requires an exact match against the stored
	 * key, so even another hex *.txt request 404s.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^([a-fA-F0-9-]{8,128})\.txt$', 'index.php?' . self::KEY_QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Register the {key}.txt query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = self::KEY_QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the IndexNow key file at /{key}.txt when the request matches the
	 * stored key and the feature is enabled. No-op for unrelated requests.
	 */
	public function serve_key_file(): void {
		$requested = (string) get_query_var( self::KEY_QUERY_VAR );
		if ( '' === $requested ) {
			return;
		}
		if ( ! $this->is_enabled() || ! hash_equals( $this->get_key(), $requested ) ) {
			status_header( 404 );
			$this->terminate();
			return;
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		status_header( 200 );
		echo $this->get_key(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hex key, no escaping needed
		$this->terminate();
	}

	/**
	 * Terminate the request. Isolated so unit tests can intercept it instead of
	 * killing the test process.
	 *
	 * @codeCoverageIgnore
	 */
	protected function terminate(): void {
		exit;
	}

	/**
	 * The AI-discovery surface URLs submitted on any catalog change.
	 *
	 * @return string[]
	 */
	public function surface_urls(): array {
		$urls    = array( home_url( '/' ), home_url( '/llms.txt' ), home_url( '/products.json' ) );
		$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		if ( $shop_id > 0 ) {
			$shop = get_permalink( $shop_id );
			if ( is_string( $shop ) && '' !== $shop ) {
				$urls[] = $shop;
			}
		}
		return $urls;
	}

	/**
	 * Whether a product's URL should be advertised to IndexNow: published, not
	 * catalog-hidden (we noindex those), and within the syndication scope.
	 *
	 * @param WC_Product $product Product.
	 */
	public function is_product_indexable( $product ): bool {
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return false;
		}
		if ( 'hidden' === $product->get_catalog_visibility() ) {
			return false;
		}
		return WC_AI_Storefront::is_product_syndicated( $product );
	}

	/**
	 * Add URLs to the deduped pending set.
	 *
	 * @param string[] $urls URLs to enqueue.
	 */
	public function enqueue( array $urls ): void {
		if ( empty( $urls ) ) {
			return;
		}
		$pending = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}
		$merged = array_values( array_unique( array_merge( $pending, array_values( $urls ) ) ) );
		if ( count( $merged ) > self::MAX_URLS ) {
			WC_AI_Storefront_Logger::debug( 'IndexNow pending set capped at %d URLs (dropped %d)', self::MAX_URLS, count( $merged ) - self::MAX_URLS );
			$merged = array_slice( $merged, 0, self::MAX_URLS );
		}
		update_option( self::PENDING_OPTION, $merged );
	}

	/**
	 * Read and clear the pending set.
	 *
	 * @return string[]
	 */
	public function take_pending(): array {
		$pending = get_option( self::PENDING_OPTION, array() );
		delete_option( self::PENDING_OPTION );
		return is_array( $pending ) ? array_values( $pending ) : array();
	}

	/**
	 * Register catalog-change hooks and the flush cron handler. Called only
	 * when the feature is enabled (see WC_AI_Storefront::init_components()).
	 */
	public function init(): void {
		add_action( 'woocommerce_update_product', array( $this, 'on_product_change' ) );
		add_action( 'woocommerce_new_product', array( $this, 'on_product_change' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_product_change' ) );
		add_action( 'woocommerce_trash_product', array( $this, 'on_product_removed' ) );
		add_action( 'woocommerce_delete_product', array( $this, 'on_product_removed' ) );
		add_action( 'created_product_cat', array( $this, 'on_term_change' ) );
		add_action( 'edited_product_cat', array( $this, 'on_term_change' ) );
		add_action( 'delete_product_cat', array( $this, 'on_term_change' ) );
		add_action( self::FLUSH_HOOK, array( $this, 'flush' ) );
	}

	/**
	 * A product was created/updated/restocked: enqueue its URL (when indexable)
	 * plus the AI surfaces, then schedule a flush.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_change( $product_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$urls    = $this->surface_urls();
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
		if ( $product && $this->is_product_indexable( $product ) ) {
			$permalink = get_permalink( $product->get_id() );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$urls[] = $permalink;
			}
		}
		$this->enqueue( $urls );
		$this->schedule_flush();
	}

	/**
	 * A product was trashed/deleted: submit its URL unconditionally (so engines
	 * re-crawl and de-index) plus the AI surfaces.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_removed( $product_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$urls      = $this->surface_urls();
		$permalink = get_permalink( (int) $product_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$urls[] = $permalink;
		}
		$this->enqueue( $urls );
		$this->schedule_flush();
	}

	/**
	 * A product category changed: enqueue its term URL plus the AI surfaces.
	 *
	 * @param int $term_id Term ID.
	 */
	public function on_term_change( $term_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$urls = $this->surface_urls();
		$link = get_term_link( (int) $term_id, 'product_cat' );
		if ( is_string( $link ) && '' !== $link ) {
			$urls[] = $link;
		}
		$this->enqueue( $urls );
		$this->schedule_flush();
	}

	/**
	 * Schedule a single debounced flush if one is not already pending.
	 */
	public function schedule_flush(): void {
		if ( ! wp_next_scheduled( self::FLUSH_HOOK ) ) {
			wp_schedule_single_event( time() + self::FLUSH_DELAY, self::FLUSH_HOOK );
		}
	}

	/**
	 * Cron handler: submit the pending batch to IndexNow. Gated on is_enabled().
	 * 429/transport errors re-queue with a fresh debounce; 403/422 are logged
	 * and dropped (retrying a structurally invalid request will not help).
	 */
	public function flush(): void {
		if ( ! $this->is_enabled() ) {
			$this->take_pending(); // clear; we are not submitting.
			return;
		}
		$urls = $this->take_pending();
		if ( empty( $urls ) ) {
			return;
		}

		$body     = array(
			'host'    => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'key'     => $this->get_key(),
			'urlList' => array_values( $urls ),
		);
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'   => 5,
				'blocking'  => true,
				'headers'   => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'      => wp_json_encode( $body ),
				'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WC_AI_Storefront_Logger::debug( 'IndexNow transport error: %s — re-queuing %d URLs', $response->get_error_message(), count( $urls ) );
			$this->enqueue( $urls );
			$this->schedule_flush();
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code || 202 === $code ) {
			WC_AI_Storefront_Logger::debug( 'IndexNow submitted %d URLs (HTTP %d)', count( $urls ), $code );
			return;
		}
		if ( 429 === $code ) {
			WC_AI_Storefront_Logger::debug( 'IndexNow rate-limited (429) — re-queuing %d URLs', count( $urls ) );
			$this->enqueue( $urls );
			$this->schedule_flush();
			return;
		}
		// 403 (key not served), 422 (host/schema mismatch), or other: log + drop.
		WC_AI_Storefront_Logger::debug( 'IndexNow submission failed (HTTP %d) — dropping %d URLs. If 403, the {key}.txt rewrite may need flushing.', $code, count( $urls ) );
	}
}
