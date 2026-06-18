<?php
/**
 * Structural guards for the orchestrator's frontend hook wiring.
 *
 * The body-visible discovery surfaces — the per-product checkout anchor
 * (`render_product_checkout_links`) and the /llms.txt discovery anchor
 * (`render_discovery_link`) — MUST register on `wp_body_open`, never
 * `wp_footer`. Both emit visible text/anchors that markdown-extraction fetch
 * tools keep but truncate: on a long archive page (most visibly the
 * front-page shop, ~100+ products) a `wp_footer` registration sits past the
 * truncation cut and never reaches the agent. The render methods' behavior
 * tests invoke them directly, so a silent revert to `wp_footer` would pass
 * every other test — this structural guard is the only thing that catches it.
 *
 * Like ActivationTest, this reads the orchestrator source as text: the
 * bootstrap (`init_components()` / `register_rewrite_rules()`) news up a dozen
 * collaborators and can't be invoked in isolation, so the registration is
 * asserted structurally rather than by spying `add_action` through a full
 * bootstrap.
 *
 * NOTE: the WC structured-data swap (`output_wc_structured_data`) deliberately
 * stays on `wp_footer` and is intentionally exempt — it re-serializes
 * `WC()->structured_data`, which WooCommerce accumulates DURING render and is
 * only complete by footer time, and it emits `<script>` JSON-LD that
 * extraction tools strip regardless of position. That registration lives in
 * the JsonLd class, not the orchestrator, so it is out of scope here.
 *
 * @package WooCommerce_AI_Storefront
 */

class FrontendHookWiringTest extends \PHPUnit\Framework\TestCase {

	private string $orchestrator_file;

	protected function setUp(): void {
		parent::setUp();
		$this->orchestrator_file = file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/class-wc-ai-storefront.php'
		);
	}

	public function test_checkout_anchor_registers_on_wp_body_open(): void {
		// The per-product checkout anchor (BuyAction's visible body counterpart)
		// must render above the truncation cut on long single-product pages.
		$this->assertMatchesRegularExpression(
			'/add_action\(\s*[\'"]wp_body_open[\'"]\s*,[^)]*[\'"]render_product_checkout_links[\'"]/',
			$this->orchestrator_file,
			'render_product_checkout_links must register on wp_body_open.'
		);
	}

	public function test_discovery_link_registers_on_wp_body_open(): void {
		// The /llms.txt discovery anchor bootstraps the whole discovery chain;
		// on the front-page shop a wp_footer anchor sat past the truncation cut.
		$this->assertMatchesRegularExpression(
			'/add_action\(\s*[\'"]wp_body_open[\'"]\s*,[^)]*[\'"]render_discovery_link[\'"]/',
			$this->orchestrator_file,
			'render_discovery_link must register on wp_body_open.'
		);
	}

	public function test_orchestrator_registers_nothing_on_wp_footer(): void {
		// The directive: nothing the orchestrator wires belongs in the footer.
		// Body-visible discovery surfaces go on wp_body_open; the only legitimate
		// wp_footer consumer (the WC structured-data <script> swap) lives in the
		// JsonLd class and is exempt for the reasons in this file's docblock. Any
		// future wp_footer registration added HERE must move to wp_body_open or
		// justify itself by updating this guard.
		preg_match_all( '/add_action\(\s*[\'"]wp_footer[\'"]/', $this->orchestrator_file, $matches );
		$this->assertCount(
			0,
			$matches[0],
			'The orchestrator must register nothing on wp_footer; body-visible ' .
			'discovery surfaces belong on wp_body_open so they survive truncation.'
		);
	}
}
