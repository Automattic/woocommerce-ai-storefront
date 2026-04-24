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
	 * Transient key for cached llms.txt content.
	 */
	const CACHE_KEY = 'wc_ai_storefront_llms_txt';

	/**
	 * Short-circuit canonical-URL redirects for the llms.txt endpoint.
	 *
	 * @param string|false $redirect_url The candidate canonical URL
	 *                                   WordPress wants to redirect to.
	 * @return string|false               False disables the redirect;
	 *                                   original value otherwise.
	 */
	public function suppress_canonical_redirect( $redirect_url ) {
		if ( get_query_var( 'wc_ai_storefront_llms_txt' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Add rewrite rule for /llms.txt.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?wc_ai_storefront_llms_txt=1', 'top' );
	}

	/**
	 * Register query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'wc_ai_storefront_llms_txt';
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
	 * - `Cache-Control: public, max-age=3600`: 1-hour client/proxy cache
	 *   matches the transient TTL inside `get_cached_content()` — both
	 *   refresh on the same cadence, so the merchant never sees a stale
	 *   file served while the internal cache has been rebuilt.
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
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );

		// Respond to CORS preflights without a body. Some browsing
		// tools fire OPTIONS first and treat a non-2xx preflight as
		// "resource unreachable" even if the GET would have succeeded.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 204 );
			exit;
		}

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
		$cached = get_transient( self::CACHE_KEY );
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
		$lock = get_transient( self::CACHE_KEY . '_regenerating' );
		if ( false !== $lock && '' !== $lock ) {
			// Primary is in-flight. Poll up to 5 seconds for the
			// main cache to appear. Using usleep with short
			// intervals rather than a single long sleep so we
			// release early when the primary succeeds.
			for ( $i = 0; $i < 50; $i++ ) {
				usleep( 100000 ); // 100ms.
				$cached = get_transient( self::CACHE_KEY );
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
		set_transient( self::CACHE_KEY . '_regenerating', 1, 10 );

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
				set_transient( self::CACHE_KEY, $content, HOUR_IN_SECONDS );
			} else {
				WC_AI_Storefront_Logger::debug( 'llms.txt generate() returned empty — not caching' );
			}
		} finally {
			// Release the single-flight sentinel regardless of
			// outcome. Waiting callers can immediately re-check the
			// main cache; if we threw or generated empty they'll
			// either serve the cached content from a prior successful
			// run or regenerate themselves.
			delete_transient( self::CACHE_KEY . '_regenerating' );
		}

		return $content;
	}

	/**
	 * Generate the llms.txt content.
	 *
	 * @return string Markdown content.
	 */
	public function generate() {
		$site_name   = html_entity_decode( wp_strip_all_tags( get_bloginfo( 'name' ) ), ENT_QUOTES, 'UTF-8' );
		$site_url    = home_url( '/' );
		$description = html_entity_decode( wp_strip_all_tags( get_bloginfo( 'description' ) ), ENT_QUOTES, 'UTF-8' );
		$currency    = get_woocommerce_currency();
		$settings    = WC_AI_Storefront::get_settings();

		$lines   = [];
		$lines[] = "# {$site_name}";
		$lines[] = '';

		if ( $description ) {
			$lines[] = "> {$description}";
			$lines[] = '';
		}

		// Store metadata — trimmed to just Currency.
		//
		// Previous revisions of this section listed:
		//   - `**URL**: {site_url}` — removed; the store URL is the
		//     hostname of the file the agent just fetched.
		//   - A free-text "This store accepts AI-assisted product
		//     discovery…" sentence — removed; file existence IS the
		//     signal per the llms.txt spec.
		//   - `**Checkout**: On-site only (web redirect)` — removed;
		//     `## Checkout Policy` below re-states this with far
		//     more detail, including the exact endpoint agents must
		//     POST to.
		//   - `**Commerce Protocol**: {site_url}.well-known/ucp` —
		//     removed; `## API Access` below already lists the UCP
		//     manifest URL.
		//
		// Currency is the only item that doesn't duplicate a later
		// section — spec-aware agents CAN read it from the UCP
		// manifest's `store_context.currency`, but text-first agents
		// benefit from a compact glanceable section.
		$lines[] = '## Store Information';
		$lines[] = '';
		$lines[] = "- **Currency**: {$currency}";
		$lines[] = '';

		// API access. This plugin does NOT expose its own authenticated
		// API — AI agents use WooCommerce's public Store API directly.
		// The UCP manifest describes capabilities + store_context in
		// machine-readable form; agents that want structured data
		// fetch that document.
		//
		// Description text kept tight — agents use the Store API +
		// UCP manifest directly; don't advertise anything the
		// manifest no longer carries.
		$lines[]   = '## API Access';
		$lines[]   = '';
		$store_api = rest_url( 'wc/store/v1' );
		$ucp_url   = $site_url . '.well-known/ucp';
		$lines[]   = "- **Store API**: `{$store_api}` — public WooCommerce Store API for product search and cart operations (no authentication required)";
		$lines[]   = "- **Commerce Protocol Manifest**: `{$ucp_url}` — declares capabilities, checkout policy, and store_context (currency, locale, country, tax/shipping posture)";
		$lines[]   = '';

		// Sitemaps section. Surfaces exhaustive URL enumeration
		// paths for agents doing deep catalog discovery — parallel
		// to robots.txt's per-bot `Allow:` sitemap entries, but
		// in llms.txt's human+machine-readable narrative form. Unlike robots.txt (where `Allow:` for
		// non-existent paths is harmless), here we probe each
		// candidate via HEAD so only URLs that actually respond
		// make it into the document. Probes are synchronous but
		// amortized by the 1-hour transient cache in
		// `get_cached_content()` — one round of probing per
		// cache miss.
		$sitemap_urls = self::discover_sitemap_urls( $site_url );
		if ( ! empty( $sitemap_urls ) ) {
			$lines[] = '## Sitemaps';
			$lines[] = '';
			$lines[] = 'Exhaustive URL lists for catalog enumeration. Agents wanting every product/category URL in one pass fetch these instead of paginating the Store API.';
			$lines[] = '';
			foreach ( $sitemap_urls as $sitemap_url ) {
				$lines[] = "- {$sitemap_url}";
			}
			$lines[] = '';
		}

		// Product categories summary.
		$categories = $this->get_syndicated_categories( $settings );
		if ( ! empty( $categories ) ) {
			$lines[] = '## Product Categories';
			$lines[] = '';
			foreach ( $categories as $category ) {
				$link = get_term_link( $category );
				if ( ! is_wp_error( $link ) ) {
					$cat_name    = html_entity_decode( wp_strip_all_tags( $category->name ), ENT_QUOTES, 'UTF-8' );
					$count_label = 1 === (int) $category->count ? 'product' : 'products';
					$lines[]     = "- [{$cat_name}]({$link}) ({$category->count} {$count_label})";
				}
			}
			$lines[] = '';
		}

		// Featured/popular products section removed: an up-to-10-product
		// marketing teaser in a machine-readable agent document was
		// scope creep — agents wanting products use the Store API
		// (documented in `## API Access` above). Stale prices between
		// llms.txt regenerations and edge cases like "Request a Quote"
		// products (no numeric price → rendered as bare "$") made the
		// section fragile for near-zero agent value. If we ever bring
		// it back, it should be sourced from the Store API with a note
		// about freshness expectations.

		// Checkout policy declaration. Makes explicit the merchant-
		// only-checkout posture that the UCP manifest already
		// declares implicitly (by what it doesn't advertise:
		// no payment_handlers, no ap2_mandate capability, no cart
		// capability, REST-only transport). Redundant signaling
		// is cheap insurance for agent trust frameworks and for
		// human merchant-review audiences that can't parse UCP.
		$lines[] = '## Checkout Policy';
		$lines[] = '';
		$lines[] = 'All purchases complete on this site (' . $site_url . '). Agents MUST redirect buyers to the `continue_url` returned from `POST ' . rtrim( rest_url( 'wc/ucp/v1' ), '/' ) . '/checkout-sessions` to finalize transactions.';
		$lines[] = '';
		$lines[] = 'This store does NOT support:';
		$lines[] = '';
		$lines[] = '- In-chat or in-agent payment completion';
		$lines[] = '- Embedded checkout (UCP Embedded Protocol / ECP)';
		$lines[] = '- Agent-delegated authorization (AP2 Mandates / Verifiable Digital Credentials)';
		$lines[] = '- Persistent agent-managed carts';
		$lines[] = '- Payment handler tokens (Google Pay, Shop Pay, etc. via UCP)';
		$lines[] = '';
		// Programmatic verification: the UCP manifest is the
		// canonical machine-readable source for the checkout
		// posture. Earlier revisions spelled out 4 bullets
		// duplicating `capabilities` / `payment_handlers` /
		// `transport` / checkout-response-status from the manifest —
		// redundant for UCP-aware agents and a drift hazard
		// (manifest could change while this prose lagged). One
		// pointer line does the job without the duplication.
		$lines[] = 'See `' . $site_url . '.well-known/ucp` for the machine-readable manifest that encodes this posture (no `payment_handlers`, REST-only transport, `requires_escalation` on every checkout response).';
		$lines[] = '';

		// Attribution instructions. This section is the
		// AUTHORITATIVE merchant-facing guidance for AI-agent
		// attribution. The UCP manifest carries the same parameter
		// set under the `com.woocommerce.ai_storefront` extension,
		// but UCP itself doesn't define attribution semantics —
		// so the canonical guidance lives here in the
		// human+machine-readable document, not in the wire-format
		// manifest.
		//
		// Attribution is API-first: `POST /checkout-sessions`
		// returns a `continue_url` with UTM values already
		// attached. No URL-template examples are emitted —
		// merchants who scope products via UCP get attribution
		// "for free," and agents that can POST never need to
		// construct UTMs themselves.
		$ucp_rest_base = rtrim( rest_url( 'wc/ucp/v1' ), '/' );
		$lines[]       = '## Attribution';
		$lines[]       = '';
		$lines[]       = 'The recommended purchase flow is to `POST` line items to our UCP checkout endpoint; the server returns a `continue_url` with attribution pre-attached, and the agent redirects the user there. This matches the UCP checkout specification\'s `requires_escalation` / `continue_url` contract.';
		$lines[]       = '';
		$lines[]       = 'Endpoint:';
		$lines[]       = '';
		$lines[]       = '`POST ' . $ucp_rest_base . '/checkout-sessions`';
		$lines[]       = '';
		$lines[]       = 'Request body (UCP Checkout schema):';
		$lines[]       = '';
		$lines[]       = '```json';
		$lines[]       = '{';
		$lines[]       = '  "line_items": [{ "item": { "id": "123" }, "quantity": 1 }]';
		$lines[]       = '}';
		$lines[]       = '```';
		$lines[]       = '';
		$lines[]       = 'Set the `UCP-Agent` request header to your agent\'s discovery profile URL; the server extracts the hostname, canonicalizes it to a short brand name for known vendors (or uses the hostname verbatim otherwise), and attaches it as `utm_source` on the returned `continue_url` — so you do not need to construct UTM parameters yourself.';
		$lines[]       = '';
		$lines[]       = 'Response includes `status: "requires_escalation"` and a `continue_url` with `utm_source` + `utm_medium=ai_agent` already attached. Redirect the user to that URL to complete the purchase on our site.';
		$lines[]       = '';

		// No hostname→brand mapping table is emitted. Runtime
		// canonicalization (`KNOWN_AGENT_HOSTS` → `utm_source`)
		// still drives display-side labels on the merchant's
		// Orders list, but that's merchant-facing context — agents
		// don't need the table. See `UcpAgentHeaderTest` for the
		// runtime contract.

		// UCP merchant-extension docs — referenced from the manifest's
		// `com.woocommerce.ai_storefront` capability as the `spec`
		// URL. Self-hosted (here, not on GitHub) so that the docs
		// always match the running plugin version and respect the
		// site's own access-control policy. The anchor
		// `#ucp-extension` lets the manifest point at this section
		// specifically. Paired with the machine-readable JSON Schema
		// at `/wp-json/wc/ucp/v1/extension/schema`.
		$ucp_schema_url = function_exists( 'rest_url' )
			? rtrim( rest_url( 'wc/ucp/v1/extension/schema' ), '/' )
			: '/wp-json/wc/ucp/v1/extension/schema';

		// UCP Extension section trimmed to just the anchor + schema
		// URL. The prose blurb was removed (duplicated the Attribution
		// section's "server-side handled" statement), the
		// `### config.store_context` field listing was removed
		// (fully duplicated the JSON Schema at the linked URL), and
		// the `### Product-level extension payload` subsection was
		// removed (documented the absence of fields + a pointer to
		// the UCP core product/variant spec agents already read
		// from). What remains: the anchor (so the manifest's `spec`
		// URL resolves) and the machine-readable schema URL (so
		// agents can validate payloads).
		$lines[] = '<a id="ucp-extension"></a>';
		$lines[] = '## UCP Extension: com.woocommerce.ai_storefront';
		$lines[] = '';
		$lines[] = 'Machine-readable JSON Schema: `' . $ucp_schema_url . '`';
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
	 * Get categories available for syndication.
	 *
	 * @param array $settings AI syndication settings.
	 * @return WP_Term[]
	 */
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
	 * the same origin. Amortized by the 1-hour transient cache in
	 * `get_cached_content()` — probes run once per cache miss, not
	 * per request. Worst case (all 4 paths time out): 4 seconds of
	 * generation latency once per hour. Typical case (paths exist
	 * or fast 404): <500ms.
	 *
	 * @param string $site_url Home URL with trailing slash.
	 * @return string[]        Sitemap URLs that returned 2xx/3xx to
	 *                         a HEAD probe. Empty on sites with no
	 *                         sitemap at any common path.
	 */
	private static function discover_sitemap_urls( string $site_url ): array {
		$candidates = [];

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
		$existent = [];
		foreach ( $candidates as $candidate ) {
			$response = wp_remote_head(
				$candidate,
				[
					'timeout'     => 1,
					'redirection' => 1,
					'blocking'    => true,
					// Skip SSL verify on self-origin probes — some
					// local/dev environments have self-signed certs
					// that would otherwise reject the probe.
					'sslverify'   => false,
				]
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 400 ) {
				$existent[] = $candidate;
			}
		}

		return $existent;
	}

	private function get_syndicated_categories( $settings ) {
		$product_mode = $settings['product_selection_mode'] ?? 'all';

		// The `## Product Categories` section in llms.txt is a
		// navigation hint for AI agents — "here's the shape of what
		// this store sells." That framing only holds for two modes:
		//
		//   - `all`: every category in the store IS reachable by
		//     agents, so the top-N-by-count list is accurate.
		//   - `categories`: the merchant's selected categories ARE
		//     the syndicated scope; listing them is informative and
		//     matches what agents can fetch.
		//
		// In `tags` / `brands` / `selected` modes the top-N-by-count
		// list is MISLEADING: agents see category labels + counts
		// that don't reflect what the Store API filter actually
		// returns for the merchant's current scope. A merchant who
		// scopes to a single brand gets an llms.txt that advertises
		// "Clothing (20 products)" when the single-brand subset of
		// Clothing might be 3 products. Agents then issue queries
		// that come back mostly empty, and it looks like the store
		// is broken rather than narrowly-scoped.
		//
		// Suppress the section entirely for those three modes. A
		// future enhancement could surface a per-mode replacement
		// (selected tags / brands / product IDs), but omitting
		// incorrect information is strictly better than the status
		// quo.
		if ( ! in_array( $product_mode, [ 'all', 'categories' ], true ) ) {
			return [];
		}

		$args = [
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 20,
		];

		if ( 'categories' === $product_mode && ! empty( $settings['selected_categories'] ) ) {
			$args['include'] = array_map( 'absint', $settings['selected_categories'] );
			$args['number']  = 0;
		}

		$terms = get_terms( $args );
		return is_wp_error( $terms ) ? [] : $terms;
	}

	// `get_featured_products()` was removed alongside the
	// "Featured Products" llms.txt section. See the deletion comment
	// where the section used to render (around line ~315) for
	// rationale. If the section is ever reintroduced, prefer sourcing
	// from the Store API with explicit freshness disclosure rather
	// than rebuilding this internal `wc_get_products()` path.
}
