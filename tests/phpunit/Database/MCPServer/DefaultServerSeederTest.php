<?php
/**
 * Feature 088 — coverage for the declarative plugin-managed server seeder.
 *
 * `WP_UnitTestCase` runs `Activator::activate()`, which calls
 * `DefaultServerSeeder::seed()` after `MCPServerTable::maybe_upgrade()`, so
 * both managed rows already exist when these tests start. Every write here
 * goes through $wpdb inside the per-test transaction, so no tearDown
 * self-heal is required (no DDL is issued).
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use ReflectionMethod;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class DefaultServerSeederTest extends WP_UnitTestCase {

	/**
	 * Seed the managed rows for this test rather than inheriting them.
	 *
	 * Other classes in this suite TRUNCATE acrossai_mcp_servers. Their teardowns
	 * call DefaultServerSeeder::seed() to restore it, but that does not survive:
	 * WP_UnitTestCase runs with autocommit=0, so the INSERT after TRUNCATE's
	 * implicit COMMIT opens a fresh transaction that parent::tearDown() rolls
	 * back. Establishing the precondition here is what actually makes it true.
	 */
	public function set_up(): void {
		parent::set_up();
		DefaultServerSeeder::seed();
	}

	/**
	 * Fully-qualified servers table name.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acrossai_mcp_servers';
	}

	/**
	 * Fetch a managed row by slug.
	 *
	 * @param string $slug Server slug.
	 * @return array<string, string>|null
	 */
	private function row( string $slug ): ?array {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE server_slug = %s LIMIT 1', $this->table(), $slug ),
			ARRAY_A
		);
	}

	/**
	 * Count rows for a slug (proves the seeder never double-inserts).
	 *
	 * @param string $slug Server slug.
	 * @return int
	 */
	private function count_rows( string $slug ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE server_slug = %s', $this->table(), $slug )
		);
	}

	/**
	 * One row per seeded type, and the default one FIRST.
	 *
	 * Order is not cosmetic. Rows are listed by id, so whichever is inserted
	 * first leads every list and the admin's "Default MCP Server" heading — the
	 * exact complaint that got the AcrossAI row withdrawn in F090, when it took
	 * id 1 and sorted above the default one.
	 */
	public function test_seeds_one_row_per_type_with_the_default_first(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->table() ) );

		DefaultServerSeeder::seed();

		$slugs = $wpdb->get_col( $wpdb->prepare( 'SELECT server_slug FROM %i ORDER BY id ASC', $this->table() ) );

		$this->assertSame(
			array_keys( ServerTypes::seeded_servers() ),
			$slugs,
			'Seeded rows must appear in registry order, default first.'
		);
	}

	/**
	 * The AcrossAI row is seeded again, which reverses F090 on purpose.
	 *
	 * The row was never the problem. It arrived carrying mcp-adapter's three
	 * tools under an AcrossAI label, because the `acrossai` type shipped with
	 * an empty tool list that only the add-on could fill. Withdrawing the row
	 * removed the symptom. 0.3.6 moved the Toolset layer into this plugin, so
	 * the type declares its own tools and the row can come back with the right
	 * ones.
	 */
	public function test_seeds_the_acrossai_row_with_its_declared_tools(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->table() ) );

		DefaultServerSeeder::seed();

		$this->assertSame( 1, $this->count_rows( DefaultServerSeeder::ACROSSAI_SLUG ) );

		$row = $this->row( DefaultServerSeeder::ACROSSAI_SLUG );
		$this->assertNotNull( $row );
		$this->assertSame( ServerTypes::ACROSSAI, $row['server_type'] );
		$this->assertSame( '0', (string) $row['is_enabled'], 'A server appearing unasked must not also be live.' );

		$curated = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT ability_slug FROM %i WHERE server_id = %d ORDER BY ability_slug ASC',
				$wpdb->prefix . 'acrossai_mcp_server_tools',
				(int) $row['id']
			)
		);

		// Composed from the registry, never restated: a list written out here
		// would drift from the one the plugin actually seeds (B48).
		$expected = ServerTypes::seeded_servers()[ DefaultServerSeeder::ACROSSAI_SLUG ]['tools'];
		sort( $expected );

		$this->assertSame( $expected, $curated );
	}

	/**
	 * The declared tools are written even though nothing has registered them.
	 *
	 * This is the whole fix, and it looks wrong at a glance. `tools_for()`
	 * narrows to abilities that exist NOW, and at seed time every `toolset/*`
	 * slug narrows away — so resolving through it would leave the AcrossAI
	 * server empty, which is the bug. The slugs are written dormant instead and
	 * light up when the add-on registers the abilities behind them: no type
	 * switch, no "Reset to Type Defaults".
	 */
	public function test_declared_tools_are_written_without_the_abilities_existing(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->table() ) );

		DefaultServerSeeder::seed();

		$row = $this->row( DefaultServerSeeder::ACROSSAI_SLUG );
		$this->assertNotNull( $row );

		$curated = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT ability_slug FROM %i WHERE server_id = %d',
				$wpdb->prefix . 'acrossai_mcp_server_tools',
				(int) $row['id']
			)
		);

		$this->assertNotEmpty( $curated );

		foreach ( $curated as $slug ) {
			$this->assertFalse(
				wp_has_ability( (string) $slug ),
				"Precondition: {$slug} is NOT registered here, and was stored anyway."
			);
		}
	}

	public function test_default_row_identity_is_unchanged_by_f088(): void {
		$row = $this->row( DefaultServerSeeder::SLUG );

		$this->assertNotNull( $row );
		$this->assertSame( 'Default MCP Server', $row['server_name'] );
		$this->assertSame( 'plugin', $row['registered_from'] );
		$this->assertSame( 'mcp', $row['server_route_namespace'] );
		$this->assertSame( DefaultServerSeeder::SLUG, $row['server_route'] );
	}

	public function test_reseeding_never_duplicates_rows(): void {
		DefaultServerSeeder::seed();
		DefaultServerSeeder::seed();

		$this->assertSame( 1, $this->count_rows( DefaultServerSeeder::SLUG ) );
	}

	public function test_restores_the_row_when_it_is_deleted(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE server_slug = %s',
				$this->table(),
				DefaultServerSeeder::SLUG
			)
		);
		$this->assertSame( 0, $this->count_rows( DefaultServerSeeder::SLUG ) );

		DefaultServerSeeder::seed();

		$this->assertSame( 1, $this->count_rows( DefaultServerSeeder::SLUG ) );
	}

	/**
	 * The forward-compatibility contract: managed columns are re-asserted on
	 * every run, so a future version that adds a column (or changes a managed
	 * value) backfills existing installs on the next admin request.
	 */
	public function test_reconciles_drifted_managed_columns(): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'server_name'  => 'Tampered',
				'server_route' => 'tampered',
			),
			array( 'server_slug' => DefaultServerSeeder::SLUG ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		DefaultServerSeeder::seed();

		$row = $this->row( DefaultServerSeeder::SLUG );
		$this->assertSame( 'Default MCP Server', $row['server_name'] );
		$this->assertSame( DefaultServerSeeder::SLUG, $row['server_route'] );
	}

	/**
	 * The other half of the contract: operator-owned columns live in the
	 * `initial` bucket and are written once, so Enable/Disable survives.
	 */
	public function test_does_not_clobber_the_operator_enable_toggle(): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array( 'is_enabled' => 1 ),
			array( 'server_slug' => DefaultServerSeeder::SLUG ),
			array( '%d' ),
			array( '%s' )
		);

		DefaultServerSeeder::seed();

		$this->assertSame(
			'1',
			(string) $this->row( DefaultServerSeeder::SLUG )['is_enabled'],
			'is_enabled is operator-owned and must never be re-forced by the seeder.'
		);
	}

	/**
	 * A healthy install must cost reads only — no INSERT/UPDATE churn on
	 * every admin page load. One SELECT per definition, nothing more.
	 */
	public function test_issues_one_read_per_definition_when_nothing_drifted(): void {
		global $wpdb;

		// Converge first so the run being measured is a steady-state run.
		DefaultServerSeeder::seed();

		$before = $wpdb->num_queries;
		DefaultServerSeeder::seed();

		// Counted from the definitions themselves. A literal here said "2" and
		// went stale the moment a definition was removed, failing a test that
		// is about query CHURN and has nothing to say about how many servers
		// the plugin seeds (B48).
		$definitions = new ReflectionMethod( DefaultServerSeeder::class, 'definitions' );
		$definitions->setAccessible( true );

		$this->assertSame(
			count( (array) $definitions->invoke( null ) ),
			$wpdb->num_queries - $before,
			'A converged seeder run must issue exactly one SELECT per managed definition.'
		);
	}
}
