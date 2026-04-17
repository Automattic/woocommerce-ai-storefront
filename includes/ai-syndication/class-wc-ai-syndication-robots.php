<?php
/**
 * AI Syndication: Robots.txt Integration
 *
 * Updates robots.txt to welcome AI crawlers and point them
 * to the llms.txt and UCP manifest.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages robots.txt directives for AI crawlers.
 */
class WC_AI_Syndication_Robots {

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
	 * crawler roster (e.g. the Bytespider → OAI-SearchBot swap in v1.1.0)
	 * and any malformed / non-matching strings. Keeping the stored list in
	 * sync with AI_CRAWLERS prevents deprecated `User-agent: Bytespider`
	 * blocks from leaking into `robots.txt` and keeps the admin UI's
	 * "X of Y" count honest.
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
	 * Initialize hooks.
	 */
	public function init() {
		add_filter( 'robots_txt', [ $this, 'add_ai_crawler_rules' ], 20, 2 );
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
		$settings = WC_AI_Syndication::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return $output;
		}

		if ( ! $is_public ) {
			return $output;
		}

		$allowed_bots = $settings['allowed_crawlers'] ?? self::AI_CRAWLERS;

		$output .= "\n# WooCommerce AI Syndication\n";
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

		/**
		 * Filter the AI crawler robots.txt rules.
		 *
		 * @since 1.0.0
		 * @param string $output   The robots.txt content.
		 * @param array  $settings The AI syndication settings.
		 */
		return apply_filters( 'wc_ai_syndication_robots_txt', $output, $settings );
	}
}
