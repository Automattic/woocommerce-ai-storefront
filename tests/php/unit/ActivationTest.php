<?php
/**
 * Tests for the plugin activation/deactivation lifecycle.
 *
 * These tests enforce structural invariants that can't be caught at
 * the unit-behavior level — specifically, that the activation hook
 * does NOT pre-write the version option, which would short-circuit
 * the main class's version-mismatch cache-bust branch.
 *
 * This whole file exists because of a real regression shipped in
 * 1.0.0 → 1.1.x where `wc_ai_storefront_activate()` called
 * `update_option( 'wc_ai_storefront_version', ... )`. On in-place
 * zip upgrades WordPress fires the activation hook, so the option
 * was written to the new version before the mismatch-detection code
 * ever ran — meaning the content cache was never busted and
 * merchants saw stale llms.txt / UCP content until the hourly
 * transient TTL expired (or forever if invalidation never fired).
 *
 * @package WooCommerce_AI_Storefront
 */

class ActivationTest extends \PHPUnit\Framework\TestCase {

	private string $main_file;
	private string $orchestrator_file;

	protected function setUp(): void {
		parent::setUp();
		$this->main_file = file_get_contents(
			dirname( __DIR__, 3 ) . '/woocommerce-ai-syndication.php'
		);
		$this->orchestrator_file = file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/class-wc-ai-storefront.php'
		);
	}

	// ------------------------------------------------------------------
	// The regression guard — the reason this test file exists
	// ------------------------------------------------------------------

	public function test_activation_hook_does_not_write_version_option(): void {
		// Extract the body of wc_ai_storefront_activate() and assert
		// it contains no `update_option( 'wc_ai_storefront_version'...`
		// call.
		//
		// If this test fails, the activation hook has been modified to
		// write the version marker. That will short-circuit the
		// version-mismatch cache-bust branch on in-place upgrades.
		// See the comment in the activation function for full history.
		$activate_body = $this->extract_function_body(
			$this->main_file,
			'wc_ai_storefront_activate'
		);

		$this->assertNotEmpty(
			$activate_body,
			'Could not locate wc_ai_storefront_activate() body.'
		);

		// The check: no call to update_option (in any form) mentioning
		// the version key. Matches both single and double quotes.
		$this->assertDoesNotMatchRegularExpression(
			'/update_option\s*\(\s*[\'"]wc_ai_storefront_version[\'"]/',
			$activate_body,
			'The activation hook writes the version option directly. ' .
			'This defeats the boot-time cache-bust branch on in-place ' .
			'upgrades. Let the main class handle version detection.'
		);
	}

	public function test_version_option_is_written_from_exactly_one_location(): void {
		// Structural guarantee: only the boot-time version-mismatch
		// branch writes this option. Any other write would fragment the
		// invariant that "stored_version changes EXACTLY when the
		// cache-bust branch runs."
		$write_count = preg_match_all(
			'/update_option\s*\(\s*[\'"]wc_ai_storefront_version[\'"]/',
			$this->main_file . "\n" . $this->orchestrator_file
		);

		$this->assertEquals(
			1,
			$write_count,
			sprintf(
				'Expected exactly one write to wc_ai_storefront_version; ' .
				'found %d. Every writer must fire within the cache-bust ' .
				'branch or merchants will see stale cached content after ' .
				'upgrades.',
				$write_count
			)
		);
	}

	public function test_mismatch_branch_writes_version_after_cache_clear(): void {
		// Order matters inside the branch. If `update_option` fires
		// BEFORE `delete_transient` (or after the flush-rewrite-rules
		// call), a race or refactor mistake could leave the version
		// marker written while the transient persists. Not a live bug
		// today, but a structural test prevents future drift.
		$branch = $this->extract_version_mismatch_branch();

		$option_pos = strpos( $branch, 'update_option' );
		$delete_pos = strpos( $branch, 'delete_transient' );

		$this->assertNotFalse( $option_pos, 'Cache-bust branch missing update_option call.' );
		$this->assertNotFalse( $delete_pos, 'Cache-bust branch missing delete_transient call.' );

		// The delete_transient for the flush flag happens first, then
		// the version is written, then the content caches are deleted.
		// The important invariant: update_option is NOT the last thing
		// to fire — content-cache deletes come after.
		$llms_delete_pos = strpos( $branch, 'WC_AI_Storefront_Llms_Txt::CACHE_KEY' );
		$this->assertNotFalse(
			$llms_delete_pos,
			'Cache-bust branch missing llms.txt transient delete.'
		);
		$this->assertGreaterThan(
			$option_pos,
			$llms_delete_pos,
			'Content-cache deletes must run AFTER update_option, ' .
			'otherwise a second request can repopulate the cache ' .
			'before the version check would see the mismatch.'
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Extract the body of a named top-level function from PHP source.
	 * Returns everything between the opening `{` and matching `}`.
	 */
	private function extract_function_body( string $source, string $function_name ): string {
		// Find `function <name>(...) {` and match braces to find the close.
		if ( ! preg_match(
			'/function\s+' . preg_quote( $function_name, '/' ) . '\s*\([^)]*\)\s*\{/',
			$source,
			$matches,
			PREG_OFFSET_CAPTURE
		) ) {
			return '';
		}

		$start = $matches[0][1] + strlen( $matches[0][0] );
		$depth = 1;
		$pos   = $start;
		$len   = strlen( $source );

		while ( $pos < $len && $depth > 0 ) {
			$ch = $source[ $pos ];
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
			}
			$pos++;
		}

		return substr( $source, $start, $pos - $start - 1 );
	}

	/**
	 * Extract the body of the version-mismatch branch from the main
	 * orchestrator file. The branch is the `if ( $needs_flush || ... )
	 * { ... }` block inside register_rewrite_rules().
	 */
	private function extract_version_mismatch_branch(): string {
		if ( ! preg_match(
			'/if\s*\(\s*\$needs_flush\s*\|\|\s*\$stored_version\s*!==\s*WC_AI_STOREFRONT_VERSION\s*\)\s*\{/',
			$this->orchestrator_file,
			$matches,
			PREG_OFFSET_CAPTURE
		) ) {
			return '';
		}

		$start = $matches[0][1] + strlen( $matches[0][0] );
		$depth = 1;
		$pos   = $start;
		$len   = strlen( $this->orchestrator_file );

		while ( $pos < $len && $depth > 0 ) {
			$ch = $this->orchestrator_file[ $pos ];
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
			}
			$pos++;
		}

		return substr( $this->orchestrator_file, $start, $pos - $start - 1 );
	}
}
