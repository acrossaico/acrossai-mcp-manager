<?php
/**
 * F095 — OAuth data migration behaviour.
 *
 * Covers T017 (fidelity + rollback-path integrity), T018 (idempotency),
 * T019 (resume) and T026 (diagnostics disclose nothing) in one file, because
 * all four need the same expensive fixture: four real companion-shaped source
 * tables, seeded and torn down. Splitting them as the task list originally
 * proposed would have duplicated that fixture four times.
 *
 * The single most important assertion in this feature is
 * `test_token_hashes_transfer_byte_for_byte`. Tokens exist server-side ONLY as
 * SHA-256 digests; the raw token lives on the AI client and nowhere else. A
 * digest altered in transit cannot be reconstructed from anything, so that
 * test failing means every connected client is permanently disconnected.
 *
 * Harness notes:
 *   - `set_up()` / `tear_down()` MUST be public — this WP test library fatals
 *     on protected ones (B56).
 *   - WP_UnitTestCase rewrites CREATE/DROP TABLE into TEMPORARY equivalents
 *     via `query` filters. This fixture needs REAL tables, because the
 *     migration probes them with `SHOW TABLES LIKE` — which does not see
 *     temporary tables. Both filters are removed in `set_up()`.
 *   - That DDL commits and therefore escapes the per-test transaction rollback
 *     (B53), so `tear_down()` drops unconditionally.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use AcrossAI_MCP_Manager\Includes\Database\OAuthDataMigration;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Table as OAuthTokensTable;
use WP_UnitTestCase;

/**
 * Exercises the companion-to-plugin OAuth copy.
 */
class OAuthDataMigrationTest extends WP_UnitTestCase {

	/** Prefix-less companion source table for tokens. */
	private const SOURCE_TOKENS = 'acrossai_pro_mcp_oauth_tokens';

	/** Prefix-less destination table for tokens. */
	private const DEST_TOKENS = 'acrossai_mcp_oauth_tokens';

	/** Every companion table the migration reads. */
	private const SOURCE_TABLES = array(
		'acrossai_pro_mcp_oauth_tokens',
		'acrossai_pro_mcp_oauth_auth_codes',
		'acrossai_pro_mcp_oauth_clients',
		'acrossai_pro_mcp_connector_approved_users',
	);

	public function set_up(): void {
		parent::set_up();

		// REAL DDL required — see the class docblock.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->reset_migration_state();
		$this->drop_source_tables();
		$this->restore_destination_tokens();

		// Rows do NOT roll back here. The DDL above commits implicitly, which
		// ends WP_UnitTestCase's per-test transaction (B53), so anything a
		// previous test inserted is still present. Start from empty explicitly.
		$this->truncate_destination_tokens();
	}

	public function tear_down(): void {
		// Leave the database exactly as found. This class DROPS the destination
		// table in one test, and because DDL commits, that drop would otherwise
		// escape into sibling test classes — PhantomVersionGuardTest asserts
		// the same table exists at baseline and would fail with no indication
		// that this file was responsible.
		$this->drop_source_tables();
		$this->restore_destination_tokens();
		$this->truncate_destination_tokens();
		$this->reset_migration_state();

		parent::tear_down();
	}

	/**
	 * Digests must survive the copy unaltered. The feature's critical test.
	 */
	public function test_token_hashes_transfer_byte_for_byte(): void {
		global $wpdb;

		$this->create_tokens_source();
		$hashes = $this->seed_tokens( 5 );

		OAuthDataMigration::maybe_migrate();

		$copied = $wpdb->get_col( 'SELECT token_hash FROM `' . $wpdb->prefix . self::DEST_TOKENS . '` ORDER BY id ASC' );

		$this->assertSame(
			$hashes,
			$copied,
			'token_hash must transfer byte-for-byte. A digest altered in transit cannot be '
				. 'reconstructed — the raw token exists only on the AI client — so this failing '
				. 'means every connected client is permanently disconnected.'
		);
	}

	/**
	 * The source tables are the only rollback path and must be untouched.
	 */
	public function test_source_tables_are_not_modified(): void {
		global $wpdb;

		$this->create_tokens_source();
		$this->seed_tokens( 5 );

		$source   = $wpdb->prefix . self::SOURCE_TOKENS;
		$before   = $wpdb->get_results( 'SELECT * FROM `' . $source . '` ORDER BY id ASC', ARRAY_A );
		$checksum = md5( (string) wp_json_encode( $before ) );

		OAuthDataMigration::maybe_migrate();

		$after = $wpdb->get_results( 'SELECT * FROM `' . $source . '` ORDER BY id ASC', ARRAY_A );

		$this->assertSame(
			$checksum,
			md5( (string) wp_json_encode( $after ) ),
			'The migration must COPY, never move. The companion tables are the only rollback path.'
		);
	}

	/**
	 * Running twice must not duplicate rows.
	 */
	public function test_migration_is_idempotent(): void {
		global $wpdb;

		$this->create_tokens_source();
		$this->seed_tokens( 5 );

		OAuthDataMigration::maybe_migrate();
		$first = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . self::DEST_TOKENS . '`' );

		// Clear the done-flag so the second call does real work rather than
		// short-circuiting at the guard.
		delete_option( OAuthDataMigration::DONE_OPTION );
		delete_option( OAuthDataMigration::CURSOR_OPTION );
		OAuthDataMigration::maybe_migrate();

		$second = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . self::DEST_TOKENS . '`' );

		$this->assertSame( 5, $first, 'First pass must copy every row.' );
		$this->assertSame( $first, $second, 'A re-run must not duplicate rows.' );
	}

	/**
	 * An interrupted run resumes from its cursor instead of restarting.
	 */
	public function test_migration_resumes_from_its_cursor(): void {
		global $wpdb;

		$this->create_tokens_source();
		$this->seed_tokens( 5 );

		// Simulate a run that got through the first two rows and then died.
		update_option(
			OAuthDataMigration::CURSOR_OPTION,
			array( self::DEST_TOKENS => 2 ),
			false
		);

		OAuthDataMigration::maybe_migrate();

		$copied = $wpdb->get_col( 'SELECT id FROM `' . $wpdb->prefix . self::DEST_TOKENS . '` ORDER BY id ASC' );

		$this->assertSame(
			array( '3', '4', '5' ),
			$copied,
			'Resume must start after the stored cursor, not from the beginning.'
		);
	}

	/**
	 * A fresh install that never had the companion migrates silently.
	 */
	public function test_absent_companion_completes_silently(): void {
		$this->drop_source_tables();

		$notices = 0;
		add_action(
			'acrossai_mcp_oauth_migration_stalled',
			static function () use ( &$notices ): void {
				++$notices;
			}
		);

		OAuthDataMigration::maybe_migrate();

		$this->assertSame( 0, $notices, 'No stall signal may fire when the companion was never installed.' );
		$this->assertSame( 1, (int) get_option( OAuthDataMigration::DONE_OPTION ), 'Done-flag must be set so later page loads short-circuit.' );
		$this->assertFalse( get_option( OAuthDataMigration::CURSOR_OPTION ), 'No cursor state may be written when there is nothing to copy.' );
	}

	/**
	 * Diagnostics must never carry credential material (SC-C2 / SEC-002).
	 */
	public function test_stall_diagnostics_disclose_no_digest(): void {
		$payloads = array();

		add_action(
			'acrossai_mcp_oauth_migration_stalled',
			static function ( $table, $cursor, $failures ) use ( &$payloads ): void {
				$payloads[] = (string) $table . '|' . (string) $cursor . '|' . (string) $failures;
			},
			10,
			3
		);

		// Source present but destination absent → every batch fails. Repeat
		// past the threshold so the stall signal fires.
		$this->create_tokens_source();
		$this->seed_tokens( 3 );
		$this->drop_destination_tokens();

		for ( $i = 0; $i < OAuthDataMigration::FAILURE_THRESHOLD; $i++ ) {
			OAuthDataMigration::maybe_migrate();
		}

		$this->assertNotEmpty( $payloads, 'A persistently failing migration must become visible.' );

		foreach ( $payloads as $payload ) {
			$this->assertDoesNotMatchRegularExpression(
				'/[0-9a-f]{64}/i',
				$payload,
				'Diagnostics must not contain a 64-character hex string. The migration moves '
					. 'credential digests, and a diagnostic echoing row data writes them to the '
					. 'error log and Site Health (CWE-532).'
			);
		}
	}

	/* Fixture helpers ----------------------------------------------------- */

	/**
	 * Creates a companion-shaped tokens table.
	 *
	 * Mirrors the shape the migration reads; only the columns under test need
	 * to be faithful, since the copy intersects live columns on both sides.
	 */
	private function create_tokens_source(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::SOURCE_TOKENS;

		$wpdb->query(
			'CREATE TABLE IF NOT EXISTS `' . $table . '` (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_hash char(64) NOT NULL DEFAULT \'\',
				token_type varchar(16) NOT NULL DEFAULT \'\',
				client_id varchar(191) NOT NULL DEFAULT \'\',
				server_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				token_family_id char(36) NOT NULL DEFAULT \'\',
				revoked tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (id)
			)'
		);
	}

	/**
	 * Seeds N token rows and returns their digests in id order.
	 *
	 * @param  int $count Rows to create.
	 * @return string[]
	 */
	private function seed_tokens( int $count ): array {
		global $wpdb;

		$table  = $wpdb->prefix . self::SOURCE_TOKENS;
		$hashes = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			// Deliberately mixed-case and full-width: a digest that survives
			// this survives any trim, fold or re-encode in the copy path.
			$hash     = str_pad( strtoupper( dechex( $i ) ) . 'aF', 64, '0123456789abcdefABCDEF' );
			$hashes[] = $hash;

			$wpdb->insert(
				$table,
				array(
					'id'              => $i,
					'token_hash'      => $hash,
					'token_type'      => 0 === $i % 2 ? 'refresh' : 'access',
					'client_id'       => 'client-' . $i,
					'server_id'       => 1,
					'user_id'         => 1,
					'token_family_id' => 'family-' . $i,
					'revoked'         => 0,
				)
			);
		}

		return $hashes;
	}

	private function drop_source_tables(): void {
		global $wpdb;

		foreach ( self::SOURCE_TABLES as $stem ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . $stem . '`' );
		}
	}

	private function drop_destination_tokens(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . self::DEST_TOKENS . '`' );
		delete_option( 'acrossai_mcp_oauth_tokens_db_version' );
	}

	/**
	 * Recreate the destination table if a test dropped it.
	 *
	 * Clears BerlinDB's upgrade lock first: it is a 900-second production
	 * concurrency guard, and with it set `maybe_upgrade()` returns without
	 * doing anything, so the table would never come back.
	 */
	private function restore_destination_tokens(): void {
		delete_transient( 'acrossai_mcp_oauth_tokens_db_version_upgrade_lock' );
		OAuthTokensTable::instance()->maybe_upgrade();
	}

	/**
	 * Empty the destination without dropping it.
	 *
	 * Needed because inserts in this class are not rolled back — see set_up().
	 */
	private function truncate_destination_tokens(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::DEST_TOKENS;

		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$wpdb->query( 'TRUNCATE TABLE `' . $table . '`' );
		}
	}

	private function reset_migration_state(): void {
		delete_option( OAuthDataMigration::DONE_OPTION );
		delete_option( OAuthDataMigration::CURSOR_OPTION );
		delete_option( OAuthDataMigration::FAILURE_OPTION );
	}
}
