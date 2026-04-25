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
	}

	protected function tearDown(): void {
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
		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Store Information', $output );
		$this->assertStringContainsString( '## API Access', $output );
		$this->assertStringContainsString( '## Attribution', $output );
	}

	public function test_api_access_section_points_to_store_api_and_ucp(): void {
		// The plugin does NOT expose its own authenticated API. llms.txt
		// must advertise WooCommerce's public Store API and the UCP
		// manifest — NOT the removed `wc/v3/ai-storefront/*` endpoints
		// or the `X-AI-Agent-Key` header (both existed in a pre-1.0
		// draft of the architecture).
		$output = $this->llms->generate();

		// Correct endpoints advertised.
		$this->assertStringContainsString( 'wc/store/v1', $output );
		$this->assertStringContainsString( '.well-known/ucp', $output );

		// Regression guard: the deleted endpoints must NEVER appear again.
		$this->assertStringNotContainsString( 'X-AI-Agent-Key', $output );
		$this->assertStringNotContainsString( 'wc/v3/ai-syndication', $output );
		$this->assertStringNotContainsString( 'Product Catalog', $output );
		$this->assertStringNotContainsString( '## API Endpoints', $output );
	}

	public function test_llms_txt_does_not_declare_ai_acceptance_in_body(): void {
		// The existence of `/llms.txt` IS the "I accept AI discovery"
		// signal per the llms.txt spec — spelling it out in the body
		// is redundant. The removal pins that no drive-by revision
		// reintroduces this line.
		$output = $this->llms->generate();

		$this->assertStringNotContainsString(
			'This store accepts AI-assisted product discovery',
			$output
		);
	}

	public function test_store_information_carries_currency_only(): void {
		$output = $this->llms->generate();

		// Currency is the one item in this section that doesn't
		// duplicate a later section's content — kept as a
		// glanceable header line for text-first agents.
		$this->assertStringContainsString( '- **Currency**: USD', $output );

		// URL bullet deliberately NOT rendered — the store's URL is
		// the hostname of the file the agent just fetched.
		$this->assertStringNotContainsString( '- **URL**:', $output );

		// Checkout bullet trimmed from this section — `## Checkout
		// Policy` below carries far more actionable detail
		// (including the exact endpoint to POST to).
		$this->assertStringNotContainsString( '- **Checkout**:', $output );

		// Commerce Protocol bullet trimmed from this section —
		// `## API Access` below already links the UCP manifest URL.
		$this->assertStringNotContainsString( '- **Commerce Protocol**:', $output );
	}

	public function test_store_information_links_ucp_manifest(): void {
		// AI crawlers that parse llms.txt should be able to follow the
		// link to the UCP manifest for machine-readable capabilities.
		$output = $this->llms->generate();

		$this->assertStringContainsString( '.well-known/ucp', $output );
	}

	public function test_attribution_section_does_not_document_client_side_utm_params(): void {
		// Post-v2.0.0 scope cut. The previous UTM-parameter list
		// (`utm_source` / `utm_medium` / `utm_campaign` / `ai_session_id`)
		// encouraged client-side URL construction — which the v2.0.0
		// UCP-POST-first posture replaced. Removed; this test is the
		// regression guard for the removal.
		//
		// The Attribution narrative still mentions `utm_source` and
		// `utm_medium` in prose ("server attaches utm_source + utm_medium
		// to continue_url"), so we specifically guard the BULLETED-LIST
		// client-construction variants — those are the shape that would
		// confuse agents into building URLs themselves. Guards all four
		// bullet variants because any one reintroduction is the regression
		// signal we care about (cutting three but keeping one would be
		// sneakier than the no-partial-revert rule implies).
		$output = $this->llms->generate();

		$this->assertStringNotContainsString(
			'- `utm_source`: Your agent identifier',
			$output
		);
		$this->assertStringNotContainsString(
			'- `utm_medium`: `ai_agent`',
			$output
		);
		$this->assertStringNotContainsString(
			'- `utm_campaign`: Optional campaign name',
			$output
		);
		$this->assertStringNotContainsString(
			'- `ai_session_id`: The current conversation/session ID',
			$output
		);
	}

	public function test_checkout_policy_section_explicitly_declares_merchant_only_posture(): void {
		// 1.6.6 origin, trimmed in 0.1.2: llms.txt carries an
		// explicit declaration of the merchant-only-checkout
		// posture. Redundant with the UCP manifest (which declares
		// by absence of capabilities), but useful for agent trust
		// frameworks and human reviewers.
		//
		// Covers (a) the section heading, (b) the "MUST redirect"
		// top-line, (c) the "does NOT support" negative list that
		// serves non-UCP-aware agents, and (d) the one-line
		// pointer to the manifest for programmatic verification —
		// which replaced the previous 4-bullet content duplication
		// of the manifest fields.
		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Checkout Policy', $output );
		$this->assertStringContainsString( 'All purchases complete on this site', $output );
		$this->assertStringContainsString( 'In-chat or in-agent payment completion', $output );
		$this->assertStringContainsString( 'Embedded checkout', $output );
		$this->assertStringContainsString( 'AP2 Mandates', $output );
		$this->assertStringContainsString( 'Persistent agent-managed carts', $output );

		// One-line pointer to the manifest is present — mentions
		// `payment_handlers` and `requires_escalation` as inline
		// factoids, not as standalone bulleted claims.
		$this->assertStringContainsString( '.well-known/ucp', $output );
		$this->assertStringContainsString( 'payment_handlers', $output );
		$this->assertStringContainsString( 'requires_escalation', $output );
	}

	public function test_checkout_policy_does_not_enumerate_manifest_fields_in_bullets(): void {
		// 0.1.2 trim: the old "Programmatic verification" subsection
		// had 4 bullets duplicating `capabilities`, `payment_handlers`,
		// `transport`, and the checkout-response status from the
		// UCP manifest. Collapsed into a single pointer line.
		//
		// Pin the collapse: neither the subsection header nor the
		// specific enumerated-bullet prose should reappear.
		$output = $this->llms->generate();

		// Smoke check: confirm the generator actually produced a
		// document before asserting removals — otherwise a silently
		// empty `generate()` output would pass every negative
		// assertion below and masquerade as "the trim worked."
		$this->assertStringContainsString( '## Checkout Policy', $output );

		$this->assertStringNotContainsString(
			'Programmatic verification —',
			$output,
			'The "Programmatic verification — the UCP manifest at … reflects this posture:" lead-in should be gone.'
		);
		$this->assertStringNotContainsString(
			'`capabilities` contains `dev.ucp.shopping.catalog.search`',
			$output
		);
		$this->assertStringNotContainsString(
			'`payment_handlers` is `{}`',
			$output
		);
		$this->assertStringNotContainsString(
			'transport: "rest"',
			$output
		);
	}

	/**
	 * Pins the removal of the "via the table below" forward-reference
	 * in the Attribution section prose. Commit 133a389 removed the
	 * `### Attribution name mapping` table; a drive-by PR could
	 * easily re-introduce the referring phrase without realizing
	 * the target table is gone. Keep this test honest by asserting
	 * BOTH the specific dangling phrase AND a regex that would
	 * catch variants.
	 */
	public function test_attribution_prose_does_not_reference_removed_table(): void {
		$output = $this->llms->generate();

		// Smoke check: Attribution section actually rendered.
		$this->assertStringContainsString( '## Attribution', $output );

		// The specific phrases from the pre-0.1.2 prose.
		$this->assertStringNotContainsString( 'table below', $output );
		$this->assertStringNotContainsString( 'via the table', $output );

		// Regex catches drive-by variants — "see the table", "in
		// the table", "per the mapping table", "table above",
		// etc. The `(?:above|below)?` slot is optional so phrases
		// without a direction word still trip the guard.
		$this->assertDoesNotMatchRegularExpression(
			'/\b(?:see|via|per|in|using|from|the)\b[^.]{0,40}\btable(?:\s+(?:above|below))?\b/i',
			$output,
			'Attribution prose re-introduced a forward-reference to a mapping table that no longer exists.'
		);
	}

	public function test_attribution_leads_with_api_first_checkout_flow(): void {
		// 1.6.5 change: the attribution section now leads with the
		// canonical UCP flow (POST /checkout-sessions) rather than
		// URL-template examples. Matches the UCP checkout spec's
		// SHOULD directive: businesses provide continue_url,
		// platforms don't construct their own.
		$output = $this->llms->generate();

		$this->assertStringContainsString( '/checkout-sessions', $output );
		$this->assertStringContainsString( 'UCP-Agent', $output );
		$this->assertStringContainsString( 'requires_escalation', $output );
		$this->assertStringContainsString( 'continue_url', $output );
	}

	public function test_attribution_example_uses_prefixed_ucp_id(): void {
		// The UCP catalog/search/lookup responses emit prefixed IDs
		// (`prod_N` for products, `var_N` for variations); the
		// line-items example in the llms.txt Attribution section
		// must match that wire format or agents will POST raw
		// WooCommerce IDs and get rejected by
		// `WC_AI_Storefront_UCP_REST_Controller::parse_ucp_id()`.
		// Guard against a drive-by reversion to a bare numeric ID.
		$output = $this->llms->generate();

		$this->assertStringContainsString( '"id": "prod_', $output );
		$this->assertStringNotContainsString( '"id": "123"', $output );
	}

	public function test_url_patterns_section_not_emitted(): void {
		// Post-v2.0.0 scope cut. The previous "URL patterns for
		// client-side construction" block (6 URL variants:
		// `/checkout-link/?products=` × 4 plus `?add-to-cart=` × 2)
		// contradicted the Checkout Policy block's "MUST redirect to
		// continue_url from POST /checkout-sessions" posture. Agents
		// reading one or the other would end up uncertain which flow
		// the store actually wanted. v2.0.0 committed to POST-first
		// at the endpoint level; this removal commits to it in the
		// docs. This test is the regression guard.
		//
		// If any of the template variables or URL shapes reappears,
		// this test fires — forcing a conscious re-decision on whether
		// to reintroduce the client-side-construction path (and if so,
		// how to reconcile it with the POST-first Checkout Policy).
		// All five placeholders from the removed section are guarded
		// explicitly; a partial reintroduction with only the most
		// common ones (`{product_id}` / `{quantity}`) would otherwise
		// slip through a narrower negative-assertion set.
		$output = $this->llms->generate();

		$this->assertStringNotContainsString(
			'### URL patterns for client-side construction',
			$output
		);
		$this->assertStringNotContainsString( '/checkout-link/?products=', $output );
		$this->assertStringNotContainsString( '/?add-to-cart=', $output );
		$this->assertStringNotContainsString( '{site_url}', $output );
		$this->assertStringNotContainsString( '{product_id}', $output );
		$this->assertStringNotContainsString( '{variation_id}', $output );
		$this->assertStringNotContainsString( '{quantity}', $output );
		$this->assertStringNotContainsString( '{coupon_code}', $output );
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

	public function test_llms_txt_does_not_publish_attribution_name_mapping_table(): void {
		$output = $this->llms->generate();

		// Section heading gone.
		$this->assertStringNotContainsString( '### Attribution name mapping', $output );

		// Markdown table header gone.
		$this->assertStringNotContainsString( '| Attribution name | Profile hostnames |', $output );

		// Preamble prose gone.
		$this->assertStringNotContainsString( 'pass through verbatim', $output );
		$this->assertStringNotContainsString( 'ucp_unknown', $output );

		// Invitation to request additions gone (tied to the removed
		// two-way-contract framing).
		$this->assertStringNotContainsString(
			'github.com/Automattic/woocommerce-ai-storefront/issues',
			$output
		);

		// And no individual known-agent hostname slipped through any
		// surviving section. The runtime table may still GROW via
		// KNOWN_AGENT_HOSTS; that growth must not leak back into the
		// emitted document.
		foreach ( WC_AI_Storefront_UCP_Agent_Header::KNOWN_AGENT_HOSTS as $host => $_brand ) {
			$this->assertStringNotContainsString(
				$host,
				$output,
				"llms.txt unexpectedly contains the vendor hostname `{$host}` — the attribution name mapping table was removed; if this hostname now appears in some other context, update the assertion."
			);
		}
	}

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

	public function test_categories_section_renders_when_categories_exist(): void {
		$category = (object) [
			'term_id' => 5,
			'name'    => 'Coffee Beans',
			'slug'    => 'coffee-beans',
			'count'   => 3,
		];
		Functions\when( 'get_terms' )->justReturn( [ $category ] );

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Product Categories', $output );
		$this->assertStringContainsString(
			'[Coffee Beans](https://example.com/product-category/coffee-beans/)',
			$output
		);
		$this->assertStringContainsString( '(3 products)', $output );
	}

	public function test_category_with_one_product_uses_singular_grammar(): void {
		// Category counts feeding "(1 products)" is a grammar bug AI
		// readers will notice. Singular vs plural is explicitly handled.
		$category = (object) [
			'term_id' => 5,
			'name'    => 'Espresso',
			'slug'    => 'espresso',
			'count'   => 1,
		];
		Functions\when( 'get_terms' )->justReturn( [ $category ] );

		$output = $this->llms->generate();

		$this->assertStringContainsString( '(1 product)', $output );
		$this->assertStringNotContainsString( '(1 products)', $output );
	}

	public function test_category_name_with_entities_is_decoded(): void {
		$category = (object) [
			'term_id' => 5,
			'name'    => 'Tea &amp; Infusions',
			'slug'    => 'tea',
			'count'   => 2,
		];
		Functions\when( 'get_terms' )->justReturn( [ $category ] );

		$output = $this->llms->generate();

		$this->assertStringContainsString( '[Tea & Infusions]', $output );
	}

	public function test_category_with_wp_error_term_link_is_skipped(): void {
		// get_term_link() can return a WP_Error for orphaned terms.
		// The generator must handle that gracefully without rendering
		// a broken link.
		$category = (object) [
			'term_id' => 5,
			'name'    => 'Broken',
			'slug'    => 'broken',
			'count'   => 1,
		];
		Functions\when( 'get_terms' )->justReturn( [ $category ] );
		Functions\when( 'get_term_link' )->justReturn(
			new WP_Error( 'invalid_term', 'bad term' )
		);

		$output = $this->llms->generate();

		// The heading still renders (a category did exist), but the
		// broken row is omitted. Confirm no "[Broken]" link slipped through.
		$this->assertStringNotContainsString( '[Broken]', $output );
	}

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

	public function test_categories_section_omitted_when_no_categories(): void {
		// Fresh stores or disabled/empty taxonomy -> no section heading.
		Functions\when( 'get_terms' )->justReturn( [] );

		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '## Product Categories', $output );
	}

	public function test_wp_error_from_get_terms_is_handled(): void {
		Functions\when( 'get_terms' )->justReturn(
			new WP_Error( 'db_error', 'database' )
		);

		$output = $this->llms->generate();

		// A WP_Error should be treated as "no categories", not crash.
		$this->assertStringNotContainsString( '## Product Categories', $output );
	}

	/**
	 * When the merchant has scoped to a NON-category dimension
	 * (tags / brands / hand-picked products), the categories
	 * section must NOT render — even if the store has categories.
	 *
	 * Pre-fix bug: `get_syndicated_categories()` always emitted
	 * the top-20-by-count list for any mode other than
	 * `categories`. Concrete scenario reported: a merchant scoped
	 * to a single brand saw their llms.txt advertise all the
	 * top categories in their store with full product counts —
	 * including categories whose products weren't in the scoped
	 * brand at all. Agents querying off that list got mostly-
	 * empty responses, inferred the store was broken, and moved on.
	 *
	 * The section is now suppressed entirely in those modes. This
	 * test pins that contract across all three non-category modes.
	 */
	public function test_categories_section_omitted_for_non_category_modes(): void {
		$category          = new stdClass();
		$category->name    = 'Clothing';
		$category->slug    = 'clothing';
		$category->count   = 42;
		$category->term_id = 1;

		// Categories DO exist in the store — so the omission we
		// assert below is specifically due to mode, not absence.
		Functions\when( 'get_terms' )->justReturn( [ $category ] );

		foreach ( [ 'tags', 'brands', 'selected' ] as $mode ) {
			WC_AI_Storefront::$test_settings = [
				'enabled'                => 'yes',
				'product_selection_mode' => $mode,
			];

			$output = $this->llms->generate();

			// Smoke check: generator actually produced content in
			// this iteration. Without it the negative assertions
			// below would pass for a silently broken generator.
			$this->assertStringContainsString(
				'## Store Information',
				$output,
				sprintf(
					'Generator produced empty or malformed output in mode "%s".',
					$mode
				)
			);

			$this->assertStringNotContainsString(
				'## Product Categories',
				$output,
				sprintf(
					'Expected categories section to be suppressed in mode "%s" because the top-N-by-count list misrepresents the syndicated scope.',
					$mode
				)
			);
			$this->assertStringNotContainsString(
				'Clothing',
				$output,
				sprintf(
					'Expected no individual category rows in mode "%s".',
					$mode
				)
			);
		}
	}

	/**
	 * 0.1.5 semantic change: under the new UNION model,
	 * `by_taxonomy` mode with NO `selected_categories` (and no
	 * `selected_tags` / `selected_brands` either) means nothing is
	 * in scope — listing top-N-by-count categories would advertise
	 * products the scope excludes. The section is suppressed
	 * entirely.
	 *
	 * This test rewrites the old pre-0.1.5 fallback (which showed
	 * top-20 in `categories` mode with empty selection) to match
	 * the new contract. Companion to
	 * `test_categories_section_omitted_for_non_category_modes` +
	 * the new `by_taxonomy` happy-path test.
	 */
	public function test_categories_section_suppressed_in_by_taxonomy_mode_with_all_empty(): void {
		$category          = new stdClass();
		$category->name    = 'Clothing';
		$category->slug    = 'clothing';
		$category->count   = 42;
		$category->term_id = 1;

		// Categories DO exist in the store — so the omission below
		// is specifically due to the mode+empty-selection combo.
		Functions\when( 'get_terms' )->justReturn( [ $category ] );

		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [],
			'selected_tags'          => [],
			'selected_brands'        => [],
		];

		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '## Product Categories', $output );
		$this->assertStringNotContainsString( 'Clothing', $output );
	}

	/**
	 * `by_taxonomy` mode with a POPULATED `selected_categories`
	 * emits the Product Categories section, scoped to the selected
	 * term IDs via the `include` arg. This is the 0.1.5 replacement
	 * for the pre-0.1.5 `categories` mode happy-path (which now
	 * routes through the legacy-mode fallback into the same code).
	 */
	public function test_llms_txt_categories_section_emits_in_by_taxonomy_mode_with_selected_categories(): void {
		$category          = new stdClass();
		$category->name    = 'Coffee Beans';
		$category->slug    = 'coffee-beans';
		$category->count   = 3;
		$category->term_id = 5;

		Functions\when( 'get_terms' )->justReturn( [ $category ] );

		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 5 ],
		];

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Product Categories', $output );
		$this->assertStringContainsString( 'Coffee Beans', $output );
	}

	/**
	 * `by_taxonomy` with only tags and/or brands selected (no
	 * categories) suppresses the Product Categories section even
	 * though the scope IS non-empty. Rationale: the selected
	 * tags/brands narrow within some set of categories, but we
	 * don't know which, so listing top-N-by-count would
	 * over-report (products the tags/brands exclude) and listing
	 * nothing is more truthful than a wrong list.
	 */
	public function test_llms_txt_categories_section_suppressed_when_only_tags_brands_selected(): void {
		$category          = new stdClass();
		$category->name    = 'Clothing';
		$category->slug    = 'clothing';
		$category->count   = 42;
		$category->term_id = 1;

		Functions\when( 'get_terms' )->justReturn( [ $category ] );

		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [],
			'selected_tags'          => [ 7 ],
			'selected_brands'        => [ 3 ],
		];

		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '## Product Categories', $output );
		$this->assertStringNotContainsString( 'Clothing', $output );
	}

	/**
	 * `categories` mode with a POPULATED `selected_categories`
	 * exercises the `$args['include']` branch — the term query is
	 * scoped to the merchant's specific picks. Pin that the
	 * selected IDs are passed through as `include`, not ignored or
	 * combined with top-N-by-count.
	 */
	public function test_categories_mode_passes_selected_ids_to_get_terms_via_include(): void {
		$captured_args = null;
		Functions\when( 'get_terms' )->alias(
			static function ( $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return [];
			}
		);

		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'categories',
			'selected_categories'    => [ 5, 12, 99 ],
		];

		$this->llms->generate();

		$this->assertIsArray( $captured_args );
		$this->assertArrayHasKey( 'include', $captured_args );
		$this->assertEquals( [ 5, 12, 99 ], $captured_args['include'] );
		// `absint()` sanitization is applied inside the function;
		// confirm IDs pass through unchanged when already ints.
		$this->assertEquals(
			0,
			$captured_args['number'],
			'When the merchant specifies categories explicitly, number=0 disables the top-N cap.'
		);
	}

	// ------------------------------------------------------------------
	// Filter extensibility
	// ------------------------------------------------------------------

	public function test_output_is_filterable_via_lines_hook(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $lines, $settings ) {
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
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$set_transient_called_with ) {
				if ( WC_AI_Storefront_Llms_Txt::CACHE_KEY === $key ) {
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
		$main_cache_writes = 0;
		Functions\when( 'set_transient' )->alias(
			static function ( $key ) use ( &$main_cache_writes ) {
				if ( WC_AI_Storefront_Llms_Txt::CACHE_KEY === $key ) {
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

		$this->assertStringContainsString( '## Sitemaps', $output );
		$this->assertStringContainsString( '- https://example.com/sitemap.xml', $output );
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

		$this->assertStringContainsString( '- https://example.com/sitemap.xml', $output );
		$this->assertStringNotContainsString( '/sitemap_index.xml', $output );
		$this->assertStringNotContainsString( '/news-sitemap.xml', $output );
	}

	// ------------------------------------------------------------------
	// UCP extension docs
	// ------------------------------------------------------------------

	public function test_llms_txt_includes_ucp_extension_section(): void {
		// The UCP manifest advertises the merchant-extension capability
		// with a `spec` URL pointing at `/llms.txt#ucp-extension`. This
		// test locks in that the anchor is present + the section is
		// actually rendered so the manifest's URL resolves.
		$output = $this->llms->generate();

		$this->assertStringContainsString( '<a id="ucp-extension"></a>', $output );
		$this->assertStringContainsString( '## UCP Extension: com.woocommerce.ai_storefront', $output );
	}

	public function test_llms_txt_extension_section_points_at_schema_endpoint(): void {
		// The human-readable section should reference the machine-
		// readable schema endpoint so agents that want to validate
		// the payload can find it from the text docs.
		$output = $this->llms->generate();

		$this->assertStringContainsString( '/wp-json/wc/ucp/v1/extension/schema', $output );
	}

	public function test_llms_txt_extension_section_does_not_duplicate_schema_fields(): void {
		// The `### config.store_context` sub-section was REMOVED in
		// the 0.1.2 declutter. Its five bullets (currency, locale,
		// country, prices_include_tax, shipping_enabled) fully
		// duplicated the JSON Schema at the linked schema URL — an
		// agent that wants field-level detail reads the machine-
		// readable schema, not a hand-maintained copy that can drift.
		//
		// This test pins the removal so a future PR doesn't
		// reintroduce the section by copy-paste from an older
		// revision. Also explicitly asserts `### Product-level
		// extension payload` didn't sneak back — that section
		// documented the ABSENCE of fields, which served no agent
		// purpose.
		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '### config.store_context', $output );
		$this->assertStringNotContainsString( '### Product-level extension payload', $output );
		// The schema URL MUST still be present — that's the whole
		// point of the section now.
		$this->assertStringContainsString(
			'/wp-json/wc/ucp/v1/extension/schema',
			$output
		);
	}

	public function test_llms_txt_extension_section_does_not_document_attribution_subkey(): void {
		// Attribution is covered by the main "Attribution for AI
		// agents" section earlier in the document (hostname→brand
		// table + fallback URL templates). The extension section
		// itself should NOT carry a `### config.attribution`
		// sub-heading — the machine-readable `config.attribution`
		// block was removed from the manifest because server-side
		// `continue_url` already injects utm_source + utm_medium,
		// and duplicating UTM conventions here implied the manifest
		// was the canonical source when it wasn't. If a future
		// refactor re-adds this heading, this test fires.
		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '### config.attribution', $output );
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
}
