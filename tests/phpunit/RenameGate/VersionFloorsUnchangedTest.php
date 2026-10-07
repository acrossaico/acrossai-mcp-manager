<?php
/**
 * FR-028 — the declared PHP and WordPress minimums must not move.
 *
 * F095 moved ~15,700 lines of OAuth and connector code into this plugin. The
 * stated position is that the incoming code imposes nothing: the floors stay
 * at PHP 8.1 (BerlinDB's official requirement) and WordPress 7.0. That was
 * asserted in the spec and nowhere else, so a casual bump in either file would
 * have gone unnoticed.
 *
 * Also pins the two files AGAINST EACH OTHER. The plugin header and README
 * carry the same two numbers in different syntax, and nothing kept them in
 * step — a release that bumped one would ship contradicting floors.
 *
 * Known defect this test deliberately does NOT fix: the header uses
 * `Requires WP:`, which WordPress does not read (the recognised key is
 * `Requires at least:`), so the WordPress floor is unenforced at runtime. It
 * is pinned here as-is so the string cannot drift while that is outstanding;
 * correcting the key is tracked separately and changes behaviour, not just
 * documentation.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\RenameGate
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\RenameGate;

use PHPUnit\Framework\TestCase;

final class VersionFloorsUnchangedTest extends TestCase {

	private const PLUGIN_ROOT = __DIR__ . '/../../..';

	private const PHP_FLOOR = '8.1';
	private const WP_FLOOR  = '7.0';

	public function test_plugin_header_declares_the_agreed_floors(): void {
		$header = (string) file_get_contents( self::PLUGIN_ROOT . '/acrossai-mcp-manager.php' );

		$this->assertMatchesRegularExpression(
			'/^\s*\*\s*Requires PHP:\s*' . preg_quote( self::PHP_FLOOR, '/' ) . '\s*$/m',
			$header,
			'FR-028 — the PHP floor moved. It is pinned at ' . self::PHP_FLOOR
				. ' because BerlinDB officially requires it; lowering it needs BerlinDB replaced, '
				. 'and raising it drops sites for no stated reason.'
		);
		$this->assertMatchesRegularExpression(
			'/^\s*\*\s*Requires WP:\s*' . preg_quote( self::WP_FLOOR, '/' ) . '\s*$/m',
			$header,
			'FR-028 — the WordPress floor moved in the plugin header.'
		);
	}

	public function test_readme_agrees_with_the_plugin_header(): void {
		$readme = (string) file_get_contents( self::PLUGIN_ROOT . '/README.txt' );

		$this->assertMatchesRegularExpression(
			'/^Requires PHP:\s*' . preg_quote( self::PHP_FLOOR, '/' ) . '\s*$/m',
			$readme,
			'FR-028 — README.txt and the plugin header disagree on the PHP floor.'
		);
		$this->assertMatchesRegularExpression(
			'/^Requires at least:\s*' . preg_quote( self::WP_FLOOR, '/' ) . '\s*$/m',
			$readme,
			'FR-028 — README.txt and the plugin header disagree on the WordPress floor.'
		);
	}
}
