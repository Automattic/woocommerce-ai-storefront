<?php
/**
 * IndexNow instant-indexing integration.
 *
 * On catalog change, submits affected URLs plus the AI-discovery surfaces to
 * IndexNow (Bing, Yandex, Seznam, Naver, Yep), so those engines re-crawl
 * quickly and keep the catalog current in the AI-powered search results they
 * back. Google does not consume IndexNow. See issue #530.
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
	 * Dedicated option holding the generated IndexNow key.
	 *
	 * Stored separately from SETTINGS_OPTION so a settings save never
	 * erases or carries forward the key, and there is no stale-cache risk
	 * from regenerate_key() updating the option while a static $settings_cache
	 * still holds the old value.
	 */
	private const KEY_OPTION = 'wc_ai_storefront_indexnow_key';

	/**
	 * Option holding the deduped pending-URL set between debounce windows.
	 */
	private const PENDING_OPTION = 'wc_ai_storefront_indexnow_pending';

	/**
	 * Option holding the last flush outcome, for the settings UI status line.
	 */
	private const LAST_RESULT_OPTION = 'wc_ai_storefront_indexnow_last_result';

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
	 *
	 * Reads from the dedicated KEY_OPTION (not from SETTINGS_OPTION) so a
	 * settings save never erases the key, and there is no stale-cache risk
	 * from the static $settings_cache that WC_AI_Storefront maintains.
	 */
	public function get_key(): string {
		$key = (string) get_option( self::KEY_OPTION, '' );
		if ( '' !== $key ) {
			return $key;
		}
		return $this->regenerate_key();
	}

	/**
	 * Return the stored key WITHOUT generating one if absent.
	 *
	 * Used by the settings GET payload to expose the current key to the React
	 * UI without triggering key-generation on every read request.
	 */
	public function peek_key(): string {
		return (string) get_option( self::KEY_OPTION, '' );
	}

	/**
	 * Generate a fresh key, persist it to the dedicated option, and return it.
	 *
	 * Writes only to KEY_OPTION — never touches SETTINGS_OPTION — so there is
	 * no read-modify-write of the settings blob and no $settings_cache concern.
	 */
	public function regenerate_key(): string {
		$key = bin2hex( random_bytes( 16 ) ); // 32 lowercase hex chars.
		update_option( self::KEY_OPTION, $key );
		return $key;
	}

	/**
	 * Register the {key}.txt rewrite rule. The pattern covers the keys THIS
	 * plugin generates (lowercase hex) and also tolerates uppercase and dashes,
	 * but does NOT cover all of IndexNow's allowed charset (a-zA-Z0-9-).
	 * It cannot shadow robots.txt / llms.txt / ads.txt (those names include
	 * letters outside [a-fA-F0-9-]); serve_key_file() additionally requires
	 * an exact match against the stored key, so even another matching *.txt
	 * request 404s.
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
		$key = $this->peek_key();
		if ( '' === $key || ! $this->is_enabled() || ! hash_equals( $key, $requested ) ) {
			status_header( 404 );
			$this->terminate();
			return;
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		status_header( 200 );
		echo $key; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hex key, no escaping needed
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
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		if ( 'publish' !== $product->get_status() ) {
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
	 * The last flush outcome, or array() when there has been none.
	 *
	 * @return array{time?:int,count?:int,code?:int,ok?:bool}
	 */
	public function last_result(): array {
		$result = get_option( self::LAST_RESULT_OPTION, array() );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Persist the outcome of the batch just submitted.
	 *
	 * @param int  $count Number of URLs in the batch.
	 * @param int  $code  HTTP status (0 for a transport error).
	 * @param bool $ok    Whether the submission was accepted (200/202).
	 */
	private function record_result( int $count, int $code, bool $ok ): void {
		update_option(
			self::LAST_RESULT_OPTION,
			array(
				'time'  => time(),
				'count' => $count,
				'code'  => $code,
				'ok'    => $ok,
			)
		);
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
			// Disabled mid-flight: drop the pending batch without recording a
			// result. The status line reflects only actual submissions, so it
			// intentionally keeps showing the prior outcome here. Do NOT add a
			// record_result() call: it would report a phantom attempt.
			$this->take_pending(); // clear; we are not submitting.
			return;
		}
		$urls = $this->take_pending();
		// Empty queue: nothing was attempted, so leave the last result as-is.
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
				'timeout'  => 5,
				'blocking' => true,
				'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'     => wp_json_encode( $body ),
				// TLS verification left at WordPress's default (on). The endpoint
				// is a fixed public HTTPS API with a valid certificate, so there
				// is never a reason to disable it here.
			)
		);

		if ( is_wp_error( $response ) ) {
			WC_AI_Storefront_Logger::debug( 'IndexNow transport error: %s — re-queuing %d URLs', $response->get_error_message(), count( $urls ) );
			$this->record_result( count( $urls ), 0, false );
			$this->enqueue( $urls );
			$this->schedule_flush();
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code || 202 === $code ) {
			WC_AI_Storefront_Logger::debug( 'IndexNow submitted %d URLs (HTTP %d)', count( $urls ), $code );
			$this->record_result( count( $urls ), $code, true );
			return;
		}
		if ( 429 === $code ) {
			WC_AI_Storefront_Logger::debug( 'IndexNow rate-limited (429) — re-queuing %d URLs', count( $urls ) );
			$this->record_result( count( $urls ), 429, false );
			$this->enqueue( $urls );
			$this->schedule_flush();
			return;
		}
		// 403 (key not served), 422 (host/schema mismatch), or other: log + drop.
		WC_AI_Storefront_Logger::debug( 'IndexNow submission failed (HTTP %d) — dropping %d URLs. If 403, the {key}.txt rewrite may need flushing.', $code, count( $urls ) );
		$this->record_result( count( $urls ), $code, false );
	}

	/**
	 * Clean up on plugin deactivation.
	 *
	 * Clears the pending flush cron — any queued URLs are lost, which is
	 * acceptable since a deactivating plugin should not schedule future work.
	 * Option data (key + pending URLs) is intentionally left in place on
	 * mere deactivation; only uninstall.php deletes them.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::FLUSH_HOOK );
	}
}
