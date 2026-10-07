<?php
/**
 * Every in-plugin class reference must resolve to a file on disk.
 *
 * F095 moved ~15,700 lines across a plugin boundary by rewriting namespaces.
 * A rewrite turns `\AcrossAI_Pro\Includes\Foo` into
 * `\AcrossAI_MCP_Manager\Includes\Foo` whether or not `Foo` came along — and
 * the companion-only classes deliberately did NOT come along. The result is a
 * reference that is syntactically perfect, passes `php -l`, passes phpcs, and
 * fatals the moment the line executes.
 *
 * That shipped twice. `HostCapabilities` survived in two `panel_url()` helpers
 * and took down the whole AI Connectors tab; `N8nTab` was imported one
 * namespace segment short (`Admin\ServerTabs` for `Admin\Partials\ServerTabs`)
 * in the controller that gates n8n token issuance, so its permission check
 * would fatal instead of returning 403.
 *
 * Nothing caught either. PHPStan is the tool for this and it is not running —
 * `phpstan.neon.dist` bootstraps `acrossai-mcp-manager.php`, whose
 * `if ( ! defined( 'WPINC' ) ) { die; }` guard exits the process before
 * analysis begins, so the gate reports success having examined nothing.
 * Fixing that properly needs WordPress stubs as a dev dependency; this test
 * needs nothing and covers the specific failure that reached users.
 *
 * A third shape exists and is the nastiest: an UNQUALIFIED name that silently
 * resolves to the file's own namespace. `MessagePage` called
 * `CacheHeaders::send_no_store()` with no import, so PHP looked for
 * `OAuth\\CacheHeaders` — a class F095 deliberately did not port, because the
 * `Utilities` copy is a superset. Every other file in that namespace imports
 * it correctly. The call site reads as perfectly ordinary code, and it fataled
 * `/authorize` plus every OAuth error page. The two checks above could not see
 * it: there is no backslash and no `use` line to inspect.
 *
 * Resolution is by PSR-4 path, not `class_exists()`: loading these files
 * requires WordPress, and this suite deliberately runs without it. The
 * unqualified check tokenizes rather than pattern-matching, because class
 * names appear constantly in docblocks and comments and a regex over raw
 * source reports hundreds of them.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\RenameGate
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\RenameGate;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class NoDanglingClassReferenceTest extends TestCase {

	private const PLUGIN_ROOT = __DIR__ . '/../../..';

	/** PSR-4 roots from composer.json, as prefix => directory. */
	private const ROOTS = array(
		'AcrossAI_MCP_Manager\\Includes\\' => 'includes/',
		'AcrossAI_MCP_Manager\\Admin\\'    => 'admin/',
		'AcrossAI_MCP_Manager\\Public\\'   => 'public/',
	);

	private const SCAN_DIRS = array( 'includes', 'admin', 'public', 'templates' );

	/**
	 * Fully-qualified references: `\AcrossAI_MCP_Manager\Includes\Foo::bar()`.
	 */
	public function test_no_dangling_fully_qualified_references(): void {
		$bad = array();

		foreach ( $this->source_files() as $file ) {
			$src = (string) file_get_contents( $file );
			preg_match_all(
				'/\\\\{1,2}(AcrossAI_MCP_Manager(?:\\\\{1,2}[A-Za-z0-9_]+)+)/',
				$src,
				$matches,
				PREG_OFFSET_CAPTURE
			);

			foreach ( $matches[1] as $match ) {
				$fqcn = str_replace( '\\\\', '\\', $match[0] );
				$path = $this->psr4_path( $fqcn );
				if ( null !== $path && ! file_exists( $path ) ) {
					$line  = substr_count( substr( $src, 0, $match[1] ), "\n" ) + 1;
					$bad[] = $this->relative( $file ) . ':' . $line . ' → ' . $fqcn;
				}
			}
		}

		$this->assertSame( array(), array_values( array_unique( $bad ) ), $this->explain() );
	}

	/**
	 * Import statements: `use AcrossAI_MCP_Manager\Admin\ServerTabs\N8nTab;`.
	 *
	 * Split from the test above because a wrong `use` fails differently: the
	 * call site reads as a plain unqualified class name, so nothing at the
	 * point of use looks suspicious.
	 */
	public function test_no_dangling_use_imports(): void {
		$bad = array();

		foreach ( $this->source_files() as $file ) {
			foreach ( (array) file( $file ) as $index => $line ) {
				if ( ! preg_match( '/^\s*use\s+(AcrossAI_MCP_Manager\\\\[A-Za-z0-9_\\\\]+)/', (string) $line, $m ) ) {
					continue;
				}
				$path = $this->psr4_path( $m[1] );
				if ( null !== $path && ! file_exists( $path ) ) {
					$bad[] = $this->relative( $file ) . ':' . ( (int) $index + 1 ) . ' → ' . $m[1];
				}
			}
		}

		$this->assertSame( array(), array_values( array_unique( $bad ) ), $this->explain() );
	}

	/**
	 * Map an FQCN to its PSR-4 file path, or null when it is not under a root
	 * (vendor classes, WordPress core, anything outside this plugin).
	 */
	private function psr4_path( string $fqcn ): ?string {
		foreach ( self::ROOTS as $prefix => $dir ) {
			if ( 0 === strpos( $fqcn, $prefix ) ) {
				$rest = substr( $fqcn, strlen( $prefix ) );
				return self::PLUGIN_ROOT . '/' . $dir . str_replace( '\\', '/', $rest ) . '.php';
			}
		}
		return null;
	}

	/**
	 * @return array<int, string>
	 */
	private function source_files(): array {
		$files = array();
		foreach ( self::SCAN_DIRS as $dir ) {
			$root = self::PLUGIN_ROOT . '/' . $dir;
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}
		sort( $files );
		return $files;
	}

	private function relative( string $path ): string {
		$root = realpath( self::PLUGIN_ROOT );
		return false === $root ? $path : ltrim( str_replace( $root, '', (string) realpath( $path ) ), '/' );
	}

	/**
	 * Unqualified names that resolve to the file's own namespace.
	 *
	 * Only real code counts, so this walks the token stream: `Foo::` and
	 * `new Foo(` where `Foo` carries no leading separator and no matching
	 * `use` import. Anything resolving outside the PSR-4 roots is ignored —
	 * that is vendor and WordPress core, which this test knows nothing about.
	 */
	public function test_no_dangling_unqualified_references(): void {
		$bad = array();

		foreach ( $this->source_files() as $file ) {
			$tokens    = token_get_all( (string) file_get_contents( $file ) );
			$namespace = $this->namespace_of( $tokens );

			if ( '' === $namespace ) {
				continue;
			}

			$imported = $this->imported_short_names( $tokens );
			$count    = count( $tokens );

			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];
				if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
					continue;
				}

				$previous = $this->neighbour( $tokens, $i, -1 );
				if ( is_array( $previous ) && in_array(
					$previous[0],
					array( T_NS_SEPARATOR, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CLASS, T_CONST, T_INTERFACE, T_TRAIT ),
					true
				) ) {
					continue;
				}

				$next      = $this->neighbour( $tokens, $i, 1 );
				$is_new    = is_array( $previous ) && T_NEW === $previous[0];
				$is_static = is_array( $next ) && T_DOUBLE_COLON === $next[0];
				if ( ! $is_new && ! $is_static ) {
					continue;
				}

				$name = $token[1];
				if ( in_array( strtolower( $name ), array( 'self', 'static', 'parent' ), true ) ) {
					continue;
				}
				if ( isset( $imported[ strtolower( $name ) ] ) ) {
					continue;
				}

				$path = $this->psr4_path( $namespace . '\\' . $name );
				if ( null !== $path && ! file_exists( $path ) ) {
					$bad[] = $this->relative( $file ) . ':' . $token[2] . ' → ' . $namespace . '\\' . $name;
				}
			}
		}

		$this->assertSame( array(), array_values( array_unique( $bad ) ), $this->explain() );
	}

	/**
	 * @param array<int, mixed> $tokens Token stream.
	 * @return string Declared namespace, or '' when the file declares none.
	 */
	private function namespace_of( array $tokens ): string {
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_NAMESPACE !== $tokens[ $i ][0] ) {
				continue;
			}
			$buffer = '';
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( ! is_array( $tokens[ $j ] ) ) {
					if ( ';' === $tokens[ $j ] || '{' === $tokens[ $j ] ) {
						break;
					}
					continue;
				}
				if ( T_WHITESPACE === $tokens[ $j ][0] ) {
					continue;
				}
				$buffer .= $tokens[ $j ][1];
			}
			return trim( $buffer );
		}
		return '';
	}

	/**
	 * Short names bound by `use` — the alias when aliased, last segment otherwise.
	 *
	 * @param array<int, mixed> $tokens Token stream.
	 * @return array<string, int> Lowercased short name => 1.
	 */
	private function imported_short_names( array $tokens ): array {
		$imported = array();
		$count    = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_USE !== $tokens[ $i ][0] ) {
				continue;
			}
			$buffer = '';
			$alias  = null;
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( ! is_array( $tokens[ $j ] ) ) {
					if ( ';' === $tokens[ $j ] ) {
						break;
					}
					if ( '(' === $tokens[ $j ] ) {
						// `function () use ( $x )` — a closure binding, not an import.
						$buffer = '';
						break;
					}
					continue;
				}
				if ( T_WHITESPACE === $tokens[ $j ][0] ) {
					continue;
				}
				if ( T_AS === $tokens[ $j ][0] ) {
					$alias = '';
					continue;
				}
				if ( null !== $alias ) {
					$alias .= $tokens[ $j ][1];
					continue;
				}
				$buffer .= $tokens[ $j ][1];
			}
			if ( '' === $buffer ) {
				continue;
			}
			if ( null !== $alias && '' !== $alias ) {
				$short = $alias;
			} else {
				$segments = explode( '\\', $buffer );
				$short    = (string) end( $segments );
			}
			$imported[ strtolower( $short ) ] = 1;
		}

		return $imported;
	}

	/**
	 * Nearest non-whitespace token in $direction, or null at the stream edge.
	 *
	 * @param array<int, mixed> $tokens    Token stream.
	 * @param int               $index     Starting index.
	 * @param int               $direction -1 or 1.
	 * @return mixed
	 */
	private function neighbour( array $tokens, int $index, int $direction ) {
		$i = $index + $direction;
		while ( isset( $tokens[ $i ] ) && is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
			$i += $direction;
		}
		return $tokens[ $i ] ?? null;
	}

	private function explain(): string {
		return 'Reference to an in-plugin class with no file behind it. Either the class '
			. 'was never ported from the companion (drop the call — it is companion-only '
			. 'wiring), or the namespace is wrong by a segment. Both fatal at runtime and '
			. 'neither is caught by php -l, phpcs, or the currently-inert PHPStan gate.';
	}
}
