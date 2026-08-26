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
	 * URLs discarded at MAX_PENDING since the last recorded result.
	 *
	 * enqueue() and record_result() USUALLY run in different requests, so the
	 * count has to survive between them. Not always: submit_all() calls both in
	 * one request, and that is the path most likely to overflow, since it
	 * enqueues a whole catalogue (#699 review).
	 *
	 * Cleared only once the queue has fully drained, so the count rides through
	 * a multi-batch submission instead of being consumed by whichever attempt
	 * happens to finish first and zeroed by the next one.
	 */
	private const DROPPED_OPTION = 'wc_ai_storefront_indexnow_dropped';

	/**
	 * Query var for the virtual {key}.txt route.
	 */
	private const KEY_QUERY_VAR = 'wc_ai_storefront_indexnow_key';

	/**
	 * Cron hook for the debounced flush.
	 */
	public const FLUSH_HOOK = 'wc_ai_storefront_indexnow_flush';

	/**
	 * Cron hook for the first-enable seed (submit_all on initial turn-on).
	 */
	public const SUBMIT_ALL_HOOK = 'wc_ai_storefront_indexnow_submit_all';

	/**
	 * Debounce window before a queued batch is flushed (seconds).
	 */
	private const FLUSH_DELAY = 60;

	/**
	 * URLs per POST. The IndexNow spec's per-REQUEST limit.
	 *
	 * "You can submit up to 10,000 URLs per post" (indexnow.org documentation);
	 * the FAQ adds that exceeding it "may cause the request to fail or return
	 * an HTTP 422". Nothing in the spec limits how much a site may submit in
	 * total, only how much fits in one payload.
	 *
	 * This used to be called MAX_URLS and was applied as a ceiling on the whole
	 * queue, with the overflow discarded. The name was the bug in one word: it
	 * reads as a ceiling and the spec means a batch size (#698).
	 */
	private const BATCH_SIZE = 10000;

	/**
	 * Runaway guard on the queue. Unrelated to the spec.
	 *
	 * Sized to stay clear of MySQL's default 4MB `max_allowed_packet`. A queue
	 * is roughly 84 bytes per URL serialized for a typical ~70-character
	 * permalink, so 50,000 landed at about 4MB — right on that limit, where
	 * `$wpdb->update()` starts failing and `update_option()` returns a false
	 * that nothing was reading (#699 review). 25,000 is about 2MB, and it also
	 * halves how much `all_product_urls()` gathers inside the admin request.
	 *
	 * Core keeps an option this size out of autoload:
	 * `wp_filter_default_autoload_value_via_option_size()` turns it off above
	 * 150,000 bytes and `update_option()` re-evaluates that when an existing
	 * option grows. That protection holds ONLY while we never pass an explicit
	 * `$autoload` argument, because core re-evaluates just the `auto*` values.
	 * Do not add one.
	 */
	private const MAX_PENDING = 25000;

	/**
	 * Whether IndexNow submission is active: syndication on AND the toggle on.
	 */
	public function is_enabled(): bool {
		$settings = WC_AI_Storefront::get_settings();
		return 'yes' === ( $settings['enabled'] ?? 'no' )
			&& 'yes' === ( $settings['indexnow_enabled'] ?? 'no' );
	}

	/**
	 * Products already enqueued this request, keyed by ID.
	 *
	 * `set_object_terms` fires several times per product save — WooCommerce
	 * rewrites `product_type` and `product_visibility` every time, plus one
	 * taxonomy per `pa_*` attribute. `enqueue()` dedupes the URL but not the
	 * work behind it, and that work is a `wc_get_product()` read plus an
	 * option read-modify-write each time (#695 review).
	 *
	 * An instance property rather than a `static`: the plugin builds one
	 * instance per request, so the scope is the same in production, and a
	 * static would leak between tests sharing a process.
	 *
	 * @var array<int,true>
	 */
	private array $seen_this_request = array();

	/**
	 * URLs still queued after the last take_batch().
	 *
	 * Zero on every path that does not leave work behind, including a failed
	 * shrink — take_batch() returns an empty batch there, so flush() exits at
	 * its `empty( $urls )` check before this is read. An earlier version
	 * promised -1 for that case and nothing ever read it (#699 review).
	 *
	 * take_batch() already computes the remainder, so flush() reads it from
	 * here rather than re-fetching and unserializing a multi-megabyte option to
	 * re-derive a fact the previous line had in hand (#699 review).
	 *
	 * @var int
	 */
	private int $remaining_after_batch = 0;

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
	 * Skip WordPress's canonical trailing-slash redirect for the key-file
	 * request. IndexNow validators fetch the key at the exact `/{key}.txt`
	 * URL; on trailing-slash-permalink sites (the WordPress default) WP would
	 * otherwise 301 it to `/{key}.txt/` and key validation would fail. Mirrors
	 * the llms.txt / UCP / products.json rewrite endpoints. See issue #542.
	 *
	 * @param string|false $redirect_url Candidate canonical URL.
	 * @return string|false False to skip the redirect, else the URL unchanged.
	 */
	public function suppress_canonical_redirect( $redirect_url ) {
		if ( '' !== (string) get_query_var( self::KEY_QUERY_VAR ) ) {
			return false;
		}
		return $redirect_url;
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
	 * The surface URLs submitted on any catalog change.
	 *
	 * Pages meant for organic search, and only those. IndexNow exists to tell
	 * engines a page they might INDEX has changed, so a machine surface has no
	 * business here even though its content really did change.
	 *
	 * `/products.json` used to be in this list and was removed for two
	 * independent reasons (#694). When the feed is enabled it serves
	 * `X-Robots-Tag: noindex` — deliberately, see
	 * WC_AI_Storefront_Products_Feed::send_feed_headers() — so submitting it
	 * asked engines to re-crawl a URL we then told them not to index. And when
	 * the merchant switches the feed off, `serve_products_feed()` answers a
	 * hard 404, which this method never checked, so a store with the feed
	 * disabled submitted a known-dead URL on every single catalog change.
	 *
	 * `/.well-known/ucp` and `/collections/all/products.json` are absent for
	 * the same reason and were never added: both answer with `noindex` too.
	 *
	 * `/agents.md` is absent for a DIFFERENT reason, and grouping it with
	 * those two was wrong (#695 review). It carries no `noindex` at all — it
	 * is a byte-identical mirror of `/llms.txt` with the same headers bar
	 * `Content-Type`. It is out because it is a runtime file agents fetch on
	 * demand rather than a page competing in organic search, and because
	 * submitting both it and `/llms.txt` would advertise the same bytes twice.
	 *
	 * `/llms.txt` stays: it carries no `noindex`, it is human-readable, and
	 * being crawlable is the point of it.
	 *
	 * @return string[]
	 */
	public function surface_urls(): array {
		$urls    = array( home_url( '/' ), home_url( '/llms.txt' ) );
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

		// Capped at the runaway guard, NOT at the per-POST batch size. Anything
		// above BATCH_SIZE is sent in a further request rather than discarded,
		// so this branch is now a last resort rather than routine (#698).
		if ( count( $merged ) > self::MAX_PENDING ) {
			$dropped = count( $merged ) - self::MAX_PENDING;
			WC_AI_Storefront_Logger::debug( 'IndexNow pending set capped at %d URLs (dropped %d)', self::MAX_PENDING, $dropped );

			// The NEWEST entries are the ones discarded; the oldest are already
			// waiting on a scheduled flush. One exception worth knowing: a
			// batch re-queued after a 429 lands at the tail, so at capacity it
			// is the batch being retried that gets cut (#699 review).
			//
			// Recorded as well as logged, because the debug log defaults to
			// off, which is how this stayed invisible.
			$merged = array_slice( $merged, 0, self::MAX_PENDING );
			update_option( self::DROPPED_OPTION, (int) get_option( self::DROPPED_OPTION, 0 ) + $dropped );
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
	 * Remove and return the first $size URLs, leaving the rest queued.
	 *
	 * The counterpart to take_pending(), which empties the queue. flush() sends
	 * ONE batch per invocation and reschedules while anything remains, which is
	 * what keeps every POST inside the spec's per-request limit without a
	 * batching loop existing anywhere (#698).
	 *
	 * @param int $size Maximum URLs to take.
	 * @return string[]
	 */
	private function take_batch( int $size ): array {
		$this->remaining_after_batch = 0;

		// A caller asking for nothing says nothing about the queue. Grouped
		// with the unusable-queue cases below, this deleted every queued URL
		// and returned an empty batch indistinguishable from a drained one
		// (#699 review). Unreachable today; the method is one filter away from
		// being reachable, which is exactly when it would not be caught.
		if ( $size < 1 ) {
			return array();
		}

		$pending = get_option( self::PENDING_OPTION, array() );

		if ( ! is_array( $pending ) ) {
			// Corrupted state, not an empty queue. enqueue() recovers from the
			// same condition by resetting to array(); say so before doing the
			// same here, because this path destroys whatever was queued.
			WC_AI_Storefront_Logger::debug( 'IndexNow: pending queue was not an array; discarding it.' );
			delete_option( self::PENDING_OPTION );
			// The counter goes with the queue it described. Every path that
			// destroys a queue clears it; leaving it here would attach a count
			// about discarded URLs to the next unrelated submission (#699
			// review).
			$this->clear_dropped();
			return array();
		}

		if ( array() === $pending ) {
			return array();
		}

		$batch     = array_slice( $pending, 0, $size );
		$remaining = array_slice( $pending, $size );

		if ( array() === $remaining ) {
			delete_option( self::PENDING_OPTION );
			return array_values( $batch );
		}

		// The return value matters. update_option() answers false on a failed
		// $wpdb->update BEFORE it touches the object cache, so a failed write
		// leaves the FULL queue readable. Ignoring it meant take_batch()
		// handed back a batch it had not dequeued, the remainder still looked
		// unsent, and flush() rescheduled to POST the identical payload every
		// FLUSH_DELAY forever (#699 review).
		if ( ! update_option( self::PENDING_OPTION, array_values( $remaining ) ) ) {
			WC_AI_Storefront_Logger::debug(
				'IndexNow: could not shrink the pending queue (%d URLs); skipping this flush rather than resending.',
				count( $pending )
			);
			return array();
		}

		$this->remaining_after_batch = count( $remaining );

		return array_values( $batch );
	}

	/**
	 * The last flush outcome, or array() when there has been none.
	 *
	 * @return array{time?:int,count?:int,code?:int,ok?:bool,dropped?:int}
	 */
	public function last_result(): array {
		$result = get_option( self::LAST_RESULT_OPTION, array() );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Release the drop counter once the queue has drained.
	 *
	 * Separate from record_result() so a count survives every attempt in a
	 * multi-batch submission and is only forgotten when there is nothing left
	 * that could still be dropped.
	 */
	private function clear_dropped(): void {
		if ( (int) get_option( self::DROPPED_OPTION, 0 ) > 0 ) {
			delete_option( self::DROPPED_OPTION );
		}
	}

	/**
	 * Persist the outcome of the batch just submitted.
	 *
	 * @param int  $count Number of URLs in the batch.
	 * @param int  $code  HTTP status (0 for a transport error).
	 * @param bool $ok    Whether the submission was accepted (200/202).
	 */
	private function record_result( int $count, int $code, bool $ok ): void {
		// Read, NOT consumed. Clearing here meant whichever attempt finished
		// first swallowed the count — including a failed one, whose result the
		// card then never shows — and the next batch 60 seconds later reported
		// dropped: 0. It is released by clear_dropped() once the queue has
		// actually drained (#699 review).
		$dropped = (int) get_option( self::DROPPED_OPTION, 0 );

		update_option(
			self::LAST_RESULT_OPTION,
			array(
				'time'    => time(),
				'count'   => $count,
				'code'    => $code,
				'ok'      => $ok,
				'dropped' => $dropped,
			)
		);
	}

	/**
	 * Gather every indexable product, product-category, product-tag, and
	 * product-brand URL plus the discovery surfaces, enqueue them, and flush
	 * immediately. Used by
	 * the admin "Submit entire catalog now" action and the first-enable seed
	 * (#540). A catalogue up to MAX_PENDING is covered: enqueue() queues the lot
	 * and flush() sends the first BATCH_SIZE, rescheduling itself until the
	 * queue drains. It used to stop at BATCH_SIZE and drop the remainder, so a
	 * store larger than one request had no way to submit its catalogue at all
	 * (#698). Beyond MAX_PENDING the tail is still dropped, and because the
	 * merge order below is surfaces, products, categories, tags, brands, the
	 * URLs discarded first are the brand and tag archives (#699 review).
	 */
	public function submit_all(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$urls = array_merge(
			$this->surface_urls(),
			$this->all_product_urls(),
			$this->all_category_urls(),
			$this->all_tag_urls(),
			$this->all_brand_urls()
		);
		$this->enqueue( $urls );
		$this->flush();
	}

	/**
	 * Gather all published, indexable product URLs by paginating wc_get_products().
	 *
	 * Stops when a page returns fewer than 200 results OR the collected URL
	 * count reaches MAX_PENDING. That bound is the queue's runaway guard, not
	 * the submission limit: the catalogue is sent in BATCH_SIZE chunks, so
	 * stopping at 10,000 here used to silently decide that a larger store
	 * simply would not be covered (#698). The break counts ACCEPTED/indexable
	 * URLs (post-filter), not raw fetched products, so a store with many
	 * non-indexable products may paginate beyond MAX_PENDING raw results
	 * before the early-exit triggers.
	 *
	 * @return string[]
	 */
	private function all_product_urls(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$urls      = array();
		$page      = 1;
		$page_size = 200;
		do {
			$products = wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => $page_size,
					'page'   => $page,
					'return' => 'objects',
				)
			);
			if ( ! is_array( $products ) ) {
				break;
			}
			foreach ( $products as $product ) {
				if ( ! $this->is_product_indexable( $product ) ) {
					continue;
				}
				$permalink = get_permalink( $product->get_id() );
				if ( is_string( $permalink ) && '' !== $permalink ) {
					$urls[] = $permalink;
				}
				$url_count = count( $urls );
				if ( $url_count >= self::MAX_PENDING ) {
					break 2;
				}
			}
			$page_count = count( $products );
			++$page;
		} while ( $page_count >= $page_size );
		return $urls;
	}

	/**
	 * Gather all non-empty product-category URLs.
	 *
	 * @return string[]
	 */
	private function all_category_urls(): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		$urls = array();
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}
		}
		return $urls;
	}

	/**
	 * Gather all non-empty product-tag (`product_tag`) archive URLs.
	 *
	 * Mirrors all_category_urls(): a tag archive is the same kind of indexable
	 * taxonomy-term page as a category. It already carries a JSON-LD ItemList,
	 * so submitting it here closes the #705 gap where the page was described
	 * for a parser but never advertised to a crawler.
	 *
	 * @return string[]
	 */
	private function all_tag_urls(): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		$urls = array();
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}
		}
		return $urls;
	}

	/**
	 * Gather all non-empty product-brand (`product_brand`) archive URLs.
	 *
	 * Mirrors all_category_urls(): brand archives are the same kind of
	 * indexable taxonomy-term page as categories, and the same second-class
	 * gap that affects the sitemap surface. `product_brand` is the
	 * WooCommerce core brand taxonomy (WC 9.4+; also provided by brand
	 * plugins). get_term_link() resolves the registered permalink base
	 * automatically (`/brand/` or `/product-brand/`), so no base is
	 * hardcoded. The is_wp_error() guard returns an empty array on stores
	 * where the taxonomy is not registered (get_terms() yields a WP_Error),
	 * so no separate taxonomy_exists() check is needed.
	 *
	 * @return string[]
	 */
	private function all_brand_urls(): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_brand',
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		$urls = array();
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}
		}
		return $urls;
	}

	/**
	 * Schedule a single first-enable seed if one is not already pending.
	 *
	 * The +1-second delay ensures the cron fires AFTER the current request
	 * completes (so is_enabled() is true at run time) while remaining
	 * effectively immediate from the merchant's perspective.
	 */
	public function schedule_submit_all(): void {
		if ( ! wp_next_scheduled( self::SUBMIT_ALL_HOOK ) ) {
			$scheduled = wp_schedule_single_event( time() + 1, self::SUBMIT_ALL_HOOK );
			if ( false === $scheduled ) {
				WC_AI_Storefront_Logger::debug( 'IndexNow: wp_schedule_single_event failed for %s', self::SUBMIT_ALL_HOOK );
			}
		}
	}

	/**
	 * Register catalog-change hooks and the flush cron handler. Called only
	 * when the feature is enabled (see WC_AI_Storefront::init_components()).
	 */
	public function init(): void {
		add_action( 'woocommerce_update_product', array( $this, 'on_product_change' ) );
		add_action( 'woocommerce_new_product', array( $this, 'on_product_change' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_product_change' ) );
		// Terms can change without the product ever being saved, so this is
		// not covered by the three product hooks above. Six args: the handler
		// needs $old_tt_ids to tell a real change from a no-op.
		add_action( 'set_object_terms', array( $this, 'on_product_terms_changed' ), 10, 6 );
		add_action( 'woocommerce_trash_product', array( $this, 'on_product_removed' ) );
		add_action( 'woocommerce_delete_product', array( $this, 'on_product_removed' ) );
		add_action( 'created_product_cat', array( $this, 'on_term_change' ) );
		add_action( 'edited_product_cat', array( $this, 'on_term_change' ) );
		add_action( 'delete_product_cat', array( $this, 'on_term_change' ) );
		// Tag (product_tag) archives — parallel to product_cat above. The tag
		// archive already carries an ItemList, so it must be advertised too (#705).
		add_action( 'created_product_tag', array( $this, 'on_tag_change' ) );
		add_action( 'edited_product_tag', array( $this, 'on_tag_change' ) );
		add_action( 'delete_product_tag', array( $this, 'on_tag_change' ) );
		// Brand (product_brand) archives — parallel to product_cat above.
		// Registered unconditionally: on a store without the brand taxonomy
		// these hooks simply never fire (no taxonomy_exists() gate, which
		// would add a load-order dependency on WC registering the taxonomy
		// before our init()).
		add_action( 'created_product_brand', array( $this, 'on_brand_change' ) );
		add_action( 'edited_product_brand', array( $this, 'on_brand_change' ) );
		add_action( 'delete_product_brand', array( $this, 'on_brand_change' ) );
		add_action( self::FLUSH_HOOK, array( $this, 'flush' ) );
		add_action( self::SUBMIT_ALL_HOOK, array( $this, 'submit_all' ) );
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
	 * Terms changed on a product without the product itself being saved.
	 *
	 * `woocommerce_update_product` tracks product SAVES, not product PAGE
	 * changes, and the two are not the same thing. Measured on a live store
	 * (#694): assigning a tag with `wp post term add` left `post_modified`
	 * untouched and fired no product hook while the page rendered the new
	 * value.
	 *
	 * WHAT THIS COVERS: any `wp_set_object_terms()` call — imports, sync
	 * plugins, bulk assignment tools, `wp post term add`.
	 *
	 * WHAT IT DOES NOT, despite an earlier version of this docblock implying
	 * otherwise (#695 review). Two sibling cases go through different core
	 * functions that never reach this action, and both were checked in
	 * wp-includes/taxonomy.php rather than assumed:
	 *
	 * - **Term renames.** `wp_update_term()` fires `edit_term`, `edited_term`,
	 *   `edited_{$taxonomy}` and `saved_term`, and never calls
	 *   `wp_set_object_terms()` — the relationships are untouched, only the
	 *   term's name changed. So renaming `pa_color` "Blue" to "Navy" re-renders
	 *   every product using it and submits nothing. That is the second half of
	 *   #694's measured repro and it remains UNCOVERED; #694 records the
	 *   decision not to fan out from a term to its products.
	 * - **Term removal.** `wp_remove_object_terms()` fires only
	 *   `delete_term_relationships` / `deleted_term_relationships`.
	 *
	 * Taxonomy-agnostic on purpose. Any taxonomy on a product can change what
	 * the page renders, and a hardcoded list would go stale the moment another
	 * attribute is seeded. Product post type only: variations keep their
	 * attributes in post meta, and `woocommerce_update_product` does not fire
	 * for them either, so excluding them matches existing behaviour.
	 *
	 * This also fires several times during an ordinary save — WooCommerce
	 * rewrites `product_type` and `product_visibility` on every save and one
	 * taxonomy per `pa_*` attribute. `enqueue()` dedupes the URL, but not the
	 * WORK, so `$this->seen_this_request` bounds it to one pass per product.
	 *
	 * Differs from `on_product_change()` in one way worth knowing: that
	 * handler builds `surface_urls()` BEFORE its indexable check and enqueues
	 * them unconditionally, so a draft product still refreshes the surfaces
	 * there. This one returns having enqueued nothing. The narrow behaviour is
	 * shared — neither submits the product URL for a draft or catalog-hidden
	 * product, so engines are never told to drop the now-stale page. Fixing
	 * that needs a was-indexable-is-not-now transition check and is its own
	 * issue.
	 *
	 * @param int    $object_id  Object the terms were set on.
	 * @param array  $terms      Term IDs or slugs (unused).
	 * @param array  $tt_ids     An array of term taxonomy IDs. On an append
	 *                           this is only what THIS call passed, not the
	 *                           resulting set.
	 * @param string $taxonomy   Taxonomy slug (unused).
	 * @param bool   $append     Whether terms were appended. Not read, but it
	 *                           determines what the two arrays above mean.
	 * @param array  $old_tt_ids Old array of term taxonomy IDs. Core hardcodes
	 *                           this to `array()` on an append.
	 */
	public function on_product_terms_changed( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Cheapest guard first: pure array work, no lookup. This catches
		// no-op REPLACEMENTS, which is the hot path — `wp_insert_post()`
		// rewrites every taxonomy in `tax_input` with no change check
		// (wp-includes/post.php), and WooCommerce rewrites `product_type` and
		// `product_visibility` on every save
		// (class-wc-product-data-store-cpt.php).
		//
		// It CANNOT catch a no-op append. Core hardcodes `$old_tt_ids =
		// array()` in the append branch (wp-includes/taxonomy.php), so
		// $before is empty rather than data and the comparison is vacuous
		// there. That is a false positive, not a miss: an extra enqueue that
		// enqueue() then dedupes. Worth knowing because `wp post term add`
		// takes the append path (#695 review).
		$before = array_map( 'intval', (array) $old_tt_ids );
		$after  = array_map( 'intval', (array) $tt_ids );
		sort( $before );
		sort( $after );
		if ( $before === $after ) {
			return;
		}

		if ( ! function_exists( 'get_post_type' ) || 'product' !== get_post_type( (int) $object_id ) ) {
			return;
		}

		// One pass per product per request. See $seen_this_request.
		if ( isset( $this->seen_this_request[ (int) $object_id ] ) ) {
			return;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $object_id ) : null;
		if ( ! $product ) {
			// get_post_type() already proved this IS a product, so a false
			// here means WooCommerce could not load one of its own.
			// WC_Product_Factory::get_product() catches every exception and
			// returns a bare false, discarding the message, so this is the
			// only place the failure can be seen at all (#695 review).
			WC_AI_Storefront_Logger::debug(
				'IndexNow: wc_get_product(%d) returned false for a confirmed product post; term change not submitted',
				(int) $object_id
			);
			return;
		}

		if ( ! $this->is_product_indexable( $product ) ) {
			// Draft, catalog-hidden or out of syndication scope. Expected, and
			// stays silent.
			return;
		}

		$permalink = get_permalink( $product->get_id() );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			// Nothing expected reaches here: the product is published, visible
			// and syndicated, so it has a permalink. An empty one means a
			// `post_link` filter returned nothing, which is a fault worth
			// seeing rather than swallowing (#695 review).
			WC_AI_Storefront_Logger::debug(
				'IndexNow: get_permalink(%d) returned no URL for a published, indexable product; term change not submitted',
				$product->get_id()
			);
			return;
		}

		$this->seen_this_request[ (int) $object_id ] = true;

		$this->enqueue( array_merge( $this->surface_urls(), array( $permalink ) ) );
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
	 * A product brand changed: enqueue its term URL plus the AI surfaces.
	 *
	 * Thin per-taxonomy parallel of on_term_change(): WordPress fires term
	 * hooks per-taxonomy (`edited_product_cat` vs `edited_product_brand` are
	 * distinct actions), so each handler already knows its taxonomy from the
	 * hook that called it — no runtime lookup, and the existing category path
	 * is left untouched. get_term_link() returns a WP_Error (not a string)
	 * for an invalid term/taxonomy, which the is_string() guard drops.
	 *
	 * @param int $term_id Term ID.
	 */
	public function on_brand_change( $term_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$urls = $this->surface_urls();
		$link = get_term_link( (int) $term_id, 'product_brand' );
		if ( is_string( $link ) && '' !== $link ) {
			$urls[] = $link;
		}
		$this->enqueue( $urls );
		$this->schedule_flush();
	}

	/**
	 * A product-tag term changed: submit its archive URL plus the AI surfaces.
	 *
	 * Parallel to on_term_change()/on_brand_change(), differing only in the
	 * taxonomy passed to get_term_link(). Tag archives already emit an ItemList,
	 * so this makes the two surfaces agree (#705).
	 *
	 * @param int $term_id Tag term ID.
	 */
	public function on_tag_change( $term_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$urls = $this->surface_urls();
		$link = get_term_link( (int) $term_id, 'product_tag' );
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
			// The counter goes with the queue it described. Left behind, it
			// lands on an unrelated submission whenever the feature is turned
			// back on (#699 review).
			$this->clear_dropped();
			return;
		}
		// ONE batch per invocation. Everything the issue asked for falls out of
		// this: chunks spread across cron runs a FLUSH_DELAY apart, and a 429
		// partway through a large queue cannot cascade, because there is no
		// loop to carry on with (#698).
		$urls = $this->take_batch( self::BATCH_SIZE );
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

			// Drain the rest on the next run rather than looping here. A large
			// catalogue therefore goes out as several POSTs a FLUSH_DELAY
			// apart, which is also what keeps us clear of the engines' own
			// undisclosed submission thresholds.
			if ( $this->remaining_after_batch > 0 ) {
				$this->schedule_flush();
				return;
			}

			// Drained. Only now is the drop count settled, so release it.
			$this->clear_dropped();

			return;
		}
		if ( 429 === $code ) {
			WC_AI_Storefront_Logger::debug( 'IndexNow rate-limited (429) — re-queuing %d URLs', count( $urls ) );
			$this->record_result( count( $urls ), 429, false );
			$this->enqueue( $urls );
			$this->schedule_flush();
			return;
		}
		// 403 (key not served) and 422 (host/schema mismatch, or an oversized
		// batch) are conditions of the SITE, not of this batch: the next batch
		// fails identically. Retrying is pointless, but so is leaving the rest
		// of the queue sitting there with nothing scheduled to send it. Before
		// batching, take_pending() emptied the queue so no remainder could be
		// orphaned; take_batch() makes that state reachable, so clear it
		// explicitly rather than by accident (#699 review).
		if ( 403 === $code || 422 === $code ) {
			$orphaned = $this->take_pending();
			WC_AI_Storefront_Logger::debug(
				'IndexNow submission failed (HTTP %d) — dropping %d URLs and clearing %d more still queued. If 403, the {key}.txt rewrite may need flushing.',
				$code,
				count( $urls ),
				count( $orphaned )
			);
			// Recorded BEFORE clearing, so the merchant still sees the count on
			// this result; cleared after, because the queue it described is
			// gone. Without the clear it would be reported again against the
			// next successful drain (#699 review).
			$this->record_result( count( $urls ), $code, false );
			$this->clear_dropped();

			return;
		}

		// Anything else is a 5xx or an unexpected code: transient as far as we
		// can tell, so treat it like the 429 and transport paths rather than
		// silently stranding whatever is still queued. This branch used to be
		// the `else`, so a single 503 on the first batch of a large drain left
		// the remainder with no cron event pointing at it.
		WC_AI_Storefront_Logger::debug( 'IndexNow submission failed (HTTP %d) — re-queuing %d URLs', $code, count( $urls ) );
		$this->record_result( count( $urls ), $code, false );
		$this->enqueue( $urls );
		$this->schedule_flush();
	}

	/**
	 * Clean up on plugin deactivation.
	 *
	 * Clears both the debounced-flush cron (FLUSH_HOOK) and the first-enable seed
	 * cron (SUBMIT_ALL_HOOK); queued URLs or a pending seed are dropped, acceptable
	 * since a deactivating plugin should not schedule future work. Option data (key
	 * + pending) is left in place; only uninstall.php deletes it.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::FLUSH_HOOK );
		wp_clear_scheduled_hook( self::SUBMIT_ALL_HOOK );
	}
}
