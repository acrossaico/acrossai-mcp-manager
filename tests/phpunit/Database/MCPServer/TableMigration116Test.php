<?php
/**
 * Feature 090 — schema `1.1.6` migration coverage (T011) and the corrective-UPDATE
 * regression (T012).
 *
 * 1.1.6 also added `tools_default_policy`, which schema 1.1.7 drops again; that
 * column's coverage lives in `TableMigration117Test` and is deliberately not
 * duplicated here.
 *
 * T012 is why this file exists. `upgrade_to_1_1_6()` stamps the F088 AcrossAI row
 * to `server_type = 'acrossai'` ONLY when it just created the column. Ungated, a
 * later re-run silently reverts an operator who used the switch-type escape hatch —
 * undoing the very affordance F090's `managed` -> `initial` bucket change exists to
 * provide. `tasks.md` calls T007 the highest-risk task in the feature for exactly
 * this reason, and until now that gate had only ever been checked by hand.
 *
 * Nothing else can catch it: the seeder puts `server_type` in the AcrossAI row's
 * `initial` bucket, so reconciliation never rewrites it either. A regression would
 * be silent, and would only surface as an operator's deliberate choice quietly
 * reverting itself on some later admin page load.
 *
 * Harness notes, each learned the hard way and recorded in BUGS.md:
 *   - `set_up()` / `tear_down()` MUST be public — this WP test library fatals on
 *     `protected` ones (B56).
 *   - PHPUnit is pinned ^9.6: `@dataProvider`, never `#[DataProvider]`.
 *   - DDL implicitly COMMITs and escapes the per-test rollback (B53), so anything
 *     this file disturbs is restored explicitly in `tear_down()`.
 *   - Never hard-code the schema version; read what the class declares (B48).
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use ReflectionClass;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class TableMigration116Test extends WP_UnitTestCase {

	private const VERSION_KEY = 'acrossai_mcp_servers_db_version';

	/**
	 * Establish the managed rows rather than inheriting them.
	 *
	 * Other classes in this suite TRUNCATE `acrossai_mcp_servers`. Their teardowns
	 * re-seed, but that does not survive: `WP_UnitTestCase` runs with
	 * `autocommit=0`, so an INSERT after TRUNCATE's implicit COMMIT opens a fresh
	 * transaction which `parent::tear_down()` rolls back. Seeding here is what
	 * actually makes the precondition true. (Same reasoning as
	 * `DefaultServerSeederTest::set_up()`.)
	 */
	public function set_up(): void {
		parent::set_up();
		DefaultServerSeeder::seed();

		// Force the T012 precondition rather than trusting the previous test's
		// rollback to have restored it.
		//
		// Seeding alone cannot: F090 puts `server_type` in the AcrossAI row's
		// `initial` bucket, which only writes at INSERT, so a row that already
		// exists keeps whatever type it has. And the rollback is not guaranteed
		// — `rerun_migration()` may execute DDL, DDL implicitly COMMITs, and a
		// commit mid-test strands the deliberate 'mcp-adapter' UPDATE that T012
		// performs (B53). Asserting a precondition is cheap; establishing it is
		// what makes these tests order-independent.
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array( 'server_type' => 'acrossai' ),
			array( 'server_slug' => DefaultServerSeeder::ACROSSAI_SLUG ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Restore everything this file is capable of disturbing.
	 *
	 * The version option is rewound by the migration tests, and BerlinDB's
	 * `maybe_upgrade()` re-stamps it — neither is covered by the transaction
	 * rollback once any DDL has committed (B53). The AcrossAI row's type is
	 * likewise restored, because T012 deliberately changes it.
	 */
	public function tear_down(): void {
		update_option( self::VERSION_KEY, $this->declared_version() );
		delete_transient( self::VERSION_KEY . '_upgrade_lock' );

		global $wpdb;
		$wpdb->update(
			$this->table(),
			array( 'server_type' => 'acrossai' ),
			array( 'server_slug' => DefaultServerSeeder::ACROSSAI_SLUG ),
			array( '%s' ),
			array( '%s' )
		);

		parent::tear_down();
	}

	// ------------------------------------------------------------- T011 ----

	public function test_migration_adds_the_server_type_column(): void {
		$this->assertContains( 'server_type', $this->column_names() );
	}

	public function test_the_server_type_default_is_the_backfill_value(): void {
		// The column DEFAULT is not cosmetic — it is what backfills every
		// pre-existing row during the ALTER, which is why the migration writes no
		// backfill pass. Defaulting to 'acrossai' instead would stamp every
		// pre-090 server as an AcrossAI server and change what each one
		// advertises, breaking SC-002.
		$this->assertSame( 'mcp-adapter', $this->column_default( 'server_type' ) );
	}

	public function test_a_row_created_without_a_type_reads_the_legacy_type(): void {
		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Migration 1.1.6 test server',
				'server_slug'            => 'migration-116-' . uniqid(),
				'description'            => 'Seeded by TableMigration116Test',
				'is_enabled'             => 0,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'migration-116',
				'server_version'         => 'v1.0.0',
			)
		);

		$row = MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) )[0];

		$this->assertSame( 'mcp-adapter', (string) $row->server_type );

		global $wpdb;
		$wpdb->delete( $this->table(), array( 'id' => $server_id ), array( '%d' ) );
	}

	public function test_the_acrossai_row_is_corrected_by_the_migration(): void {
		// The column default gets this ONE row wrong: the F088 AcrossAI server is
		// an AcrossAI-type server, but the ALTER stamps it 'mcp-adapter' along
		// with everything else. The seeder cannot fix it either — F090 puts
		// `server_type` in that row's `initial` bucket, which only writes at
		// INSERT, and the row already exists on any site that has F088.
		$this->assertSame( 'acrossai', $this->acrossai_type() );
	}

	// ------------------------------------------------------------- T012 ----

	public function test_a_rerun_does_not_revert_a_deliberate_operator_type_switch(): void {
		global $wpdb;

		// The operator uses the escape hatch: the sibling add-on is deactivated,
		// so they switch the AcrossAI server to the legacy type to keep it usable.
		$wpdb->update(
			$this->table(),
			array( 'server_type' => 'mcp-adapter' ),
			array( 'server_slug' => DefaultServerSeeder::ACROSSAI_SLUG ),
			array( '%s' ),
			array( '%s' )
		);
		$this->assertSame( 'mcp-adapter', $this->acrossai_type(), 'setup: the operator switch must be stored' );

		$this->rerun_migration();

		$this->assertSame(
			'mcp-adapter',
			$this->acrossai_type(),
			'A migration re-run MUST NOT revert a deliberate operator type switch. '
				. 'The corrective UPDATE is gated on having just CREATED the column; '
				. 'ungated, it silently undoes the escape hatch it exists to protect.'
		);
	}

	public function test_a_rerun_leaves_an_untouched_acrossai_row_alone(): void {
		// The mirror of the test above: gating the UPDATE must not be achieved by
		// disabling it. A row nobody touched stays correct across a re-run.
		$this->assertSame( 'acrossai', $this->acrossai_type(), 'setup' );

		$this->rerun_migration();

		$this->assertSame( 'acrossai', $this->acrossai_type() );
	}

	public function test_a_rerun_adds_no_columns_and_loses_no_data(): void {
		$before = $this->column_names();

		$this->rerun_migration();

		$this->assertSame( $before, $this->column_names(), 'Each step is independently idempotent.' );
		$this->assertSame( $this->declared_version(), get_option( self::VERSION_KEY ), 'BerlinDB re-stamps the version.' );
	}

	// ---------------------------------------------------------- helpers ----

	/**
	 * Force `upgrade_to_1_1_6()` to run again against a schema that already has
	 * both columns — the exact state a real re-run encounters.
	 *
	 * Rewinds the stored version rather than deleting the option: deleting it
	 * sends BerlinDB down its FRESH-INSTALL path, which never calls the upgrade
	 * callback at all and would make these tests pass without proving anything.
	 *
	 * The transient is BerlinDB v3's 900-second concurrency guard. Any earlier
	 * test in this suite that ran an upgrade can leave it set, and with it in
	 * place `maybe_upgrade()` returns immediately — again passing vacuously.
	 * (Lesson from `PhantomVersionGuardTest`.)
	 */
	private function rerun_migration(): void {
		update_option( self::VERSION_KEY, '1.1.5' );
		delete_transient( self::VERSION_KEY . '_upgrade_lock' );

		MCPServerTable::instance()->maybe_upgrade();
	}

	/**
	 * The version the Table class DECLARES, read from its default property.
	 *
	 * Never hard-code it: a literal goes stale on the next migration and fails a
	 * test that has nothing to do with the change (B48 — the same trap that broke
	 * `PolicyReconcilerTest` on '1.1.5').
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

	private function column_default( string $column ): string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $this->table() . '` LIKE %s', $column ) );

		return null === $row ? '' : (string) $row->Default;
	}

	private function acrossai_type(): string {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT server_type FROM `' . $this->table() . '` WHERE server_slug = %s',
				DefaultServerSeeder::ACROSSAI_SLUG
			)
		);
	}
}
