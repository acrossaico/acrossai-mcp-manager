<?php
/**
 * F091 — SchemaReconciler regression tests.
 *
 * Covers the mechanism that heals a table whose version stamp claims it is
 * current while its columns say otherwise. Every case runs against all five
 * Table subclasses, because the two defects being fixed are properties of
 * BerlinDB rather than of any one module — a fix proven on MCPServer alone
 * would say nothing about the two tables that declare no `$upgrades` at all.
 *
 * Harness notes:
 *   - `set_up()` / `tear_down()` MUST be public — this WP test library fatals
 *     on protected ones (B56).
 *   - DDL commits and therefore escapes WP_UnitTestCase's per-test transaction
 *     rollback (B53), so `tear_down()` restores unconditionally.
 *   - WP_UnitTestCase rewrites CREATE/DROP TABLE into TEMPORARY equivalents via
 *     `query` filters. Tests that need real DDL must remove them first, exactly
 *     as PhantomVersionGuardTest does.
 *   - Nothing is hard-coded: target columns are chosen by reflection over the
 *     declared Schema (B48), so adding or renaming a column never breaks this.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Table as CliAuthLogTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table as MCPServerAbilityTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Table as MCPServerMetaTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Table as MCPServerToolTable;
use AcrossAI_MCP_Manager\Includes\Database\SchemaReconciler;
use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Table;
use ReflectionClass;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class SchemaReconcilerTest extends WP_UnitTestCase {

	/**
	 * Table classes whose physical schema this run may have altered.
	 *
	 * @var string[]
	 */
	private $touched = array();

	public function set_up(): void {
		parent::set_up();

		delete_option( SchemaReconciler::FINGERPRINT_OPTION );
		delete_transient( SchemaReconciler::LOCK_TRANSIENT );
		SchemaReconciler::reset_request_cache();

		// Real DDL, not the TEMPORARY rewrites (B53).
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down(): void {
		global $wpdb;

		// Unconditional self-heal: a failure mid-test must not leave a mutilated
		// table for every later test in the suite (B53 — DDL commits and escapes
		// the per-test rollback).
		//
		// DROP and reinstall rather than reconcile. Reconciling would restore a
		// dropped column but NOT an index that went with it, which would leave a
		// subtly wrong table behind. A fresh install is the only restore that is
		// correct for every column, indexed or not — and it is what lets these
		// tests drop indexed columns at all.
		foreach ( $this->touched as $table_class ) {
			$table_name = $wpdb->prefix . self::declared( $table_class, 'name' );
			$version_key = self::declared( $table_class, 'db_version_key' );

			$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );

			delete_option( $version_key );
			delete_transient( $version_key . '_upgrade_lock' );

			$table_class::instance()->maybe_upgrade();
		}

		$this->touched = array();

		delete_option( SchemaReconciler::FINGERPRINT_OPTION );
		delete_transient( SchemaReconciler::LOCK_TRANSIENT );
		SchemaReconciler::reset_request_cache();

		parent::tear_down();
	}

	/**
	 * A column the reconciler must be able to restore comes back with its
	 * declared definition, on every table.
	 *
	 * @dataProvider provideTables
	 */
	public function test_restores_a_dropped_addable_column( string $table_class ): void {
		global $wpdb;

		$table  = $table_class::instance();
		$table->maybe_upgrade();

		$column = $this->pick_droppable_column( $table_class );

		if ( null === $column ) {
			$this->markTestSkipped( $table_class . ' declares no column that is both addable and index-free.' );
		}

		$this->touched[] = $table_class;

		$name       = (string) $column->name;
		$table_name = $wpdb->prefix . self::declared( $table_class, 'name' );

		// Empty the table before the DROP.
		//
		// The chosen column may participate in a composite UNIQUE index — on
		// MCPServerTool it is `ability_slug`, half of unique(server_id,
		// ability_slug). Dropping it collapses that index to unique(server_id),
		// which MySQL refuses while rows share a server_id, so the ALTER fails
		// and the column never goes away. Rows are irrelevant to what this test
		// asserts, and `tear_down()` reinstalls the table anyway.
		$wpdb->query( "DELETE FROM `{$table_name}`" );

		$wpdb->query( "ALTER TABLE `{$table_name}` DROP COLUMN `{$name}`" );
		$this->assertFalse( $table->column_exists( $name ), 'setup: the column must actually be gone' );

		$created = SchemaReconciler::reconcile( $table );

		$this->assertContains( $name, $created, 'the reconciler must report the column it created' );
		$this->assertTrue( $table->column_exists( $name ), 'the column must exist again' );

		$live = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table_name}` LIKE %s", $name ), ARRAY_A );

		$this->assertNotNull( $live, 'SHOW COLUMNS must describe the restored column' );

		// Compare on the base type only. MySQL 8.0.19+ stopped reporting integer
		// display width, so a declared `tinyint(1)` comes back as plain `tinyint`
		// and an equality assertion would fail for the wrong reason.
		$declared_base = strtolower( (string) $column->type );
		$this->assertStringStartsWith(
			$declared_base,
			strtolower( (string) $live['Type'] ),
			'restored column must carry its declared base type'
		);
	}

	/**
	 * A table that already matches its declaration is left completely alone.
	 *
	 * @dataProvider provideTables
	 */
	public function test_second_run_creates_nothing( string $table_class ): void {
		$table = $table_class::instance();
		$table->maybe_upgrade();

		SchemaReconciler::reconcile( $table );

		$this->assertSame(
			array(),
			SchemaReconciler::reconcile( $table ),
			'a reconciled table must need no further work'
		);
	}

	/**
	 * An absent table is BerlinDB's to install. The reconciler must not treat
	 * "no live columns" as "every column is missing" and start altering a table
	 * that is not there.
	 *
	 * @dataProvider provideTables
	 */
	public function test_returns_empty_and_issues_no_ddl_when_table_absent( string $table_class ): void {
		global $wpdb;

		$table      = $table_class::instance();
		$table->maybe_upgrade();
		$table_name = $wpdb->prefix . self::declared( $table_class, 'name' );

		$this->touched[] = $table_class;

		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );

		$this->assertSame( array(), SchemaReconciler::reconcile( $table ), 'no columns may be created' );
		$this->assertEmpty(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ),
			'the reconciler must not have recreated or altered the table'
		);
	}

	/**
	 * The addability rule must reject exactly the definitions MySQL refuses as
	 * an added column. Each assertion below maps to an error verified against
	 * MySQL 8.0.35: 1075 (auto-increment without a key), 1101 (TEXT/BLOB/JSON
	 * carrying a default) and 1067 (the zero date under strict mode).
	 *
	 * @dataProvider provideTables
	 */
	public function test_unsafe_columns_are_never_addable( string $table_class ): void {
		$checked = 0;

		foreach ( $this->declared_columns( $table_class ) as $column ) {
			$create = strtolower( $column->get_create_string() );

			if ( false !== strpos( $create, 'auto_increment' ) ) {
				$this->assertFalse( SchemaReconciler::is_addable( $column ), 'auto-increment columns must be excluded (errno 1075)' );
				++$checked;
				continue;
			}

			$has_default = ( false !== strpos( $create, ' default ' ) );

			if ( $has_default && in_array( strtoupper( (string) $column->type ), SchemaReconciler::DEFAULTLESS_TYPES, true ) ) {
				$this->assertFalse( SchemaReconciler::is_addable( $column ), 'TEXT/BLOB/JSON with a default must be excluded (errno 1101)' );
				++$checked;
				continue;
			}

			if ( false !== strpos( $create, "default '0000-00-00" ) ) {
				$this->assertFalse( SchemaReconciler::is_addable( $column ), 'the zero date must be excluded (errno 1067)' );
				++$checked;
				continue;
			}

			$this->assertTrue( SchemaReconciler::is_addable( $column ), $column->name . ' should be addable' );
		}

		$this->assertGreaterThan( 0, $checked, 'every table has at least an auto-increment key to exclude' );
	}

	/**
	 * The reconciler reads declared values by reflection precisely because
	 * BerlinDB's magic accessor prefers a `get_{$key}()` method when one exists
	 * — which is why `$table->version` silently returns the INSTALLED version.
	 * If a future library release adds getters for the other two, code that
	 * reached for them through the accessor would start reading something else
	 * entirely. Fail loudly here rather than silently there.
	 */
	public function test_no_library_getter_shadows_the_magic_properties(): void {
		$this->assertFalse( method_exists( Table::class, 'get_schema_object' ), 'a get_schema_object() would shadow $table->schema_object' );
		$this->assertFalse( method_exists( Table::class, 'get_table_name' ), 'a get_table_name() would shadow $table->table_name' );
		$this->assertTrue( method_exists( Table::class, 'get_version' ), 'get_version() is the shadowing precedent this guards against' );
	}

	/**
	 * Width and type divergence is reported, never altered.
	 *
	 * Uses MCPServer's slug column: widening it is reversible by tear_down()'s
	 * maybe_upgrade(), and no data can be lost by making a column wider.
	 */
	public function test_divergent_columns_are_reported_not_altered(): void {
		global $wpdb;

		$table_class = MCPServerTable::class;
		$table       = $table_class::instance();
		$table->maybe_upgrade();

		$this->touched[] = $table_class;

		$table_name = $wpdb->prefix . self::declared( $table_class, 'name' );

		// Widen, so nothing can be truncated by the change or by its reversal.
		$wpdb->query( "ALTER TABLE `{$table_name}` MODIFY COLUMN `server_slug` varchar(400) NOT NULL DEFAULT ''" );

		$captured = array();

		add_action(
			'acrossai_mcp_schema_drift_detected',
			static function ( $unhealed, $divergent ) use ( &$captured ): void {
				$captured = $divergent;
			},
			10,
			2
		);

		delete_option( SchemaReconciler::FINGERPRINT_OPTION );
		SchemaReconciler::reset_request_cache();
		SchemaReconciler::maybe_reconcile( array( $table ) );

		$this->assertArrayHasKey( 'acrossai_mcp_servers', $captured, 'divergence must be reported' );
		$this->assertArrayHasKey( 'server_slug', $captured['acrossai_mcp_servers'], 'the widened column must be named' );

		$live = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table_name}` LIKE %s", 'server_slug' ), ARRAY_A );
		$this->assertStringContainsString( '400', (string) $live['Type'], 'the reconciler must NOT have changed the column' );
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
	 * Pick a column the reconciler is expected to be able to restore.
	 *
	 * Chosen by reflection over the declared Schema rather than hard-coded, so
	 * renaming or reordering a column never breaks this test (B48). Indexed
	 * columns are fair game: `tear_down()` reinstalls the table wholesale, so
	 * an index dropped along with its column is restored too.
	 */
	private function pick_droppable_column( string $table_class ): ?Column {
		foreach ( array_reverse( $this->declared_columns( $table_class ) ) as $column ) {
			if ( SchemaReconciler::is_addable( $column ) ) {
				return $column;
			}
		}

		return null;
	}

	/**
	 * Build declared Column objects WITHOUT constructing the Schema.
	 *
	 * Constructing the Schema also constructs its Index objects, and
	 * MCPServerMeta declares an index `length` key that BerlinDB's Index does
	 * not define — a PHP 8.2+ dynamic-property deprecation that prints into
	 * output and makes the test RISKY, which this suite fails on.
	 *
	 * @return Column[]
	 */
	private function declared_columns( string $table_class ): array {
		$columns = array();

		foreach ( self::declared_array( self::declared( $table_class, 'schema' ), 'columns' ) as $args ) {
			$columns[] = new Column( $args );
		}

		return $columns;
	}

	private static function declared( string $class, string $property ): string {
		$value = ( new ReflectionClass( $class ) )->getDefaultProperties()[ $property ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function declared_array( string $class, string $property ): array {
		$value = ( new ReflectionClass( $class ) )->getDefaultProperties()[ $property ] ?? array();

		return is_array( $value ) ? $value : array();
	}
}
