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

		$cached = get_transient( self::CACHE_KEY );
		if ( false === $cached ) {
			WC_AI_Storefront_Logger::debug( 'UCP manifest cache miss — regenerating' );
			$cached = wp_json_encode( $this->generate_manifest( $settings ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
			set_transient( self::CACHE_KEY, $cached, HOUR_IN_SECONDS );
		} else {
			WC_AI_Storefront_Logger::debug( 'UCP manifest cache hit' );
		}
		echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON content.
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
		// `continue_url` with `utm_source` + `utm_medium=ai_agent`
		// pre-attached from the `UCP-Agent` header, so agents don't
		// need to read or replicate the UTM schema to be correctly
		// attributed. The human-readable attribution flow (including
		// the hostname→brand mapping table and the fallback URL
		// templates for non-UCP flows) still lives in llms.txt.
		// Duplicating it under a machine-readable key encouraged
		// agents to construct URLs client-side — the exact path the
		// UCP spec's `continue_url` directive steers away from.

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
				'capabilities'     => [
					'dev.ucp.shopping.catalog.search' => [
						[
							'version' => self::PROTOCOL_VERSION,
							'spec'    => $spec_base . '/specification/catalog/search',
							'schema'  => $spec_base . '/schemas/shopping/catalog_search.json',
						],
					],
					'dev.ucp.shopping.catalog.lookup' => [
						[
							'version' => self::PROTOCOL_VERSION,
							'spec'    => $spec_base . '/specification/catalog/lookup',
							'schema'  => $spec_base . '/schemas/shopping/catalog_lookup.json',
						],
					],
					// Pure canonical UCP — no `mode`, no `config`. The
					// pre-1.6.5 `mode: "handoff"` hint is non-canonical
					// (not defined in capability.json) and redundant
					// with the runtime `status: requires_escalation`
					// signal that already carries the handoff intent
					// in the response. The pre-1.6.5 `config` with
					// purchase URL templates was the "less preferred"
					// path per the UCP checkout spec's SHOULD directive
					// on business-provided continue_url. Canonical
					// flow: agent POSTs to /checkout-sessions → server
					// builds continue_url with UTM → returns with
					// status: requires_escalation → agent redirects.
					'dev.ucp.shopping.checkout'       => [
						[
							'version' => self::PROTOCOL_VERSION,
							'spec'    => $spec_base . '/specification/checkout',
							'schema'  => $spec_base . '/schemas/shopping/checkout.json',
						],
					],

					// Merchant-specific extension capability. Carries
					// commerce context (currency, locale, tax/shipping
					// posture) that UCP doesn't define but agents benefit
					// from knowing upfront — currency for price quoting,
					// tax/shipping posture so quoted totals don't
					// mislead. Uses the spec-defined extension pattern
					// (`extends` pointing at a parent service/capability)
					// rather than a root-level custom field — this
					// is the idiomatic UCP home for vendor-specific
					// merchant data. See
					// `source/schemas/capability.json` `base.extends`
					// for the pattern definition.
					//
					// Agents that only iterate standard `dev.ucp.*`
					// capabilities ignore this entirely; agents that
					// want upfront store facts (currency to quote in,
					// whether prices include tax, whether shipping
					// applies) find them without an extra API call.
					'com.woocommerce.ai_storefront'   => [
						[
							'version' => self::PROTOCOL_VERSION,
							'extends' => self::SERVICE_NAME,
							// Self-hosted docs URLs. The spec + schema
							// are served from this site (not GitHub) for
							// three reasons:
							//   1. Permission parity — if the store
							//      restricts REST access, the docs
							//      restrict alongside. No third-party
							//      leak vector.
							//   2. Version truth — the schema served
							//      describes the version of the plugin
							//      the merchant is running. No drift
							//      from "latest".
							//   3. Zero external dependency — a GitHub
							//      outage or repo visibility change
							//      can't break agent integrations.
							// Same self-hosting pattern as the manifest
							// itself (at /.well-known/ucp) and llms.txt.
							'spec'    => function_exists( 'home_url' )
								? home_url( '/llms.txt#ucp-extension' )
								: '/llms.txt#ucp-extension',
							'schema'  => function_exists( 'rest_url' )
								? rest_url( 'wc/ucp/v1/extension/schema' )
								: '/wp-json/wc/ucp/v1/extension/schema',
							'config'  => [
								'store_context' => $this->build_store_context(),
							],
						],
					],
				],

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
}
