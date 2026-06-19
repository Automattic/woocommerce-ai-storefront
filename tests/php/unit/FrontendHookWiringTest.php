<?php
/**
 * Structural guards for the orchestrator's frontend hook wiring.
 *
 * Both former body-visible anchors are gone: the /llms.txt discovery anchor
 * (#491) and the per-product checkout anchor (#496) were removed as intrusive
 * to shoppers. The /llms.txt advertisement is now a machine-only `Link` HTTP
 * header (`send_discovery_link_header` on `send_headers`) — this guard pins
 * that registration so it can't be silently dropped. And nothing the
 * orchestrator wires may sit on `wp_footer`.
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

	public function test_discovery_link_header_registers_on_send_headers(): void {
		// The /llms.txt advertisement is a machine-only `Link` HTTP header (no
		// visible body anchor). Pin its registration so it can't be dropped.
		$this->assertMatchesRegularExpression(
			'/add_action\(\s*[\'"]send_headers[\'"]\s*,[^)]*[\'"]send_discovery_link_header[\'"]/',
			$this->orchestrator_file,
			'send_discovery_link_header must register on send_headers.'
		);
	}

	public function test_orchestrator_registers_nothing_on_wp_footer(): void {
		// The directive: nothing the orchestrator wires belongs in the footer.
		// The only legitimate wp_footer consumer (the WC structured-data
		// <script> swap) lives in the JsonLd class and is exempt for the reasons
		// in this file's docblock. Any future wp_footer registration added HERE
		// must justify itself by updating this guard.
		preg_match_all( '/add_action\(\s*[\'"]wp_footer[\'"]/', $this->orchestrator_file, $matches );
		$this->assertCount(
			0,
			$matches[0],
			'The orchestrator must register nothing on wp_footer.'
		);
	}
}
