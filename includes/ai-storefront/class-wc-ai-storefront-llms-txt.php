<?php
/**
 * AI Syndication: llms.txt Generator
 *
 * Generates a machine-readable Markdown document at /llms.txt
 * that gives AI crawlers a direct guide to the store's products
 * and API capabilities.
 *
 * @package WooCommerce_AI_Storefront
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles generation and serving of the llms.txt file.
 */
class WC_AI_Storefront_Llms_Txt {

	/**
	 * Base transient key for cached llms.txt content.
	 *
	 * Never use this constant directly in `get_transient` / `set_transient`
	 * / `delete_transient` calls — always use `self::host_cache_key()`
	 * instead so the stored value is segmented by HTTP Host. This base
	 * constant is kept for backward-compat (third-party code, legacy
	 * delete_transient calls).
	 */
	const CACHE_KEY = 'wc_ai_storefront_llms_txt';

	/**
	 * Transient key for the discovered sitemap URL list.
	 *
	 * Cached independently from CACHE_KEY so a product/settings change
	 * that busts the llms.txt content cache does NOT re-run the 4
	 * synchronous HTTP HEAD probes — sitemaps are far more stable than
	 * product data. TTL: 24h (DAY_IN_SECONDS). Invalidated on plugin
	 * deactivation and uninstall alongside CACHE_KEY.
	 */
	const SITEMAP_CACHE_KEY = 'wc_ai_storefront_sitemap_urls';

	/**
	 * Query var for the /agents.md mirror endpoint.
	 *
	 * `/agents.md` serves a byte-identical mirror of `/llms.txt` (the
	 * emerging canonical agent-doc path — some storefronts, e.g.
	 * Allbirds, publish both). It shares the SAME generator and the SAME
	 * cached content (`get_cached_content()`), so the two surfaces can
	 * never drift, and the existing cache invalidation already covers it.
	 *
	 * Kept as a constant so the four callsites that reference it
	 * (`add_rewrite_rules()`, `add_query_vars()`, `serve_agents_md()`,
	 * `suppress_canonical_redirect()`) stay in sync — mirroring the
	 * `WC_AI_Storefront_Ucp::OPENSEARCH_QUERY_VAR` pattern.
	 */
	const AGENTS_MD_QUERY_VAR = 'wc_ai_storefront_agents_md';

	/**
	 * Return a Host-specific transient key for the llms.txt cache.
	 *
	 * llms.txt body contains URLs derived from `home_url()` and
	 * `rest_url()`, which are Host-derived on loose-vhost / multisite
	 * installs. Keying the transient on the current HTTP Host value
	 * ensures two requests from different virtual hosts never share a
	 * cached body through the PHP layer. The CDN/proxy layer is
	 * separately defended by the `Vary: Host` response header.
	 *
	 * The key is `CACHE_KEY + '_' + md5(HTTP_HOST)`.  md5 is used for
	 * compactness (WP transient keys are limited to 172 chars), not for
	 * security. In non-HTTP contexts (WP-Cron, CLI) `HTTP_HOST` is the
	 * WP_HOME hostname, which is the correct host for those contexts.
	 *
	 * @return string Transient key for the current Host value.
	 */
	public static function host_cache_key(): string {
		$host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		return self::CACHE_KEY . '_' . md5( $host );
	}

	/**
	 * Short-circuit canonical-URL redirects for the llms.txt and
	 * agents.md endpoints.
	 *
	 * @param string|false $redirect_url The candidate canonical URL
	 *                                   WordPress wants to redirect to.
	 * @return string|false               False disables the redirect;
	 *                                   original value otherwise.
	 */
	public function suppress_canonical_redirect( $redirect_url ) {
		if ( get_query_var( 'wc_ai_storefront_llms_txt' ) || get_query_var( self::AGENTS_MD_QUERY_VAR ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Add rewrite rules for /llms.txt and its /agents.md mirror.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?wc_ai_storefront_llms_txt=1', 'top' );
		add_rewrite_rule( '^agents\.md$', 'index.php?' . self::AGENTS_MD_QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Register query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'wc_ai_storefront_llms_txt';
		$vars[] = self::AGENTS_MD_QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the llms.txt response.
	 *
	 * Response headers are tuned for maximum compatibility with the
	 * AI-tooling fleet (Gemini's browsing tool, ChatGPT browse, Claude
	 * web search, Perplexity spider, plus CLI fetchers):
	 *
	 * - `Content-Type: text/plain`: the llms.txt spec (RFC-style memo
	 *   by Jeremy Howard) accepts either `text/plain` or
	 *   `text/markdown`. We serve `text/plain` because some headless-
	 *   browser tooling has MIME allow-lists that don't include
	 *   `text/markdown` and will drop the response. Plain-text is the
	 *   universal fallback and still renders correctly in the merchant's
	 *   browser when they visit the URL directly.
	 *
	 * - `Access-Control-Allow-Origin: *`: required so AI browsing tools
	 *   running in Chromium-based contexts (where CORS applies even on
	 *   tool-initiated fetches) can read the resource. Without it the
	 *   file is invisible to Gemini's tool — the UCP manifest (which
	 *   sets CORS) reads fine, llms.txt (which didn't) did not. Symmetry
	 *   fixes discovery.
	 *
	 * - `X-Content-Type-Options: nosniff`: prevents MIME sniffing from
	 *   mis-classifying the response (e.g. as HTML if the merchant's
	 *   content happens to begin with an `<` character). Small hardening.
	 *
	 * - `Cache-Control: public, max-age=N` (via
	 *   WC_AI_Storefront::discovery_cache_control()): makes the file
	 *   edge-cacheable. As a non-`/wp-json/` rewrite endpoint the
	 *   WordPress.com / Atomic edge caches it, so agent discovery bursts are
	 *   served as cache HITs instead of every fetch booting WordPress and
	 *   counting against the platform per-origin rate limit (429 past ~10
	 *   requests in a short window). Trade-off: a CDN HIT never reaches PHP,
	 *   so per-request hit logging is no longer accurate — the "llms.txt
	 *   hits" stat card is retired in the same change, and accurate
	 *   counting for cached surfaces will move to edge logs later. This
	 *   reverses the no-store decision made for llms.txt in 0.10.1
	 *   (#307), itself a follow-up to the 0.9.1/#283 manifest fix, now
	 *   that the under-counting it avoided is outweighed by the
	 *   rate-limit cost.
	 *
	 * (No `X-Robots-Tag: noindex`): earlier revisions set noindex to
	 * keep llms.txt out of human-facing search results, but 1.4.4
	 * dropped it. Some AI browsing tools (notably Gemini) appear to
	 * use Google's search index as a discovery layer — when they
	 * find a URL in the index they'll fetch it; when the URL is
	 * noindexed they never try. Because llms.txt exists specifically
	 * to be discovered, noindex was working against the plugin's
	 * own purpose. Agents that go direct to `/llms.txt` continue to
	 * work either way; agents that search-first now work too.
	 */
	public function serve_llms_txt() {
		if ( ! get_query_var( 'wc_ai_storefront_llms_txt' ) ) {
			return;
		}

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			status_header( 404 );
			exit;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: ' . WC_AI_Storefront::discovery_cache_control() );
		// `Vary: Host` matters now that the response is edge-cached: the body
		// contains URLs derived from `home_url()` / `rest_url()`, which are
		// Host-derived on loose-vhost / multisite installs, so the cache must
		// key on Host or it could serve a body whose endpoint URLs point at a
		// different virtual host.
		header( 'Vary: Host' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );

		// Respond to CORS preflights without a body. Some browsing
		// tools fire OPTIONS first and treat a non-2xx preflight as
		// "resource unreachable" even if the GET would have succeeded.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_METHOD is validated against a constant, no sanitization required.
			status_header( 204 );
			exit;
		}

		echo $this->get_cached_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown content.
		exit;
	}

	/**
	 * Serve the /agents.md response — a byte-identical mirror of
	 * /llms.txt.
	 *
	 * `agents.md` is the emerging canonical path for agent-facing store
	 * documentation (some storefronts, e.g. Allbirds, publish both
	 * `/llms.txt` and `/agents.md`). Serving both from ONE generator and
	 * ONE cache guarantees they can never drift: this handler reuses the
	 * exact same `get_cached_content()` the llms.txt handler echoes, with
	 * NO separate cache key — so the existing content-cache invalidation
	 * (product/settings changes, plugin updates) already covers both
	 * surfaces with zero extra wiring.
	 *
	 * Every response header matches `serve_llms_txt()` so the two
	 * surfaces behave identically at the edge — in particular the shared
	 * `WC_AI_Storefront::discovery_cache_control()` header, which is what
	 * makes this a CDN-cacheable surface (a non-`/wp-json/` rewrite
	 * endpoint the WordPress.com / Atomic edge caches), and `Vary: Host`
	 * so the cache keys on Host (the body contains Host-derived URLs).
	 * The single intentional difference is the Content-Type:
	 * `text/markdown` here vs `text/plain` for llms.txt, because this URL
	 * carries a `.md` extension and consumers/browsers key off it. See
	 * `serve_llms_txt()` for the full header rationale.
	 */
	public function serve_agents_md() {
		if ( ! get_query_var( self::AGENTS_MD_QUERY_VAR ) ) {
			return;
		}

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			status_header( 404 );
			exit;
		}

		// The one intentional divergence from serve_llms_txt(): a `.md`
		// URL advertises Markdown rather than plain text. Every other
		// header below is identical so both surfaces edge-cache and CORS
		// the same way.
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Cache-Control: ' . WC_AI_Storefront::discovery_cache_control() );
		header( 'Vary: Host' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );

		// Respond to CORS preflights without a body, mirroring llms.txt.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_METHOD is validated against a constant, no sanitization required.
			status_header( 204 );
			exit;
		}

		// REUSE the exact same cached content as llms.txt — same single
		// source of truth, so the two endpoints are always byte-identical.
		echo $this->get_cached_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown content.
		exit;
	}

	/**
	 * Get cached llms.txt content, regenerating if expired.
	 *
	 * Cache-hit detection must exclude both `false` (the transient
	 * miss sentinel) AND empty strings. Before 1.4.4 the check was
	 * `false !== $cached`, which treated an empty cached string as
	 * a valid hit — a real bug that surfaced in production:
	 * `generate()` had captured empty content during a transient
	 * bad state (likely a handler that never ran because of the
	 * 1.4.2 wiring bug), and the empty value stuck in the cache for
	 * the full 1-hour TTL, serving blank responses even after every
	 * upstream fix.
	 *
	 * Defensive matching pair: we also refuse to write empty content
	 * into the cache in the first place, so a single bad
	 * generate() call can't poison the next hour of responses.
	 *
	 * @return string Markdown content.
	 */
	private function get_cached_content() {
		// All transient operations use the Host-specific key so two
		// requests from different virtual hosts never share a cached body
		// through the PHP layer. See self::host_cache_key() for rationale.
		$cache_key = self::host_cache_key();

		$cached = get_transient( $cache_key );
		if ( false !== $cached && '' !== $cached ) {
			WC_AI_Storefront_Logger::debug( 'llms.txt cache hit' );
			return $cached;
		}

		// Single-flight guard against thundering-herd regeneration.
		// `generate()` does up to 4 synchronous HEAD probes in
		// `discover_sitemap_urls()` (1-second timeout each, so up
		// to 4 seconds in the worst case). If two crawlers hit us
		// simultaneously right after the transient expires, without
		// this guard both would regenerate — paying the cost twice.
		// The sentinel is a short-lived transient that secondary
		// callers read; when set, they wait briefly for the primary
		// to finish, then re-check the main cache. If the primary
		// missed its window (crashed, timed out), the sentinel
		// expires and the secondary will regenerate itself.
		// The sentinel check mirrors the main cache-read pattern:
		// treat both `false` (not held) AND empty-string (a stray /
		// poisoned value) as "no lock held." Without the empty-string
		// guard, a transient backend returning '' for a missing key
		// would falsely trigger the wait loop.
		$lock = get_transient( $cache_key . '_regenerating' );
		if ( false !== $lock && '' !== $lock ) {
			// Primary is in-flight. Poll up to 5 seconds for the
			// main cache to appear. Using usleep with short
			// intervals rather than a single long sleep so we
			// release early when the primary succeeds.
			for ( $i = 0; $i < 50; $i++ ) {
				usleep( 100000 ); // 100ms.
				$cached = get_transient( $cache_key );
				if ( false !== $cached && '' !== $cached ) {
					WC_AI_Storefront_Logger::debug( 'llms.txt cache hit after single-flight wait' );
					return $cached;
				}
			}
			// Primary didn't deliver; fall through to regenerate
			// ourselves rather than serve stale-or-empty.
			WC_AI_Storefront_Logger::debug( 'llms.txt single-flight timed out, regenerating' );
		}

		// Claim the sentinel for a short window covering the
		// probe-timeout worst case (4s) plus a margin.
		set_transient( $cache_key . '_regenerating', 1, 10 );

		// Wrap generation in try/finally so the sentinel ALWAYS
		// releases on exit — even if generate() or the subsequent
		// set_transient() throws. Without this, an uncaught
		// exception during regeneration would leave the sentinel
		// live until the 10-second TTL expired, during which all
		// other callers would poll-then-give-up before eventually
		// regenerating themselves. The try/finally makes the guard
		// symmetric with its claim.
		$content = '';
		try {
			WC_AI_Storefront_Logger::debug( 'llms.txt cache miss — regenerating' );
			$content = $this->generate();

			// Only cache non-empty content. Caching an empty string
			// would re-create the poisoning scenario the cache-hit
			// check above now defends against; belt + suspenders.
			if ( '' !== $content ) {
				set_transient( $cache_key, $content, HOUR_IN_SECONDS );
			} else {
				WC_AI_Storefront_Logger::debug( 'llms.txt generate() returned empty — not caching' );
			}
		} finally {
			// Release the single-flight sentinel regardless of
			// outcome. Waiting callers can immediately re-check the
			// main cache; if we threw or generated empty they'll
			// either serve the cached content from a prior successful
			// run or regenerate themselves.
			delete_transient( $cache_key . '_regenerating' );
		}

		return $content;
	}

	/**
	 * Generate the llms.txt content.
	 *
	 * @return string Markdown content.
	 */
	public function generate() {
		// Sanitize through sanitize_markdown_inline() to strip control
		// characters (including newlines) that would break the surrounding
		// Markdown line structure (FIND-S06). html_entity_decode() +
		// wp_strip_all_tags() run first so the sanitizer sees plain text.
		$site_name   = self::sanitize_markdown_inline( html_entity_decode( wp_strip_all_tags( get_bloginfo( 'name' ) ), ENT_QUOTES, 'UTF-8' ) );
		$site_url    = home_url( '/' );
		$description = self::sanitize_markdown_inline( html_entity_decode( wp_strip_all_tags( get_bloginfo( 'description' ) ), ENT_QUOTES, 'UTF-8' ) );
		$currency    = get_woocommerce_currency();
		$settings    = WC_AI_Storefront::get_settings();

		// Shared store-identity data (logo, postal address, contact email,
		// catalog summary) is sourced from `WC_AI_Storefront_JsonLd`'s
		// public helpers. Same single source feeds the homepage
		// `OnlineBusiness` JSON-LD; both consumers share one cache hit
		// for the catalog summary and one resolution pass for the
		// contact-email noreply guard. See issue #398.
		$jsonld          = new WC_AI_Storefront_JsonLd();
		$identity_fields = $jsonld->build_identity_fields();
		$postal_address  = $jsonld->build_postal_address();
		$catalog_summary = $jsonld->get_catalog_summary();

		$lines   = [];
		$lines[] = "# {$site_name}";
		$lines[] = '';

		if ( $description ) {
			$lines[] = "> {$description}";
			$lines[] = '';
		}

		// ============================================================
		// ## Store
		// ============================================================
		// Identity essentials. Currency stays from the previous
		// `## Store Information` (the only field that section emitted).
		// Joined by Location / Logo / Support, all sourced from the
		// same helpers that build the homepage `OnlineBusiness`
		// JSON-LD's `address`, `logo`, and `contactPoint.email`.
		//
		// Each subline is omit-when-empty so a freshly-installed
		// merchant (no logo uploaded, no city configured, noreply-only
		// From address) emits a Currency-only section instead of
		// "Logo: (not set)" placeholder lines.
		$lines[] = '## Store';
		$lines[] = '';
		$lines[] = "- **Currency**: {$currency}";

		$accepted_currencies = WC_AI_Storefront_Multi_Currency::get_accepted_currencies();
		if ( count( $accepted_currencies ) > 1 ) {
			$lines[] = '- **Accepted currencies**: ' . implode( ', ', $accepted_currencies );
		}

		if ( ! empty( $postal_address ) ) {
			$location_parts = [];
			foreach ( [ 'addressLocality', 'addressRegion', 'addressCountry' ] as $key ) {
				if ( empty( $postal_address[ $key ] ) ) {
					continue;
				}
				$raw              = (string) $postal_address[ $key ];
				$value            = 'addressCountry' === $key ? $this->resolve_country_name( $raw ) : $raw;
				$location_parts[] = self::sanitize_markdown_inline( $value );
			}
			if ( ! empty( $location_parts ) ) {
				$lines[] = '- **Location**: ' . implode( ', ', $location_parts );
			}
		}

		if ( ! empty( $identity_fields['logo'] ) ) {
			$lines[] = '- **Logo**: ' . esc_url( (string) $identity_fields['logo'] );
		}

		if ( ! empty( $identity_fields['contactPoint']['email'] ) ) {
			$lines[] = '- **Support**: ' . self::sanitize_markdown_inline( (string) $identity_fields['contactPoint']['email'] );
		}

		$lines[] = '';

		// ============================================================
		// ## Browse
		// ============================================================
		// Catalog-discovery URLs an agent (or human via an AI surface)
		// can follow directly. Shop archive + a search-URL template
		// (with `{search_term}` substitution slot, parallel to the
		// homepage `SearchAction.urlTemplate`) + the existing sitemap
		// discovery pipeline (HEAD-probed, 24-hour transient cache).
		//
		// Both publicly clickable URLs carry the canonical
		// `utm_medium=referral&utm_id=woo_llms` pair. No `utm_source`
		// in the template — the actual referring domain populates it
		// from `Referer` downstream. See WOO_LLMS_ID in
		// WC_AI_Storefront_Attribution for the channel-classification
		// rationale.
		$browse_utm = '&utm_medium=referral&utm_id=' . WC_AI_Storefront_Attribution::WOO_LLMS_ID;
		$shop_url   = $site_url . 'shop/?' . ltrim( $browse_utm, '&' );
		$search_url = $site_url . '?s={search_term}&post_type=product' . $browse_utm;

		$lines[] = '## Browse';
		$lines[] = '';
		$lines[] = "- **Shop archive**: {$shop_url}";
		$lines[] = "- **Search**: `{$search_url}` — replace `{search_term}` with the buyer's query";

		$sitemap_urls = self::discover_sitemap_urls( $site_url );
		if ( ! empty( $sitemap_urls ) ) {
			$lines[] = '- **Sitemaps** (exhaustive URL lists for full-catalog enumeration):';
			foreach ( $sitemap_urls as $sitemap_url ) {
				$lines[] = "  - {$sitemap_url}";
			}
		}
		$lines[] = '';

		// ============================================================
		// ## Catalog
		// ============================================================
		// Top categories by product count, sampled (not exhaustive).
		// Reuses the same `get_catalog_summary()` result that drives
		// the homepage JSON-LD `hasOfferCatalog` — one transient hit,
		// two surfaces. The preamble explicitly labels the list as a
		// sample so agents wanting every product/category URL know to
		// follow the sitemaps under `## Browse` or POST UCP
		// `/catalog/search` for the full enumeration.
		//
		// `Specializes in:` mirrors JSON-LD's `Organization.knowsAbout`
		// — the top-level category-name array — so agents reading
		// llms.txt see the same topic signal that schema.org consumers
		// see on the homepage.
		if ( ! empty( $catalog_summary ) && is_array( $catalog_summary ) ) {
			$lines[]        = '## Catalog';
			$lines[]        = '';
			$lines[]        = 'Top categories by product count. This is a sample, not exhaustive: full enumeration via the sitemaps under Browse, or `POST /wp-json/wc/ucp/v1/catalog/search`.';
			$lines[]        = '';
			$specializes_in = [];
			foreach ( $catalog_summary as $category ) {
				if ( ! is_array( $category ) || empty( $category['name'] ) || empty( $category['url'] ) ) {
					continue;
				}
				$cat_name         = self::sanitize_markdown_inline(
					html_entity_decode( wp_strip_all_tags( (string) $category['name'] ), ENT_QUOTES, 'UTF-8' ),
					true
				);
				$cat_count        = isset( $category['numberOfItems'] ) ? (int) $category['numberOfItems'] : 0;
				$cat_label        = 1 === $cat_count ? 'product' : 'products';
				$lines[]          = "- [{$cat_name}](" . esc_url( (string) $category['url'] ) . ") ({$cat_count} {$cat_label})";
				$specializes_in[] = $cat_name;
			}
			if ( ! empty( $specializes_in ) ) {
				$lines[] = '';
				$lines[] = 'Specializes in: ' . implode( ', ', $specializes_in ) . '.';
			}
			$lines[] = '';
		}

		// ============================================================
		// ## Shipping & Returns
		// ============================================================
		// Sourced from the same Policies-tab settings that feed the
		// JSON-LD `OfferShippingDetails` (handling time) and
		// `MerchantReturnPolicy` (return window / fees / country).
		// Omit each subline when the merchant hasn't configured the
		// corresponding setting — no "Returns: not set" placeholders.
		$shipping_lines = [];

		$base_location = wc_get_base_location();
		$ship_country  = isset( $base_location['country'] ) ? (string) $base_location['country'] : '';
		if ( '' !== $ship_country ) {
			$shipping_lines[] = '- **Ships from**: ' . self::sanitize_markdown_inline( $this->resolve_country_name( $ship_country ) );
		}

		$handling     = isset( $settings['handling_time'] ) && is_array( $settings['handling_time'] )
			? $settings['handling_time']
			: [];
		$handling_min = isset( $handling['min'] ) ? (int) $handling['min'] : 0;
		$handling_max = isset( $handling['max'] ) ? (int) $handling['max'] : 0;
		if ( $handling_min > 0 && $handling_max > 0 ) {
			$range            = $handling_min === $handling_max
				? sprintf( '%d business day%s', $handling_max, 1 === $handling_max ? '' : 's' )
				: sprintf( '%d to %d business days', $handling_min, $handling_max );
			$shipping_lines[] = '- **Handling time**: ' . $range;
		}

		$return_policy = isset( $settings['return_policy'] ) && is_array( $settings['return_policy'] )
			? $settings['return_policy']
			: [];
		$return_mode   = isset( $return_policy['mode'] ) ? (string) $return_policy['mode'] : 'unconfigured';

		if ( 'returns_accepted' === $return_mode ) {
			$return_parts = [];
			$days         = isset( $return_policy['days'] ) ? (int) $return_policy['days'] : 0;
			if ( $days > 0 ) {
				$return_parts[] = sprintf( '%d days', $days );
			}
			$fees_map = [
				'FreeReturn'                       => 'free return shipping',
				'ReturnFeesCustomerResponsibility' => 'buyer pays return shipping',
				'OriginalShippingFees'             => 'original shipping non-refundable',
				'RestockingFees'                   => 'restocking fee applies',
			];
			$fees     = isset( $return_policy['fees'] ) ? (string) $return_policy['fees'] : '';
			if ( isset( $fees_map[ $fees ] ) ) {
				$return_parts[] = $fees_map[ $fees ];
			}
			if ( ! empty( $return_policy['country'] ) ) {
				$return_parts[] = 'applies to ' . self::sanitize_markdown_inline( (string) $return_policy['country'] );
			}
			if ( ! empty( $return_parts ) ) {
				$shipping_lines[] = '- **Returns**: ' . implode( ', ', $return_parts );
			}
		} elseif ( 'final_sale' === $return_mode ) {
			$shipping_lines[] = '- **Returns**: final sale, no returns accepted';
		}

		if ( ! empty( $shipping_lines ) ) {
			$lines[] = '## Shipping & Returns';
			$lines[] = '';
			foreach ( $shipping_lines as $line ) {
				$lines[] = $line;
			}
			$lines[] = '';
		}

		// ============================================================
		// ## Policies
		// ============================================================
		// Direct links to the store's real policy pages, sourced from
		// WP core / WC settings — never placeholders. Each line is
		// emitted only when its page is actually configured (resolves to
		// a non-empty URL), mirroring the omit-when-empty convention the
		// `## Store` and `## Shipping & Returns` sections use. The whole
		// section is suppressed when none of the three resolve, so a
		// freshly-installed merchant doesn't publish an empty heading.
		//
		// Sources:
		//   - Privacy: `get_privacy_policy_url()` (WP core; returns '' when
		//     no privacy page is set OR when the configured page isn't
		//     published — core self-heals against a trashed privacy page).
		//   - Terms: `get_permalink( wc_terms_and_conditions_page_id() )`,
		//     guarded on a positive page id, `function_exists` (WC may be
		//     loaded without the page-id helper in odd bootstraps), AND a
		//     `publish` status check (see below).
		//   - Refunds & returns: `get_permalink()` of the WC
		//     `woocommerce_refund_returns_page_id` option, guarded on a
		//     positive id and the same `publish` status check.
		//
		// The `'publish' === get_post_status( $id )` gate on the two
		// page-backed links is load-bearing: unlike `get_privacy_policy_url()`,
		// `get_permalink()` happily returns a live-looking URL for a TRASHED
		// page, and the WC option keeps pointing at it after the merchant
		// trashes it — so without the status check we'd publish a 404 link
		// to every agent. `get_post_status()` also returns false for a
		// hard-deleted id, so the same check hardens the dangling-id case.
		$policy_lines = array();

		$privacy_url = get_privacy_policy_url();
		if ( is_string( $privacy_url ) && '' !== $privacy_url ) {
			$policy_lines[] = '- **Privacy**: ' . esc_url_raw( $privacy_url );
		}

		if ( function_exists( 'wc_terms_and_conditions_page_id' ) ) {
			$terms_page_id = (int) wc_terms_and_conditions_page_id();
			if ( $terms_page_id > 0 && 'publish' === get_post_status( $terms_page_id ) ) {
				$terms_url = get_permalink( $terms_page_id );
				if ( is_string( $terms_url ) && '' !== $terms_url ) {
					$policy_lines[] = '- **Terms**: ' . esc_url_raw( $terms_url );
				}
			}
		}

		$refunds_page_id = (int) get_option( 'woocommerce_refund_returns_page_id' );
		if ( $refunds_page_id > 0 && 'publish' === get_post_status( $refunds_page_id ) ) {
			$refunds_url = get_permalink( $refunds_page_id );
			if ( is_string( $refunds_url ) && '' !== $refunds_url ) {
				$policy_lines[] = '- **Refunds & returns**: ' . esc_url_raw( $refunds_url );
			}
		}

		if ( ! empty( $policy_lines ) ) {
			$lines[] = '## Policies';
			$lines[] = '';
			foreach ( $policy_lines as $line ) {
				$lines[] = $line;
			}
			$lines[] = '';
		}

		// ============================================================
		// ## Structured data
		// ============================================================
		// One-line signpost. Inlining the JSON-LD itself would be
		// token-heavy and defeat the format's purpose — agents wanting
		// the structured payload fetch the product page directly. The
		// BuyAction-as-deterministic-cart-link callout is load-bearing:
		// it tells agents to read URLs from per-product JSON-LD rather
		// than constructing cart links by hand, which is the only way
		// to route correctly across simple / bundle / grouped /
		// variable product types.
		$lines[] = '## Structured data';
		$lines[] = '';
		$lines[] = 'Product pages emit schema.org/Product JSON-LD with `BuyAction.urlTemplate` for the per-product cart link, plus `MerchantReturnPolicy`, `OfferShippingDetails`, brand, price, availability, SKU, and GTIN where set. The `BuyAction` URL is the canonical deterministic cart link: it routes correctly across simple, variable, bundle, and grouped product types. The homepage emits `OnlineBusiness` with `hasOfferCatalog` and `SearchAction`.';
		$lines[] = '';

		// ============================================================
		// ## Rules for agents
		// ============================================================
		// Static behavioural guidance in the store's terse voice — no
		// settings, no dynamic data. Three rules cover the three things
		// an agent most needs to get right against this store: pace
		// (the store is rate-limited), checkout model (buyer-confirmed,
		// no delegated/in-chat payment — send the buyer the
		// `continue_url`), and currency (`context.currency` for accurate
		// pricing). Placed right before `## For agents` so the rules sit
		// next to the machine endpoints they govern.
		//
		// NO em-dashes in this copy (hyphens/periods only) — it's
		// merchant-facing and follows the readme/UI convention. The lines
		// remain mergeable with the `wc_ai_storefront_llms_txt_lines`
		// filter applied at the end of generate().
		$lines[] = '## Rules for agents';
		$lines[] = '';
		$lines[] = '- **Pace requests.** This store is rate-limited; on HTTP 429, back off and retry after a short delay.';
		$lines[] = '- **Checkout is buyer-confirmed on this store.** There is no delegated or in-chat payment. Create a checkout session (or follow a product BuyAction link) and send the buyer the `continue_url` to complete payment on the merchant\'s own checkout.';
		$lines[] = '- **Send `context.currency`** (ISO 4217) on catalog and checkout requests for accurate pricing. Accepted currencies are listed under Store.';
		$lines[] = '';

		// UCP endpoint bases — collectively reused across ## Typical agent
		// flow, ## For agents, and ## Read-only browsing below. NO UTMs on any
		// of these: they're machine endpoints agents call directly, not links a
		// buyer follows; UTM params would pollute the structured response payloads.
		$ucp_api_base = rtrim( rest_url( 'wc/ucp/v1' ), '/' );
		$ucp_manifest = $site_url . '.well-known/ucp';
		$ucp_checkout = $ucp_api_base . '/checkout-sessions';
		$mcp_enabled  = 'yes' === ( $settings['mcp_enabled'] ?? 'no' );

		// Real catalog refs for the lookup examples below — a real id/handle
		// keeps allowlist-based fetch tools (which snap to the literal example
		// query string) on a working endpoint instead of a not_found stub.
		$example_refs   = $this->get_example_catalog_refs( $settings );
		$example_ids    = ! empty( $example_refs['ids'] ) ? implode( ',', $example_refs['ids'] ) : 'prod_1,prod_2,…';
		$example_handle = '' !== $example_refs['slug'] ? $example_refs['slug'] : '{handle}';

		// ============================================================
		// ## Typical agent flow
		// ============================================================
		// The numbered orchestration an agent follows to transact, grounded in
		// this store's REAL UCP endpoints (not copied from another store — our
		// REST verbs differ). Culminates in the buyer-confirmed `continue_url`
		// handoff. The MCP closing line is emitted only when the MCP transport
		// is enabled (otherwise that endpoint 404s).
		$lines[] = '## Typical agent flow';
		$lines[] = '';
		$lines[] = "1. **Discover** — `GET {$ucp_manifest}` confirms capabilities and the supported UCP version, and advertises the REST API base (`{$ucp_api_base}`)" . ( $mcp_enabled ? " plus the MCP transport (`{$ucp_api_base}/mcp`)" : '' ) . '.';
		$lines[] = "2. **Search** — `POST {$ucp_api_base}/catalog/search` (or `GET {$ucp_api_base}/catalog/search?q={query}`) to find products matching the buyer's intent. Send `context.currency` for accurate pricing.";
		$lines[] = "3. **Look up** — `POST {$ucp_api_base}/catalog/lookup` (or `GET {$ucp_api_base}/catalog/lookup?ids={ids}`) for full details on the ids you selected.";
		$lines[] = "4. **Create a checkout session** — `POST {$ucp_checkout}` with the line items; the response returns a `continue_url`.";
		$lines[] = '5. **Hand off to the buyer** — redirect the buyer to that `continue_url` to review and pay on the store\'s own checkout. This store is buyer-confirmed: there is no delegated or in-chat payment to complete programmatically.';
		if ( $mcp_enabled ) {
			$lines[] = '';
			$lines[] = "MCP-capable agents can drive the same flow over the MCP transport (`POST {$ucp_api_base}/mcp`) using the `catalog_search`, `catalog_lookup`, and `checkout_create` tools (call `tools/list` to discover their schemas).";
		}
		$lines[] = '';

		// ============================================================
		// ## For agents
		// ============================================================
		// The collapsed UCP-discovery surface — what was previously spread
		// across `## API Access`, `## Checkout Policy`, and `## Attribution`.
		// Five bullets cover the canonical agent-doc path, capability discovery
		// (manifest), API base (REST root), batch catalog lookup, and checkout
		// escalation (the POST endpoint that returns a `continue_url`). URL
		// bases are defined above.
		// `agents.md` is the emerging canonical agent-doc path; this same
		// document is served byte-identically at both `/llms.txt` and
		// `/agents.md`. The line names `agents.md` as canonical and notes
		// the document is also served at `/llms.txt`, so it reads correctly
		// whichever path delivered it — a self-reference on `/agents.md`,
		// a pointer from `/llms.txt`. (The earlier wording, "this `/llms.txt`
		// mirrors it", was wrong when served on `/agents.md`, where "this"
		// document IS the canonical agents.md, not the mirror.)
		$agents_md_url = $site_url . 'agents.md';
		$lines[]       = '## For agents';
		$lines[]       = '';
		$lines[]       = "- **Agent doc**: `{$agents_md_url}` (canonical agent doc; the same document is served at `/llms.txt`)";
		$lines[]       = "- **UCP manifest**: `{$ucp_manifest}` — capability discovery (what the store supports)";
		$lines[]       = "- **UCP API base**: `{$ucp_api_base}` — REST root for search, lookup, checkout";
		$lines[]       = "- **Batch lookup**: `GET {$ucp_api_base}/catalog/lookup?ids={$example_ids}` — fetch up to " . WC_AI_Storefront_UCP_REST_Controller::MAX_IDS_PER_LOOKUP . ' products in one request (or `POST /catalog/lookup`). Prefer this over many single lookups.';
		$lines[]       = "- **Checkout API**: `POST {$ucp_checkout}` — server returns a `continue_url`; redirect the buyer there. Product-specific cart links are also available via JSON-LD `BuyAction.urlTemplate` on each product page (deterministic across product types).";
		$lines[]       = '';

		// ============================================================
		// ## Read-only browsing
		// ============================================================
		// For agents that only need to READ the catalog without transacting.
		// Structured UCP catalog reads lead (currency-aware). The bulk
		// `/products.json` is listed for fetch-only agents that cannot issue
		// POST (catalog/search) and cannot reliably append query params
		// (allowlist fetch tools snap to seen query strings): one parameterless
		// URL returns the whole catalog. The Shopify-compatible `*.json` paths
		// (bulk + scoped) are emitted only when the products.json feed is on.
		$lines[] = '## Read-only browsing';
		$lines[] = '';
		$lines[] = 'For agents that only need to read catalog data without transacting:';
		$lines[] = '';
		$lines[] = "- **Search** — `GET {$ucp_api_base}/catalog/search?q={query}` (UCP, structured, currency-aware)";
		$lines[] = "- **Look up** — `GET {$ucp_api_base}/catalog/lookup?slug={$example_handle}` (UCP, structured, by product handle) or `?ids={ids}` for batch";
		if ( 'yes' === ( $settings['products_json_enabled'] ?? 'no' ) ) {
			$lines[] = "- **All products (one file)** — `GET {$site_url}products.json` (Shopify-compatible; whole catalog, no params, no POST — simplest for fetch-only agents)";
			$lines[] = "- **Product JSON** — `GET {$site_url}products/{handle}.json` (Shopify-compatible)";
			$lines[] = "- **Collection JSON** — `GET {$site_url}collections/{handle}/products.json`";
			$lines[] = "- **Collection list** — `GET {$site_url}collections.json`";
		}
		$lines[] = '';
		$lines[] = 'Prefer the UCP catalog endpoints for structured, currency-aware access; the `*.json` paths are a Shopify-compatible convenience.';
		$lines[] = '';

		// ============================================================
		// ## Extension schema
		// ============================================================
		// Heading title narrowed from the previous
		// `## UCP Extension: com.woocommerce.ai_storefront` — that
		// title oversold the single-URL content beneath it and
		// exposed package-namespace syntax to merchants reading
		// their own llms.txt.
		//
		// The `#ucp-extension` anchor is preserved (independent of
		// heading text). The UCP manifest's `com.woocommerce.ai_storefront`
		// capability still declares its `spec` URL as
		// `/llms.txt#ucp-extension`, so agents following the spec
		// link land at the correct anchor regardless of how the
		// section header reads.
		$ucp_schema_url = function_exists( 'rest_url' )
			? rtrim( rest_url( 'wc/ucp/v1/extension/schema' ), '/' )
			: '/wp-json/wc/ucp/v1/extension/schema';

		$lines[] = '<a id="ucp-extension"></a>';
		$lines[] = '## Extension schema';
		$lines[] = '';
		$lines[] = "Machine-readable JSON Schema for the `com.woocommerce.ai_storefront` UCP extension: `{$ucp_schema_url}`";
		$lines[] = '';

		/**
		 * Filter the llms.txt content lines before rendering.
		 *
		 * @since 1.0.0
		 * @param array $lines    The lines of Markdown content.
		 * @param array $settings The AI syndication settings.
		 */
		$lines = apply_filters( 'wc_ai_storefront_llms_txt_lines', $lines, $settings );

		return implode( "\n", $lines );
	}

	/**
	 * Source a real syndicated product for the llms.txt lookup examples.
	 *
	 * The catalog/lookup endpoint is the one a fetch tool is most likely to
	 * call from llms.txt, and allowlist-based tools (e.g. claude.ai web_fetch)
	 * snap to the literal example query string they have seen — so a
	 * placeholder like `?ids=prod_1,prod_2` resolves to a `not_found` stub.
	 * Emitting a REAL id / handle makes the documented example return real
	 * product data instead. Queries up to 10 published, catalog-visible
	 * products and returns the first two that pass the syndication gate.
	 *
	 * @param array $settings Plugin settings (for the syndication gate).
	 * @return array{ids: string[], slug: string} UCP ids (`prod_<id>`) and the
	 *               first product's slug; empty when no syndicated product exists.
	 */
	private function get_example_catalog_refs( array $settings ): array {
		$result = [ 'ids' => [], 'slug' => '' ];
		if ( ! function_exists( 'wc_get_products' ) ) {
			return $result;
		}

		$products = wc_get_products(
			[
				'status'     => 'publish',
				'visibility' => 'catalog',
				'limit'      => 10,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'objects',
			]
		);

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			if ( ! WC_AI_Storefront::is_product_syndicated( $product, $settings ) ) {
				continue;
			}
			$result['ids'][] = WC_AI_Storefront_UCP_Product_Translator::PRODUCT_ID_PREFIX . $product->get_id();
			if ( '' === $result['slug'] ) {
				$result['slug'] = (string) $product->get_slug();
			}
			if ( count( $result['ids'] ) >= 2 ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Discover sitemap URLs by probing known paths + WP core's helper.
	 *
	 * Unlike the robots.txt sitemap handling (where `Allow:` for a
	 * non-existent path is a harmless no-op), llms.txt is a
	 * user-facing content document — emitting URLs that 404 would
	 * be factually incorrect. So here we HEAD-probe each candidate
	 * and only include the ones that actually respond.
	 *
	 * Probe sources:
	 *   - `get_sitemap_url( 'index' )` — WP core canonical (5.5+)
	 *   - `WC_AI_Storefront_Robots::COMMON_SITEMAP_PATHS` — common
	 *     plugin paths (Jetpack, Yoast, Rank Math, etc.) appended
	 *     to site URL
	 *
	 * Synchronous HEAD requests with a 1-second timeout, made on
	 * the same origin. The discovered URL list is cached in
	 * SITEMAP_CACHE_KEY for 24 hours (independent of the 1-hour
	 * llms.txt content cache) so sitemap probe I/O does not
	 * re-run every time a product update busts the content cache
	 * (P-18). Worst case (cold sitemap cache, all 4 paths time out):
	 * 4 seconds of latency once per 24 hours. Typical case (paths
	 * exist or fast 404): <500ms; near-zero on warm cache.
	 *
	 * @param string $site_url Home URL with trailing slash.
	 * @return string[]        Sitemap URLs that returned 2xx/3xx to
	 *                         a HEAD probe. Empty on sites with no
	 *                         sitemap at any common path.
	 */
	private static function discover_sitemap_urls( string $site_url ): array {
		// Check the sitemap-specific cache first. Sitemaps rarely change
		// (new plugin installs, domain moves) while product content changes
		// constantly — decoupling the TTLs means the expensive HEAD probes
		// run at most once per 24 hours regardless of how often llms.txt
		// content is regenerated.
		$cached = get_transient( self::SITEMAP_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$candidates = array();

		// WP core canonical (returns full URL when core sitemap is
		// enabled; returns empty string if disabled via filter).
		if ( function_exists( 'get_sitemap_url' ) ) {
			$core = get_sitemap_url( 'index' );
			if ( is_string( $core ) && '' !== $core ) {
				$candidates[] = $core;
			}
		}

		// Common plugin paths, absolute-URL form for llms.txt output.
		$base = rtrim( $site_url, '/' );
		foreach ( WC_AI_Storefront_Robots::COMMON_SITEMAP_PATHS as $path ) {
			$candidates[] = $base . $path;
		}
		$candidates = array_values( array_unique( $candidates ) );

		// HEAD-probe each. Only URLs returning 2xx/3xx make it in.
		$existent = array();
		foreach ( $candidates as $candidate ) {
			$response = wp_remote_head(
				$candidate,
				array(
					'timeout'     => 1,
					'redirection' => 1,
					'blocking'    => true,
					// SSL verification: disabled only in dev/debug
					// environments (WP_DEBUG on) where self-signed certs
					// are common. In production, verify the cert so these
					// self-origin probes don't silently accept MITM
					// responses.
					'sslverify'   => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 400 ) {
				$existent[] = $candidate;
			}
		}

		// Cache the probed result for 24 hours. An empty array is a valid
		// cached result ("no sitemap found") — caching it prevents re-probing
		// every hour on sites that have no sitemap.
		set_transient( self::SITEMAP_CACHE_KEY, $existent, DAY_IN_SECONDS );

		return $existent;
	}

	/**
	 * Sanitize a plain-text value for safe embedding in Markdown inline contexts.
	 *
	 * Two sanitization passes are applied (FIND-S06):
	 *
	 *   1. Control-character stripping: newlines inside `# {$site_name}` end the
	 *      heading; inside `> {$description}` they end the blockquote. All control
	 *      characters (U+0000-U+001F, U+007F) including CR and LF are removed.
	 *
	 *   2. Markdown link-text escaping (only when `$is_link_text` is true): a `]`
	 *      character inside `[{$text}]({$url})` closes the link-text segment early,
	 *      allowing a value like `Photos]( https://evil.example)` to inject a
	 *      second link target. Backslashes are escaped first to prevent double-
	 *      processing; then `[` and `]` are backslash-escaped per the CommonMark
	 *      spec. This escaping must NOT be applied to heading or blockquote contexts
	 *      where literal backslashes would be visible to the reader.
	 *
	 * @param string $value        Raw value (post-entity-decode, post-tag-strip).
	 * @param bool   $is_link_text True when embedding inside `[...]` link text.
	 * @return string              Sanitized value safe for the target Markdown context.
	 */
	private static function sanitize_markdown_inline( string $value, bool $is_link_text = false ): string {
		// Remove control characters including CR, LF, and TAB.
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );

		if ( $is_link_text ) {
			// Escape backslash first so subsequent escapes are not double-processed.
			$value = str_replace( '\\', '\\\\', $value );
			// Escape brackets that delimit the Markdown link-text segment.
			$value = str_replace( array( '[', ']' ), array( '\\[', '\\]' ), $value );
		}

		return $value;
	}

	/**
	 * Resolve an ISO-3166-1 alpha-2 country code to its human-readable
	 * English name via WC's bundled country list (e.g. `US` -> `United
	 * States`, `GB` -> `United Kingdom`).
	 *
	 * Why a helper rather than inline: the same lookup runs in two
	 * places — the `## Store` Location line (drawn from
	 * `build_postal_address()`'s `addressCountry`) and the
	 * `## Shipping & Returns` Ships-from line (drawn from
	 * `wc_get_base_location()`). Keeping them in lockstep means a
	 * merchant in the UK never sees "United Kingdom" in one place and
	 * "GB" in the other.
	 *
	 * Falls back to the raw code when the country map can't be
	 * resolved (impossible at runtime — the plugin requires WC — but
	 * plays nicely with the unit-test path that calls generate()
	 * without stubbing WC()). The map source comes from
	 * `get_country_map()`, which is a protected instance seam tests
	 * subclass to inject a fixture without globally stubbing the
	 * `WC()` function (which would leak across the test suite).
	 *
	 * @param string $code ISO-3166-1 alpha-2 country code.
	 * @return string Human-readable country name, or the raw code as
	 *                fallback when the country map doesn't contain it.
	 */
	private function resolve_country_name( string $code ): string {
		$code = strtoupper( trim( $code ) );
		if ( '' === $code ) {
			return '';
		}

		$map  = $this->get_country_map();
		$name = isset( $map[ $code ] ) ? (string) $map[ $code ] : '';

		if ( '' === $name ) {
			return $code;
		}

		// WC's country names can carry HTML entities for non-ASCII
		// letters (e.g. `Cura&ccedil;ao`). Decode so the rendered
		// llms.txt has plain text — agents and merchants don't want
		// to see `&ccedil;` in a "what country is this store in" line.
		return html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Return WC's ISO country code -> human-readable English name map.
	 *
	 * Test seam (mirrors the `get_wc_countries()` pattern on JsonLd):
	 * extracted into its own protected method so unit tests can
	 * subclass and inject a country-map fixture. Globally stubbing
	 * `WC()` via Brain Monkey leaks the function definition across
	 * the whole test suite and breaks unrelated tests that call WC()
	 * unstubbed; the instance-method seam avoids that entirely.
	 *
	 * @return array<string, string> ISO alpha-2 code -> entity-encoded
	 *                               English name. Empty array when WC
	 *                               is unavailable.
	 */
	protected function get_country_map(): array {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! $wc || ! isset( $wc->countries ) || ! is_object( $wc->countries ) ) {
			return [];
		}
		return isset( $wc->countries->countries ) ? (array) $wc->countries->countries : [];
	}


	// `get_featured_products()` was removed alongside the
	// "Featured Products" llms.txt section. See the deletion comment
	// where the section used to render (around line ~315) for
	// rationale. If the section is ever reintroduced, prefer sourcing
	// from the Store API with explicit freshness disclosure rather
	// than rebuilding this internal `wc_get_products()` path.
}
