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
}
