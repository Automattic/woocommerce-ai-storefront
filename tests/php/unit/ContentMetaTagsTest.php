<?php
/**
 * Tests for the non-commerce social metadata fallback (#680).
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class ContentMetaTagsTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Content_Meta_Tags $tags;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'strip_shortcodes' )->returnArg();

		// Nothing else is emitting, and this is a singular post.
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_product_category' )->justReturn( false );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );

		Functions\when( 'get_queried_object_id' )->justReturn( 7 );
		Functions\when( 'get_the_title' )->justReturn( 'Hello world' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/hello-world/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_the_excerpt' )->justReturn( 'A short post about hoodies.' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );

		$this->tags = new WC_AI_Storefront_Content_Meta_Tags();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		$this->tags->render();
		return (string) ob_get_clean();
	}

	// --- The gate ---

	public function test_emits_on_a_singular_post_when_nothing_else_does(): void {
		$this->assertTrue( $this->tags->should_emit() );
	}

	public function test_stays_silent_on_a_commerce_page(): void {
		// The commerce emitter owns these, and has since before this existed.
		Functions\when( 'is_product' )->justReturn( true );
		$this->assertFalse( $this->tags->should_emit() );
	}

	public function test_stays_silent_on_the_shop_when_it_is_the_front_page(): void {
		// The one page where the two boundaries touch: it is commerce, so it
		// belongs to the other emitter even though it is also the front page.
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( true );
		$this->assertFalse( $this->tags->should_emit() );
	}

	public function test_stays_silent_on_an_archive(): void {
		// Singular only. A generated description for an author or date
		// archive is worse than none (#680).
		Functions\when( 'is_singular' )->justReturn( false );
		$this->assertFalse( $this->tags->should_emit() );
	}

	public function test_stays_silent_when_an_seo_plugin_is_present(): void {
		// Presence-based and deliberately coarse: a false negative leaves a
		// blank card, a false positive puts duplicate tags on a page this
		// plugin has never touched. Err toward silence (#680).
		$this->assertFalse( $this->tags->should_emit( array( 'yoast' ) ) );
		$this->assertFalse( $this->tags->should_emit( array( 'seopress' ) ) );
	}

	public function test_jetpack_being_active_is_not_enough_to_silence_us(): void {
		// The issue's own repro is a store with Jetpack active and its Open
		// Graph OFF. Treating presence as disqualifying would mean the fix
		// never fires on the store that demonstrated the bug (#680).
		$this->assertTrue( $this->tags->should_emit( array( 'jetpack' ) ) );
	}

	public function test_jetpack_open_graph_being_on_does_silence_us(): void {
		// Same signal suppress_jetpack_open_graph() keys on, and it is
		// registered by wp_head:1 — before this runs at wp_head:5.
		Functions\when( 'has_action' )->alias(
			static function ( $hook, $callback = false ) {
				return 'wp_head' === $hook && 'jetpack_og_tags' === $callback;
			}
		);
		$this->assertFalse( $this->tags->should_emit( array( 'jetpack' ) ) );
	}

	public function test_the_master_filter_still_wins(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'wc_ai_storefront_emit_meta_tags' === $hook ? false : $value;
			}
		);
		$this->assertFalse( $this->tags->should_emit() );
	}

	public function test_stays_silent_when_the_plugin_is_off(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no' );
		$this->assertFalse( $this->tags->should_emit() );
	}

	// --- What it emits ---

	public function test_a_post_gets_the_six_core_properties(): void {
		$html = $this->render();

		$this->assertStringContainsString( '<meta property="og:type" content="article"', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="Hello world"', $html );
		$this->assertStringContainsString( '<meta property="og:description" content="A short post about hoodies."', $html );
		$this->assertStringContainsString( '<meta property="og:url" content="https://shop.test/hello-world/"', $html );
		$this->assertStringContainsString( '<meta property="og:site_name" content="Saltwarp"', $html );
		$this->assertStringContainsString( '<meta property="og:locale" content="en_US"', $html );
	}

	public function test_og_type_is_article_which_the_spec_actually_defines(): void {
		// Unlike `product`, `article` is in the ogp.me vocabulary, so this
		// one carries no grey area (#680).
		$this->assertStringContainsString( 'content="article"', $this->render() );
	}

	public function test_a_post_gets_a_meta_description_too(): void {
		// Same hole from the same cause, and the same gate covers it.
		$this->assertStringContainsString( '<meta name="description" content="A short post about hoodies."', $this->render() );
	}

	public function test_the_twitter_card_follows_the_image(): void {
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary"', $this->render() );

		Functions\when( 'get_post_thumbnail_id' )->justReturn( 42 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( array( 'https://shop.test/hero.jpg', 1200, 630, false ) );

		$html = $this->render();
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image"', $html );
		$this->assertStringContainsString( '<meta property="og:image" content="https://shop.test/hero.jpg"', $html );
		$this->assertStringContainsString( '<meta name="twitter:image" content="https://shop.test/hero.jpg"', $html );
	}

	public function test_no_article_timestamps_are_emitted(): void {
		// Explicitly out of scope: article:published_time, :modified_time,
		// :author and profile:* belong to a general-purpose SEO plugin, and
		// #679 excluded them from the commerce path for the same reason.
		$html = $this->render();

		$this->assertStringNotContainsString( 'article:published_time', $html );
		$this->assertStringNotContainsString( 'article:modified_time', $html );
		$this->assertStringNotContainsString( 'article:author', $html );
		$this->assertStringNotContainsString( 'profile:', $html );
	}

	public function test_a_post_with_no_excerpt_falls_back_to_its_content(): void {
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( '<p>The full body of the post, which is long enough to matter.</p>' );

		$this->assertStringContainsString( 'The full body of the post', $this->render() );
	}

	public function test_a_post_with_nothing_to_say_emits_no_description(): void {
		// Better a card with a title and no description than one repeating
		// the site tagline on every post.
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( '&nbsp;' );

		$html = $this->render();
		$this->assertStringNotContainsString( 'og:description', $html );
		$this->assertStringContainsString( 'og:title', $html, 'The rest of the card still ships.' );
	}

	public function test_a_post_of_pure_punctuation_gets_no_description(): void {
		// Separates the two gates: a bullet rule cleans to something
		// non-empty, so only the readable-prose check rejects it. Publishing
		// it would put "•••" in the SERP snippet.
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( '••• ••• •••' );

		$html = $this->render();
		$this->assertStringNotContainsString( '•', $html );
		$this->assertStringContainsString( 'og:title', $html );
	}

	public function test_render_emits_nothing_when_the_gate_is_closed(): void {
		Functions\when( 'is_product' )->justReturn( true );
		$this->assertSame( '', $this->render() );
	}
}
