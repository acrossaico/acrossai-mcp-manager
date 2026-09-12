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

	public function test_seeds_both_managed_rows_on_an_empty_table(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->table() ) );

		DefaultServerSeeder::seed();

		$this->assertSame( 1, $this->count_rows( DefaultServerSeeder::SLUG ) );
		$this->assertSame( 1, $this->count_rows( DefaultServerSeeder::ACROSSAI_SLUG ) );
	}

	public function test_acrossai_row_ships_the_expected_identity(): void {
		$row = $this->row( DefaultServerSeeder::ACROSSAI_SLUG );

		$this->assertNotNull( $row, 'Activation must seed the AcrossAI managed row.' );
		$this->assertSame( 'AcrossAI', $row['server_name'] );
		$this->assertSame( 'acrossai', $row['server_route_namespace'] );
		$this->assertSame( 'mcp-server', $row['server_route'] );
		$this->assertSame( 'v1.0.0', $row['server_version'] );

		// Must be 'database', not 'plugin' — MCP\Controller only registers
		// database-sourced rows, so a 'plugin' row would be a dead endpoint.
		$this->assertSame( 'database', $row['registered_from'] );

		// Seeded inactive: an in-place plugin update must not bring an MCP
		// endpoint live without an operator click.
		$this->assertSame( '0', (string) $row['is_enabled'] );
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
		$this->assertSame( 1, $this->count_rows( DefaultServerSeeder::ACROSSAI_SLUG ) );
	}

	public function test_restores_only_the_row_that_was_deleted(): void {
		global $wpdb;

		$default_id = (int) $this->row( DefaultServerSeeder::SLUG )['id'];

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE server_slug = %s',
				$this->table(),
				DefaultServerSeeder::ACROSSAI_SLUG
			)
		);
		$this->assertSame( 0, $this->count_rows( DefaultServerSeeder::ACROSSAI_SLUG ) );

		DefaultServerSeeder::seed();

		$this->assertSame( 1, $this->count_rows( DefaultServerSeeder::ACROSSAI_SLUG ) );
		$this->assertSame(
			$default_id,
			(int) $this->row( DefaultServerSeeder::SLUG )['id'],
			'Re-seeding must leave the untouched managed row alone.'
		);
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
			array( 'server_slug' => DefaultServerSeeder::ACROSSAI_SLUG ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		DefaultServerSeeder::seed();

		$row = $this->row( DefaultServerSeeder::ACROSSAI_SLUG );
		$this->assertSame( 'AcrossAI', $row['server_name'] );
		$this->assertSame( 'mcp-server', $row['server_route'] );
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
			array( 'server_slug' => DefaultServerSeeder::ACROSSAI_SLUG ),
			array( '%d' ),
			array( '%s' )
		);

		DefaultServerSeeder::seed();

		$this->assertSame(
			'1',
			(string) $this->row( DefaultServerSeeder::ACROSSAI_SLUG )['is_enabled'],
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
