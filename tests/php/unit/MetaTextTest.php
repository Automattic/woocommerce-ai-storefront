<?php
/**
 * Tests for the shared text helpers.
 *
 * These were private to WC_AI_Storefront_Meta_Tags and tested only through
 * whichever caller happened to exercise them. That was adequate while the
 * caller WAS the contract; it stopped being adequate the moment a second
 * emitter shared them (#680 review). The class docblock argues that two
 * copies would drift and "the copy that drifted would be the one nobody had
 * measured" — this file is the measurement.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class MetaTextTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// strip_shortcodes() only removes REGISTERED tags, which is the whole
		// reason clean_text() has its own remnant pass. Identity here mirrors
		// a site where the shortcode's plugin is gone.
		Functions\when( 'strip_shortcodes' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider non_prose_provider
	 */
	public function test_non_prose_cleans_to_nothing( string $raw, string $why ): void {
		$this->assertSame( '', WC_AI_Storefront_Meta_Text::clean_text( $raw ), $why );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function non_prose_provider(): array {
		return array(
			'entity nbsp'       => array( '&nbsp;', 'The block editor stores its empty paragraph as this.' ),
			'raw nbsp'          => array( "\xC2\xA0", 'Two bytes that trim() does not touch.' ),
			'zero width space'  => array( "\xE2\x80\x8B", 'Invisible and not ASCII whitespace.' ),
			'unregistered code' => array( '[some_slider id="3"]', 'Left by a deactivated plugin.' ),
			'closing shortcode' => array( '[vc_row][/vc_row]', 'Both halves of the pair.' ),
			'block comment'     => array( '<!-- wp:woocommerce/product-collection /-->', 'Markup with no prose.' ),
			'editor empty para' => array( '<!-- wp:paragraph --><p>&nbsp;</p><!-- /wp:paragraph -->', 'Open, press Enter, leave.' ),
		);
	}

	public function test_real_prose_survives_every_pass(): void {
		$this->assertSame(
			'Handmade leather goods.',
			WC_AI_Storefront_Meta_Text::clean_text( '[vc_row]<p>Handmade&nbsp;leather goods.</p>[/vc_row]' )
		);
	}

	public function test_accented_copy_is_not_mangled(): void {
		// Get the entity-decode charset wrong and `&eacute;` becomes a lone
		// 0xE9, which makes the /u pass return NULL.
		$this->assertSame(
			'Café blend — small batch roasting.',
			WC_AI_Storefront_Meta_Text::clean_text( '<p>Caf&eacute; blend &mdash; small batch roasting.</p>' )
		);
	}

	public function test_mis_encoded_input_is_kept_not_discarded(): void {
		// A /u pattern returns NULL when the SUBJECT is invalid UTF-8, and
		// (string) null is ''. Mojibake from an old latin-1 import must not
		// silently delete the merchant's copy.
		$cleaned = WC_AI_Storefront_Meta_Text::clean_text( "Caf\xE9 blend" );

		$this->assertNotSame( '', $cleaned );
		$this->assertStringContainsString( 'blend', $cleaned );
	}

	public function test_readable_prose_rejects_punctuation_but_accepts_digits(): void {
		// This is the gap clean_text() does not close: text that cleans to
		// something and still is not prose.
		$this->assertFalse( WC_AI_Storefront_Meta_Text::is_readable_prose( '••• ••• •••' ) );
		$this->assertFalse( WC_AI_Storefront_Meta_Text::is_readable_prose( '' ) );
		$this->assertTrue( WC_AI_Storefront_Meta_Text::is_readable_prose( '2026' ), 'A digit is prose enough.' );
		$this->assertTrue( WC_AI_Storefront_Meta_Text::is_readable_prose( 'こんにちは' ), 'Any script, not just Latin.' );
	}

	public function test_readable_prose_keeps_mis_encoded_text(): void {
		// preg_match() returns false, not 0, on invalid UTF-8. Treating that
		// as junk would discard merchant copy the fold deliberately kept.
		$this->assertTrue( WC_AI_Storefront_Meta_Text::is_readable_prose( "Caf\xE9 blend" ) );
	}

	public function test_truncate_cuts_on_a_word_boundary(): void {
		$this->assertSame(
			'Heavyweight fourteen…',
			WC_AI_Storefront_Meta_Text::truncate( 'Heavyweight fourteen ounce French terry', 25 )
		);
	}

	public function test_truncate_leaves_short_text_alone(): void {
		$this->assertSame( 'Short.', WC_AI_Storefront_Meta_Text::truncate( 'Short.', 155 ) );
	}

	public function test_truncate_measures_characters_not_bytes(): void {
		// mb_strlen, so an accented string is not cut short of the limit.
		$text = str_repeat( 'é', 20 );

		$this->assertSame( $text, WC_AI_Storefront_Meta_Text::truncate( $text, 20 ) );
	}

	public function test_truncate_handles_a_single_long_word(): void {
		// No space to cut at. The guard keeps this from returning a bare
		// ellipsis, which is what the docblock's "word boundary" glosses.
		$this->assertSame(
			'Donaudampfschiff…',
			WC_AI_Storefront_Meta_Text::truncate( 'Donaudampfschifffahrtsgesellschaft', 16 )
		);
	}

	public function test_og_locale_normalises_a_wordpress_locale(): void {
		Functions\when( 'get_locale' )->justReturn( 'de_DE_formal' );
		$this->assertSame( 'de_DE', WC_AI_Storefront_Meta_Text::og_locale() );
	}

	public function test_og_locale_accepts_a_bcp47_hyphen_form(): void {
		Functions\when( 'get_locale' )->justReturn( 'pt-BR' );
		$this->assertSame( 'pt_BR', WC_AI_Storefront_Meta_Text::og_locale() );
	}

	public function test_og_locale_falls_back_when_there_is_none(): void {
		Functions\when( 'get_locale' )->justReturn( '' );
		$this->assertSame( 'en_US', WC_AI_Storefront_Meta_Text::og_locale() );
	}
}
