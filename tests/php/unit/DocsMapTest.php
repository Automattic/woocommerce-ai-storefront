<?php
/**
 * The path-to-docs map must agree with itself (#671).
 *
 * AGENTS.md's table and the MAP array in .github/workflows/docs-followup.yml
 * are meant to be the same list. Nothing checked, and they drifted: a row in
 * one and not the other, a row where the doc lists disagreed, and five code
 * files in neither.
 *
 * The drift is invisible by construction. A MAP key matching no changed file
 * contributes nothing, DOCS stays empty, `impact=false`, and the follow-up
 * job is skipped by its own `if:`. The workflow goes green. A doc path that
 * does not exist is never stat'd — it lands in the prompt and cannot be read.
 * So the automation that exists to catch documentation drift could not report
 * its own, which is how ucp-mcp went unmapped long enough for ARCHITECTURE.md
 * to lose its entry for the module entirely (#664).
 *
 * This lives in the test suite rather than in a new CI job on purpose:
 * `composer test` is already a required gate on every PR, so it cannot be
 * quietly not-run.
 *
 * @package WooCommerce_AI_Storefront
 */

class DocsMapTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Repository root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * The table in AGENTS.md, as path => sorted doc basenames.
	 *
	 * A row is a path cell and a document cell. The path cell holds one or
	 * more backticked paths, with directories written `dir/**`; a cell
	 * naming two paths expands to two entries sharing one document list,
	 * because the workflow's array can only hold one key per entry. The
	 * document cell is a comma-separated list of BASENAMES, each of which
	 * may carry a trailing parenthetical naming a section.
	 *
	 * @return array<string,string[]>
	 */
	private static function agents_map(): array {
		$lines = file( self::root() . '/AGENTS.md', FILE_IGNORE_NEW_LINES );
		$map   = array();

		foreach ( (array) $lines as $line ) {
			if ( 1 !== preg_match( '/^\|\s*((?:`[^`]+`(?:,\s*)?)+)\s*\|\s*(.+?)\s*\|\s*$/', (string) $line, $m ) ) {
				continue;
			}

			$docs = array();
			foreach ( explode( ',', $m[2] ) as $entry ) {
				// `AGENTS.md (Local development section)` is the document
				// AGENTS.md; the parenthetical is a note to the reader.
				if ( 1 === preg_match( '/^\s*([A-Za-z0-9._-]+\.md)\b/', $entry, $doc ) ) {
					$docs[] = $doc[1];
				}
			}
			if ( array() === $docs ) {
				continue;
			}
			sort( $docs );

			preg_match_all( '/`([^`]+)`/', $m[1], $paths );
			foreach ( $paths[1] as $path ) {
				$map[ self::normalise_key( $path ) ] = $docs;
			}
		}

		return $map;
	}

	/**
	 * The MAP array in the workflow, as path => sorted doc basenames.
	 *
	 * Entries read `["path"]="docs/a/DOC.md docs/b/OTHER.md"`, with FULL doc
	 * paths and a trailing slash on directories. Reduced to basenames so the
	 * two sources are comparable despite writing the same thing differently.
	 *
	 * @return array<string,string[]>
	 */
	private static function workflow_map(): array {
		$yaml  = (string) file_get_contents( self::root() . '/.github/workflows/docs-followup.yml' );
		$map   = array();
		$found = preg_match_all( '/^\s*\["([^"]+)"\]="([^"]*)"/m', $yaml, $matches, PREG_SET_ORDER );

		if ( ! $found ) {
			return $map;
		}

		foreach ( $matches as $m ) {
			$docs = array_values(
				array_filter(
					array_map(
						static function ( $path ) {
							return basename( trim( $path ) );
						},
						explode( ' ', $m[2] )
					),
					static function ( $d ) {
						return '' !== $d;
					}
				)
			);
			sort( $docs );
			$map[ self::normalise_key( $m[1] ) ] = $docs;
		}

		return $map;
	}

	/**
	 * The workflow's doc values as written, keyed by path.
	 *
	 * workflow_map() reduces these to basenames so the two sources compare
	 * despite writing docs differently. That reduction must not leak into the
	 * existence check: `docs/not-a-real-directory/ARCHITECTURE.md` has a
	 * basename that exists elsewhere, so a basename check calls a path real
	 * when the workflow would hand the prompt a file nothing can open
	 * (#691 review).
	 *
	 * @return array<string,string[]> Repo-relative paths.
	 */
	private static function workflow_doc_paths(): array {
		$yaml = (string) file_get_contents( self::root() . '/.github/workflows/docs-followup.yml' );
		$map  = array();

		if ( ! preg_match_all( '/^\s*\["([^"]+)"\]="([^"]*)"/m', $yaml, $matches, PREG_SET_ORDER ) ) {
			return $map;
		}

		foreach ( $matches as $m ) {
			$map[ self::normalise_key( $m[1] ) ] = array_values(
				array_filter( array_map( 'trim', explode( ' ', $m[2] ) ), 'strlen' )
			);
		}

		return $map;
	}

	/**
	 * One spelling for a path the two files write differently.
	 *
	 * AGENTS.md marks directories `dir/**`; the workflow writes `dir/`.
	 *
	 * @param string $key Raw key from either source.
	 */
	private static function normalise_key( string $key ): string {
		return rtrim( trim( $key ), '/*' );
	}

	/**
	 * Full paths of every doc a row may target, keyed by basename.
	 *
	 * The repo root is included because the local-development row targets
	 * AGENTS.md itself. Nothing stops the workflow expressing that — its
	 * values are paths pasted into a prompt, not files it opens — so that
	 * row is mapped like any other rather than exempted here.
	 *
	 * @return array<string,string[]>
	 */
	private static function docs_by_basename(): array {
		$found = array();
		foreach ( array( '/docs/engineering', '/docs/user-guide', '' ) as $dir ) {
			foreach ( (array) glob( self::root() . $dir . '/*.md' ) as $path ) {
				$found[ basename( (string) $path ) ][] = (string) $path;
			}
		}

		return $found;
	}

	public function test_every_agents_row_exists_in_the_workflow(): void {
		$missing = array_diff( array_keys( self::agents_map() ), array_keys( self::workflow_map() ) );

		$this->assertSame(
			array(),
			array_values( $missing ),
			"AGENTS.md maps these paths but the workflow does not, so changing them triggers nothing:\n  " .
			implode( "\n  ", $missing )
		);
	}

	public function test_every_workflow_row_exists_in_agents(): void {
		$missing = array_diff( array_keys( self::workflow_map() ), array_keys( self::agents_map() ) );

		$this->assertSame(
			array(),
			array_values( $missing ),
			"The workflow maps these paths but AGENTS.md does not, so a human reading the table will not know:\n  " .
			implode( "\n  ", $missing )
		);
	}

	public function test_the_two_lists_agree_on_which_docs_each_path_affects(): void {
		$agents   = self::agents_map();
		$workflow = self::workflow_map();
		$drift    = array();

		foreach ( $agents as $path => $docs ) {
			if ( ! isset( $workflow[ $path ] ) || $workflow[ $path ] === $docs ) {
				continue;
			}
			$drift[] = sprintf(
				'%s: AGENTS.md says [%s], workflow says [%s]',
				$path,
				implode( ', ', $docs ),
				implode( ', ', $workflow[ $path ] )
			);
		}

		$this->assertSame( array(), $drift, "The two lists disagree:\n  " . implode( "\n  ", $drift ) );
	}

	public function test_every_mapped_path_still_exists(): void {
		$stale = array();

		foreach ( array_keys( self::workflow_map() ) as $path ) {
			$full = self::root() . '/' . $path;

			// is_file(), not file_exists(): the latter is true for a
			// directory too, which made the empty-directory branch below
			// unreachable and let a mapped directory with nothing in it pass
			// as real (#691 review).
			if ( is_file( $full ) ) {
				continue;
			}

			if ( is_dir( $full ) ) {
				// A directory key matches by prefix, so an empty one matches
				// no changed file and contributes no docs — the exact silent
				// failure this whole test exists to catch.
				if ( array() === (array) glob( $full . '/*' ) ) {
					$stale[] = $path . '  (directory exists but is empty)';
				}
				continue;
			}

			$stale[] = $path;
		}

		$this->assertSame(
			array(),
			$stale,
			"These mapped paths no longer exist — a rename or delete left the key behind, and it now matches nothing:\n  " .
			implode( "\n  ", $stale )
		);
	}

	public function test_every_referenced_doc_exists(): void {
		$missing = array();

		foreach ( self::workflow_doc_paths() as $path => $docs ) {
			foreach ( $docs as $doc ) {
				if ( ! is_file( self::root() . '/' . $doc ) ) {
					$missing[] = "$path -> $doc";
				}
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"These documents are mapped but do not exist. A missing path is never stat'd — it reaches the prompt and simply cannot be read:\n  " .
			implode( "\n  ", $missing )
		);
	}

	public function test_no_two_documents_share_a_basename(): void {
		// The whole comparison above reduces full paths to basenames. Two
		// docs called the same thing in different directories would make the
		// two lists compare equal while pointing at different files.
		$collisions = array();
		foreach ( self::docs_by_basename() as $name => $paths ) {
			if ( count( $paths ) > 1 ) {
				$collisions[] = $name . ': ' . implode( ', ', $paths );
			}
		}

		$this->assertSame( array(), $collisions, "Ambiguous document basenames:\n  " . implode( "\n  ", $collisions ) );
	}

	public function test_every_plugin_php_file_is_mapped(): void {
		$mapped = array_keys( self::workflow_map() );
		$holes  = array();

		foreach ( self::plugin_php_files() as $file ) {
			foreach ( $mapped as $key ) {
				// Deliberately stricter than the workflow, which matches a
				// bare `$changed == $prefix*` with no separator. Requiring an
				// exact file or a real directory boundary means a new file
				// needs its own row rather than inheriting one by sharing a
				// name prefix with a neighbour — which is how a row ends up
				// pointing at docs that describe a different module.
				if ( $file === $key || str_starts_with( $file, $key . '/' ) ) {
					continue 2;
				}
			}
			$holes[] = $file;
		}

		$this->assertSame(
			array(),
			$holes,
			"These code files are in neither list, so changing them triggers no documentation review:\n  " .
			implode( "\n  ", $holes )
		);
	}

	/**
	 * Files exempt from coverage, each for its own reason.
	 *
	 * Named individually rather than matched by a blanket rule, so adding one
	 * is a decision someone has to write down. `includes/autoload.php` is a
	 * generated classmap with no behaviour to document; `phpstan-bootstrap.php`
	 * is analysis scaffolding that never runs inside WordPress. The vendored
	 * `includes/lib/` tree is excluded by prefix below, being third-party code
	 * we do not own.
	 *
	 * @var string[]
	 */
	private const UNMAPPED_BY_DESIGN = array(
		'includes/autoload.php',
		'phpstan-bootstrap.php',
	);

	/**
	 * Every PHP file the map is expected to cover.
	 *
	 * The repo root is walked as well as `includes/`. Scanning `includes/`
	 * alone left `uninstall.php` mapped but never coverage-checked: its rows
	 * could be deleted from both sources and every test here would still pass,
	 * because the symmetric tests only compare the two lists to each other
	 * (#691 review).
	 *
	 * @return string[] Repo-relative paths.
	 */
	private static function plugin_php_files(): array {
		$root  = self::root();
		$files = array();

		foreach ( (array) glob( $root . '/*.php' ) as $path ) {
			$files[] = basename( (string) $path );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			$path = (string) $item;
			if ( ! str_ends_with( $path, '.php' ) ) {
				continue;
			}
			$files[] = ltrim( str_replace( $root, '', $path ), '/' );
		}

		$files = array_filter(
			$files,
			static function ( $relative ) {
				return ! in_array( $relative, self::UNMAPPED_BY_DESIGN, true )
					&& ! str_starts_with( $relative, 'includes/lib/' );
			}
		);

		sort( $files );

		return $files;
	}
}
