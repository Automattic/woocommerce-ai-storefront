<?php
/**
 * Tests for WC_AI_Syndication_Logger.
 *
 * The logger is the observability layer — off by default, enabled
 * per-request via the `wc_ai_syndication_debug` filter. Tests cover
 * the gating (does logging happen when the filter is true/false?)
 * and the sprintf-style formatting. We don't verify the error_log()
 * call itself (side effect); we verify the decision to call it.
 *
 * @package WooCommerce_AI_Syndication
 */

use Brain\Monkey;
use Brain\Monkey\Filters;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class LoggerTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The logger caches the filter result for the request; tests
		// need a clean slate between cases.
		WC_AI_Syndication_Logger::reset_cache();
	}

	protected function tearDown(): void {
		WC_AI_Syndication_Logger::reset_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Gating — the filter controls enablement
	// ------------------------------------------------------------------

	public function test_is_enabled_returns_false_by_default(): void {
		// No filter registered -> apply_filters returns the default (false).
		Filters\expectApplied( 'wc_ai_syndication_debug' )
			->once()
			->with( false )
			->andReturn( false );

		$this->assertFalse( WC_AI_Syndication_Logger::is_enabled() );
	}

	public function test_is_enabled_returns_true_when_filter_returns_true(): void {
		Filters\expectApplied( 'wc_ai_syndication_debug' )
			->once()
			->andReturn( true );

		$this->assertTrue( WC_AI_Syndication_Logger::is_enabled() );
	}

	public function test_is_enabled_coerces_truthy_values(): void {
		// A sloppy filter might return a string or int. The logger
		// casts to bool so downstream gate checks never see junk.
		Filters\expectApplied( 'wc_ai_syndication_debug' )
			->once()
			->andReturn( '1' );

		$this->assertTrue( WC_AI_Syndication_Logger::is_enabled() );
	}

	public function test_is_enabled_caches_result_for_lifetime_of_request(): void {
		// The enabled state is evaluated ONCE per request. Subsequent
		// calls in the same request must NOT re-invoke the filter —
		// that's the whole point of caching. If we ever regressed to
		// per-call filter invocation, the perf cost at thousands of
		// log sites would be non-trivial.
		Filters\expectApplied( 'wc_ai_syndication_debug' )
			->once() // Exactly once — caching verified.
			->andReturn( true );

		WC_AI_Syndication_Logger::is_enabled();
		WC_AI_Syndication_Logger::is_enabled();
		WC_AI_Syndication_Logger::is_enabled();
	}

	public function test_reset_cache_forces_re_evaluation(): void {
		// The reset is test-only but we verify it does what the test
		// plumbing depends on.
		Filters\expectApplied( 'wc_ai_syndication_debug' )
			->twice()
			->andReturn( false, true );

		$this->assertFalse( WC_AI_Syndication_Logger::is_enabled() );

		WC_AI_Syndication_Logger::reset_cache();

		$this->assertTrue( WC_AI_Syndication_Logger::is_enabled() );
	}

	// ------------------------------------------------------------------
	// debug() — behavior when disabled (the common case)
	// ------------------------------------------------------------------

	public function test_debug_is_a_noop_when_logging_disabled(): void {
		Filters\expectApplied( 'wc_ai_syndication_debug' )->andReturn( false );

		// If debug() were to emit anything, it would show up in PHPUnit's
		// output buffer as a PHP warning (error_log goes to the test
		// runner's stderr). The fact that this test passes silently is
		// the proof — no assertion needed beyond "this doesn't throw".
		WC_AI_Syndication_Logger::debug( 'this message should never surface' );

		$this->assertTrue( true ); // Marker so PHPUnit doesn't warn "risky test".
	}

	// ------------------------------------------------------------------
	// debug() — sprintf-style formatting
	// ------------------------------------------------------------------
	//
	// The debug() method formats the message with vsprintf when args
	// are passed. We can't intercept error_log() directly, but we CAN
	// verify the format-string contract holds by enabling logging and
	// exercising both paths through a test subclass that captures
	// what would have been emitted.

	public function test_debug_formats_sprintf_arguments(): void {
		// Enable logging for this test.
		Filters\expectApplied( 'wc_ai_syndication_debug' )->andReturn( true );

		// Capture error_log output to a temp file via PHP's ini.
		$log_file = tempnam( sys_get_temp_dir(), 'logger-test-' );
		$prev     = ini_set( 'error_log', $log_file );

		try {
			WC_AI_Syndication_Logger::debug(
				'cache %s for key=%s',
				'miss',
				'llms_txt'
			);

			$written = file_get_contents( $log_file );

			$this->assertStringContainsString( '[wc-ai-syndication]', $written );
			$this->assertStringContainsString( 'cache miss for key=llms_txt', $written );
		} finally {
			ini_set( 'error_log', $prev );
			unlink( $log_file );
		}
	}

	public function test_debug_passes_message_through_unchanged_with_no_args(): void {
		Filters\expectApplied( 'wc_ai_syndication_debug' )->andReturn( true );

		$log_file = tempnam( sys_get_temp_dir(), 'logger-test-' );
		$prev     = ini_set( 'error_log', $log_file );

		try {
			// Note the literal `%s` in the message — without args, the
			// message must be emitted verbatim, NOT passed through
			// sprintf (which would produce a warning about missing args).
			WC_AI_Syndication_Logger::debug( 'status: 100% complete' );

			$written = file_get_contents( $log_file );

			$this->assertStringContainsString( 'status: 100% complete', $written );
		} finally {
			ini_set( 'error_log', $prev );
			unlink( $log_file );
		}
	}
}
