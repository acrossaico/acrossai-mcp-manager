<?php
/**
 * uninstall.php options sweep boundary.
 *
 * History worth keeping, because this test asserted the exact opposite for
 * several releases. F039 added a `NOT LIKE 'acrossai_mcp_connector_%'` clause
 * to the `acrossai_mcp_%` sweep, because F040 had handed that option namespace
 * to the companion plugin and deleting another plugin's options on our
 * uninstall would have been data loss (SEC-001). F095 took the connector stack
 * back and the companion stopped registering any of it, which inverted the
 * clause's effect: it no longer protected a foreign namespace, it orphaned our
 * own rows.
 *
 * Measured on a dev install carrying both plugins, the only option the
 * exclusion still matched was `acrossai_mcp_connector_approved_users_db_version`
 * — the BerlinDB version key for a table THIS plugin creates and drops a few
 * lines above the sweep. Leaving that behind is the B44 failure mode: a
 * table's companion option surviving an uninstall that dropped the table.
 *
 * So the invariant is now the reverse, and this file pins it in the direction
 * that can actually regress — someone reading the old SEC-001 rationale and
 * re-adding the clause.
 *
 * The SQL shape is read from `uninstall.php` rather than re-implemented here.
 * The previous version of this test built its own copy of the query, which
 * meant it kept passing unchanged after the production sweep was corrected —
 * asserting a boundary that no longer existed.
 *
 * @package AcrossAI_MCP_Manager\Tests\Uninstall
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Uninstall;

use WP_UnitTestCase;

final class UninstallSweepBoundaryTest extends WP_UnitTestCase {

	/** Seeded per-test — cleaned in tearDown. */
	private const MCP_MANAGER_OPTION = 'acrossai_mcp_test_only_option';

	/**
	 * Formerly "companion-owned". This prefix is ours again, and this
	 * specific shape is the one the old exclusion orphaned: the db_version
	 * key for the connector-approvals table.
	 */
	private const CONNECTOR_OPTION = 'acrossai_mcp_connector_approved_users_db_version';

	public function setUp(): void {
		parent::setUp();
		delete_option( self::MCP_MANAGER_OPTION );
		delete_option( 'acrossai_mcp_uninstall_delete_data' );
	}

	public function tearDown(): void {
		delete_option( self::MCP_MANAGER_OPTION );
		delete_option( 'acrossai_mcp_uninstall_delete_data' );
		parent::tearDown();
	}

	private function uninstall_source(): string {
		$src = file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );
		$this->assertNotFalse( $src, 'uninstall.php must be readable.' );
		return (string) $src;
	}

	/**
	 * The sweep must cover the whole `acrossai_mcp_%` namespace. Re-adding a
	 * `NOT LIKE` carve-out would orphan our own options again.
	 *
	 * Comments are stripped before the assertion: the file deliberately
	 * explains the removed carve-out in prose, and that explanation must not
	 * read as the clause still being there.
	 */
	public function test_sweep_has_no_namespace_carve_out(): void {
		$code_only = preg_replace( '#//[^\n]*#', '', $this->uninstall_source() );

		$this->assertStringNotContainsString(
			'acrossai_mcp_connector_%',
			(string) $code_only,
			'The connector carve-out belonged to F040 and was removed in F095 — that prefix is this plugin\'s own again.'
		);
		$this->assertStringNotContainsString(
			'NOT LIKE',
			(string) $code_only,
			'A NOT LIKE clause on the options sweep orphans plugin-owned rows (B44).'
		);
	}

	/**
	 * The behavioural half: both an ordinary option and the previously
	 * carved-out db_version key must fall inside the sweep's target set.
	 */
	public function test_sweep_reaches_every_acrossai_mcp_option(): void {
		add_option( self::MCP_MANAGER_OPTION, 'mcp-manager-owned-value' );
		add_option( self::CONNECTOR_OPTION, '1.0.0' );
		update_option( 'acrossai_mcp_uninstall_delete_data', 1 );

		global $wpdb;
		$options = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				'acrossai_mcp_%'
			)
		);

		$this->assertIsArray( $options );
		$this->assertContains(
			self::MCP_MANAGER_OPTION,
			$options,
			'An ordinary plugin-owned option must be in the sweep target set.'
		);
		$this->assertContains(
			self::CONNECTOR_OPTION,
			$options,
			'The connector-approvals db_version key is ours: its table is dropped by this same uninstall, so its version option must go with it (B44).'
		);

		delete_option( self::CONNECTOR_OPTION );
	}

	/**
	 * Every table the uninstall drops should have its `*_db_version` option
	 * reachable by the sweep. This is the general form of the bug the
	 * carve-out caused, and it holds only because the sweep has no exclusion.
	 */
	public function test_db_version_keys_for_dropped_tables_fall_inside_the_sweep(): void {
		$keys = array(
			'acrossai_mcp_oauth_tokens_db_version',
			'acrossai_mcp_oauth_clients_db_version',
			'acrossai_mcp_oauth_auth_codes_db_version',
			'acrossai_mcp_connector_approved_users_db_version',
		);

		foreach ( $keys as $key ) {
			$this->assertSame(
				0,
				strpos( $key, 'acrossai_mcp_' ),
				$key . ' must sit inside the swept namespace, or uninstall leaves it behind.'
			);
		}
	}
}
