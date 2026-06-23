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
}
