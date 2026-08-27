<?php
/**
 * F080 — Rename canary.
 *
 * Filesystem grep expressed as a PHPUnit assertion. Fails loudly if a
 * future PR reintroduces the retired "quick-setup" / "quicksetup" /
 * "QuickSetup" identifier anywhere in the plugin's code surface.
 *
 * Historical documentation (README changelog for 0.3.0 / 0.3.1, memory
 * notes, F069 / F072 planning + spec docs) is scoped OUT because those
 * describe what shipped under the old name at merge time and must not
 * be retroactively rewritten. See F080 planning doc §"What NOT to touch".
 *
 * Runs under the pure-PHP `rename-gate` suite — no WordPress bootstrap
 * required.
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit\RenameGate
 * @since   0.3.2
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\RenameGate;

use PHPUnit\Framework\TestCase;

final class RenameCanaryTest extends TestCase {

	/**
	 * Absolute path to the plugin root.
	 */
	private const PLUGIN_ROOT = __DIR__ . '/../../..';

	/**
	 * Directories / files whose CODE MUST NOT contain the retired name.
	 * Relative to plugin root.
	 *
	 * @var array<int, string>
	 */
	private const CODE_ROOTS = array(
		'admin',
		'includes',
		'src',
		'tests/phpunit',
		'acrossai-mcp-manager.php',
		'webpack.config.js',
	);

	/**
	 * File extensions to scan.
	 *
	 * @var array<int, string>
	 */
	private const CODE_EXTS = array( 'php', 'jsx', 'js', 'scss', 'json' );

	/**
	 * Retired identifier patterns (case-insensitive). Any match in a
	 * code file fails the canary — the rename is intentionally atomic
	 * with no backwards-compat shim, so a hit means an accidental
	 * reintroduction.
	 *
	 * @var array<int, string>
	 */
	private const RETIRED_PATTERNS = array(
		'quick-setup',
		'quicksetup',
	);

	/**
	 * Paths inside the code roots that ARE allowed to mention the
	 * retired name. Kept explicit so a future contributor deleting one
	 * of these paths gets a fast signal that the exception is no longer
	 * needed.
	 *
	 * Currently only the rename-gate suite itself is allowlisted — its
	 * test files quote the retired identifiers as string literals in
	 * order to assert their absence in production code, which the
	 * canary would otherwise (correctly) flag.
	 *
	 * @var array<int, string>
	 */
	private const HISTORICAL_ALLOWLIST = array(
		'tests/phpunit/RenameGate',
	);

	public function test_no_retired_identifier_survives_in_code(): void {
		$hits = array();

		foreach ( self::CODE_ROOTS as $root ) {
			$path = self::PLUGIN_ROOT . '/' . $root;
			if ( ! file_exists( $path ) ) {
				continue;
			}

			$files = is_file( $path )
				? array( $path )
				: $this->collect_files( $path );

			foreach ( $files as $file ) {
				if ( $this->is_allowlisted( $file ) ) {
					continue;
				}

				$contents = (string) file_get_contents( $file );
				foreach ( self::RETIRED_PATTERNS as $pattern ) {
					// Case-insensitive substring — matches Quick-Setup,
					// quick-setup, QuickSetup (contains "quicksetup"
					// lower-cased), etc.
					if ( false !== stripos( $contents, $pattern ) ) {
						$hits[] = sprintf(
							'%s (pattern: %s)',
							$this->relative_path( $file ),
							$pattern
						);
					}
				}
			}
		}

		$this->assertEmpty(
			$hits,
			"F080 rename canary FAILED — retired identifier 'quick-setup' / 'quicksetup' / 'QuickSetup' "
			. "found in the following code files. Rename them to 'quick-connect' / 'QuickConnect' "
			. "or add the file to the historical allowlist if it is intentional history:\n  - "
			. implode( "\n  - ", $hits )
		);
	}

	/**
	 * Recursively collect files with allowed extensions under $dir.
	 *
	 * @return array<int, string>
	 */
	private function collect_files( string $dir ): array {
		$out      = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, self::CODE_EXTS, true ) ) {
				continue;
			}
			$out[] = $file->getPathname();
		}
		return $out;
	}

	private function is_allowlisted( string $file ): bool {
		$rel = $this->relative_path( $file );
		foreach ( self::HISTORICAL_ALLOWLIST as $allowed ) {
			if ( 0 === strpos( $rel, $allowed ) ) {
				return true;
			}
		}
		return false;
	}

	private function relative_path( string $file ): string {
		$root = realpath( self::PLUGIN_ROOT );
		if ( false === $root ) {
			return $file;
		}
		$abs = realpath( $file );
		if ( false === $abs ) {
			return $file;
		}
		return ltrim( str_replace( $root, '', $abs ), '/' );
	}
}
