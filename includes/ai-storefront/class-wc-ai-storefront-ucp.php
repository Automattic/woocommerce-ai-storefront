<?php
/**
 * AI Syndication: Universal Commerce Protocol (UCP) Manifest
 *
 * Serves a /.well-known/ucp manifest that declares the store's
 * AI commerce capabilities with web-redirect only checkout.
 * No delegated/in-chat payments (no ACP).
 *
 * @package WooCommerce_AI_Storefront
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the /.well-known/ucp manifest endpoint.
 */
class WC_AI_Storefront_Ucp {

	/**
	 * UCP protocol version target.
	 *
	 * Date-formatted per the UCP schema (YYYY-MM-DD). This is the
	 * revision of the protocol our manifest conforms to, NOT our
	 * plugin version. It should change when we upgrade to a newer
	 * UCP protocol revision, which may or may not align with plugin
	 * releases.
	 *
	 * Bumped from 2026-01-11 → 2026-04-08 in plugin 1.3.0 to track the
	 * then-current UCP spec revision referenced below.
	 * `WC_AI_Storefront_UCP_Envelope::catalog_envelope()` and
	 * `checkout_envelope()` both read this constant, so the bump flows
	 * through to every handler response envelope automatically.
	 *
	 * @link https://github.com/Universal-Commerce-Protocol/ucp/blob/main/source/schemas/ucp.json
	 */
	const PROTOCOL_VERSION = '2026-04-08';

	/**
	 * Reverse-domain name for our published service.
	 *
	 * Pre-1.3.0 this was `com.woocommerce.store_api` — we advertised
	 * the raw WC Store API as a generic service. Now that we host
	 * UCP-shaped endpoints at `/wp-json/wc/ucp/v1/`, we declare the
	 * canonical `dev.ucp.shopping` identifier so UCP-aware agents
	 * discover the right entry point directly.
	 *
	 * `dev.ucp.*` is not reserved against third-party use per the UCP
	 * authority-identifier convention — it names UCP-defined services
	 * that anyone can implement. Our implementation lives at the
	 * endpoint this constant points to.
	 */
	const SERVICE_NAME = 'dev.ucp.shopping';

	/**
	 * Canonical shopping capability suffixes this plugin implements.
	 *
	 * Single source of truth for the three capability IDs emitted under
	 * `manifest.capabilities` AND listed in
	 * `com.woocommerce.ai_storefront[0].extends`. Keeping the list in one
	 * place enforces the structural invariant that the extension's
	 * `extends` array stays in lockstep with the canonical capabilities
	 * declared elsewhere in the manifest. A future addition (e.g.
	 * `subscription`) updates this constant in one place and both sides
	 * pick it up automatically.
	 *
	 * Suffixes only — fully-qualified IDs are constructed as
	 * `SERVICE_NAME . '.' . $suffix` at use-site (e.g.
	 * `'dev.ucp.shopping.catalog.search'`).
	 *
	 * @var string[]
	 */
	const CANONICAL_CAPABILITIES = [
		'catalog.search',
		'catalog.lookup',
		'checkout',
	];

	/**
	 * Transient key for cached UCP manifest.
	 */
	const CACHE_KEY = 'wc_ai_storefront_ucp';

	/**
	 * Short-circuit canonical-URL redirects for the manifest endpoint.
	 *
	 * @param string|false $redirect_url WP's candidate canonical URL.
	 * @return string|false               False disables the redirect;
	 *                                   original value otherwise.
	 */
	public function suppress_canonical_redirect( $redirect_url ) {
		if ( get_query_var( 'wc_ai_storefront_ucp' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Add rewrite rule for /.well-known/ucp.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^\.well-known/ucp$', 'index.php?wc_ai_storefront_ucp=1', 'top' );
	}

	/**
	 * Register query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'wc_ai_storefront_ucp';
		return $vars;
	}

	/**
	 * Serve the UCP manifest.
	 */
	public function serve_manifest() {
		if ( ! get_query_var( 'wc_ai_storefront_ucp' ) ) {
			return;
		}

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			status_header( 404 );
			exit;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		// `Vary: Host` is required because the manifest body is derived
		// from `home_url()` / `rest_url()`, which are themselves derived
		// from the HTTP Host header on loose-vhost / multisite installs.
		// Without this header, a shared cache (CDN, reverse proxy) keyed
		// on URL alone would store one body and serve it across Host
		// values — a cache-poisoning vector if an attacker can issue a
		// request with a forged Host header. `Vary: Host` forces the
		// cache to maintain a separate entry per Host value.
		header( 'Vary: Host' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
		// Note: no `Access-Control-Allow-Headers` entry. The UCP
		// manifest is fetched with a bare GET — no custom headers,
		// so CORS preflight with header allowlists isn't triggered.
		// A prior version of this file advertised `X-AI-Agent-Key`
		// here, left over from the authenticated-catalog-API era.

		if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 204 );
			exit;
		}

		// Manifest is computed per-request (no transient). The generation
		// path is cheap — `home_url()`, `rest_url()`, a settings read, and
		// a JSON encode; no external HTTP calls, no unbounded DB queries.
		// Per-request computation also eliminates the Host-keying problem:
		// two requests from different Host values can never share a
		// poisoned cached body through the PHP layer. The HTTP layer
		// cache is segmented by the `Vary: Host` header above.
		//
		// The old `CACHE_KEY` constant is retained for backward
		// compatibility — it is still referenced by the cache invalidator
		// (harmless no-op delete) and any third-party code that reads it.
		// HEX flags hex-escape `<`, `>`, `&`, `'`, `"` — defense-in-depth
		// even though the manifest is served as `application/json`.
		WC_AI_Storefront_Logger::debug( 'UCP manifest — generating per-request' );
		$body = wp_json_encode( $this->generate_manifest( $settings ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON content.
		exit;
	}

	/**
	 * Generate the UCP discovery profile manifest.
	 *
	 * Conforms to the UCP `business_profile` schema at:
	 *
	 *   https://github.com/Universal-Commerce-Protocol/ucp/blob/main/source/discovery/profile_schema.json
	 *
	 * The spec requires `ucp: { version, services, payment_handlers }`.
	 * `capabilities` is optional but must be an object when present.
	 *
	 * Plugin 1.3.0 shifted posture from "discovery-only" to "UCP
	 * adapter":
	 *
	 *   - One `service`: `dev.ucp.shopping` (REST) pointing at our
	 *     own `/wp-json/wc/ucp/v1/` endpoint. Agents hit this base
	 *     URL + capability-specific paths (e.g. POST /catalog/search)
	 *     to invoke UCP operations.
	 *   - Two declared `capabilities`:
	 *       - `dev.ucp.shopping.catalog`  (search + lookup)
	 *       - `dev.ucp.shopping.checkout` (stateless create)
	 *   - Zero `payment_handlers`. Checkout stays on the merchant's
	 *     site — every checkout-sessions response returns
	 *     `status: requires_escalation` with a `continue_url` into
	 *     WooCommerce's native Shareable Checkout flow. Merchants
	 *     keep ownership of payment, tax, fulfillment.
	 *
	 * The merchant extension capability `com.woocommerce.ai_storefront`
	 * sits alongside the canonical `dev.ucp.shopping.*` capabilities and
	 * declares a single `config.store_context` block — merchant-level
	 * context (currency, locale, country, tax/shipping posture) so
	 * agents know what currency they'll be quoting in and whether
	 * the catalog price matches the checkout price, without having
	 * to either fetch llms.txt first or call the Store API. Added in
	 * 1.4.5 in response to cross-agent review feedback that surfaced
	 * this as the dominant manifest-level gap. See
	 * `build_store_context()` for field-by-field rationale.
	 *
	 * Not included (deliberately):
	 *   - Store name/description: redundant with Store API responses
	 *     and llms.txt. The store_context block is for commerce-
	 *     semantic hints only, not human-readable metadata.
	 *   - Rate limits: enforced through HTTP 429 / Retry-After
	 *     response headers, not manifest advertisement.
	 *   - Pointers to llms.txt / sitemap: agents find llms.txt at
	 *     `/llms.txt` (known location) and sitemap via robots.txt.
	 *
	 * @param array $settings AI syndication settings (unused; retained
	 *                        in signature for the `apply_filters` hook
	 *                        contract).
	 * @return array The manifest data.
	 */
	public function generate_manifest( $settings ) {
		$ucp_endpoint = rest_url( 'wc/ucp/v1' );

		// Attribution used to be advertised here as an extension
		// config block (`config.attribution`) so agents could read
		// UTM parameter conventions off the manifest. Removed: the
		// attribution contract is entirely server-side — the
		// `POST /checkout-sessions` endpoint constructs a
		// `continue_url` with the canonical UTM shape pre-attached
		// (`utm_source={hostname}&utm_medium=referral&utm_id=woo_ucp`
		// as of 0.5.0; pre-0.5.0 used `utm_medium=ai_agent`). Agents
		// don't need to read or replicate the UTM schema to be
		// correctly attributed. The human-readable attribution flow
		// (including the hostname→brand mapping table and the
		// fallback URL templates for non-UCP flows) still lives in
		// llms.txt. Duplicating it under a machine-readable key
		// encouraged agents to construct URLs client-side — the exact
		// path the UCP spec's `continue_url` directive steers away
		// from.

		// Base docs URL for the version-pinned ucp.dev spec site.
		// All `spec` and `schema` URLs route through the same
		// canonical host so consumers have a single origin to
		// trust/cache. Pattern: `https://ucp.dev/{version}/...`.
		$spec_base = 'https://ucp.dev/' . self::PROTOCOL_VERSION;

		$manifest = [
			'ucp' => [
				'version'          => self::PROTOCOL_VERSION,

				// Services — single REST binding at our UCP namespace.
				// Under business_schema, a REST binding requires
				// `endpoint`. The service-level `spec` points at the
				// UCP specification overview (updated in 1.6.4 — the
				// previous URL pointed at the GitHub schema directory
				// listing, which isn't a "specification document" per
				// the entity schema's intent).
				//
				// `schema` points at the canonical OpenAPI 3.1 spec
				// for the UCP Shopping REST service. This gives
				// agents a machine-readable contract for every
				// operation exposed at our `endpoint`. The schema's
				// own `{endpoint}` server variable is a placeholder;
				// per the OpenAPI document's own note, consumers
				// must substitute the merchant endpoint from the
				// discovery profile (i.e. `$ucp_endpoint` below).
				'services'         => [
					self::SERVICE_NAME => [
						[
							'version'   => self::PROTOCOL_VERSION,
							'spec'      => $spec_base . '/specification/overview',
							'schema'    => $spec_base . '/services/shopping/rest.openapi.json',
							'transport' => 'rest',
							'endpoint'  => $ucp_endpoint,
						],
					],
				],

				// UCP shopping capabilities we implement. Per the
				// business_profile schema linked above, each capability
				// key maps to an ARRAY of binding objects (one per
				// implementation version) — the array wrapper leaves
				// room to advertise multiple versions concurrently in
				// the future. Consumers key off
				// `dev.ucp.shopping.{capability}` to discover whether
				// our implementation covers their use case.
				//
				// 1.6.0 split the umbrella `dev.ucp.shopping.catalog`
				// into the two sub-capabilities per the April spec.
				// 1.6.4 added `spec` + `schema` URLs to each capability
				// binding and moved the checkout `config` (purchase
				// URLs + attribution conventions) from the service
				// level to the checkout capability — all fields are
				// valid on the entity schema but semantically belong
				// with the capability they describe.
				// Canonical capabilities are constructed from
				// `CANONICAL_CAPABILITIES` (see
				// `build_canonical_capabilities()` for URL transforms).
				// The merchant-specific extension capability is appended
				// after via `array_merge`. This shape keeps the canonical
				// capability list and the extension's `extends` array
				// derived from the same constant — adding a fourth
				// canonical capability updates one place and both sides
				// reflect it.
				//
				// Pure canonical UCP — no `mode`, no `config` on any
				// canonical capability. The pre-1.6.5 `mode: "handoff"`
				// hint on `checkout` was non-canonical (not defined in
				// capability.json) and redundant with the runtime
				// `status: requires_escalation` signal that already
				// carries the handoff intent in the response. The
				// pre-1.6.5 `config` with purchase URL templates was
				// the "less preferred" path per the UCP checkout spec's
				// SHOULD directive on business-provided continue_url.
				'capabilities'     => array_merge(
					self::build_canonical_capabilities( $spec_base ),
					[
						// Merchant-specific extension capability. Carries
						// commerce context (currency, locale, tax/shipping
						// posture) that UCP doesn't define but agents
						// benefit from knowing upfront — currency for
						// price quoting, tax/shipping posture so quoted
						// totals don't mislead.
						//
						// `extends` uses the spec-defined array form to
						// declare multi-parent inheritance over all three
						// canonical shopping capabilities. Per the UCP
						// 2026-04-08 capability schema (see
						// https://ucp.dev/2026-04-08/schemas/capability.json
						// `base.extends`): the regex pattern accepts any
						// matching identifier, but the field's description
						// constrains the meaning to capability IDs —
						// "Parent capability(s) this extends. Use array
						// for multi-parent extensions." Pre-0.1.9 this
						// field pointed at the service ID
						// (`dev.ucp.shopping`), which is a service, not
						// a capability — passing the regex but not the
						// description's intent. The array form below is
						// more honest about what the extension actually
						// augments: store_context applies to search,
						// lookup, AND checkout, so all three are listed
						// as parents.
						//
						// Constructed from `CANONICAL_CAPABILITIES` so
						// the extension's `extends` list and the
						// canonical capability declarations above stay
						// structurally in lockstep — adding a fourth
						// capability updates one constant and both sides
						// reflect it.
						//
						// Agents that only iterate standard `dev.ucp.*`
						// capabilities ignore this entirely; agents that
						// want upfront store facts (currency to quote
						// in, whether prices include tax, whether
						// shipping applies) find them without an extra
						// API call.
						'com.woocommerce.ai_storefront' => [
							[
								'version' => self::PROTOCOL_VERSION,
								'extends' => array_map(
									static fn( string $suffix ): string => self::SERVICE_NAME . '.' . $suffix,
									self::CANONICAL_CAPABILITIES
								),
								// Self-hosted docs URLs. The spec +
								// schema are served from this site
								// (not GitHub) for three reasons:
								//   1. Permission parity — if the
								//      store restricts REST access,
								//      the docs restrict alongside.
								//      No third-party leak vector.
								//   2. Version truth — the schema
								//      served describes the version
								//      of the plugin the merchant is
								//      running. No drift from "latest".
								//   3. Zero external dependency — a
								//      GitHub outage or repo
								//      visibility change can't break
								//      agent integrations.
								// Same self-hosting pattern as the
								// manifest itself (at /.well-known/ucp)
								// and llms.txt.
								'spec'    => function_exists( 'home_url' )
									? home_url( '/llms.txt#ucp-extension' )
									: '/llms.txt#ucp-extension',
								'schema'  => function_exists( 'rest_url' )
									? rest_url( 'wc/ucp/v1/extension/schema' )
									: '/wp-json/wc/ucp/v1/extension/schema',
								'config'  => [
									'store_context' => $this->build_store_context(),
									'agent_guide'   => $this->build_agent_guide(),
								],
							],
						],
					]
				),

				// Required by business_schema. Empty object is the
				// valid "zero handlers" declaration — merchant's WC
				// checkout handles payment via their configured gateway.
				'payment_handlers' => (object) [],
			],
		];

		/**
		 * Filter the UCP manifest data.
		 *
		 * @since 1.0.0
		 * @param array $manifest The UCP manifest.
		 * @param array $settings The AI syndication settings.
		 */
		return apply_filters( 'wc_ai_storefront_ucp_manifest', $manifest, $settings );
	}

	/**
	 * Build the canonical UCP shopping capability declarations.
	 *
	 * Iterates `CANONICAL_CAPABILITIES` and constructs both the
	 * fully-qualified capability key (`dev.ucp.shopping.{suffix}`) and
	 * the per-binding `spec` + `schema` URLs for each. Returns the
	 * `manifest.capabilities` slice for the canonical caps; the caller
	 * `array_merge`s it with the merchant-extension capability.
	 *
	 * URL transformations from the suffix:
	 *
	 *   - `spec` path: `.` → `/` so `catalog.search` becomes
	 *     `/specification/catalog/search` and `checkout` stays
	 *     `/specification/checkout`. Matches how the UCP spec's
	 *     directory structure mirrors capability nesting.
	 *
	 *   - `schema` filename: `.` → `_` so `catalog.search` becomes
	 *     `catalog_search.json` and `checkout` stays `checkout.json`.
	 *     Matches the JSON-Schema filenames in the UCP spec repo.
	 *
	 * Adding a new canonical capability is a one-line change to the
	 * `CANONICAL_CAPABILITIES` constant — both the capability
	 * declaration here AND the extension's `extends` array (which
	 * reads the same constant via `array_map` in `generate_manifest()`)
	 * pick up the new entry automatically.
	 *
	 * @param string $spec_base Base URL for spec/schema docs (e.g.
	 *                          `https://ucp.dev/2026-04-08`).
	 * @return array<string, array<int, array{version: string, spec: string, schema: string}>>
	 */
	private static function build_canonical_capabilities( string $spec_base ): array {
		$capabilities = [];
		foreach ( self::CANONICAL_CAPABILITIES as $suffix ) {
			$key             = self::SERVICE_NAME . '.' . $suffix;
			$spec_path       = str_replace( '.', '/', $suffix );
			$schema_filename = str_replace( '.', '_', $suffix );

			$capabilities[ $key ] = [
				[
					'version' => self::PROTOCOL_VERSION,
					'spec'    => $spec_base . '/specification/' . $spec_path,
					'schema'  => $spec_base . '/schemas/shopping/' . $schema_filename . '.json',
				],
			];
		}
		return $capabilities;
	}

	/**
	 * Build the merchant-level commerce context block.
	 *
	 * Declares the store's currency, locale, base country, and
	 * commerce-posture facts (tax inclusion, shipping enablement)
	 * that agents need to know BEFORE they start consuming catalog
	 * data — not after. Pre-1.4.5 this information lived only in
	 * llms.txt (currency) and Store API responses (tax posture
	 * on per-price endpoints), leaving agents who only fetched the
	 * UCP manifest flying blind on basic commerce semantics.
	 *
	 * Field notes:
	 *
	 *   - `currency`: ISO 4217. Source of truth is WooCommerce's
	 *     stored currency setting; drives every price string on
	 *     the store.
	 *
	 *   - `locale`: BCP 47 form (`en-US`). WordPress stores locale
	 *     in ICU underscore form (`en_US`); we convert for standards
	 *     compatibility — HTTP `Accept-Language`, browser
	 *     `navigator.language`, and most web APIs expect hyphens.
	 *
	 *   - `country`: ISO 3166-1 alpha-2. The merchant's base
	 *     country, which controls default tax and shipping behavior.
	 *     Not the customer's country — an AI agent talking to a
	 *     customer in France buying from a US-based store should
	 *     see `country: US` here.
	 *
	 *   - `prices_include_tax`: boolean. Tells agents whether the
	 *     price string they see on catalog endpoints already
	 *     includes tax, or whether tax will be added at checkout.
	 *     This is THE critical signal for agents quoting totals
	 *     honestly to users. VAT-inclusive storefronts (common in
	 *     EU) return `true`; US-style tax-exclusive storefronts
	 *     return `false`.
	 *
	 *   - `shipping_enabled`: boolean. Whether the store collects
	 *     shipping addresses at checkout. `false` → digital-only
	 *     or in-person-only store; agents can skip shipping
	 *     prompts. Detected via `wc_shipping_enabled()`, which
	 *     reflects the top-level WC shipping toggle.
	 *
	 * @return array The store context block.
	 */
	private function build_store_context() {
		// `countries` is a PROPERTY on the WooCommerce singleton (an
		// instance of WC_Countries), not a method — a `method_exists`
		// check would always return false. Guard via `isset()` on the
		// property to pick up the country when WC is fully loaded
		// and fall through gracefully otherwise (tests, early boot,
		// WC-deactivated state). Same pattern as build_seller() in
		// the REST controller — kept in sync deliberately.
		$country     = null;
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		if ( $woocommerce && isset( $woocommerce->countries ) && is_object( $woocommerce->countries ) ) {
			$country = $woocommerce->countries->get_base_country();
		}

		// `get_locale()` returns ICU format (e.g. `en_US`). BCP 47
		// uses hyphens. Swap the single underscore delimiter; if
		// future WP releases add more (e.g. script subtags), the
		// conversion still holds because BCP 47 also uses hyphens
		// for those.
		$locale = str_replace( '_', '-', get_locale() );

		return [
			'currency'           => get_woocommerce_currency(),
			'locale'             => $locale,
			'country'            => $country ? $country : null,
			'prices_include_tax' => (bool) wc_prices_include_tax(),
			'shipping_enabled'   => (bool) wc_shipping_enabled(),
		];
	}

	/**
	 * Build the operational `agent_guide` string for the manifest.
	 *
	 * Some UCP clients (e.g. UCPPlayground as of plugin 0.4.0) inject
	 * the manifest's `agent_guide` field directly into the LLM's
	 * system prompt at session start, bypassing llms.txt entirely.
	 * This gives the agent immediate operational context — what
	 * checkout posture the store enforces, which endpoints are
	 * stateless, how to identify itself for attribution — without
	 * needing a separate fetch.
	 *
	 * Why concise: every word here costs the consuming agent tokens
	 * on every conversation that includes this manifest in context.
	 * Cover only what the agent must know to behave correctly:
	 *
	 *   1. Checkout posture (`requires_escalation`) — the foundational
	 *      "you cannot place orders directly here" signal that prevents
	 *      destructive retries and incorrect API patterns.
	 *   2. The `continue_url` flow — how the agent should hand the
	 *      user off to merchant-side checkout.
	 *   3. The stateless `/checkout-sessions/{id}` posture — prevents
	 *      agents trained on REST-style "look up / modify / cancel"
	 *      patterns from issuing GET/PUT/PATCH/DELETE requests we
	 *      explicitly reject.
	 *   4. Self-identification via `UCP-Agent` header — both formats
	 *      we accept, so attribution converges on the agent's brand
	 *      rather than bucketing as "Other AI".
	 *
	 * Defer comprehensive behavior docs to llms.txt and the canonical
	 * UCP spec at ucp.dev. This field is operational guidance, not
	 * documentation.
	 *
	 * Single translatable string rather than concatenated sentences
	 * because translators must be able to restructure the text for
	 * other languages (Romance languages may want clause reordering;
	 * agglutinative languages may need different connective grammar).
	 * Concatenating four `__()` calls would lock the English clause
	 * order into all locales.
	 *
	 * @return string The agent guide string.
	 */
	private function build_agent_guide() {
		return __(
			/* translators: This string is injected verbatim into LLM system prompts via the UCP manifest. Translate the natural-language prose, but DO NOT translate the technical identifiers (`requires_escalation`, `continue_url`, `/checkout-sessions`, `unsupported_operation`, `UCP-Agent`, `Product/Version`, `Other AI`) or HTTP methods (POST, GET, PUT, PATCH, DELETE) — agents must see these tokens exactly. */
			'This store uses requires_escalation checkout: agents do not place orders directly. POST /checkout-sessions returns a continue_url with attribution UTMs already attached; redirect the user to that URL to complete the purchase on the merchant site. The /checkout-sessions/{id} URL is stateless — GET, PUT, PATCH, and DELETE all return HTTP 405 with code "unsupported_operation" because there is no persistent session to act on. Send your agent identity via the UCP-Agent header (profile URL form preferred, Product/Version form also accepted) so attribution canonicalizes to your brand rather than bucketing as "Other AI".',
			'woocommerce-ai-storefront'
		);
	}
}
