<?php
/**
 * F091 — a freshly installed table must match its declaration exactly.
 *
 * This is the assertion that would have caught the four PKCE columns on the
 * authentication-log table: declared in `Schema.php`, never migrated, and so
 * present on fresh installs only. Nothing compared the two until now.
 *
 * Comparison is by SET, not sequence. `ADD COLUMN` appends, so a table the
 * reconciler has repaired will never have the same column ORDER as a fresh
 * install, and nothing in this codebase reads a column by ordinal position.
 * Asserting order would fail for a reason that does not matter.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Table as CliAuthLogTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table as MCPServerAbilityTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Table as MCPServerMetaTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Table as MCPServerToolTable;
use ReflectionClass;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class SchemaParityTest extends WP_UnitTestCase {

	/**
	 * Every declared column exists in the live table, and vice versa.
	 *
	 * @dataProvider provideTables
	 */
	public function test_declared_and_live_columns_agree( string $table_class ): void {
		global $wpdb;

		$table = $table_class::instance();
		$table->maybe_upgrade();

		$stem       = self::declared( $table_class, 'name' );
		$table_name = $wpdb->prefix . $stem;

		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}`", ARRAY_A );

		$this->assertNotEmpty( $rows, "{$stem} must exist and report its columns" );

		$live = array();

		foreach ( (array) $rows as $row ) {
			$live[] = strtolower( (string) $row['Field'] );
		}

		$declared = array();

		foreach ( $this->declared_columns( $table_class ) as $column ) {
			$declared[] = strtolower( (string) $column['name'] );
		}

		sort( $live );
		sort( $declared );

		$missing = array_values( array_diff( $declared, $live ) );
		$extra   = array_values( array_diff( $live, $declared ) );

		$this->assertSame(
			array(),
			$missing,
			"{$stem} is missing declared column(s): " . implode( ', ', $missing )
				. '. A column declared without a migration reaches fresh installs only —'
				. ' this is the exact shape of the drift F091 exists to prevent.'
		);

		$this->assertSame(
			array(),
			$extra,
			"{$stem} carries undeclared column(s): " . implode( ', ', $extra )
				. '. Either declare them or remove them; the reconciler will never drop a column.'
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provideTables(): array {
		return array(
			'MCPServer'        => array( MCPServerTable::class ),
			'MCPServerTool'    => array( MCPServerToolTable::class ),
			'MCPServerAbility' => array( MCPServerAbilityTable::class ),
			'MCPServerMeta'    => array( MCPServerMetaTable::class ),
			'CliAuthLog'       => array( CliAuthLogTable::class ),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function declared_columns( string $table_class ): array {
		$schema_class = self::declared( $table_class, 'schema' );

		$columns = ( new ReflectionClass( $schema_class ) )->getDefaultProperties()['columns'] ?? array();

		return is_array( $columns ) ? $columns : array();
	}

	private static function declared( string $class, string $property ): string {
		$value = ( new ReflectionClass( $class ) )->getDefaultProperties()[ $property ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}
}
