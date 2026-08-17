<?php
/**
 * Guards against docblocks that document nothing.
 *
 * Adding a member ABOVE an existing one is easy to get wrong: the new member
 * lands after the existing member's docblock rather than before it. The
 * result is two docblocks in a row — the first attaches to nothing, the
 * second attaches to the wrong member, and the member the first was written
 * for ends up undocumented.
 *
 * It is invisible in a diff, because the diff shows a docblock and a member
 * adjacent and correct-looking. And nothing else catches it:
 *
 *   - phpcs does not. `phpcs.xml.dist` does not enable
 *     `Squiz.Commenting.FunctionComment.Missing`.
 *   - PHPStan does not. Worse, an orphaned `@return` attached to a constant
 *     is never checked against the method it was written for, so a stale
 *     return type goes unverified — which is exactly what happened in #640,
 *     where an orphan claimed `array<string, int>` after the shape had
 *     gained a `string[]` member.
 *
 * It happened four times before this test existed (#641): `all_zones()`,
 * `add_handling_time()`, `WC_AI_Storefront_Handling_Time::sanitize()`,
 * `WC_AI_Storefront::get_settings()`, and two in the JSON-LD emitter.
 *
 * @package WooCommerce_AI_Storefront
 */

class DocblockAttachmentTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Vendored code we do not control.
	 *
	 * @var string[]
	 */
	private const EXCLUDED = array( 'plugin-update-checker' );

	public function test_no_docblock_documents_nothing(): void {
		$orphans = array();

		foreach ( $this->plugin_php_files() as $file ) {
			$source = file_get_contents( $file );
			if ( false === $source ) {
				continue;
			}

			// A docblock whose next non-whitespace content is another
			// docblock. The first one cannot be attached to anything.
			if ( ! preg_match_all( '#\*/\s*\n\s*/\*\*#', $source, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[0] as $match ) {
				$line      = substr_count( substr( $source, 0, $match[1] ), "\n" ) + 1;
				$relative  = str_replace( dirname( __DIR__, 3 ) . '/', '', $file );
				$orphans[] = sprintf( '%s:%d', $relative, $line );
			}
		}

		$this->assertSame(
			array(),
			$orphans,
			"Docblock(s) documenting nothing — the member they were written for is undocumented.\n"
			. "This happens when a member is added ABOVE an existing one and lands after that\n"
			. "member's docblock instead of before it. Move the stranded block down to sit\n"
			. "directly above the member it describes.\n\nFound at:\n  "
			. implode( "\n  ", $orphans )
		);
	}

	/**
	 * Every PHP file the plugin ships, minus vendored trees.
	 *
	 * @return string[]
	 */
	private function plugin_php_files(): array {
		$root  = dirname( __DIR__, 3 ) . '/includes';
		$files = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $iterator as $entry ) {
			if ( ! $entry->isFile() || 'php' !== $entry->getExtension() ) {
				continue;
			}
			$path = $entry->getPathname();
			foreach ( self::EXCLUDED as $skip ) {
				if ( false !== strpos( $path, $skip ) ) {
					continue 2;
				}
			}
			$files[] = $path;
		}

		return $files;
	}
}
