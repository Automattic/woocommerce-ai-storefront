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
	 * the `-User` suffix (ChatGPT-User, Claude-User, Perplexity-User,
	 * Mistralai-User) signals "triggered by an active user session"
	 * per each vendor's documentation.
	 *
	 * @var string[]
	 */
	const LIVE_BROWSING_AGENTS = [
		// AI search & discovery crawlers — alphabetical.
		//
		// These build indexes that AI surfaces draw on when answering
		// product queries. Two sub-flavours coexist here:
		//
		// Search-index crawlers (build a persistent index):
		//   Applebot → Siri/Spotlight/Apple Intelligence
		//   Bingbot  → Bing/Copilot/DuckDuckGo (partial)
		//   BraveBot → Brave Search/Leo/Grounding API
		//   Claude-SearchBot → Claude.ai search index
		//   DuckDuckBot → DDG search (feeds DuckAssistBot)
		//   Googlebot → Google Search/Shopping/AI Overviews/Gemini
		//   Mojeekbot → independent index relicensed to AI startups
		//   OAI-SearchBot → ChatGPT Search index
		//   PerplexityBot → Perplexity answers index
		//   Phindbot → Phind developer AI search
		//   YouBot → You.com (dual-purpose: index + live retrieval)
		//   AdIdxBot → Bing Ads/Copilot Shopping
		//   Amazonbot → Amazon Rufus product awareness index
		//   Pinterestbot → Rich Pins + Pinterest AI Shopping (518M MAUs)
		//   Storebot-Google → Google Shopping AI/AI Outfit
		//
		// Dual-purpose crawlers (index + live answer surface):
		//   ClaudeBot → Anthropic's general crawler; index-builds and
		//               feeds live Claude.ai answer surfaces alongside
		//               Claude-User/Claude-SearchBot
		//   GPTBot → OpenAI's general crawler; index-builds and feeds
		//            live ChatGPT answer surfaces alongside ChatGPT-User/
		//            OAI-SearchBot
		//
		// Live user-session agents (fetch on behalf of an active user):
		//   ChatGPT-User → live fetch during ChatGPT session
		//   Claude-User → live fetch during Claude.ai session
		//   DuckAssistBot → DDG AI answer summaries
		//   Mistralai-User → live fetch during Mistral session
		//                    (dual-purpose: also trains Mistral models)
		//   Perplexity-User → live fetch during Perplexity session
		//
		// All three sub-flavours are default-on: search-index crawlers
		// drive discovery; dual-purpose crawlers feed live answer surfaces;
		// live-session agents drive immediate conversion.
		// The distinction is captured in the JS KNOWN_CRAWLERS metadata,
		// which the admin UI uses to render separate headings within this
		// category. No subgroup data lives in this PHP constant.
		//
		// Note: AmazonBuyForMe (autonomous purchase execution) has been
		// removed. It represents a checkout-in-AI model this plugin does
		// not support — this plugin routes shoppers to the merchant's own
		// checkout, not into an AI-side payment flow.
		//
		// Googlebot/Bingbot are also managed by WordPress core and SEO
		// plugins; this entry ensures AI-specific discoverability is not
		// inadvertently blocked by a merchant-side robots.txt override.
		//
		// Regional crawlers (Asia + Europe) have been moved to
		// REGIONAL_CRAWLERS (default-off). Merchants targeting those
		// markets opt in explicitly.
		'Applebot',
		'Bingbot',
		'BraveBot',
		'ChatGPT-User',
		'Claude-SearchBot',
		'Claude-User',
		'ClaudeBot',
		'DuckAssistBot',
		'DuckDuckBot',
		'Googlebot',
		'GPTBot',
		'Mistralai-User',
		'Mojeekbot',
		'OAI-SearchBot',
		'Perplexity-User',
		'PerplexityBot',
		'Phindbot',
		'YouBot',
		'AdIdxBot',
		'Amazonbot',
		'Pinterestbot',
		'Storebot-Google',
	];

	/**
	 * Regional search + AI crawlers — default off.
	 *
	 * These are real, high-value crawlers for their respective markets,
	 * but merchants selling only in English-speaking markets get no
	 * benefit from them. A merchant selling in Korea, China, Vietnam,
	 * Russia, France, or the Czech Republic should opt in; everyone
	 * else can leave these off without affecting AI discoverability in
	 * their target markets.
	 *
	 * Default-off rationale: unlike LIVE_BROWSING_AGENTS (which are
	 * globally relevant and revenue-routing), regional crawlers are
	 * market-specific. An English-only store opting in gets bot
	 * traffic with zero conversion upside. The merchant signals their
	 * market by toggling these on.
	 *
	 * @var string[]
	 */
	const REGIONAL_CRAWLERS = [
		// Asia — alphabetical.
		// Baiduspider (China) is the primary Baidu crawler — gates
		// Baidu Search and Ernie Bot. ERNIEBot + YiyanBot are Baidu's
		// AI-model and conversational-citation crawlers. NaverBot powers
		// Naver AiRSearch; Yeti feeds HyperCLOVA X. Daumoa serves
		// Daum/Kakao (Korea). PetalBot backs Huawei Petal Search + AI
		// Assistant on hundreds of millions of devices. WRTNBot powers
		// Wrtn ("the Korean ChatGPT"). coccocbot-web covers Coccoc
		// browser + search (Vietnam).
		'Baiduspider',
		'coccocbot-web',
		'Daumoa',
		'ERNIEBot',
		'NaverBot',
		'PetalBot',
		'WRTNBot',
		'Yeti',
		'YiyanBot',

		// Europe — alphabetical.
		// YandexBot powers Yandex's AI Assistant + traditional search
		// (Russian-speaking markets globally). Qwantify is Qwant's
		// crawler (France/EU privacy segment). SeznamBot is the top
		// engine in the Czech market.
		'Qwantify',
		'SeznamBot',
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
		// Alphabetical (case-insensitive). The list is a flat
		// brand-strategy decision — no functional sub-grouping
		// (compare LIVE_BROWSING_AGENTS where revenue-vs-discovery
		// distinctions matter), so ordering is pure scannability.
		// `anthropic-ai` is Anthropic's older crawler identifier,
		// still seen in real logs alongside the newer `ClaudeBot`.
		// `Diffbot` builds the Knowledge Graph that several LLM
		// vendors purchase as training input.
		// `Amazonbot` was previously here but has moved to
		// LIVE_BROWSING_AGENTS (AI search & discovery) — it is the
		// indexing prerequisite for Amazon Rufus, a live AI
		// shopping surface, not a pure training crawler.
		// `ClaudeBot` and `GPTBot` have moved to LIVE_BROWSING_AGENTS
		// (AI search & discovery) — both vendors publish separate live
		// tokens (Claude-User, Claude-SearchBot, ChatGPT-User,
		// OAI-SearchBot) and treat their main crawler as dual-purpose:
		// index-building that also feeds live answer surfaces.
		'anthropic-ai',
		'Applebot-Extended',
		'Bytespider',
		'CCBot',
		'cohere-ai',
		'Diffbot',
		'Google-Extended',
		'Meta-ExternalAgent',
		'Microsoft-BingBot-Extended',
	];

	/**
	 * Test / validation crawlers.
	 *
	 * Developer tools merchants run against their own store to validate
	 * UCP compliance, indexing structure, or AI-readiness — not actual
	 * AI training corpora and not revenue-routing live agents.
	 *
	 * Default-off, like training crawlers: a test crawler hitting the
	 * store inflates the merchant's stats with non-real activity (a
	 * UCPPlayground validation pass would show up as "1 AI order" if
	 * left enabled). Merchants explicitly opt in for the duration of
	 * a validation session, then opt out.
	 *
	 * Visually grouped with `TRAINING_CRAWLERS` in the admin UI under
	 * the "Training and Test Crawlers" heading — both categories share
	 * the "non-revenue AI bot" semantic, and the merchant treats them
	 * the same way (toggle on/off case-by-case).
	 *
	 * @var string[]
	 */
	const TEST_CRAWLERS = [
		// UCP Playground (ucpplayground.com) — third-party validation
		// tool that exercises the UCP catalog/search/lookup/checkout
		// flow against a merchant's store. Useful when merchants want
		// to confirm their UCP endpoint is responding correctly before
		// soliciting traffic from real AI agents. Default-off; merchant
		// flips it on while validating, off when done.
		'UCPPlayground',
	];

	/**
	 * Combined allow-list — live browsing + regional + training + test.
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
	 * Order invariant — must remain
	 *   `LIVE_BROWSING_AGENTS` ++ `REGIONAL_CRAWLERS` ++ `TRAINING_CRAWLERS` ++ `TEST_CRAWLERS`
	 * in declaration order. Adding a new entry: append it to the
	 * appropriate category constant AND add it here at the end of the
	 * matching block. Don't introduce a new category without updating
	 * both this constant and
	 * `RobotsTest::test_ai_crawlers_is_union_of_all_categories`,
	 * which `assertSame()`s the order.
	 *
	 * @var string[]
	 */
	const AI_CRAWLERS = [
		// Live browsing — order mirrors LIVE_BROWSING_AGENTS.
		'Applebot',
		'Bingbot',
		'BraveBot',
		'ChatGPT-User',
		'Claude-SearchBot',
		'Claude-User',
		'ClaudeBot',
		'DuckAssistBot',
		'DuckDuckBot',
		'Googlebot',
		'GPTBot',
		'Mistralai-User',
		'Mojeekbot',
		'OAI-SearchBot',
		'Perplexity-User',
		'PerplexityBot',
		'Phindbot',
		'YouBot',
		'AdIdxBot',
		'Amazonbot',
		'Pinterestbot',
		'Storebot-Google',

		// Regional crawlers — order mirrors REGIONAL_CRAWLERS:
		// Asia then Europe, alphabetical within each.
		'Baiduspider',
		'coccocbot-web',
		'Daumoa',
		'ERNIEBot',
		'NaverBot',
		'PetalBot',
		'WRTNBot',
		'Yeti',
		'YiyanBot',
		'Qwantify',
		'SeznamBot',
		'YandexBot',

		// Training crawlers — alphabetical (case-insensitive).
		'anthropic-ai',
		'Applebot-Extended',
		'Bytespider',
		'CCBot',
		'cohere-ai',
		'Diffbot',
		'Google-Extended',
		'Meta-ExternalAgent',
		'Microsoft-BingBot-Extended',

		// Test / validation crawlers (default-off; merchant opts in
		// for validation sessions). Alphabetical for forward-compat.
		'UCPPlayground',
	];

	/**
	 * Return the first AI_CRAWLERS token found in the current request's
	 * User-Agent string, or '' if none match.
	 *
	 * Used by passive-serve paths (llms.txt, UCP manifest) that don't carry
	 * a structured UCP-Agent header. Pass the returned token directly to
	 * `WC_AI_Storefront_Crawl_Logger::record()`, which maps UA tokens to
	 * merchant-facing brand names internally via its own token→brand table.
	 * Do not pass UA tokens through `canonicalize_host_idempotent()` — that
	 * helper maps hostnames/utm_source values, not UA tokens.
	 *
	 * @return string Matched bot token (e.g. 'GPTBot', 'ChatGPT-User') or ''.
	 */
	public static function detect_crawler_from_ua(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		if ( '' === $ua ) {
			return '';
		}

		// Sort longest token first so a shorter token (e.g. 'Bingbot') can't
		// shadow a longer one that contains it ('Microsoft-BingBot-Extended').
		$bots = self::AI_CRAWLERS;
		usort( $bots, static fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

		foreach ( $bots as $bot ) {
			if ( stripos( $ua, $bot ) !== false ) {
				return $bot;
			}
		}

		return '';
	}

	/**
	 * Sanitize an `allowed_crawlers` input against the canonical crawler list.
	 *
	 * Strips unknown IDs left over from plugin upgrades that rotated the
	 * crawler roster — e.g. the phantom `Gemini` entry that was removed
	 * in an earlier release (it never matched any real crawler; Google's
	 * Gemini-training bot is `Google-Extended`). Keeping the stored list
	 * in sync with `AI_CRAWLERS` prevents stale `User-agent:` blocks
	 * from leaking into `robots.txt` and keeps the admin UI's "X of Y"
	 * count honest.
	 *
	 * Note: `anthropic-ai` was previously treated as deprecated and stripped
	 * on upgrade, but Anthropic continues to send it in real-world traffic
	 * alongside the newer `ClaudeBot` / `Claude-User` / `Claude-SearchBot`
	 * family. It is now restored as a kept entry.
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
	 *   3. Merchant saved a non-empty list: return it with Bingbot and
	 *      Googlebot force-unioned in. These two drive general search
	 *      indexing; emitting `Disallow: /` for them because they
	 *      predate the saved list would silently deindex the store.
	 *      Case 2 (empty list = block all) is unaffected — the union
	 *      only applies to non-empty stored lists.
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
		if ( ! is_array( $stored ) ) {
			return [];
		}

		// Empty list = merchant's explicit "block all" choice. Preserve as-is.
		if ( empty( $stored ) ) {
			return [];
		}

		// Non-empty list: always include Bingbot and Googlebot regardless of
		// when the list was saved. Stored lists that predate these IDs being
		// added to AI_CRAWLERS would otherwise emit `Disallow: /` for them
		// on the next robots.txt render, silently blocking search indexing.
		return array_values(
			array_unique( array_merge( $stored, array( 'Bingbot', 'Googlebot' ) ) )
		);
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

		// Opt-in rule group for all allowed AI crawlers.
		//
		// All allowed bots share the same Allow/Disallow body, so we
		// emit one consolidated rule group (multiple `User-agent:` lines
		// followed by a single Allow/Disallow block) — valid per
		// RFC 9309 §2.2.1 and the same shape used by the opt-out block
		// below.
		//
		// Pre-0.8.8 this section emitted a separate User-agent block per
		// bot, duplicating the ~10-line rule body for every entry. With
		// ~20 default-on bots that produced ~200 lines of repeated
		// content. Consolidation drops that to ~30 lines without changing
		// the semantics — Google, Bing, OpenAI, Anthropic, and Perplexity
		// all document support for grouped User-agent rule blocks.
		//
		// Note: pre-0.1.9 each per-bot block also emitted
		// `Crawl-delay: 2` as a polite advisory rate hint. Removed in
		// 0.1.9 because (1) Google explicitly doesn't support
		// `Crawl-delay` and Search Console's robots.txt tester flags it
		// as an "ignored" directive globally, creating merchant-facing
		// noise; (2) Bing's compliance is inconsistent in practice;
		// (3) the major AI crawlers (OpenAI, Anthropic, Perplexity)
		// don't publish their stance on `Crawl-delay`. Hard rate
		// enforcement remains via the plugin's Store API rate limiter
		// (HTTP 429 + Retry-After at 25 req/min per bot by default),
		// which every well-behaved crawler honors more reliably than
		// the polite advisory ever did.
		//
		// Note: pre-0.1.9 this section also emitted `Allow:` rules for
		// the discovered sitemap paths, justified as "defense against
		// crawlers that only parse directives within their own
		// User-agent group." That defense was misdirected — `Allow:`
		// only matters when a `Disallow:` would otherwise block the
		// path, and none of the per-bot `Disallow:` rules below touch
		// sitemap paths. The rules permitted something that was never
		// blocked. Sitemap discovery happens via the top-level
		// `Sitemap:` directives emitted by WP core / Jetpack / SEO
		// plugins outside this section.
		if ( ! empty( $allowed_bots ) ) {
			foreach ( $allowed_bots as $bot ) {
				$output .= 'User-agent: ' . sanitize_text_field( $bot ) . "\n";
			}

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

		// Note: pre-0.1.13 this section re-emitted `Sitemap:` URLs
		// at the bottom of our section (paired with the top-level
		// emissions from WP core / Jetpack / SEO plugins). The
		// duplication was justified as "defense against parsers
		// that process directives in document order," but in
		// practice it created two failure modes:
		//
		//   1. When the existing `$output` body had no `Sitemap:`
		//      directive (because Jetpack et al. emit theirs via
		//      the `do_robotstxt` action, AFTER our `robots_txt`
		//      filter runs), the fallback to `get_sitemap_url('index')`
		//      fired and emitted a `wp-sitemap.xml` URL that was
		//      a different file than the merchant's actual sitemap
		//      — and on sites where WP-core sitemap is disabled,
		//      the URL pointed at a 404. Observed on a merchant
		//      site where Jetpack emitted `sitemap.xml` +
		//      `news-sitemap.xml` at the top, our fallback emitted
		//      `wp-sitemap.xml` at the bottom, and the WP-core
		//      file didn't exist.
		//
		//   2. RFC 9309 specifies `Sitemap:` as a top-level
		//      directive whose position is not order-sensitive.
		//      No conformant parser cares whether it appears at
		//      top or bottom. The "defense against ordering-sensitive
		//      parsers" is theoretical, not load-bearing.
		//
		// Net: the top-level Sitemap: directives (whoever emits
		// them — WP core, Jetpack, Yoast, etc.) are authoritative
		// and stand alone. Our plugin doesn't need to re-emit.

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
	 * plugins.
	 *
	 * Used by `WC_AI_Storefront_Llms_Txt::discover_sitemap_urls()` to
	 * HEAD-probe candidate sitemap locations on the merchant's origin —
	 * llms.txt is user-facing content, so it only lists sitemaps that
	 * actually respond. The probing covers SEO plugins that emit
	 * `Sitemap:` via the `do_robotstxt` action (direct echo) rather
	 * than the `robots_txt` filter — the latter only sees what's been
	 * passed through the filter callbacks, not what the action callbacks
	 * echo afterward. HEAD-probing the canonical path list is how
	 * llms.txt enumerates sitemaps regardless of which mechanism the
	 * site's SEO plugin uses.
	 *
	 * Two prior consumers of this constant were removed in earlier
	 * releases:
	 *   - Pre-0.1.9 the robots.txt generator emitted per-bot `Allow:`
	 *     rules for every path here. Redundant — sitemap discovery
	 *     happens via `Sitemap:` directives, not `Allow:`.
	 *   - Pre-0.1.13 a private `extract_sitemap_urls()` helper paired
	 *     a regex pass over `$output` with a `get_sitemap_url('index')`
	 *     fallback to feed a bottom-of-section `Sitemap:` re-emission.
	 *     Both helper and re-emission removed; the constant remains
	 *     only for the llms.txt probe path above.
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
}
