<?php
/**
 * F091 — a Row's property defaults must match its Schema's column defaults.
 *
 * These defaults are the reason the production drift was invisible rather than
 * loud. `Row` declares `public $tool_discover_abilities = 1;` and BerlinDB fills
 * absent keys from the class defaults, so a table missing that column read back
 * as ENABLED and `ToolPolicy::compose_for_row()` cheerfully advertised three
 * tools nobody had selected. No error, no warning — just a wrong answer,
 * delivered confidently.
 *
 * We cannot remove that masking without giving up BerlinDB's Row contract. What
 * we can do is guarantee the mask never says something the Schema does not,
 * which is what this asserts. If a Schema default changes and its Row is not
 * updated in the same commit, the two disagree and the next silent-wrong-answer
 * bug is already written.
 *
 * WP-free: reads declarations only.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RowDefaultParityTest extends TestCase {

	/**
	 * Row class => Schema class.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const MODULES = array(
		'MCPServer'        => array(
			'AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row',
			'AcrossAI_MCP_Manager\Includes\Database\MCPServer\Schema',
		),
		'MCPServerTool'    => array(
			'AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Row',
			'AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Schema',
		),
		'MCPServerAbility' => array(
			'AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Row',
			'AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Schema',
		),
		'CliAuthLog'       => array(
			'AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Row',
			'AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Schema',
		),
	);

	/**
	 * @dataProvider provideModules
	 */
	public function test_row_defaults_match_schema_defaults( string $row_class, string $schema_class ): void {
		if ( ! class_exists( $row_class ) ) {
			$this->markTestSkipped( $row_class . ' does not exist' );
		}

		$row_defaults = ( new ReflectionClass( $row_class ) )->getDefaultProperties();
		$columns      = ( new ReflectionClass( $schema_class ) )->getDefaultProperties()['columns'] ?? array();

		$compared = 0;

		foreach ( (array) $columns as $column ) {
			$name = (string) $column['name'];

			// Only columns the Row actually mirrors. A Row is entitled to
			// declare fewer properties than the table has columns.
			if ( ! array_key_exists( $name, $row_defaults ) ) {
				continue;
			}

			// A column with no declared default has no expectation to check —
			// BerlinDB synthesises one at DDL time, which is a different thing
			// from what a PHP property should hold.
			if ( ! array_key_exists( 'default', $column ) ) {
				continue;
			}

			$this->assertEquals(
				$column['default'],
				$row_defaults[ $name ],
				sprintf(
					'%s::$%s defaults to %s but its Schema column declares %s. '
						. 'BerlinDB fills absent keys from the Row default, so a disagreement here is '
						. 'read back as fact when the column is missing — the exact mechanism that hid F091.',
					$row_class,
					$name,
					var_export( $row_defaults[ $name ], true ),
					var_export( $column['default'], true )
				)
			);

			++$compared;
		}

		$this->assertGreaterThan( 0, $compared, 'at least one column should have been comparable' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provideModules(): array {
		return self::MODULES;
	}
}
