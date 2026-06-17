<?php
/**
 * Tests for User-Agent-derived attribution: the pure classifier
 * WC_AI_Storefront_UCP_Agent_Header::classify_user_agent() and its
 * wiring into the REST/MCP agent resolvers.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpUserAgentAttributionTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/** @var string|null */
	private $original_ua;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? (string) $_SERVER['HTTP_USER_AGENT']
			: null;

		// detect_crawler_from_ua()'s $_SERVER path uses these. wp_parse_url is
		// already defined as a parse_url pass-through in tests/php/stubs.php
		// (loaded before Patchwork, so it cannot be re-aliased via Brain Monkey
		// without triggering a Patchwork\DefinedTooEarly error). Stub only the
		// two functions that are NOT pre-defined in stubs.php.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		if ( null === $this->original_ua ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $this->original_ua;
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---- classify_user_agent() ----

	public function test_classify_maps_chatgpt_user_to_chatgpt(): void {
		$result = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent(
			'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)'
		);

		$this->assertSame( 'ChatGPT', $result['name'] );
		$this->assertSame( 'chatgpt.com', $result['source_host'] );
		$this->assertSame( 'ChatGPT-User', $result['raw_host'] );
	}

	public function test_classify_maps_gptbot_to_chatgpt(): void {
		$result = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( 'GPTBot/1.2' );

		$this->assertSame( 'ChatGPT', $result['name'] );
		$this->assertSame( 'chatgpt.com', $result['source_host'] );
		$this->assertSame( 'GPTBot', $result['raw_host'] );
	}

	public function test_classify_maps_claudebot_to_claude(): void {
		$result = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent(
			'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)'
		);

		$this->assertSame( 'Claude', $result['name'] );
		$this->assertSame( 'claude.ai', $result['source_host'] );
		$this->assertSame( 'ClaudeBot', $result['raw_host'] );
	}

	public function test_classify_maps_perplexitybot_to_perplexity(): void {
		$result = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( 'PerplexityBot/1.0' );

		$this->assertSame( 'Perplexity', $result['name'] );
		$this->assertSame( 'perplexity.ai', $result['source_host'] );
		$this->assertSame( 'PerplexityBot', $result['raw_host'] );
	}

	public function test_classify_returns_null_for_generic_indexer(): void {
		// Bingbot is a generic indexer, deliberately NOT mapped → stays ucp_unknown.
		$this->assertNull(
			WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( 'Mozilla/5.0 (compatible; Bingbot/2.0)' )
		);
	}

	public function test_classify_returns_null_for_plain_browser(): void {
		$this->assertNull(
			WC_AI_Storefront_UCP_Agent_Header::classify_user_agent(
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
			)
		);
	}

	public function test_classify_returns_null_for_empty_ua(): void {
		$this->assertNull( WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( '' ) );
	}

	public function test_classify_never_returns_other_ai(): void {
		// Every mapped token resolves to a real brand, never the Other AI bucket.
		$result = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( 'OAI-SearchBot/1.0' );

		$this->assertNotSame( WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET, $result['name'] );
		$this->assertSame( 'ChatGPT', $result['name'] );
	}

	/**
	 * Every UA_AGENT_HOSTS token must resolve to a real brand (never the
	 * Other AI bucket) with the mapped host and the token as provenance.
	 * Iterating the constant seals the invariant for future additions too.
	 *
	 * @dataProvider provide_mapped_ua_tokens
	 */
	public function test_classify_resolves_every_mapped_token( string $token, string $expected_host ): void {
		$result = WC_AI_Storefront_UCP_Agent_Header::classify_user_agent( "Mozilla/5.0 (compatible; {$token}/1.0)" );

		$this->assertNotNull( $result, "{$token} should classify to a brand" );
		$this->assertNotSame( WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET, $result['name'], "{$token} must not bucket to Other AI" );
		$this->assertNotSame( '', $result['name'] );
		$this->assertSame( $expected_host, $result['source_host'], "{$token} should map to {$expected_host}" );
		$this->assertSame( $token, $result['raw_host'] );
	}

	public static function provide_mapped_ua_tokens(): array {
		$cases = [];
		foreach ( WC_AI_Storefront_UCP_Agent_Header::UA_AGENT_HOSTS as $token => $host ) {
			$cases[ $token ] = [ $token, $host ];
		}
		return $cases;
	}
}
