<?php
/**
 * Crawl logger — schema and write path for AI-agent crawler visibility.
 *
 * Two tables:
 *
 * - `wc_ai_storefront_crawl_log`     Raw events, 30-day rolling retention.
 *                                    One row per identified AI-agent request
 *                                    (product page, Store API hit, llms.txt,
 *                                    UCP endpoint, or throttled 429).
 *
 * - `wc_ai_storefront_crawl_summary` Daily aggregates, 90-day retention.
 *                                    Materialised from the raw log by a daily
 *                                    cron. Stat cards read from here; the raw
 *                                    log is for the detailed query view only.
 *
 * Write path
 * ----------
 * Callers invoke `record()` during a request (zero DB writes); everything
 * accumulates in a static pending array. `flush()` is registered on the
 * WordPress `shutdown` action and performs a single batched INSERT after the
 * response has been sent to the browser.
 *
 * Agent identification varies by call site: passive endpoints (product pages,
 * llms.txt, UCP manifest) match against the UA token allowlist; UCP REST
 * routes identify agents via the UCP-Agent request header. Unknown UA tokens
 * pass through unchanged and are stored as-is (forward-compatible with new
 * bots added before the brand-name map is updated).
 *
 * @package WooCommerce_AI_Storefront
 * @since 0.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the crawl-log schema and the in-request write buffer.
 */
class WC_AI_Storefront_Crawl_Logger {

	const TABLE_LOG     = 'wc_ai_storefront_crawl_log';
	const TABLE_SUMMARY = 'wc_ai_storefront_crawl_summary';

	const ENDPOINT_PRODUCT_PAGE     = 'product_page';
	const ENDPOINT_STORE_API_SINGLE = 'store_api_product';
	const ENDPOINT_STORE_API_SEARCH = 'store_api_search';
	const ENDPOINT_LLMS_TXT         = 'llms_txt';
	const ENDPOINT_UCP              = 'ucp';

	const RAW_RETENTION_DAYS     = 30;
	const SUMMARY_RETENTION_DAYS = 90;

	/**
	 * Events accumulated during the current request.
	 *
	 * Each entry: [ product_id, agent, endpoint, query string ('' for non-search), throttled ].
	 *
	 * @var array[]
	 */
	private static $pending = array();

	/**
	 * Whether the shutdown flush has been registered for this request.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	/**
	 * Create or upgrade both crawl-log tables.
	 *
	 * Uses dbDelta, which is idempotent — safe to call on every version bump.
	 * Requires `wp-admin/includes/upgrade.php` to be loaded by the caller
	 * (already true inside the `plugins_loaded` / activation paths this is
	 * invoked from).
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Raw event log — one row per AI-agent request.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}" . self::TABLE_LOG . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  agent VARCHAR(64) NOT NULL DEFAULT '',
  endpoint VARCHAR(32) NOT NULL DEFAULT '',
  query VARCHAR(255) NOT NULL DEFAULT '',
  throttled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  crawled_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_crawled_at (crawled_at),
  KEY idx_agent_crawled_at (agent, crawled_at),
  KEY idx_product_crawled_at (product_id, crawled_at),
  KEY idx_endpoint_crawled_at (endpoint, crawled_at)
) {$charset_collate};"
		);

		// Daily aggregate summary — one row per (agent, product, endpoint, date).
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}" . self::TABLE_SUMMARY . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent VARCHAR(64) NOT NULL DEFAULT '',
  product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  endpoint VARCHAR(32) NOT NULL DEFAULT '',
  crawl_date DATE NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  throttle_count INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_daily (agent, product_id, endpoint, crawl_date),
  KEY idx_crawl_date (crawl_date),
  KEY idx_agent_date (agent, crawl_date)
) {$charset_collate};"
		);
		// phpcs:enable
	}

	/**
	 * Drop both crawl-log tables.
	 *
	 * Called only from uninstall.php — never on deactivation.
	 */
	public static function drop_tables(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . self::TABLE_SUMMARY ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . self::TABLE_LOG );     // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable
	}

	// -------------------------------------------------------------------------
	// Cron scheduling
	// -------------------------------------------------------------------------

	/**
	 * Ensure the daily prune and rollup crons are scheduled.
	 *
	 * Called from `WC_AI_Storefront::init_components()` on every request
	 * so the events are re-registered if accidentally cleared.
	 */
	public static function schedule_crons(): void {
		static $scheduled = false;
		if ( $scheduled ) {
			return;
		}
		$scheduled = true;

		// Next UTC midnight: floor current UTC time to the day, then add one day.
		$utc_midnight = gmmktime( 0, 0, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ) + 1, (int) gmdate( 'Y' ) );

		if ( ! wp_next_scheduled( 'wc_ai_storefront_prune_crawl_log' ) ) {
			wp_schedule_event( $utc_midnight, 'daily', 'wc_ai_storefront_prune_crawl_log' );
		}
		if ( ! wp_next_scheduled( 'wc_ai_storefront_rollup_crawl_log' ) ) {
			wp_schedule_event( $utc_midnight + 60, 'daily', 'wc_ai_storefront_rollup_crawl_log' );
		}
	}

	/**
	 * Clear scheduled cron events.
	 *
	 * Called on plugin deactivation.
	 */
	public static function clear_crons(): void {
		wp_clear_scheduled_hook( 'wc_ai_storefront_prune_crawl_log' );
		wp_clear_scheduled_hook( 'wc_ai_storefront_rollup_crawl_log' );

		// On multisite, cron events are stored per-blog (each site's wp_options).
		// Clear the hooks for every subsite so they don't fire after deactivation.
		if ( is_multisite() ) {
			$current_blog_id = get_current_blog_id();
			$offset          = 0;
			$batch           = 500;
			do {
				$blog_ids = get_sites(
					array(
						'fields' => 'ids',
						'number' => $batch,
						'offset' => $offset,
					)
				);
				foreach ( $blog_ids as $blog_id ) {
					if ( (int) $blog_id === $current_blog_id ) {
						continue;
					}
					switch_to_blog( $blog_id );
					try {
						wp_clear_scheduled_hook( 'wc_ai_storefront_prune_crawl_log' );
						wp_clear_scheduled_hook( 'wc_ai_storefront_rollup_crawl_log' );
					} finally {
						restore_current_blog();
					}
				}
				$offset       += $batch;
				$fetched_count = count( $blog_ids );
			} while ( $fetched_count === $batch );
		}
	}

	// -------------------------------------------------------------------------
	// Write path
	// -------------------------------------------------------------------------

	/**
	 * Queue a crawl event for the current request.
	 *
	 * Zero DB writes — the event is buffered in memory until `flush()` runs
	 * on the WordPress `shutdown` action after the response is sent.
	 *
	 * @param string   $endpoint  One of the ENDPOINT_* constants.
	 * @param int      $product_id Product ID, or 0 for non-product endpoints.
	 * @param string   $agent      Raw crawler token (e.g. 'GPTBot') or brand
	 *                             name. Canonicalised to brand name before storing
	 *                             so stats surface consistently with attribution.
	 * @param string   $query      Search query string (ENDPOINT_STORE_API_SEARCH only).
	 * @param bool     $throttled  Whether this request was rejected with a 429.
	 */
	public static function record(
		string $endpoint,
		int $product_id,
		string $agent,
		string $query = '',
		bool $throttled = false
	): void {
		if ( '' === $agent ) {
			return;
		}

		// Map raw User-Agent tokens → merchant-facing brand names so crawl
		// stats surface the same names as the attribution / orders layer.
		// Tokens not in the table are stored as-is (forward-compat for bots
		// added to AI_CRAWLERS before the map is updated).
		$brand_names = array(
			// OpenAI.
			'GPTBot'                     => 'ChatGPT',
			'ChatGPT-User'               => 'ChatGPT',
			'OAI-SearchBot'              => 'ChatGPT',
			// Anthropic.
			'ClaudeBot'                  => 'Claude',
			'Claude-User'                => 'Claude',
			'Claude-SearchBot'           => 'Claude',
			// Perplexity.
			'PerplexityBot'              => 'Perplexity',
			'Perplexity-User'            => 'Perplexity',
			// Google.
			'Storebot-Google'            => 'Google Shopping',
			'Google-Extended'            => 'Google',
			// Apple.
			'Applebot'                   => 'Apple',
			'Applebot-Extended'          => 'Apple',
			// Amazon.
			'AmazonBuyForMe'             => 'Amazon Rufus',
			'Amazonbot'                  => 'Amazon',
			// Microsoft / Copilot.
			'AdIdxBot'                   => 'Copilot',
			'Microsoft-BingBot-Extended' => 'Copilot',
			// DuckDuckGo.
			'DuckAssistBot'              => 'DuckDuckGo',
			// Klarna.
			'KlarnaBot'                  => 'Klarna',
			// Baidu.
			'ERNIEBot'                   => 'Baidu',
			'YiyanBot'                   => 'Baidu',
			// Regional.
			'NaverBot'                   => 'Naver',
			'PetalBot'                   => 'Petal',
			'WRTNBot'                    => 'WRTN',
			'YandexBot'                  => 'Yandex',
			// Training.
			'Bytespider'                 => 'ByteDance',
			'CCBot'                      => 'Common Crawl',
			'cohere-ai'                  => 'Cohere',
			'Meta-ExternalAgent'         => 'Meta',
		);
		$agent       = $brand_names[ $agent ] ?? $agent;
		// mb_substr is recommended but not required; fall back to substr so
		// record() never fatals on minimal hosts without the mbstring extension.
		$fn       = function_exists( 'mb_substr' ) ? 'mb_substr' : 'substr';
		$agent    = $fn( $agent, 0, 64 );
		$endpoint = $fn( $endpoint, 0, 32 );
		$query    = $fn( $query, 0, 255 );

		self::$pending[] = array( $product_id, $agent, $endpoint, $query, $throttled ? 1 : 0 );

		if ( ! self::$shutdown_registered ) {
			add_action( 'shutdown', array( static::class, 'flush' ) );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * Write all buffered events to the raw log in a single INSERT.
	 *
	 * Called automatically on the `shutdown` action. Safe to call manually
	 * in tests; calling it when the pending array is empty is a no-op.
	 */
	public static function flush(): void {
		if ( empty( self::$pending ) ) {
			return;
		}

		global $wpdb;

		$now                       = current_time( 'mysql', true ); // UTC
		$rows                      = self::$pending;
		self::$pending             = array();
		self::$shutdown_registered = false;

		$placeholders = array();
		$values       = array();

		foreach ( $rows as $row ) {
			[ $product_id, $agent, $endpoint, $query, $throttled ] = $row;
			$placeholders[]                                        = '(%d, %s, %s, %s, %d, %s)';
			array_push( $values, $product_id, $agent, $endpoint, $query, $throttled, $now );
		}

		$sql = "INSERT INTO {$wpdb->prefix}" . self::TABLE_LOG // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			. ' (product_id, agent, endpoint, query, throttled, crawled_at) VALUES '
			. implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		if ( false === $result ) {
			wc_get_logger()->warning(
				'WC_AI_Storefront_Crawl_Logger::flush() — DB write failed: ' . $wpdb->last_error,
				array( 'source' => 'wc-ai-storefront' )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Pruning
	// -------------------------------------------------------------------------

	/**
	 * Delete raw log entries older than RAW_RETENTION_DAYS.
	 *
	 * Called by the daily prune cron hook `wc_ai_storefront_prune_crawl_log`.
	 */
	public static function prune_raw_log(): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RAW_RETENTION_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}" . self::TABLE_LOG . ' WHERE crawled_at < %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$cutoff
			)
		);
		if ( false === $result ) {
			wc_get_logger()->warning(
				'WC_AI_Storefront_Crawl_Logger::prune_raw_log() — DB query failed: ' . $wpdb->last_error,
				array( 'source' => 'wc-ai-storefront' )
			);
		}
	}

	/**
	 * Delete summary rows older than SUMMARY_RETENTION_DAYS.
	 *
	 * Called by the daily rollup cron hook `wc_ai_storefront_rollup_crawl_log`
	 * after the rollup INSERT completes.
	 */
	public static function prune_summary(): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d', time() - self::SUMMARY_RETENTION_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}" . self::TABLE_SUMMARY . ' WHERE crawl_date < %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$cutoff
			)
		);
		if ( false === $result ) {
			wc_get_logger()->warning(
				'WC_AI_Storefront_Crawl_Logger::prune_summary() — DB query failed: ' . $wpdb->last_error,
				array( 'source' => 'wc-ai-storefront' )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Rollup
	// -------------------------------------------------------------------------

	/**
	 * Materialise yesterday's raw events into the daily summary table.
	 *
	 * Uses INSERT … ON DUPLICATE KEY UPDATE so repeated cron runs are safe.
	 * Called by the daily cron hook `wc_ai_storefront_rollup_crawl_log`.
	 */
	public static function rollup(): void {
		global $wpdb;

		$yesterday       = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$yesterday_start = $yesterday . ' 00:00:00';
		$today_start     = gmdate( 'Y-m-d' ) . ' 00:00:00';

		// Use a range on crawled_at rather than DATE(crawled_at) = %s so MySQL
		// can use the idx_crawled_at index instead of doing a full table scan.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}" . self::TABLE_SUMMARY // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				. " (agent, product_id, endpoint, crawl_date, request_count, throttle_count)
				SELECT agent, product_id, endpoint, %s AS crawl_date,
				       COUNT(*) AS request_count,
				       SUM(throttled) AS throttle_count
				FROM {$wpdb->prefix}" . self::TABLE_LOG // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				. ' WHERE crawled_at >= %s AND crawled_at < %s
				GROUP BY agent, product_id, endpoint
				ON DUPLICATE KEY UPDATE
				  request_count  = VALUES(request_count),
				  throttle_count = VALUES(throttle_count)',
				$yesterday,
				$yesterday_start,
				$today_start
			)
		);

		if ( false === $result ) {
			wc_get_logger()->error(
				'WC_AI_Storefront_Crawl_Logger::rollup() — summary INSERT failed: ' . $wpdb->last_error,
				array( 'source' => 'wc-ai-storefront' )
			);
			return;
		}

		self::prune_summary();
		self::bust_crawl_stats_cache();
	}

	/**
	 * Delete all period-keyed crawl-stats transients.
	 *
	 * Called after rollup so the next period-chip click fetches fresh data.
	 */
	public static function bust_crawl_stats_cache(): void {
		foreach ( array( 'day', 'week', 'month', 'quarter' ) as $period ) {
			delete_transient( 'wc_ai_storefront_crawl_stats_' . $period );
		}
	}
}
