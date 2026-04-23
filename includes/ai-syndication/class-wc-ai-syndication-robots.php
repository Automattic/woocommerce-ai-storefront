<?php
/**
 * AI Syndication: Robots.txt Integration
 *
 * Updates robots.txt to welcome AI crawlers and point them
 * to the llms.txt and UCP manifest.
 *
 * @package WooCommerce_AI_Storefront
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages robots.txt directives for AI crawlers.
 */
class WC_AI_Storefront_Robots {

	/**
	 * Live browsing / user-initiated AI agents.
	 *
	 * These agents fetch content during a user's active query — an
	 * agent acting on a user's behalf *right now*. For commerce this
	 * is the revenue-path traffic: an agent sees fresh inventory +
	 * prices, routes a user to checkout, conversion happens. These
	 * should generally be allowed for merchants who want any AI
	 * discoverability at all.
	 *
	 * Distinguished from training crawlers by vendor convention —
	 * the `-User` suffix (ChatGPT-User, Claude-User, Perplexity-User)
	 * signals "triggered by an active user session" per each
	 * vendor's documentation.
	 *
	 * @var string[]
	 */
	const LIVE_BROWSING_AGENTS = [
		// OpenAI.
		'ChatGPT-User',
		'OAI-SearchBot',

		// Anthropic.
		'Claude-User',
		'Claude-SearchBot',

		// Perplexity — PerplexityBot indexes for live answer
		// retrieval (search-index style), distinct from training
		// corpus construction. Per Perplexity's documentation it
		// maps to the same live-answer path as Perplexity-User.
		'PerplexityBot',
		'Perplexity-User',

		// Apple — plain Applebot is the long-standing Siri/Spotlight
		// search crawler (since 2015). Applebot-Extended is the
		// newer AI-training variant and lives in TRAINING_CRAWLERS.
		'Applebot',

		// Agentic shopping — AI that doesn't just read the catalog
		// but ALSO places the order on the user's behalf. Highest-
		// value AI traffic for commerce: showing up here means
		// showing up at purchase intent, not just research.
		//
		// AmazonBuyForMe powers Amazon Rufus's "buy from the open
		// web" feature — Rufus compares your products to Amazon's
		// catalog and can execute purchases. KlarnaBot drives
		// high-intent shopping queries primarily in the EU and US
		// fashion/lifestyle verticals.
		'AmazonBuyForMe',
		'KlarnaBot',

		// Google Shopping — distinct from Googlebot (search indexing)
		// and Google-Extended (training). Storebot-Google powers
		// the Shopping Overviews surface and "AI Outfit" visual
		// recommendations. US-centric but global for commerce.
		'Storebot-Google',

		// Microsoft Shopping / Bing Ads — Microsoft's counterpart
		// to Storebot-Google. AdIdxBot validates and indexes
		// product landing pages for Microsoft Advertising (Bing
		// Ads) and Microsoft Shopping listings. The index it
		// builds also feeds Copilot's shopping answers, so
		// allowing AdIdxBot is the commerce prerequisite for
		// Copilot discoverability — parallels the Storebot-Google
		// → Gemini relationship. Distinct from `bingbot` (general
		// search) and `Microsoft-BingBot-Extended` (training
		// opt-out, see TRAINING_CRAWLERS).
		'AdIdxBot',

		// Regional search + AI — Asia. Baidu (China) dominates
		// Chinese discovery with ERNIEBot (general crawling for
		// the Ernie model) and YiyanBot (real-time conversational
		// citations). Wrtn ("the Korean ChatGPT") is the lifestyle
		// product-discovery leader in South Korea. Naver powers
		// AiRSearch, vital for the Korean market in a different
		// slice from Wrtn. Huawei's PetalBot backs Petal Search
		// and the AI Assistant shipped on hundreds of millions of
		// Huawei devices (primary Android alternative in China
		// and growing presence across Asia + emerging markets).
		//
		// Merchants selling only in English-speaking markets can
		// safely keep these checked without traffic impact — they
		// only invoke when users actually search from the relevant
		// region. Merchants selling in Asia lose significant AI
		// discovery if these are blocked.
		'ERNIEBot',
		'YiyanBot',
		'WRTNBot',
		'NaverBot',
		'PetalBot',

		// Regional search + AI — Europe. YandexBot powers Yandex's
		// AI Assistant plus the traditional Yandex search engine;
		// covers Russian-speaking markets (Russia, Belarus,
		// Kazakhstan, Ukraine, and Russian-language speakers
		// globally). The search + AI fusion is similar to
		// Naver/Baidu — one bot, dual duties.
		'YandexBot',
	];

	/**
	 * AI training / indexing crawlers.
	 *
	 * These agents crawl to build training corpora or static indexes
	 * that feed model weights / cached snapshots. Inclusion here is a
	 * merchant brand-strategy decision, NOT an AI-discoverability
	 * one — these crawlers do not route revenue to the merchant.
	 *
	 * The commerce-specific trade-off: a training crawl captures
	 * your catalog at a single point in time and that snapshot may
	 * surface in AI answers months later when your actual inventory,
	 * pricing, and availability have moved. A user asking "is X in
	 * stock at Piero's Fashion House?" could get a stale-but-
	 * confidently-wrong answer attributed to your brand. Merchants
	 * who prioritize brand awareness over quote accuracy allow them;
	 * merchants who prioritize quote accuracy block them. Neither
	 * choice is wrong.
	 *
	 * UCP's design philosophy (as of v2026-04-08) focuses exclusively
	 * on live agentic commerce — the spec has no verbs for "indexed
	 * for later use." Training crawler policy is therefore
	 * out-of-scope for UCP and left to merchant discretion.
	 *
	 * @var string[]
	 */
	const TRAINING_CRAWLERS = [
		// OpenAI.
		'GPTBot',

		// Google.
		'Google-Extended',

		// Anthropic.
		'ClaudeBot',

		// Meta.
		'Meta-ExternalAgent',

		// Amazon.
		'Amazonbot',

		// Apple.
		'Applebot-Extended',

		// Microsoft — Bing's AI-training opt-out token. Parallels
		// Google-Extended / Applebot-Extended: honoring it keeps
		// your content out of Microsoft's generative-AI training
		// corpora without affecting Bing's regular search ranking
		// or shopping index (those are governed by `bingbot` and
		// `AdIdxBot` respectively, not this token). Default-off
		// under the training-crawlers policy; merchants who want
		// to contribute content to Copilot's training explicitly
		// opt in.
		'Microsoft-BingBot-Extended',

		// ByteDance (TikTok). Primarily training, but also powers
		// TikTok's internal search and commerce surfaces. Merchants
		// who rely on TikTok Shop or viral-traffic discovery should
		// consider manually enabling this from the admin UI —
		// defaulting to training-blocked keeps it consistent with
		// the other training crawlers but loses TikTok visibility.
		'Bytespider',

		// CommonCrawl — feeds most open-source LLM training corpora.
		// Merchants who want maximum visibility across the AI
		// ecosystem enable this; those who prefer catalog privacy
		// block it.
		'CCBot',

		// Cohere.
		'cohere-ai',
	];

	/**
	 * Combined allow-list — live browsing + training.
	 *
	 * Preserved as the pre-1.5.0 canonical list for backward
	 * compatibility: existing installs' saved `allowed_crawlers`
	 * values, the `sanitize_allowed_crawlers()` intersect, and any
	 * consumer code that historically consumed this constant
	 * continue to work unchanged.
	 *
	 * New code should prefer the category-specific constants when
	 * the distinction matters (e.g. default-on/default-off logic
	 * in the admin UI).
	 *
	 * @var string[]
	 */
	const AI_CRAWLERS = [
		// Live browsing (revenue path — recommended on).
		'ChatGPT-User',
		'OAI-SearchBot',
		'Claude-User',
		'Claude-SearchBot',
		'PerplexityBot',
		'Perplexity-User',
		'Applebot',
		'AmazonBuyForMe',
		'KlarnaBot',
		'Storebot-Google',
		'AdIdxBot',
		'ERNIEBot',
		'YiyanBot',
		'WRTNBot',
		'NaverBot',
		'PetalBot',
		'YandexBot',

		// Training crawlers (brand-strategy decision — merchant choice).
		'GPTBot',
		'Google-Extended',
		'ClaudeBot',
		'Meta-ExternalAgent',
		'Amazonbot',
		'Applebot-Extended',
		'Microsoft-BingBot-Extended',
		'Bytespider',
		'CCBot',
		'cohere-ai',
	];

	/**
	 * Crawl-delay (in seconds) emitted alongside each AI-crawler
	 * `User-agent:` block in robots.txt.
	 *
	 * Crawl-delay is an advisory directive originally introduced by
	 * Google as a robots.txt extension. Well-behaved crawlers respect
	 * it and wait this many seconds between requests; less-careful
	 * ones ignore it. Combined with the plugin's Store API rate
	 * limiter (hard enforcement at 25 req/min per bot by default),
	 * Crawl-delay acts as the polite-signal layer before the hard
	 * limit kicks in.
	 *
	 * The value (2 seconds) is deliberately conservative — matches
	 * the merchant-protection guidance recommended for e-commerce
	 * sites facing aggressive AI search bots (OAI-SearchBot and
	 * similar live agents have been observed bursting dozens of
	 * requests in seconds during product-comparison queries). Two
	 * seconds is low enough to not materially hurt user-perceived
	 * agent latency, high enough to dampen the worst burst behavior.
	 */
	const CRAWL_DELAY_SECONDS = 2;

	/**
	 * Sanitize an `allowed_crawlers` input against the canonical crawler list.
	 *
	 * Strips unknown IDs left over from plugin upgrades that rotated the
	 * crawler roster — e.g. the phantom `Gemini` entry removed in 1.6.0
	 * (never matched any real crawler; Google's Gemini-training bot is
	 * `Google-Extended`), or the deprecated `anthropic-ai` UA that
	 * Anthropic replaced with the `ClaudeBot` / `Claude-User` /
	 * `Claude-SearchBot` family. Keeping the stored list in sync with
	 * `AI_CRAWLERS` prevents deprecated `User-agent:` blocks from
	 * leaking into `robots.txt` and keeps the admin UI's "X of Y"
	 * count honest.
	 *
	 * @param mixed $input Raw input from settings save — expected array of strings.
	 * @return string[]    Re-indexed list of valid crawler IDs.
	 */
	public static function sanitize_allowed_crawlers( $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$sanitized = array_map( 'sanitize_text_field', $input );

		// `array_intersect` preserves first-argument keys, so `array_values`
		// re-indexes — otherwise the JSON response serializes as an object.
		return array_values( array_intersect( $sanitized, self::AI_CRAWLERS ) );
	}

	/**
	 * Resolve which crawlers are allowed for a given stored settings row.
	 *
	 * Encapsulates the three-way branch callers would otherwise
	 * implement ad-hoc:
	 *
	 *   1. Fresh install (no `allowed_crawlers` key in stored option):
	 *      return `LIVE_BROWSING_AGENTS` — commerce-safe default,
	 *      training crawlers off.
	 *
	 *   2. Merchant explicitly saved an empty list (e.g. via the
	 *      admin UI's "Clear selection" button): preserve `[]`. This
	 *      is the "block all AI crawlers" opt-out choice.
	 *
	 *   3. Merchant saved a non-empty list: preserve verbatim.
	 *
	 *      Using `array_key_exists()` rather than `! empty()` is
	 *      load-bearing for case 2: `! empty([])` is true, which
	 *      would silently revert a merchant's explicit opt-out to
	 *      the fresh-install default on every `get_settings()`
	 *      call — a real consent regression.
	 *
	 * Extracted to a pure helper so the three branches are
	 * testable without needing to instantiate the full plugin
	 * settings/storage layer.
	 *
	 * @param array<string, mixed> $stored_settings The settings array
	 *                                              as returned from
	 *                                              `get_option()`, which
	 *                                              may or may not include
	 *                                              an `allowed_crawlers`
	 *                                              key.
	 * @return string[]                              The resolved allow-list.
	 */
	public static function resolve_allowed_crawlers( array $stored_settings ): array {
		if ( ! array_key_exists( 'allowed_crawlers', $stored_settings ) ) {
			return self::LIVE_BROWSING_AGENTS;
		}

		$stored = $stored_settings['allowed_crawlers'];
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_filter( 'robots_txt', [ $this, 'add_ai_crawler_rules' ], 20, 2 );

		// CORS + nosniff headers on the robots.txt response. Same
		// rationale as the llms.txt CORS fix in 1.4.1: AI browsing
		// tools running in Chromium-headless contexts enforce CORS
		// on their fetches, and without `Access-Control-Allow-Origin`
		// the file is invisible to them. Perplexity's browsing tool
		// was confirmed affected before 1.6.1.
		//
		// We don't serve /robots.txt ourselves — WordPress core does
		// via `do_robotstxt`. The `do_robotstxt` action fires inside
		// `do_robots()` after WP sets Content-Type but BEFORE the
		// body is flushed, which is the right moment to inject
		// additional headers without fighting WP core.
		add_action( 'do_robotstxt', [ $this, 'send_cors_headers' ], 5 );
	}

	/**
	 * Inject CORS + nosniff headers on the /robots.txt response.
	 *
	 * Hooked on `do_robotstxt` (action that fires exactly once,
	 * only on requests WP has identified as robots.txt). Runs at
	 * priority 5 to set headers before any other plugin hooking
	 * the same action can echo content (which would flush headers).
	 */
	public function send_cors_headers() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * Add AI crawler rules to robots.txt.
	 *
	 * Hooked onto WordPress's `robots_txt` filter. WP passes whether the
	 * site is "public" (Reading > Search engine visibility) as the second
	 * argument; we no-op on private sites to avoid advertising a catalog
	 * the operator explicitly wants hidden.
	 *
	 * @param string $output    The existing robots.txt content.
	 * @param bool   $is_public Whether the site is publicly visible.
	 * @return string Modified robots.txt content.
	 */
	public function add_ai_crawler_rules( $output, $is_public ) {
		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return $output;
		}

		if ( ! $is_public ) {
			return $output;
		}

		$allowed_bots = $settings['allowed_crawlers'] ?? self::AI_CRAWLERS;

		// Discover sitemap URLs from the existing robots.txt content
		// (WordPress core since 5.5 emits one at the top; SEO plugins
		// like Yoast and Rank Math emit their own). We parse what's
		// already there rather than hardcoding a path, so any source
		// of truth a merchant's site is using — WP core, Yoast, Rank
		// Math, custom — flows through to the AI-bot blocks.
		//
		// Captured before the `$output .=` loop so we're reading the
		// ORIGINAL input, not lines we've already appended ourselves
		// (which would create a feedback loop if we re-emitted them).
		$sitemap_urls = self::extract_sitemap_urls( $output );

		$output .= "\n# WooCommerce AI Storefront\n";
		$output .= "# Machine-readable store data for AI-assisted product discovery\n\n";

		// Derive paths from actual WooCommerce permalink settings.
		// `wp_parse_url` can return an empty string, false, or null when the
		// permalink isn't set yet (fresh WC installs). Fall back to sensible
		// defaults that match WC's out-of-box routes.
		$parse_path    = static function ( string $page, string $fallback ): string {
			$path = wp_parse_url( wc_get_page_permalink( $page ), PHP_URL_PATH );
			return ( is_string( $path ) && '' !== $path ) ? $path : $fallback;
		};
		$shop_path     = $parse_path( 'shop', '/shop/' );
		$cart_path     = $parse_path( 'cart', '/cart/' );
		$checkout_path = $parse_path( 'checkout', '/checkout/' );
		$account_path  = $parse_path( 'myaccount', '/my-account/' );

		$product_base  = '/' . trim( get_option( 'woocommerce_permalinks', [] )['product_base'] ?? 'product', '/' ) . '/';
		$category_base = '/' . trim( get_option( 'woocommerce_permalinks', [] )['category_base'] ?? 'product-category', '/' ) . '/';

		foreach ( $allowed_bots as $bot ) {
			$bot     = sanitize_text_field( $bot );
			$output .= "User-agent: {$bot}\n";

			// Advisory rate-hint: well-behaved crawlers wait this many
			// seconds between requests, which dampens burst traffic
			// (AI search bots in particular have been observed firing
			// 10+ requests in a second during product-comparison
			// queries). Emitted before the Allow/Disallow rules per
			// robots.txt convention so crawlers pick it up before
			// they fetch anything. See the CRAWL_DELAY_SECONDS
			// constant docblock for value rationale.
			$output .= 'Crawl-delay: ' . self::CRAWL_DELAY_SECONDS . "\n";

			$output .= "Allow: /llms.txt\n";
			$output .= "Allow: /.well-known/ucp\n";
			$output .= "Allow: /wp-json/wc/store/\n";
			// UCP adapter endpoints (plugin 1.3.0+): catalog/search,
			// catalog/lookup, checkout-sessions. Paired visually with
			// the Store API allow above — both are JSON REST surfaces
			// agents dispatch to. Distinct from the /.well-known/ucp
			// discovery manifest, which announces that these exist.
			$output .= "Allow: /wp-json/wc/ucp/\n";

			// Sitemap Allows, emitted inside each named block as
			// defense against crawlers that only parse directives
			// within their own User-agent group (a spec-noncompliant
			// but not-unheard-of behavior). The top-level Sitemap:
			// directive is already authoritative per spec, so this
			// is pure redundancy — but it's free and it accommodates
			// implementations that over-scope their parsing.
			//
			// Two-source strategy: paths discovered from the existing
			// robots.txt body (via `extract_sitemap_urls`, covers
			// SEO plugins that use the `robots_txt` filter) plus the
			// hardcoded `COMMON_SITEMAP_PATHS` list (covers SEO
			// plugins that emit via `do_robotstxt` action — invisible
			// to our filter but commonly at known paths). Union,
			// deduped — non-existent paths are no-ops for bots.
			$discovered_paths = array_filter(
				array_map(
					static function ( string $url ): ?string {
						$parsed = wp_parse_url( $url, PHP_URL_PATH );
						return ( is_string( $parsed ) && '' !== $parsed ) ? $parsed : null;
					},
					$sitemap_urls
				)
			);
			$emit_paths       = array_values(
				array_unique(
					array_merge( $discovered_paths, self::COMMON_SITEMAP_PATHS )
				)
			);
			foreach ( $emit_paths as $sitemap_path ) {
				if ( is_string( $sitemap_path ) && '' !== $sitemap_path ) {
					$output .= "Allow: {$sitemap_path}\n";
				}
			}

			if ( '/' !== $shop_path ) {
				$output .= "Allow: {$shop_path}\n";
			}
			$output .= "Allow: {$product_base}\n";
			$output .= "Allow: {$category_base}\n";
			$output .= "Disallow: {$cart_path}\n";
			$output .= "Disallow: {$checkout_path}\n";
			$output .= "Disallow: {$account_path}\n";
			$output .= "\n";
		}

		// Emit explicit opt-out for any known AI bot the merchant
		// has unchecked. Pre-1.6.1 these bots silently fell through
		// to `User-agent: *` (which allows most of the site); post-
		// 1.6.1 they receive a specific `Disallow: /` block that
		// matches merchant intent more honestly.
		//
		// The most important case is the training-default-off
		// policy from 1.6.0: on fresh installs, every training
		// crawler is unchecked. This block converts the implicit
		// "not listed" signal into an explicit "you are not welcome"
		// signal, which well-behaved crawlers respect more reliably.
		//
		// Multiple User-agent lines before a single Disallow is a
		// valid rule group per RFC 9309 section 2.2.1 — saves
		// ~150 bytes vs. a separate block per bot on a fresh
		// install.
		$opted_out = array_values( array_diff( self::AI_CRAWLERS, $allowed_bots ) );
		if ( ! empty( $opted_out ) ) {
			$output .= "# Explicit opt-out for AI bots the merchant has unchecked.\n";
			foreach ( $opted_out as $bot ) {
				$output .= 'User-agent: ' . sanitize_text_field( $bot ) . "\n";
			}
			$output .= "Disallow: /\n\n";
		}

		// Re-emit sitemap references at the bottom of our section.
		// Industry convention is to place Sitemap: declarations at
		// the end of robots.txt so parsers that process directives
		// in document order encounter them after they've finished
		// their User-agent group parsing. WordPress core emits them
		// at the top (valid per spec); duplicating at the bottom
		// is defense against both orderings.
		foreach ( $sitemap_urls as $sitemap_url ) {
			$output .= "Sitemap: {$sitemap_url}\n";
		}

		/**
		 * Filter the AI crawler robots.txt rules.
		 *
		 * @since 1.0.0
		 * @param string $output   The robots.txt content.
		 * @param array  $settings The AI syndication settings.
		 */
		return apply_filters( 'wc_ai_storefront_robots_txt', $output, $settings );
	}

	/**
	 * Common sitemap paths emitted by WordPress core and popular SEO
	 * plugins. We emit `Allow:` for every entry in this list
	 * regardless of whether the specific path is active on the
	 * merchant's site — an `Allow:` directive for a non-existent
	 * path is a no-op (bots still get 404 if they try to fetch it),
	 * so this is harmless fallback coverage for sites whose SEO
	 * plugin emits `Sitemap:` via the `do_robotstxt` action rather
	 * than the `robots_txt` filter (a common pattern that leaves
	 * our auto-discovery blind to their URLs).
	 *
	 * Paths chosen from observed real-world usage:
	 *   - `/sitemap.xml`        — Yoast, Rank Math, AIOSEO default,
	 *                             WooCommerce SEO, many custom configs
	 *   - `/sitemap_index.xml`  — Yoast's index format (`sitemap.xml`
	 *                             is often an alias to this)
	 *   - `/wp-sitemap.xml`     — WordPress core (since 5.5)
	 *   - `/news-sitemap.xml`   — Yoast Premium's Google News variant,
	 *                             also some Rank Math setups
	 *
	 * @var string[]
	 */
	const COMMON_SITEMAP_PATHS = [
		'/sitemap.xml',
		'/sitemap_index.xml',
		'/wp-sitemap.xml',
		'/news-sitemap.xml',
	];

	/**
	 * Extract sitemap URLs from existing robots.txt content.
	 *
	 * Two discovery strategies layered:
	 *
	 *   1. Regex the existing `$output` body for `Sitemap:`
	 *      directives. Catches any SEO plugin that uses the
	 *      `robots_txt` filter correctly (emission flows through
	 *      `$output`).
	 *
	 *   2. Fall back to `get_sitemap_url( 'index' )` — WP core
	 *      helper since 5.5 — for sites running the default
	 *      core sitemap with no SEO plugin configured.
	 *
	 * NOT covered by either strategy: SEO plugins that emit
	 * `Sitemap:` via the `do_robotstxt` action (direct `echo`).
	 * Those lines appear in the final robots.txt body but aren't
	 * visible to our filter. For those sites, the caller falls
	 * back to the hardcoded `COMMON_SITEMAP_PATHS` list — this
	 * function's job is to return specifically-discovered URLs,
	 * not to guarantee coverage.
	 *
	 * @param string $robots_txt The full robots.txt body at the
	 *                           moment our filter runs.
	 * @return string[]          Discovered sitemap URLs (may be empty).
	 */
	private static function extract_sitemap_urls( string $robots_txt ): array {
		if ( preg_match_all( '/^\s*Sitemap:\s*(\S+)/mi', $robots_txt, $matches ) ) {
			return array_values( array_unique( $matches[1] ) );
		}

		// No Sitemap directive in input → fall back to WP core's
		// canonical sitemap URL. Returns empty string on sites where
		// the core sitemap is disabled via filter; we filter that out.
		if ( function_exists( 'get_sitemap_url' ) ) {
			$core_sitemap = get_sitemap_url( 'index' );
			if ( is_string( $core_sitemap ) && '' !== $core_sitemap ) {
				return [ $core_sitemap ];
			}
		}

		return [];
	}
}
