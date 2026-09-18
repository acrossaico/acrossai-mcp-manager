<?php
/**
 * Feature 090 retraction — schema `1.1.7` DROPs `tools_default_policy`.
 *
 * 1.1.6 added the column one version earlier, to back a coarse `expose`/`hide`
 * rule sitting above the operator's curation. `MCP\ToolExposureGate` gates
 * `tools/call` on curated presence rows alone and never read the column, so an
 * `expose` server advertised tools in `tools/list` that it then refused with
 * `acrossai_mcp_tool_not_added`. The rule is gone and so is its storage.
 *
 * This file exists to make the DROP checkable rather than assumed. A column that
 * `Schema.php` no longer declares but the live table still has is precisely the
 * drift B34 describes: the two disagree and nothing says which is wrong.
 *
 * `abilities_default_policy` (1.1.5, F082) is a DIFFERENT feature on the SAME
 * table and must survive untouched — asserted explicitly below, because a DROP
 * aimed at the wrong `%default_policy%` column is the plausible way to get this
 * wrong.
 *
 * Harness notes, each recorded in BUGS.md:
 *   - `set_up()` / `tear_down()` MUST be public — this WP test library fatals on
 *     `protected` ones (B56).
 *   - DDL implicitly COMMITs and escapes the per-test rollback (B53), so this
 *     file restores the schema AND the version option unconditionally in
 *     `tear_down()`. Without that, a mid-flight failure leaves the column
 *     dropped and the version stamped current PERSISTENTLY — `maybe_upgrade()`
 *     then no-ops forever and unrelated suites start failing on a shared DB.
 *   - Never hard-code the schema version; read what the class declares (B48).
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use ReflectionClass;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

class TableMigration117Test extends WP_UnitTestCase {

	private const VERSION_KEY = 'acrossai_mcp_servers_db_version';

	private const DROPPED = 'tools_default_policy';

	/**
	 * Self-heal, unconditionally.
	 *
	 * Runs whether the test passed, failed or errored — B53's whole point. The
	 * column is NOT recreated: `Schema.php` no longer declares it, so leaving it
	 * absent is the correct resting state. What must be restored is the version
	 * option, which `rerun_migration()` rewinds and BerlinDB re-stamps.
	 */
	public function tear_down(): void {
		update_option( self::VERSION_KEY, $this->declared_version() );
		delete_transient( self::VERSION_KEY . '_upgrade_lock' );

		parent::tear_down();
	}

	public function test_the_tools_policy_column_is_gone(): void {
		$this->assertNotContains(
			self::DROPPED,
			$this->column_names(),
			'Schema.php no longer declares this column; the live table must agree (B34).'
		);
	}

	public function test_the_abilities_policy_column_survives(): void {
		// The sibling column, same table, different feature (F082). The DROP
		// names one column exactly; a wildcard aimed at `%default_policy%` would
		// take out the Abilities tab with it.
		$this->assertContains( 'abilities_default_policy', $this->column_names() );
	}

	public function test_the_row_object_no_longer_carries_the_property(): void {
		$this->assertArrayNotHasKey(
			self::DROPPED,
			( new ReflectionClass( \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row::class ) )->getDefaultProperties(),
			'A Row property with no column behind it reads as storage that silently never persists (B34).'
		);
	}

	public function test_the_migration_drops_the_column_when_it_is_present(): void {
		global $wpdb;

		// Recreate the 1.1.6 state, then prove 1.1.7 removes it. Asserting only
		// that the column is absent would pass vacuously on a database where the
		// migration had never run at all.
		$wpdb->query(
			'ALTER TABLE `' . $this->table() . '` ADD COLUMN `' . self::DROPPED . "` varchar(16) NOT NULL DEFAULT 'per-tool'"
		);
		$this->assertContains( self::DROPPED, $this->column_names(), 'setup: the 1.1.6 column must exist' );

		$this->rerun_migration();

		$this->assertNotContains( self::DROPPED, $this->column_names() );
	}

	public function test_a_rerun_against_an_already_dropped_column_is_harmless(): void {
		$this->assertNotContains( self::DROPPED, $this->column_names(), 'setup' );
		$before = $this->column_names();

		$this->rerun_migration();

		$this->assertSame( $before, $this->column_names(), 'The existence check makes the DROP idempotent.' );
		$this->assertSame( $this->declared_version(), get_option( self::VERSION_KEY ), 'BerlinDB re-stamps the version.' );
	}

	// ---------------------------------------------------------- helpers ----

	/**
	 * Force `upgrade_to_1_1_7()` to run again.
	 *
	 * Rewinds the stored version rather than deleting the option: deleting it
	 * sends BerlinDB down its FRESH-INSTALL path, which never calls the upgrade
	 * callback at all and would make these tests pass without proving anything.
	 *
	 * The transient is BerlinDB v3's 900-second concurrency guard. Any earlier
	 * test in this suite that ran an upgrade can leave it set, and with it in
	 * place `maybe_upgrade()` returns immediately — again passing vacuously.
	 */
	private function rerun_migration(): void {
		update_option( self::VERSION_KEY, '1.1.6' );
		delete_transient( self::VERSION_KEY . '_upgrade_lock' );

		MCPServerTable::instance()->maybe_upgrade();
	}

	/**
	 * The version the Table class DECLARES, read from its default property.
	 *
	 * Never hard-code it: a literal goes stale on the next migration and fails a
	 * test that has nothing to do with the change (B48).
	 */
	private function declared_version(): string {
		return (string) ( new ReflectionClass( MCPServerTable::class ) )->getDefaultProperties()['version'];
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acrossai_mcp_servers';
	}

	/**
	 * @return string[]
	 */
	private function column_names(): array {
		global $wpdb;
		$rows  = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $this->table() . '`' );
		$names = array_map(
			static function ( $row ): string {
				return (string) $row->Field;
			},
			(array) $rows
		);
		sort( $names );

		return $names;
	}
}
