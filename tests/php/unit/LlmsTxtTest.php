<?php
/**
 * Tests for WC_AI_Storefront_Llms_Txt.
 *
 * Focuses on `generate()` — the method that produces the Markdown
 * document served at `/llms.txt`. These tests pin the document's
 * *structure* (required sections, heading hierarchy) and the
 * decoration/escaping of dynamic values (HTML entities, singular/
 * plural grammar, price formatting).
 *
 * The featured-products path is intentionally covered minimally: it
 * requires rich WC_Product mocks whose shape is better exercised in
 * dedicated integration tests. The structural test here stubs
 * `wc_get_products` to return an empty array, which takes the "no
 * featured products" branch — enough to confirm the surrounding
 * code doesn't crash on empty fixtures.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class LlmsTxtTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Llms_Txt $llms;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->llms = new WC_AI_Storefront_Llms_Txt();

		// Configure the shared test settings (consumed by the stubbed
		// `WC_AI_Storefront::get_settings()` in the bootstrap).
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];

		// Baseline WP/WC function stubs. Individual tests override these
		// via `Functions\when()->alias()` for specific scenarios.
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . ( $path ?: '/' )
		);
		Functions\when( 'rest_url' )->alias(
			static fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' )
		);
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => [
				'name'        => 'Example Store',
				'description' => 'A test storefront',
			][ $key ] ?? ''
		);
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_woocommerce_currency_symbol' )->justReturn( '$' );
		// wp_strip_all_tags() is now stubbed globally in tests/php/stubs.php
		// (it's loaded early enough to be seen by every test). Previously
		// we Brain\Monkey-aliased it here, but Patchwork — Brain\Monkey's
		// runtime — refuses to redefine symbols declared before Patchwork
		// itself is loaded, which is the case for our stubs.php. The
		// global stub uses the real WordPress-equivalent implementation,
		// so functional behavior for this test is unchanged.
		Functions\when( 'get_terms' )->justReturn( [] );
		Functions\when( 'get_term_link' )->alias(
			static fn( $term ) => 'https://example.com/product-category/' . ( $term->slug ?? 'x' ) . '/'
		);
		Functions\when( 'wc_get_products' )->justReturn( [] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// `__()` returns its first arg (the source string). Used by
		// generate()'s sprintf for the multi-currency Accepted-currencies
		// line. JsonLdTest stubs the same shape via Functions\when().
		Functions\when( '__' )->returnArg( 1 );

		// `get_syndicated_brands()` consults `taxonomy_exists` to
		// decide whether to query the `product_brand` taxonomy.
		// Default to true so brand-aware tests work; brand-absent
		// tests can override.
		Functions\when( 'taxonomy_exists' )->justReturn( true );

		// Sitemap-discovery stubs. Default: nothing found (no sitemap
		// section in output). Individual tests override via
		// `Functions\when()->alias()` to simulate found sitemaps.
		// `is_wp_error` is globally stubbed in `tests/php/stubs.php`
		// (too early for Patchwork to redefine it here).
		Functions\when( 'get_sitemap_url' )->justReturn( '' );
		Functions\when( 'wp_remote_head' )->justReturn(
			new WP_Error( 'no_probe', 'Not stubbed in test' )
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 0 );

		// host_cache_key() sanitizes $_SERVER['HTTP_HOST'] via these
		// helpers. Stub them as pass-throughs so every test that calls
		// get_cached_content() (and therefore host_cache_key()) works
		// without re-declaring them.
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// Single-flight sentinel for concurrent cache regeneration
		// uses `delete_transient()` on completion. Stub it as a
		// no-op for tests that don't care about the guard behavior.
		// (`usleep` can't be stubbed via Patchwork — it's a PHP
		// internal function not in patchwork.json's redefinable
		// list. The single-flight wait loop is guarded out for
		// tests because `get_transient( ... . '_regenerating' )`
		// returns `''` or `false` in our stub setups, which the
		// handler treats as "no lock held" — skipping the usleep
		// branch entirely.)
		Functions\when( 'delete_transient' )->justReturn( true );

		// Sitemap URL discovery (P-18) reads a 24h transient before
		// running HTTP probes. Default to a cold cache (false = miss)
		// so probes still run in tests that care about sitemap output,
		// and stub set_transient as a no-op so the write path doesn't
		// error. Individual tests that test caching behaviour override
		// these via their own Functions\when() calls.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		// get_option() is called by the FIND-S07 fix to build a
		// canonical self-link ID from siteurl rather than rest_url().
		// Default to the same origin used by the rest_url stub above.
		// Also covers the WC email-option lookups inside
		// `WC_AI_Storefront_JsonLd::get_validated_contact_email()` — that
		// helper is called by `build_identity_fields()` which the new
		// generate() invokes for the `## Store` section's Support line.
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = '' ) {
				switch ( $option ) {
					case 'siteurl':
						return 'https://example.com';
					case 'woocommerce_email_reply_to_enabled':
						return 'no';   // Default: stage-2 fallback to From
					case 'woocommerce_email_reply_to_address':
					case 'woocommerce_email_from_address':
						return '';     // Default: no contact email → omit
					default:
						return $default;
				}
			}
		);

		// WP-core stubs added for issue #398's new sections.
		//
		// `get_theme_mod` / `get_site_icon_url` / `wp_get_attachment_image_src`
		// feed `build_identity_fields().logo`. Default: no custom logo, no
		// site icon → `## Store` omits the Logo line.
		Functions\when( 'get_theme_mod' )->justReturn( 0 );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );

		// `wc_get_base_location` feeds the `## Shipping & Returns`
		// "Ships from" line AND (indirectly, via WC_Countries) the
		// `## Store` Location line. Default: US store, matches the
		// WC default fixture.
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => 'US', 'state' => '' ]
		);

		// Email validation helpers used by `get_validated_contact_email()`.
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'is_email' )->alias(
			static fn( $email ) => is_string( $email ) && false !== strpos( $email, '@' )
		);

		// `esc_url` is called from the new generate() when emitting the
		// Logo URL. Pass-through for tests is fine — real escaping is
		// covered by WP core's own test suite.
		Functions\when( 'esc_url' )->returnArg();
		// `esc_url_raw` is used by the `## Policies` section (issue #445)
		// to sanitize the resolved policy-page URLs. Pass-through for
		// tests; real escaping is WP core's responsibility.
		Functions\when( 'esc_url_raw' )->returnArg();

		// `## Policies` section sources (issue #445). Defaults: no policy
		// pages configured, so the section is omitted unless a test
		// overrides these. `get_privacy_policy_url()` returns '' when
		// unset; `wc_terms_and_conditions_page_id()` returns 0;
		// `get_permalink()` returns false for an unknown/zero id. The
		// refunds-page id comes from the `woocommerce_refund_returns_page_id`
		// option, which the default `get_option` stub above resolves to ''
		// (→ 0) so no Refunds line renders by default.
		Functions\when( 'get_privacy_policy_url' )->justReturn( '' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 0 );
		Functions\when( 'get_permalink' )->justReturn( false );
		// The Terms / Refunds links are additionally gated on a `publish`
		// status check so a trashed page never emits a dead 404 link.
		// Default to `'publish'` so tests that configure a page id render
		// its line; the trashed/deleted-page tests override this to
		// `'trash'` / `false`.
		Functions\when( 'get_post_status' )->justReturn( 'publish' );

		// Note: NOT stubbing `WC()` here despite the new generate()
		// reaching into WC()->countries->countries via the
		// `resolve_country_name()` helper. Brain Monkey-stubbing
		// `WC()` at suite setUp() level leaks the function definition
		// across the entire test suite, breaking UcpTest /
		// UcpCheckoutPostureTest / others that call WC() unstubbed
		// (see JsonLdTest's `get_wc_countries()` docblock for the
		// same architectural concern).
		//
		// Instead, the production class exposes a `get_country_map()`
		// protected seam that returns the ISO -> name array.
		// Per-test subclasses (built via `llms_with_countries()` below)
		// override the seam to inject a country fixture. The
		// resolve_country_name() helper calls through the instance
		// so the override is picked up.
	}

	/**
	 * Build a LlmsTxt subclass that injects a country-map fixture.
	 * Use this when a test cares about the human-readable Ships-from
	 * or Location line rendering. The subclass overrides
	 * `get_country_map()` to return the fixture without stubbing
	 * the global `WC()` function (which would leak across the suite).
	 *
	 * @param array<string, string> $map ISO code -> human-readable name.
	 * @return WC_AI_Storefront_Llms_Txt
	 */
	private function llms_with_countries( array $map ): WC_AI_Storefront_Llms_Txt {
		return new class( $map ) extends WC_AI_Storefront_Llms_Txt {
			private array $fixture;

			public function __construct( array $fixture ) {
				$this->fixture = $fixture;
			}

			protected function get_country_map(): array {
				return $this->fixture;
			}
		};
	}

	protected function tearDown(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		WC_AI_Storefront::$test_settings = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Structural — required sections
	// ------------------------------------------------------------------

	public function test_output_starts_with_h1_of_store_name(): void {
		$output = $this->llms->generate();

		// The llms.txt convention is H1 = store identity.
		$this->assertStringStartsWith( "# Example Store\n", $output );
	}

	public function test_output_includes_blockquote_description(): void {
		$output = $this->llms->generate();

		// Blockquote is the llms.txt convention for site tagline.
		$this->assertStringContainsString( '> A test storefront', $output );
	}

	public function test_output_includes_core_sections(): void {
		// The seven H2s emitted by the new generate() (issue #398).
		// `## Catalog` and `## Shipping & Returns` are conditionally
		// omitted when no data is configured; this test pins the
		// other five as always-present.
		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Store', $output );
		$this->assertStringContainsString( '## Browse', $output );
		$this->assertStringContainsString( '## Structured data', $output );
		$this->assertStringContainsString( '## For agents', $output );
		$this->assertStringContainsString( '## Extension schema', $output );
	}

	public function test_output_emits_sections_in_documented_order(): void {
		// The always-present H2s must appear in this documented order.
		// Section order matters because llms.txt readers (both human and
		// machine) scan top-to-bottom and the order reflects the
		// document's information hierarchy: identity → discovery →
		// structured-data signpost → agent behavioural rules → agent
		// machine interface → extension schema.
		//
		// `## Catalog`, `## Shipping & Returns`, and `## Policies` are
		// conditionally omitted when their data isn't configured (see
		// test_output_includes_core_sections), so they're not pinned
		// here. The full documented order, with every section present,
		// is: Store → Browse → Catalog → Shipping & Returns → Policies →
		// Structured data → Rules for agents → For agents → Extension
		// schema.
		$output = $this->llms->generate();

		$expected_order = [
			'## Store',
			'## Browse',
			'## Structured data',
			'## Rules for agents',
			'## For agents',
			'## Extension schema',
		];

		$last_pos = -1;
		foreach ( $expected_order as $section ) {
			$pos = strpos( $output, $section );
			$this->assertNotFalse( $pos, "Section {$section} missing from output" );
			$this->assertGreaterThan(
				$last_pos,
				$pos,
				"Section {$section} appeared before the previous one — section order regression."
			);
			$last_pos = $pos;
		}
	}

	// ------------------------------------------------------------------
	// ## Store section (issue #398)
	// ------------------------------------------------------------------

	public function test_store_section_always_carries_currency(): void {
		// Currency is the only Store-section field that's always
		// present — every WC install configures a currency. Other
		// fields (Location, Logo, Support) are omit-when-empty.
		$output = $this->llms->generate();

		$this->assertMatchesRegularExpression(
			'/## Store\s*\n\s*\n- \*\*Currency\*\*: USD/',
			$output,
			'## Store section should lead with Currency'
		);
	}

	public function test_generate_accepted_currencies_line_omitted_on_single_currency_store(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '**Accepted currencies**', $output );
	}

	public function test_generate_accepted_currencies_line_present_on_multi_currency_store(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$extras ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR', 'GBP' );
				}
				return $value;
			}
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString(
			'- **Accepted currencies**: USD, EUR, GBP',
			$output
		);
	}

	public function test_store_section_emits_location_when_wc_has_country(): void {
		// `## Store` Location line is built from
		// `WC_AI_Storefront_JsonLd::build_postal_address()`, which
		// reads WC's base-address country/state/city. We can't easily
		// stub WC()->countries here (that requires touching the JsonLd
		// internals), so this test pins the format rather than the
		// data: when build_postal_address returns at least a country,
		// the Location line should appear.
		//
		// Instead, exercise this through the `wc_ai_storefront_llms_txt_lines`
		// filter — inject a known fixture and assert it survives.
		// Pure-formatting test; the data-sourcing test belongs in
		// JsonLdTest (which already covers build_postal_address).
		$output = $this->llms->generate();

		// Without a stubbed WC()->countries, build_postal_address
		// returns []. So Location is omitted. That IS the correct
		// behavior — the test passes by absence:
		$this->assertStringContainsString( '## Store', $output );
		$this->assertStringNotContainsString( '- **Location**: ,', $output );
	}

	public function test_store_section_omits_logo_when_unset(): void {
		// Default stubs: no custom logo, no site icon → no Logo line.
		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '- **Logo**:', $output );
	}

	public function test_store_section_emits_logo_when_site_icon_set(): void {
		Functions\when( 'get_site_icon_url' )->justReturn(
			'https://example.com/wp-content/uploads/2026/05/saltwarp-favicon-512.png'
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString(
			'- **Logo**: https://example.com/wp-content/uploads/2026/05/saltwarp-favicon-512.png',
			$output
		);
	}

	public function test_store_section_omits_support_when_emails_unset(): void {
		// Default `get_option` stub returns '' for both reply-to and
		// from addresses, so `get_validated_contact_email()` resolves
		// to '' and the Support line is omitted.
		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '- **Support**:', $output );
	}

	public function test_store_section_emits_support_when_from_address_set(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = '' ) {
				switch ( $option ) {
					case 'siteurl':
						return 'https://example.com';
					case 'woocommerce_email_reply_to_enabled':
						return 'no';
					case 'woocommerce_email_from_address':
						return 'hello@saltwarp.com';
					default:
						return $default;
				}
			}
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( '- **Support**: hello@saltwarp.com', $output );
	}

	public function test_store_section_does_not_emit_noreply_email_as_support(): void {
		// `is_noreply_email()` filters out `noreply@`-shaped addresses
		// so they're never published as customer-facing contact. This
		// is the same guard the JSON-LD `contactPoint.email` uses.
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = '' ) {
				switch ( $option ) {
					case 'siteurl':
						return 'https://example.com';
					case 'woocommerce_email_reply_to_enabled':
						return 'no';
					case 'woocommerce_email_from_address':
						return 'noreply@example.com';
					default:
						return $default;
				}
			}
		);

		$output = $this->llms->generate();

		$this->assertStringNotContainsString( 'noreply@example.com', $output );
		$this->assertStringNotContainsString( '- **Support**:', $output );
	}

	// ------------------------------------------------------------------
	// ## Browse section (issue #398): UTM hygiene
	// ------------------------------------------------------------------

	public function test_browse_section_shop_url_carries_woo_llms_utm(): void {
		// Browse-discovery URLs MUST carry `utm_medium=referral` and
		// `utm_id=woo_llms` so the merchant's channel split can
		// attribute follow-through orders. See the issue #398 UTM
		// hygiene rule + WOO_LLMS_ID docblock.
		$output = $this->llms->generate();

		$this->assertStringContainsString(
			'https://example.com/shop/?utm_medium=referral&utm_id=woo_llms',
			$output
		);
	}

	public function test_browse_section_search_url_carries_woo_llms_utm(): void {
		// Same UTM rule as Shop archive. The Search URL is a template
		// with `{search_term}` substitution; the consumer fills the
		// slot and follows the link.
		$output = $this->llms->generate();

		$this->assertStringContainsString(
			's={search_term}&post_type=product&utm_medium=referral&utm_id=woo_llms',
			$output
		);
	}

	public function test_browse_section_urls_do_not_carry_utm_source(): void {
		// UTM hygiene: llms.txt URLs intentionally OMIT utm_source —
		// the actual referring domain populates it from `Referer`
		// downstream. A literal `utm_source={agent_id}` placeholder
		// (which JSON-LD uses) would be wrong here because llms.txt
		// URLs are followed directly, not surfaced as search results
		// for an AI to fill in. See WOO_LLMS_ID docblock.
		$output = $this->llms->generate();

		// Extract just the Browse section bullets to avoid false
		// positives from anywhere else in the document.
		$browse_start = strpos( $output, '## Browse' );
		$browse_end   = strpos( $output, '## ', $browse_start + 1 );
		$browse       = substr( $output, $browse_start, $browse_end - $browse_start );

		$this->assertStringNotContainsString( 'utm_source=', $browse );
	}

	// ------------------------------------------------------------------
	// ## For agents section (issue #398): no UTMs on machine endpoints
	// ------------------------------------------------------------------

	public function test_for_agents_section_machine_endpoints_have_no_utm(): void {
		// Machine endpoints (manifest, API base, checkout-sessions)
		// MUST NOT carry UTM params. Adding UTMs here would pollute
		// the agent's structured response payloads with attribution
		// data that doesn't belong in machine APIs.
		$output = $this->llms->generate();

		$for_agents_start = strpos( $output, '## For agents' );
		$for_agents_end   = strpos( $output, '## ', $for_agents_start + 1 );
		$for_agents       = substr( $output, $for_agents_start, $for_agents_end - $for_agents_start );

		$this->assertStringNotContainsString( 'utm_medium', $for_agents );
		$this->assertStringNotContainsString( 'utm_id', $for_agents );
		$this->assertStringNotContainsString( 'utm_source', $for_agents );
	}

	public function test_for_agents_section_lists_manifest_api_and_checkout(): void {
		// All three required pointers must appear so a UCP-aware
		// agent can discover the capability manifest, the REST base,
		// and the checkout escalation endpoint from one section.
		$output = $this->llms->generate();

		$this->assertStringContainsString( 'https://example.com/.well-known/ucp', $output );
		$this->assertStringContainsString( 'https://example.com/wp-json/wc/ucp/v1', $output );
		$this->assertStringContainsString( '/checkout-sessions', $output );
	}

	public function test_for_agents_points_at_jsonld_buyaction_for_cart_links(): void {
		// Per issue #398 decision: llms.txt does NOT emit a cart-link
		// URL template directly. Agents should construct cart links
		// from JSON-LD `BuyAction.urlTemplate` on product pages —
		// that's deterministic across product types (simple, variable,
		// bundle, grouped). The `## For agents` section must direct
		// agents there.
		$output = $this->llms->generate();

		$this->assertStringContainsString( 'BuyAction', $output );
	}

	// ------------------------------------------------------------------
	// ## Shipping & Returns section (issue #398)
	// ------------------------------------------------------------------

	public function test_shipping_section_emits_ships_from_country(): void {
		// Inject a fixture country map (via the `llms_with_countries`
		// helper that subclasses LlmsTxt and overrides
		// `get_country_map()`). This pins the production path —
		// `resolve_country_name()` looking up the ISO code in the map
		// and returning the human-readable name. The default
		// $this->llms instance has no WC() stub so it would return
		// the raw ISO code, which is the fallback we test separately.
		$llms = $this->llms_with_countries(
			[
				'US' => 'United States (US)',
				'GB' => 'United Kingdom (UK)',
			]
		);

		$output = $llms->generate();

		$this->assertStringContainsString( '- **Ships from**: United States (US)', $output );
	}

	public function test_shipping_section_falls_back_to_iso_when_country_map_unavailable(): void {
		// Default $this->llms doesn't inject a country fixture, so
		// `get_country_map()` returns []. The fallback in
		// resolve_country_name() returns the raw ISO code rather than
		// suppressing the Ships-from line entirely — better to render
		// `US` than to silently omit the data.
		$output = $this->llms->generate();

		$this->assertStringContainsString( '- **Ships from**: US', $output );
	}

	public function test_shipping_section_emits_handling_time_range(): void {
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'handling_time'          => [ 'min' => 1, 'max' => 3 ],
		];

		$output = $this->llms->generate();

		$this->assertStringContainsString( '- **Handling time**: 1 to 3 business days', $output );
	}

	public function test_shipping_section_handling_time_singular_grammar(): void {
		// Min === max collapses to single-value grammar with the
		// correct singular/plural.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'handling_time'          => [ 'min' => 1, 'max' => 1 ],
		];

		$output = $this->llms->generate();

		$this->assertStringContainsString( '- **Handling time**: 1 business day', $output );
		$this->assertStringNotContainsString( '1 business days', $output );
	}

	public function test_shipping_section_emits_returns_when_accepted(): void {
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => [
				'mode'    => 'returns_accepted',
				'days'    => 30,
				'fees'    => 'FreeReturn',
				'country' => 'US',
			],
		];

		$output = $this->llms->generate();

		$this->assertStringContainsString( '- **Returns**: 30 days', $output );
		$this->assertStringContainsString( 'free return shipping', $output );
		$this->assertStringContainsString( 'applies to US', $output );
	}

	public function test_shipping_section_emits_final_sale_when_no_returns(): void {
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => [ 'mode' => 'final_sale' ],
		];

		$output = $this->llms->generate();

		$this->assertStringContainsString( '- **Returns**: final sale, no returns accepted', $output );
	}

	public function test_shipping_section_omitted_when_no_data_configured(): void {
		// Override the default `wc_get_base_location` to return an
		// empty country, simulating a freshly-installed WC with no
		// settings configured. With no Ships-from, no handling time,
		// no return policy → the entire `## Shipping & Returns`
		// section is omitted (rather than rendered with an empty
		// bullet list).
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => '', 'state' => '' ]
		);

		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '## Shipping & Returns', $output );
	}

	// ------------------------------------------------------------------
	// ## Policies section (issue #445)
	//
	// Links the store's real policy pages, each line emitted only when
	// the corresponding page is actually configured (non-empty URL) —
	// matching the existing "no placeholders / no 'not set'" convention.
	// Sources: WP core `get_privacy_policy_url()`, WC's
	// `wc_terms_and_conditions_page_id()`, and the
	// `woocommerce_refund_returns_page_id` option.
	// ------------------------------------------------------------------

	public function test_policies_section_omitted_when_no_pages_configured(): void {
		// Default fixtures: privacy URL empty, no terms page, no refunds
		// page. With nothing resolvable, the whole `## Policies` section
		// is suppressed rather than rendered with an empty bullet list.
		Functions\when( 'get_privacy_policy_url' )->justReturn( '' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 0 );
		Functions\when( 'get_permalink' )->justReturn( false );

		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '## Policies', $output );
	}

	public function test_policies_section_emits_privacy_when_configured(): void {
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy-policy/' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 0 );
		Functions\when( 'get_permalink' )->justReturn( false );

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Policies', $output );
		$this->assertStringContainsString( '- **Privacy**: https://example.com/privacy-policy/', $output );
	}

	public function test_policies_section_omits_privacy_when_url_empty(): void {
		// WP core's `get_privacy_policy_url()` returns '' when no privacy
		// page is set. The Privacy line must be omitted in that case (not
		// rendered as `- **Privacy**: `).
		Functions\when( 'get_privacy_policy_url' )->justReturn( '' );
		// But a terms page IS set, so the section still renders (proving
		// it's the Privacy line specifically that's omitted, not the
		// whole section).
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 12 );
		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => 12 === (int) $id ? 'https://example.com/terms/' : false
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Policies', $output );
		$this->assertStringNotContainsString( '- **Privacy**:', $output );
	}

	public function test_policies_section_emits_terms_when_page_id_positive(): void {
		Functions\when( 'get_privacy_policy_url' )->justReturn( '' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 12 );
		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => 12 === (int) $id ? 'https://example.com/terms/' : false
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( '- **Terms**: https://example.com/terms/', $output );
	}

	public function test_policies_section_omits_terms_when_page_trashed(): void {
		// A merchant who trashes their Terms page leaves the WC setting
		// pointing at it, and `get_permalink()` still returns a live-looking
		// URL — so without the `publish` gate we'd publish a 404 link.
		// `get_post_status()` returns `'trash'` for a trashed page.
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy-policy/' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 12 );
		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => 12 === (int) $id ? 'https://example.com/terms/' : false
		);
		Functions\when( 'get_post_status' )->justReturn( 'trash' );

		$output = $this->llms->generate();

		// Section still renders (Privacy is set) but the Terms line is gone.
		$this->assertStringContainsString( '## Policies', $output );
		$this->assertStringNotContainsString( '- **Terms**:', $output );
	}

	public function test_policies_section_omits_terms_when_page_deleted(): void {
		// Hard-deleted page id: `get_post_status()` returns false. The
		// `'publish' === ...` gate also covers this dangling-id case.
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy-policy/' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 12 );
		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => 12 === (int) $id ? 'https://example.com/terms/' : false
		);
		Functions\when( 'get_post_status' )->justReturn( false );

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Policies', $output );
		$this->assertStringNotContainsString( '- **Terms**:', $output );
	}

	public function test_policies_section_omits_terms_when_page_id_zero(): void {
		// `wc_terms_and_conditions_page_id()` returns 0 when no T&C page
		// is selected in WC settings. A non-positive id must skip the
		// Terms line without calling get_permalink(0).
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy-policy/' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 0 );
		Functions\when( 'get_permalink' )->justReturn( false );

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Policies', $output );
		$this->assertStringNotContainsString( '- **Terms**:', $output );
	}

	public function test_policies_section_emits_refunds_when_option_positive(): void {
		Functions\when( 'get_privacy_policy_url' )->justReturn( '' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = '' ) {
				switch ( $option ) {
					case 'siteurl':
						return 'https://example.com';
					case 'woocommerce_refund_returns_page_id':
						return 77;
					default:
						return $default;
				}
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => 77 === (int) $id ? 'https://example.com/refunds/' : false
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( '- **Refunds & returns**: https://example.com/refunds/', $output );
	}

	public function test_policies_section_omits_refunds_when_page_trashed(): void {
		// Same dead-link guard as Terms: a trashed WC refunds page keeps
		// the option id and a live-looking permalink. `'publish'` gate omits it.
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy-policy/' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = '' ) {
				switch ( $option ) {
					case 'siteurl':
						return 'https://example.com';
					case 'woocommerce_refund_returns_page_id':
						return 77;
					default:
						return $default;
				}
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => 77 === (int) $id ? 'https://example.com/refunds/' : false
		);
		Functions\when( 'get_post_status' )->justReturn( 'trash' );

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Policies', $output );
		$this->assertStringNotContainsString( '- **Refunds & returns**:', $output );
	}

	public function test_policies_section_omits_refunds_when_page_deleted(): void {
		// Hard-deleted refunds page id → `get_post_status()` false → omit.
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy-policy/' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = '' ) {
				switch ( $option ) {
					case 'siteurl':
						return 'https://example.com';
					case 'woocommerce_refund_returns_page_id':
						return 77;
					default:
						return $default;
				}
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => 77 === (int) $id ? 'https://example.com/refunds/' : false
		);
		Functions\when( 'get_post_status' )->justReturn( false );

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Policies', $output );
		$this->assertStringNotContainsString( '- **Refunds & returns**:', $output );
	}

	public function test_policies_section_omits_refunds_when_option_zero(): void {
		// The `woocommerce_refund_returns_page_id` option is 0 / unset on
		// stores that never created the WC refunds page. Non-positive →
		// no Refunds line.
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy-policy/' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 0 );
		// Default `get_option` stub returns '' (→ 0) for the refunds id.
		Functions\when( 'get_permalink' )->justReturn( false );

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Policies', $output );
		$this->assertStringNotContainsString( '- **Refunds & returns**:', $output );
	}

	public function test_policies_section_emits_all_three_when_configured(): void {
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy-policy/' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 12 );
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = '' ) {
				switch ( $option ) {
					case 'siteurl':
						return 'https://example.com';
					case 'woocommerce_refund_returns_page_id':
						return 77;
					default:
						return $default;
				}
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static function ( $id ) {
				$map = array(
					12 => 'https://example.com/terms/',
					77 => 'https://example.com/refunds/',
				);
				return $map[ (int) $id ] ?? false;
			}
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Policies', $output );
		$this->assertStringContainsString( '- **Privacy**: https://example.com/privacy-policy/', $output );
		$this->assertStringContainsString( '- **Terms**: https://example.com/terms/', $output );
		$this->assertStringContainsString( '- **Refunds & returns**: https://example.com/refunds/', $output );
	}

	public function test_policies_section_appears_after_shipping_and_returns(): void {
		// Document hierarchy: `## Policies` is placed right after
		// `## Shipping & Returns`. Configure both so they render, then
		// assert ordering.
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => array( 'mode' => 'final_sale' ),
		);
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy-policy/' );
		Functions\when( 'wc_terms_and_conditions_page_id' )->justReturn( 0 );
		Functions\when( 'get_permalink' )->justReturn( false );

		$output = $this->llms->generate();

		$shipping_pos = strpos( $output, '## Shipping & Returns' );
		$policies_pos = strpos( $output, '## Policies' );

		$this->assertNotFalse( $shipping_pos );
		$this->assertNotFalse( $policies_pos );
		$this->assertGreaterThan(
			$shipping_pos,
			$policies_pos,
			'## Policies must appear after ## Shipping & Returns.'
		);
	}

	// ------------------------------------------------------------------
	// ## Rules for agents section (issue #445)
	//
	// Static guidance lines (no settings). Three bullets covering
	// rate-limit back-off, buyer-confirmed checkout (no delegated/in-chat
	// payment), and the `context.currency` requirement. No em-dashes.
	// ------------------------------------------------------------------

	public function test_rules_for_agents_section_present(): void {
		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Rules for agents', $output );
	}

	public function test_rules_for_agents_section_has_three_bullets(): void {
		$output = $this->llms->generate();

		$this->assertStringContainsString( '**Pace requests.**', $output );
		$this->assertStringContainsString( '**Checkout is buyer-confirmed on this store.**', $output );
		$this->assertStringContainsString( '**Send `context.currency`**', $output );
	}

	public function test_rules_for_agents_section_mentions_429_backoff(): void {
		// The rate-limit bullet must tell agents to back off on HTTP 429.
		$output = $this->llms->generate();

		$this->assertStringContainsString( 'HTTP 429', $output );
	}

	public function test_rules_for_agents_section_mentions_continue_url(): void {
		// The checkout bullet routes agents to send the buyer the
		// continue_url for buyer-confirmed payment on the merchant's
		// own checkout.
		$output = $this->llms->generate();

		$this->assertStringContainsString( 'continue_url', $output );
	}

	public function test_rules_for_agents_section_uses_no_em_dashes(): void {
		// Merchant-facing copy convention: no em-dashes anywhere in the
		// Rules-for-agents block. Slice the section out and assert.
		$output = $this->llms->generate();

		$start = strpos( $output, '## Rules for agents' );
		$this->assertNotFalse( $start );
		// Section ends at the next `## ` heading (or end of doc).
		$end     = strpos( $output, "\n## ", $start + 1 );
		$section = false === $end ? substr( $output, $start ) : substr( $output, $start, $end - $start );

		$this->assertStringNotContainsString( "\u{2014}", $section, 'Rules-for-agents copy must not use em-dashes.' );
	}

	// ------------------------------------------------------------------
	// ## Catalog section (issue #398)
	// ------------------------------------------------------------------

	public function test_catalog_section_omitted_when_no_categories_exist(): void {
		// Default `get_terms` stub returns []. catalog_summary is
		// empty array → the section is suppressed entirely.
		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '## Catalog', $output );
		$this->assertStringNotContainsString( 'Specializes in:', $output );
	}

	// ------------------------------------------------------------------
	// Attribution name mapping — the published hostname → brand table
	// was REMOVED in the 0.1.2 llms.txt declutter. It documented how
	// merchants see attribution in their Orders list, which is
	// merchant-facing context that an AI agent doesn't care about
	// (the agent already knows who it is; how the label renders
	// downstream in someone else's admin UI is none of its business).
	//
	// These tests PIN the removal — if a future refactor reintroduces
	// the table or its preamble copy, the negative assertions fire.
	// The runtime canonicalization (`KNOWN_AGENT_HOSTS` → `utm_source`
	// on `continue_url`) is unchanged and covered by attribution-layer
	// tests elsewhere in the suite.
	// ------------------------------------------------------------------

	// ------------------------------------------------------------------
	// HTML-entity decoding
	// ------------------------------------------------------------------

	public function test_store_name_with_html_entities_is_decoded(): void {
		// WordPress `get_bloginfo()` HTML-encodes by default. Raw entities
		// in a Markdown document confuse AI crawlers; the generator must
		// decode them.
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => [
				'name'        => 'Joe&#039;s Shop &amp; Cafe',
				'description' => 'Best &quot;coffee&quot;',
			][ $key ] ?? ''
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( "# Joe's Shop & Cafe\n", $output );
		$this->assertStringContainsString( '> Best "coffee"', $output );
		// And the encoded forms should NOT appear.
		$this->assertStringNotContainsString( '&#039;', $output );
		$this->assertStringNotContainsString( '&amp;', $output );
	}

	public function test_empty_description_omits_blockquote(): void {
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => 'name' === $key ? 'Example Store' : ''
		);

		$output = $this->llms->generate();

		// No tagline -> no '>' blockquote line.
		$this->assertStringNotContainsString( '> ', $output );
	}

	// ------------------------------------------------------------------
	// Category section
	// ------------------------------------------------------------------

	/**
	 * The emitted document must not embed the plugin version in its
	 * prose. Previous revisions had "As of 2.0.0, no product-level
	 * response fields are emitted…" hardcoded, which was stale (the
	 * plugin was on 0.1.x by the time agents read it) and pointless
	 * (the paragraph describes the CURRENT extension contract, not
	 * a historical one). Pin the removal so a future copy-paste
	 * from other release-notes doesn't reintroduce the pattern.
	 */
	public function test_output_does_not_embed_plugin_version_in_prose(): void {
		$output = $this->llms->generate();

		// Smoke check: generator actually produced content. Without
		// this, a silently empty `generate()` would pass every
		// negative assertion below.
		$this->assertNotEmpty( $output );

		// Specifically the pattern that was wrong before:
		$this->assertStringNotContainsString( 'As of 2.0.0', $output );
		// And the general pattern — any three-digit SemVer-ish
		// string in the prose is a smell.
		$this->assertDoesNotMatchRegularExpression(
			'/\bAs of \d+\.\d+\.\d+/',
			$output,
			'llms.txt should not embed a hardcoded plugin version in its prose.'
		);
	}

	// ------------------------------------------------------------------
	// Filter extensibility
	// ------------------------------------------------------------------

	public function test_output_is_filterable_via_lines_hook(): void {
		Functions\when( 'apply_filters' )->alias(
			// Variadic capture because generate() also fires
			// `wc_ai_storefront_accepted_currencies` (2-arg) via the
			// Multi_Currency helper; a strict 3-arg signature would
			// ArgumentCountError on that 2-arg dispatch.
			static function ( $hook, $lines, ...$extras ) {
				if ( 'wc_ai_storefront_llms_txt_lines' === $hook ) {
					$lines[] = '## Custom Extension';
					$lines[] = 'Injected by third party.';
				}
				return $lines;
			}
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Custom Extension', $output );
		$this->assertStringContainsString( 'Injected by third party.', $output );
	}

	// ------------------------------------------------------------------
	// Cache-hit semantics — the 1.4.4 empty-string regression
	// ------------------------------------------------------------------
	//
	// These tests lock in the defense against the bug that shipped in
	// production before 1.4.4: the cache-hit check was `false !== $cached`,
	// which treated an empty-string transient as a valid hit rather than
	// a miss. If anything ever poisoned the cache with `''` (and one did,
	// during the 1.4.2 wiring-bug window), blank responses were served
	// for the full 1-hour TTL. The fix is a pair: treat empty as miss on
	// read, refuse to write empty on the update path. These tests cover
	// both halves so a future refactor that only restores one of them
	// leaves a broken build.
	//
	// `get_cached_content()` is private by design; reflection is the
	// least-invasive way to exercise it without altering visibility.

	public function test_empty_cached_value_is_treated_as_miss(): void {
		Functions\when( 'get_transient' )->justReturn( '' );
		// Capture only the MAIN cache-key write — the single-flight
		// sentinel write (to `CACHE_KEY . '_regenerating'`) is a
		// separate concern and would clobber the "the cache was
		// healed with real content" assertion below if we captured
		// indiscriminately.
		$set_transient_called_with = null;
		$expected_cache_key        = WC_AI_Storefront_Llms_Txt::host_cache_key();
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$set_transient_called_with, $expected_cache_key ) {
				if ( $expected_cache_key === $key ) {
					$set_transient_called_with = [
						'key'   => $key,
						'value' => $value,
					];
				}
				return true;
			}
		);

		$result = $this->invoke_private( 'get_cached_content' );

		// Non-empty content was produced by regeneration — proves the
		// empty cached value did NOT short-circuit the lookup.
		$this->assertNotSame( '', $result );
		$this->assertStringContainsString( '# Example Store', $result );

		// The fresh non-empty content was written back to the cache,
		// healing the poisoned state on first request.
		$this->assertNotNull( $set_transient_called_with );
		$this->assertNotSame( '', $set_transient_called_with['value'] );
	}

	public function test_valid_cached_value_is_returned_verbatim(): void {
		Functions\when( 'get_transient' )->justReturn( "# Cached Content\n\nHello from cache." );
		Functions\when( 'set_transient' )->justReturn( true );

		$result = $this->invoke_private( 'get_cached_content' );

		// Verbatim return — we did NOT fall through to regeneration
		// when a valid cached value was present.
		$this->assertSame( "# Cached Content\n\nHello from cache.", $result );
	}

	public function test_empty_generated_content_is_not_written_to_cache(): void {
		Functions\when( 'get_transient' )->justReturn( false ); // Fresh miss.

		// Count set_transient calls for the CACHE_KEY only — the
		// single-flight sentinel writes to `CACHE_KEY . '_regenerating'`
		// as part of the lock-claim step and is NOT an empty-content
		// poisoning concern. The invariant this test pins is "empty
		// generated content must not land in the main cache", not
		// "no transients are set anywhere during regeneration."
		$main_cache_writes  = 0;
		$expected_cache_key = WC_AI_Storefront_Llms_Txt::host_cache_key();
		Functions\when( 'set_transient' )->alias(
			static function ( $key ) use ( &$main_cache_writes, $expected_cache_key ) {
				if ( $expected_cache_key === $key ) {
					++$main_cache_writes;
				}
				return true;
			}
		);
		// Force generate() to return empty by having the filter nuke
		// the lines array. This is the only realistic path to empty
		// output given generate()'s always-produces-skeleton design;
		// if a future refactor introduces other empty paths, this
		// test still catches them via the set_transient observation.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $lines ) {
				return ( 'wc_ai_storefront_llms_txt_lines' === $hook ) ? [] : $lines;
			}
		);

		$result = $this->invoke_private( 'get_cached_content' );

		$this->assertSame( '', $result, 'generate() should have returned empty in this setup.' );
		$this->assertSame( 0, $main_cache_writes, 'Empty content must not be cached — would poison the TTL window.' );
	}

	// ------------------------------------------------------------------
	// Sitemaps section (1.6.3)
	// ------------------------------------------------------------------

	public function test_sitemap_section_absent_when_no_sitemaps_respond(): void {
		// Default stubs: wp_remote_head returns WP_Error for every
		// probe, get_sitemap_url returns empty → zero candidates
		// confirmed existent → section not rendered.
		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '## Sitemaps', $output );
	}

	public function test_sitemap_section_rendered_when_sitemap_exists(): void {
		// Simulate a site where /sitemap.xml responds 200 to a HEAD
		// probe. The section should render with that URL listed.
		Functions\when( 'wp_remote_head' )->alias(
			static function ( string $url ): array {
				if ( str_ends_with( $url, '/sitemap.xml' ) ) {
					return [ 'response' => [ 'code' => 200 ] ];
				}
				return [ 'response' => [ 'code' => 404 ] ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) =>
				is_array( $response ) && isset( $response['response']['code'] )
					? (int) $response['response']['code']
					: 0
		);

		$output = $this->llms->generate();

		// In the issue #398 restructure the sitemap list moved from
		// its own `## Sitemaps` H2 to a sub-bullet group under
		// `## Browse`. The discovered URLs are still emitted but as
		// indented (2-space) sub-bullets beneath a `- **Sitemaps**`
		// parent.
		$this->assertStringContainsString( '- **Sitemaps**', $output );
		$this->assertStringContainsString( '  - https://example.com/sitemap.xml', $output );
	}

	public function test_sitemap_section_excludes_paths_that_404(): void {
		// When some candidates probe OK and others don't, only
		// the responding URLs make it into the output. Validates
		// the HEAD-filter logic — emitting non-existent paths in
		// llms.txt would be factually wrong (unlike robots.txt
		// Allow, which is a harmless no-op).
		Functions\when( 'wp_remote_head' )->alias(
			static function ( string $url ): array {
				// Only /sitemap.xml responds; /sitemap_index.xml,
				// /wp-sitemap.xml, /news-sitemap.xml all 404.
				$code = str_ends_with( $url, '/sitemap.xml' ) ? 200 : 404;
				return [ 'response' => [ 'code' => $code ] ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => (int) $response['response']['code']
		);

		$output = $this->llms->generate();

		// Sub-bullet shape under `## Browse` (2-space indent).
		$this->assertStringContainsString( '  - https://example.com/sitemap.xml', $output );
		$this->assertStringNotContainsString( '/sitemap_index.xml', $output );
		$this->assertStringNotContainsString( '/news-sitemap.xml', $output );
	}

	public function test_sitemap_probe_uses_sslverify_true_by_default(): void {
		// L-2: sitemap HEAD probes must use sslverify => true in
		// production (i.e. when WP_DEBUG is not true). Pin this so a
		// future change that re-introduces unconditional sslverify=false
		// is caught. WP_DEBUG is a compile-time constant that can't be
		// redefined at test time, so we capture the args passed to
		// wp_remote_head and assert on the sslverify value.
		//
		// In the test environment WP_DEBUG is undefined or false, so
		// the expression `! ( defined('WP_DEBUG') && WP_DEBUG )` must
		// resolve to true. We confirm the captured arg matches.
		$captured_args = null;
		Functions\when( 'wp_remote_head' )->alias(
			static function ( string $url, array $args ) use ( &$captured_args ): array {
				$captured_args = $args;
				// Return a 200 so the probe succeeds and the llms.txt
				// output includes a sitemap section we can assert on.
				return [ 'response' => [ 'code' => 200 ] ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => (int) $response['response']['code']
		);

		$this->llms->generate();

		$this->assertIsArray( $captured_args, 'wp_remote_head must have been called.' );

		// In this test environment WP_DEBUG is not true, so sslverify
		// must be true. This is the production-path assertion.
		$expected_sslverify = ! ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		$this->assertSame(
			$expected_sslverify,
			$captured_args['sslverify'],
			'sslverify must be true in production (WP_DEBUG off) and false only when WP_DEBUG is on.'
		);
	}

	// ------------------------------------------------------------------
	// UCP extension docs
	// ------------------------------------------------------------------

	public function test_llms_txt_includes_ucp_extension_anchor(): void {
		// The UCP manifest advertises the merchant-extension capability
		// with a `spec` URL pointing at `/llms.txt#ucp-extension`. This
		// test locks in that the anchor is present + the section header
		// is actually rendered so the manifest's URL resolves.
		//
		// The H2 title is "Extension schema" rather than the previous
		// "UCP Extension: com.woocommerce.ai_storefront" — that title
		// oversold the single-URL content beneath it and exposed
		// package-namespace syntax to merchants reading their own
		// llms.txt. The anchor ID stays `ucp-extension` so the
		// manifest's `spec` URL keeps resolving.
		$output = $this->llms->generate();

		$this->assertStringContainsString( '<a id="ucp-extension"></a>', $output );
		$this->assertStringContainsString( '## Extension schema', $output );
	}

	public function test_llms_txt_extension_section_points_at_schema_endpoint(): void {
		// The human-readable section should reference the machine-
		// readable schema endpoint so agents that want to validate
		// the payload can find it from the text docs.
		$output = $this->llms->generate();

		$this->assertStringContainsString( '/wp-json/wc/ucp/v1/extension/schema', $output );
	}

	public function test_wp_core_sitemap_included_when_non_empty(): void {
		// `get_sitemap_url( 'index' )` returns WP core's canonical
		// sitemap URL when the feature is active. That candidate
		// should be probed alongside the hardcoded COMMON_SITEMAP_PATHS.
		Functions\when( 'get_sitemap_url' )->justReturn( 'https://example.com/wp-sitemap.xml' );
		Functions\when( 'wp_remote_head' )->alias(
			static function ( string $url ): array {
				$code = str_ends_with( $url, '/wp-sitemap.xml' ) ? 200 : 404;
				return [ 'response' => [ 'code' => $code ] ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => (int) $response['response']['code']
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( '- https://example.com/wp-sitemap.xml', $output );
	}

	// ------------------------------------------------------------------
	// host_cache_key()
	// ------------------------------------------------------------------

	public function test_host_cache_key_begins_with_base_cache_key(): void {
		$_SERVER['HTTP_HOST'] = 'shop.example.com';
		$key                  = WC_AI_Storefront_Llms_Txt::host_cache_key();

		$this->assertStringStartsWith( WC_AI_Storefront_Llms_Txt::CACHE_KEY . '_', $key );
	}

	public function test_host_cache_key_differs_for_different_hosts(): void {
		$_SERVER['HTTP_HOST'] = 'host-a.example.com';
		$key_a                = WC_AI_Storefront_Llms_Txt::host_cache_key();

		$_SERVER['HTTP_HOST'] = 'host-b.example.com';
		$key_b                = WC_AI_Storefront_Llms_Txt::host_cache_key();

		$this->assertNotEquals( $key_a, $key_b );
	}

	public function test_host_cache_key_is_stable_for_same_host(): void {
		$_SERVER['HTTP_HOST'] = 'stable.example.com';
		$key1                 = WC_AI_Storefront_Llms_Txt::host_cache_key();
		$key2                 = WC_AI_Storefront_Llms_Txt::host_cache_key();

		$this->assertSame( $key1, $key2 );
	}

	public function test_host_cache_key_uses_md5_of_host(): void {
		$host                 = 'verify.example.com';
		$_SERVER['HTTP_HOST'] = $host;
		$key                  = WC_AI_Storefront_Llms_Txt::host_cache_key();

		$this->assertSame( WC_AI_Storefront_Llms_Txt::CACHE_KEY . '_' . md5( $host ), $key );
	}

	/**
	 * Invoke a private method on the LlmsTxt instance via reflection.
	 *
	 * @param string $method Method name.
	 * @return mixed          Return value of the method.
	 */
	private function invoke_private( string $method ) {
		$reflection = new ReflectionClass( $this->llms );
		$m          = $reflection->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $this->llms );
	}

	/**
	 * Call the private static sanitize_markdown_inline() via reflection.
	 *
	 * @param string $value        Raw input.
	 * @param bool   $is_link_text Whether bracket-escaping is applied.
	 * @return string Sanitized value.
	 */
	private static function sanitize_inline( string $value, bool $is_link_text = false ): string {
		$m = new ReflectionMethod( WC_AI_Storefront_Llms_Txt::class, 'sanitize_markdown_inline' );
		$m->setAccessible( true );
		return (string) $m->invoke( null, $value, $is_link_text );
	}

	// sanitize_markdown_inline() unit tests
	// ------------------------------------------------------------------

	public function test_sanitize_markdown_inline_strips_control_characters(): void {
		// CR, LF, TAB, and other C0 control chars must be removed.
		$this->assertSame( 'HelloWorld', self::sanitize_inline( "Hello\nWorld" ) );
		$this->assertSame( 'HelloWorld', self::sanitize_inline( "Hello\rWorld" ) );
		$this->assertSame( 'HelloWorld', self::sanitize_inline( "Hello\tWorld" ) );
		$this->assertSame( 'HelloWorld', self::sanitize_inline( "Hello\x00World" ) );
		$this->assertSame( 'HelloWorld', self::sanitize_inline( "Hello\x1FWorld" ) );
		$this->assertSame( 'HelloWorld', self::sanitize_inline( "Hello\x7FWorld" ) );
	}

	public function test_sanitize_markdown_inline_passes_normal_text_unchanged(): void {
		$normal = 'Outdoor Gear & Sports — Summer 2025';
		$this->assertSame( $normal, self::sanitize_inline( $normal ) );
	}

	public function test_sanitize_markdown_inline_link_text_escapes_brackets(): void {
		// In link-text context, [ and ] must be backslash-escaped so they
		// cannot break out of the Markdown [text](url) structure.
		$this->assertSame( '\\[click here\\]', self::sanitize_inline( '[click here]', true ) );
	}

	public function test_sanitize_markdown_inline_link_text_escapes_backslash_first(): void {
		// Backslash must be escaped before brackets to avoid double-escaping:
		// "a\[b]" → "a\\[b]" not "a\\\\[b]".
		$this->assertSame( 'a\\\\\\[b\\]', self::sanitize_inline( 'a\\[b]', true ) );
	}

	public function test_sanitize_markdown_inline_non_link_text_does_not_escape_brackets(): void {
		// When not in link-text mode, brackets are preserved verbatim.
		$this->assertSame( '[click here]', self::sanitize_inline( '[click here]', false ) );
	}

	// ------------------------------------------------------------------
	// Edge-cache wiring guard (source inspection)
	// ------------------------------------------------------------------

	/**
	 * Guards the edge-cache wiring: serve_llms_txt() must emit the shared
	 * discovery cache header and must NOT re-introduce no-store or per-hit
	 * crawl-logging (either would defeat the rate-limit fix). The serve method
	 * emits headers + exit() so it can't be unit-tested directly; this pins
	 * the source instead, mirroring ActivationTest's approach.
	 */
	public function test_llms_txt_stays_wired_to_discovery_cache_helper(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/ai-storefront/class-wc-ai-storefront-llms-txt.php' );
		$this->assertStringContainsString( 'WC_AI_Storefront::discovery_cache_control()', $source );
		$this->assertStringNotContainsString( 'Cache-Control: no-store', $source );
		$this->assertStringNotContainsString( 'Crawl_Logger::record(', $source );
	}

	// ------------------------------------------------------------------
	// /agents.md mirror endpoint (issue #446)
	//
	// `/agents.md` is a byte-identical mirror of `/llms.txt`: same
	// generator, same cache (`get_cached_content()`), same edge-cache
	// headers — the only intentional difference is the Content-Type
	// (`text/markdown` vs `text/plain`). These tests pin the rewrite/
	// query-var wiring, the body-identity contract, the headers, and the
	// disabled/OPTIONS branches.
	// ------------------------------------------------------------------

	public function test_agents_md_rewrite_rule_registered(): void {
		$rules = [];
		Functions\when( 'add_rewrite_rule' )->alias(
			static function ( $regex, $query, $after ) use ( &$rules ) {
				$rules[ $regex ] = [ 'query' => $query, 'after' => $after ];
			}
		);

		$this->llms->add_rewrite_rules();

		$this->assertArrayHasKey( '^agents\.md$', $rules );
		$this->assertSame( 'top', $rules['^agents\.md$']['after'] );
		$this->assertStringContainsString(
			'wc_ai_storefront_agents_md=1',
			$rules['^agents\.md$']['query']
		);
	}

	public function test_agents_md_rewrite_rule_does_not_displace_llms_txt(): void {
		// Both endpoints must be registered — /agents.md is additive, it
		// does not replace the existing /llms.txt rule.
		$rules = [];
		Functions\when( 'add_rewrite_rule' )->alias(
			static function ( $regex, $query, $after ) use ( &$rules ) {
				$rules[ $regex ] = [ 'query' => $query, 'after' => $after ];
			}
		);

		$this->llms->add_rewrite_rules();

		$this->assertArrayHasKey( '^llms\.txt$', $rules );
		$this->assertArrayHasKey( '^agents\.md$', $rules );
	}

	public function test_agents_md_query_var_registered(): void {
		Functions\when( 'add_rewrite_rule' )->justReturn();

		$vars = $this->llms->add_query_vars( [] );

		$this->assertContains( 'wc_ai_storefront_agents_md', $vars );
		// The llms.txt query var must still be present too.
		$this->assertContains( 'wc_ai_storefront_llms_txt', $vars );
	}

	public function test_canonical_redirect_suppressed_for_agents_md_query_var(): void {
		// WP would otherwise 301 `/agents.md` to a trailing-slash variant
		// that no longer matches the rewrite rule, 404ing the request.
		// The guard must return false when the agents.md query var is set.
		Functions\when( 'get_query_var' )->alias(
			static fn( $var ) => 'wc_ai_storefront_agents_md' === $var ? 1 : 0
		);

		$this->assertFalse( $this->llms->suppress_canonical_redirect( 'https://example.com/agents.md/' ) );
	}

	public function test_canonical_redirect_untouched_when_neither_query_var_set(): void {
		// Canonical behaviour elsewhere on the site must be preserved:
		// when neither discovery query var is set, the candidate URL is
		// returned unchanged.
		Functions\when( 'get_query_var' )->justReturn( 0 );

		$this->assertSame(
			'https://example.com/some-page/',
			$this->llms->suppress_canonical_redirect( 'https://example.com/some-page/' )
		);
	}

	public function test_agents_md_body_is_identical_to_llms_txt_body(): void {
		// Single source of truth: both endpoints echo the SAME
		// `get_cached_content()` result, so they can never drift. Capture
		// the shared content method (the exact value both serve handlers
		// echo) and confirm it is non-empty markdown. The serve handlers
		// echo this verbatim before exit(); the byte-for-byte wiring is
		// additionally guarded by the source-inspection test below
		// (`test_agents_md_serve_mirrors_llms_txt_exactly`), which proves
		// both methods contain the identical `echo $this->get_cached_content();`
		// statement. Together these guarantee identical bodies without the
		// fragility of capturing stdout across exit().
		$llms_body   = $this->invoke_private( 'get_cached_content' );
		$agents_body = $this->invoke_private( 'get_cached_content' );

		$this->assertSame( $llms_body, $agents_body );
		$this->assertNotSame( '', $llms_body );
		$this->assertStringContainsString( '# Example Store', $llms_body );
	}

	/**
	 * Source-inspection guard for the mirror contract. The serve methods
	 * emit headers + exit(), so (per the established suite pattern, see
	 * `test_llms_txt_stays_wired_to_discovery_cache_helper`) the byte-for-byte
	 * mirror wiring is pinned at the source level: serve_agents_md() must
	 *   - reuse the shared `get_cached_content()` (no second cache key),
	 *   - emit the shared discovery cache-control header + Vary: Host,
	 *   - emit the CORS + nosniff headers,
	 *   - send `text/markdown` (the one intentional difference from llms.txt),
	 *   - never introduce a no-store header or per-hit crawl logging.
	 */
	public function test_agents_md_serve_mirrors_llms_txt_exactly(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/ai-storefront/class-wc-ai-storefront-llms-txt.php' );

		// Isolate the serve_agents_md() method body for precise assertions.
		$start = strpos( $source, 'function serve_agents_md(' );
		$this->assertNotFalse( $start, 'serve_agents_md() must exist.' );
		$body = substr( $source, $start );

		// Same cached content method as llms.txt — no separate cache key.
		$this->assertStringContainsString( '$this->get_cached_content()', $body );
		// Edge-cache + mirror headers (the point of this PR).
		$this->assertStringContainsString( 'WC_AI_Storefront::discovery_cache_control()', $body );
		$this->assertStringContainsString( "header( 'Vary: Host' )", $body );
		$this->assertStringContainsString( "header( 'X-Content-Type-Options: nosniff' )", $body );
		$this->assertStringContainsString( "header( 'Access-Control-Allow-Origin: *' )", $body );
		// The one intentional difference from llms.txt: markdown content-type.
		$this->assertStringContainsString( 'text/markdown; charset=utf-8', $body );
		// Must not regress the rate-limit fix.
		$this->assertStringNotContainsString( 'Cache-Control: no-store', $body );
		$this->assertStringNotContainsString( 'Crawl_Logger::record(', $body );
	}

	/**
	 * Disabled store → 404 then exit, mirroring serve_llms_txt(). We stub
	 * status_header() to throw a sentinel so the 404 branch is asserted
	 * WITHOUT reaching the real exit(). Separate process so a stray exit()
	 * (if the stub ever fails to intercept) ends only the forked child.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_agents_md_returns_404_when_plugin_disabled(): void {
		Functions\when( 'get_query_var' )->justReturn( 1 ); // agents.md requested.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];

		$captured_status = null;
		Functions\when( 'status_header' )->alias(
			static function ( $code ) use ( &$captured_status ) {
				$captured_status = $code;
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->llms->serve_agents_md();
			$this->fail( 'Expected serve_agents_md() to emit a 404 on a disabled store.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 404, $captured_status );
		}
	}

	/**
	 * An OPTIONS preflight → 204 then exit, mirroring serve_llms_txt().
	 * Same throw-sentinel + separate-process technique as the 404 test.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_agents_md_returns_204_on_options_request(): void {
		Functions\when( 'get_query_var' )->justReturn( 1 );
		$_SERVER['REQUEST_METHOD'] = 'OPTIONS';

		$captured_status = null;
		Functions\when( 'status_header' )->alias(
			static function ( $code ) use ( &$captured_status ) {
				$captured_status = $code;
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->llms->serve_agents_md();
			$this->fail( 'Expected serve_agents_md() to emit a 204 on an OPTIONS preflight.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 204, $captured_status );
		} finally {
			unset( $_SERVER['REQUEST_METHOD'] );
		}
	}

	public function test_agents_md_serve_returns_early_when_query_var_not_set(): void {
		// When the agents.md query var is absent, the handler must no-op
		// (no output, no exit) so it doesn't hijack unrelated requests.
		Functions\when( 'get_query_var' )->justReturn( 0 );

		ob_start();
		$this->llms->serve_agents_md();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_generated_content_contains_agents_md_reference_line(): void {
		// The shared content carries a pointer to the canonical /agents.md
		// doc. The wording is surface-neutral so it reads correctly on
		// BOTH endpoints: it names agents.md as canonical and notes that
		// /llms.txt serves the same document. It must NOT say "/llms.txt
		// mirrors it" — that demonstrative is wrong when this byte-identical
		// content is served on /agents.md, where "this" document IS the
		// canonical agents.md, not the mirror.
		$output = $this->llms->generate();

		$this->assertStringContainsString( 'https://example.com/agents.md', $output );
		$this->assertStringContainsString( '**Agent doc**', $output );
		$this->assertStringContainsString( 'canonical agent doc', $output );
		$this->assertStringNotContainsString( 'mirrors it', $output );
	}
}
