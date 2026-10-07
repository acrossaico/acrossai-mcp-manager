<?php
/**
 * Every bundled asset a source file points at must exist on disk.
 *
 * F095 ported the five connector profile classes but not the icon files they
 * reference, so `AIConnectorsTab` rendered a broken-image placeholder next to
 * every connector name. The same shape as the dangling-class bugs: a reference
 * that is syntactically perfect, passes `php -l`, phpcs and the test suite, and
 * only fails when a browser asks for the URL.
 *
 * `plugins_url()` builds a URL from a path without ever touching the
 * filesystem, so nothing in PHP objects to a missing file — the 404 surfaces in
 * the browser, which is exactly where this one was found.
 *
 * Deliberately narrow: this resolves the literal `assets/...` argument of a
 * `plugins_url()` call. Dynamically-built paths are out of scope — they cannot
 * be checked statically, and widening the pattern to catch them would mean
 * guessing.
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

final class BundledAssetsExistTest extends TestCase {

	private const PLUGIN_ROOT = __DIR__ . '/../../..';

	private const SCAN_DIRS = array( 'includes', 'admin', 'public', 'templates' );

	public function test_every_referenced_bundled_asset_exists(): void {
		$missing = array();

		foreach ( $this->source_files() as $file ) {
			$source = (string) file_get_contents( $file );

			preg_match_all(
				"/plugins_url\(\s*'((?:assets|build)\/[^']+)'/",
				$source,
				$matches,
				PREG_OFFSET_CAPTURE
			);

			foreach ( $matches[1] as $match ) {
				$relative = $match[0];
				if ( file_exists( self::PLUGIN_ROOT . '/' . $relative ) ) {
					continue;
				}
				$line      = substr_count( substr( $source, 0, $match[1] ), "\n" ) + 1;
				$missing[] = $this->relative( $file ) . ':' . $line . ' → ' . $relative;
			}
		}

		$this->assertSame(
			array(),
			array_values( array_unique( $missing ) ),
			'A source file points at a bundled asset that is not in the repository. '
				. 'plugins_url() does not touch the filesystem, so this fails only in the '
				. 'browser, as a broken image or a 404 on a script or stylesheet.'
		);
	}

	/**
	 * The five connector icons specifically — pinned by name.
	 *
	 * The check above is generic and would pass if a profile stopped asking for
	 * its icon at all. These are the five surfaces an operator sees on the AI
	 * Connectors tab and on Quick Connect step 10.
	 *
	 * @dataProvider provideConnectorIcons
	 */
	public function test_connector_icon_ships( string $slug ): void {
		$path = self::PLUGIN_ROOT . '/assets/' . $slug . '-icon.svg';

		$this->assertFileExists(
			$path,
			sprintf( 'The %s connector icon is missing; its tab renders a broken image.', $slug )
		);
		$this->assertStringContainsString(
			'<svg',
			(string) file_get_contents( $path ),
			sprintf( 'The %s icon exists but is not an SVG.', $slug )
		);
	}

	public static function provideConnectorIcons(): array {
		return array(
			'claude'  => array( 'claude' ),
			'chatgpt' => array( 'chatgpt' ),
			'gemini'  => array( 'gemini' ),
			'grok'    => array( 'grok' ),
			'cursor'  => array( 'cursor' ),
		);
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
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
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
}
