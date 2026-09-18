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

	public function test_seeds_exactly_one_row_on_an_empty_table(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->table() ) );

		DefaultServerSeeder::seed();

		$this->assertSame( 1, $this->count_rows( DefaultServerSeeder::SLUG ) );

		// The whole table, not just the slugs this suite knows about: a second
		// managed row would take id 1 and lead every list, which is how the
		// withdrawn AcrossAI row came to sort above the default one.
		$this->assertSame(
			1,
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table() ) ),
			'Seeding must create ONE server. Adding a second is a product decision, not a detail.'
		);
	}

	/**
	 * The AcrossAI row was withdrawn before 0.3.4 reached any site.
	 *
	 * A second MCP server appearing unasked is a surprise the plugin should not
	 * spring; the `acrossai` server TYPE already covers the case. This asserts
	 * the seeder does not bring it back — the constant survives only because
	 * `Table::upgrade_to_1_1_6()` still names it for installs that have one.
	 */
	public function test_does_not_seed_the_withdrawn_acrossai_row(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->table() ) );

		DefaultServerSeeder::seed();

		$this->assertSame( 0, $this->count_rows( DefaultServerSeeder::ACROSSAI_SLUG ) );
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

		$this->assertSame(
			2,
			$wpdb->num_queries - $before,
			'A converged seeder run must issue exactly one SELECT per managed definition.'
		);
	}
}
