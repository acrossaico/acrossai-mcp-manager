<?php
/**
 * FR-018/019 phantom-version guard regression test.
 *
 * Verifies that each of the four Table subclasses drops a stamped-but-orphaned
 * db_version_key option and recreates the missing table on maybe_upgrade().
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Table as CliAuthLogTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table as MCPServerAbilityTable;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class PhantomVersionGuardTest extends WP_UnitTestCase {

	/**
	 * @dataProvider provideTables
	 *
	 * @param string $table_class           Fully qualified Table subclass name.
	 * @param string $table_name_no_prefix  Table name without the wpdb prefix.
	 * @param string $db_version_key        WordPress option key for the schema version.
	 * @param string $version               Unused — the expected version is read from
	 *                                      $table_class::$version, since a hard-coded
	 *                                      value goes stale on every migration.
	 */
	public function test_phantom_version_guard_recreates_dropped_table( string $table_class, string $table_name_no_prefix, string $db_version_key, string $version ): void {
		global $wpdb;

		$full_table = $wpdb->prefix . $table_name_no_prefix;

		// The provider's hard-coded '1.0.0' went stale the moment any table
		// gained a migration (MCPServer is on 1.1.5). Read the version the
		// class actually declares, so adding a migration never breaks this
		// test again. Reflection on the DECLARED default avoids booting the
		// Table just to read a property.
		$version = (string) ( new \ReflectionClass( $table_class ) )->getDefaultProperties()['version'];

		// Ensure table exists first (baseline).
		$table = $table_class::instance();
		$table->maybe_upgrade();
		$this->assertNotEmpty( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table ) ), 'baseline: table must exist' );

		// Stamp the option ourselves rather than trusting whatever earlier tests
		// in this suite left behind — several of them deliberately rewind this
		// value to exercise migrations, and DDL commits escape the per-test
		// rollback. The phantom state under test is "option stamped + table
		// missing", so constructing the stamp explicitly is the point, and the
		// assertion after the DROP still proves dropping the table does not
		// clear it.
		update_option( $db_version_key, $version );

		// WP_UnitTestCase installs `query` filters that rewrite CREATE TABLE and
		// DROP TABLE into their TEMPORARY equivalents. This test needs REAL DDL:
		// with the filters in place the DROP becomes DROP TEMPORARY TABLE, fails
		// against the real table, and the phantom-version state is never created.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// Drop the physical table but leave db_version_key stamped — the "phantom version" state.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $full_table ) );
		$this->assertEmpty( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table ) ), 'setup: table must be dropped' );
		$this->assertSame( $version, get_option( $db_version_key ), 'setup: db_version_key still stamped after drop' );

		// Invoke maybe_upgrade — the phantom-version guard should drop the option and recreate the table.
		$table->maybe_upgrade();

		$this->assertNotEmpty( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table ) ), 'guard: table must be recreated' );
		$this->assertSame( $version, get_option( $db_version_key ), 'guard: db_version_key re-stamped after fresh install' );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function provideTables(): array {
		return array(
			'MCPServer'         => array( MCPServerTable::class, 'acrossai_mcp_servers', 'acrossai_mcp_servers_db_version', '1.0.0' ),
			'CliAuthLog'        => array( CliAuthLogTable::class, 'acrossai_mcp_cli_auth_logs', 'acrossai_mcp_cli_auth_logs_db_version', '1.0.0' ),
			'MCPServerAbility'  => array( MCPServerAbilityTable::class, 'acrossai_mcp_server_abilities', 'acrossai_mcp_server_abilities_db_version', '1.0.0' ),
		);
	}
}
