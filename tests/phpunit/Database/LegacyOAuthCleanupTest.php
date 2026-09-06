<?php
/**
 * F083 — coverage for the one-shot orphaned-OAuth-table cleanup.
 *
 * Exercises every branch of `LegacyOAuthCleanup::maybe_cleanup()`:
 * empty-table drop + version-option delete, non-empty skip + D19 action
 * payload, absent-table stale-option sweep, done-flag short-circuit.
 *
 * NOTE ON DDL (B53): CREATE/DROP TABLE implicitly COMMITs and escapes
 * WP_UnitTestCase's per-test rollback, so tear_down() unconditionally drops
 * every legacy-named table this test may have created and clears the flag +
 * version options — the shared test DB must never inherit state from here.
 *
 * @package AcrossAI_MCP_Manager\Tests\Database
 */

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use AcrossAI_MCP_Manager\Includes\Database\LegacyOAuthCleanup;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

/**
 * Tests for LegacyOAuthCleanup::maybe_cleanup().
 */
class LegacyOAuthCleanupTest extends WP_UnitTestCase {

	/**
	 * WP's test suite rewrites CREATE/DROP TABLE into TEMPORARY variants via
	 * query filters — but TEMPORARY tables are invisible to
	 * INFORMATION_SCHEMA, which is exactly how the production code (and this
	 * test's own existence probe) detects the orphans. Remove the rewriters
	 * so the fixtures are REAL tables; the B53 tear_down below guarantees
	 * they never outlive a test.
	 */
	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * B53 self-heal — remove every artifact this test can leave behind,
	 * regardless of how each test exited.
	 */
	public function tear_down(): void {
		global $wpdb;
		foreach ( LegacyOAuthCleanup::LEGACY_TABLE_STEMS as $stem ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $stem ) );
			delete_option( $stem . '_db_version' );
		}
		delete_option( LegacyOAuthCleanup::DONE_OPTION );
		parent::tear_down();
	}

	/**
	 * Create a minimal legacy-shaped table for one stem.
	 *
	 * @param string $stem Prefix-less table stem.
	 */
	private function create_legacy_table( string $stem ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, payload VARCHAR(64) NULL, PRIMARY KEY (id) )', $wpdb->prefix . $stem ) );
	}

	private function table_exists( string $stem ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$wpdb->prefix . $stem
			)
		);
	}

	public function test_empty_legacy_tables_are_dropped_and_version_options_deleted(): void {
		delete_option( LegacyOAuthCleanup::DONE_OPTION );
		$this->create_legacy_table( 'acrossai_mcp_oauth_clients' );
		update_option( 'acrossai_mcp_oauth_clients_db_version', '1.0.0' );

		LegacyOAuthCleanup::maybe_cleanup();

		$this->assertFalse( $this->table_exists( 'acrossai_mcp_oauth_clients' ), 'Empty orphaned table must be dropped.' );
		$this->assertFalse( get_option( 'acrossai_mcp_oauth_clients_db_version' ), 'Legacy version option must be deleted.' );
		$this->assertSame( 1, (int) get_option( LegacyOAuthCleanup::DONE_OPTION ), 'Done flag must be set.' );
	}

	public function test_non_empty_legacy_table_is_never_dropped_and_skip_action_fires(): void {
		global $wpdb;
		delete_option( LegacyOAuthCleanup::DONE_OPTION );
		$this->create_legacy_table( 'acrossai_mcp_oauth_tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $wpdb->prefix . 'acrossai_mcp_oauth_tokens', array( 'payload' => 'live-row' ), array( '%s' ) );

		$captured = null;
		add_action(
			'acrossai_mcp_legacy_oauth_cleanup_skipped',
			static function ( $skipped ) use ( &$captured ) {
				$captured = $skipped;
			}
		);

		LegacyOAuthCleanup::maybe_cleanup();

		$this->assertTrue( $this->table_exists( 'acrossai_mcp_oauth_tokens' ), 'Non-empty orphaned table must NOT be auto-dropped — pro never migrated data, these rows exist nowhere else.' );
		$this->assertSame( array( 'acrossai_mcp_oauth_tokens' => 1 ), $captured, 'Skip action must carry the stem => row-count map (D19).' );
		$this->assertSame( 1, (int) get_option( LegacyOAuthCleanup::DONE_OPTION ), 'Done flag still sets — no per-request retry loop.' );
	}

	public function test_absent_table_with_stale_version_option_gets_option_swept(): void {
		delete_option( LegacyOAuthCleanup::DONE_OPTION );
		update_option( 'acrossai_mcp_oauth_auth_codes_db_version', '1.0.0' );

		LegacyOAuthCleanup::maybe_cleanup();

		$this->assertFalse( get_option( 'acrossai_mcp_oauth_auth_codes_db_version' ), 'Stale version option must be deleted even when its table is already gone.' );
	}

	public function test_done_flag_short_circuits_subsequent_runs(): void {
		update_option( LegacyOAuthCleanup::DONE_OPTION, 1, false );
		$this->create_legacy_table( 'acrossai_mcp_oauth_clients' );

		LegacyOAuthCleanup::maybe_cleanup();

		$this->assertTrue( $this->table_exists( 'acrossai_mcp_oauth_clients' ), 'With the done flag set, maybe_cleanup() must be a no-op.' );
	}
}
