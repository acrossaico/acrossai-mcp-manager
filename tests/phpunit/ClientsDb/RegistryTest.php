<?php
/**
 * DB-backed contract for the clients registry (Feature 104).
 *
 * Needs a real database, because what is being tested is what MySQL actually
 * does: the upsert collides on a UNIQUE index, increments in place, and refuses
 * to let a later sighting blank what an earlier one established. None of that
 * is observable from the declarations — `Clients\Query::touch()` is one
 * statement and MySQL decides its semantics.
 *
 * Runs under tests/bootstrap-wp.php, like the activity log's DB suite.
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ClientsDb;

use AcrossAI_MCP_Manager\Includes\Database\Clients\Identity;
use AcrossAI_MCP_Manager\Includes\Database\Clients\Query;
use AcrossAI_MCP_Manager\Includes\Database\Clients\Recorder;
use AcrossAI_MCP_Manager\Includes\Database\Clients\Row;
use AcrossAI_MCP_Manager\Includes\Database\Clients\Schema;
use AcrossAI_MCP_Manager\Includes\Database\Clients\Table;
use AcrossAI_MCP_Manager\Includes\Observability\ConnectionContext;
use AcrossAI_MCP_Manager\Includes\Utilities\ClientsConstants;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Database\Clients\Query
 * @covers \AcrossAI_MCP_Manager\Includes\Database\Clients\Recorder
 */
class RegistryTest extends \WP_UnitTestCase {

	/** Server id used throughout; arbitrary and never read back as a server. */
	private const SERVER = 4242;

	public function set_up(): void {
		parent::set_up();

		Table::instance()->maybe_upgrade();

		delete_option( ClientsConstants::OPTION_WRITE_FAILURES );

		$this->wipe();
	}

	public function tear_down(): void {
		$this->wipe();

		delete_option( ClientsConstants::OPTION_WRITE_FAILURES );

		parent::tear_down();
	}

	/** Remove every row this suite writes, so each test starts empty. */
	private function wipe(): void {
		Query::instance()->delete_items_for_server( self::SERVER );
		Query::instance()->delete_items_for_server( self::SERVER + 1 );
	}

	/** The signals a plain application-password client would present. */
	private function signals( array $overrides = array() ): array {
		return array_merge(
			array(
				'server_id'       => self::SERVER,
				'connection_type' => ConnectionContext::TYPE_APP_PASSWORD,
				'client_name'     => 'Claude Code',
				'client_version'  => '2.1.236',
				'user_id'         => 7,
				'user_agent'      => 'claude-code/2.1.236 mcp-wordpress-remote/0.5.4',
				'ip_address'      => '203.0.113.9',
			),
			$overrides
		);
	}

	/** The single row for a set of signals, or null. */
	private function find( array $signals ): ?Row {
		$type = (string) ( $signals['connection_type'] ?? '' );

		return Query::instance()->find(
			(int) $signals['server_id'],
			Identity::hash( (int) $signals['server_id'], $type, Identity::resolve( $signals ) )
		);
	}

	public function test_the_table_was_actually_created(): void {
		$this->assertTrue( Table::instance()->exists() );
	}

	/**
	 * Every declared column exists at its declared width — the B34 guard. A
	 * live table can drift from Schema.php while the stored version still
	 * matches, because dbDelta compares names and not types.
	 */
	public function test_every_declared_column_exists_at_its_declared_width(): void {
		global $wpdb;

		$table = $wpdb->prefix . ClientsConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );
		$live = array();

		foreach ( $rows as $row ) {
			$live[ $row->Field ] = strtolower( (string) $row->Type );
		}

		foreach ( ( new Schema() )->columns as $column ) {
			$name = (string) $column->name;

			$this->assertArrayHasKey( $name, $live, "Column {$name} is declared but absent from the live table." );

			$length = (string) ( $column->length ?? '' );

			if ( '' !== $length && in_array( strtolower( (string) $column->type ), array( 'varchar', 'char' ), true ) ) {
				$this->assertStringContainsString(
					'(' . $length . ')',
					$live[ $name ],
					"Column {$name} is declared at length {$length} but the live table disagrees."
				);
			}
		}
	}

	/** A first sighting creates one row with a count of 1. */
	public function test_a_first_sighting_creates_one_row(): void {
		$signals = $this->signals();

		$this->assertTrue( Recorder::record( $signals ) );

		$row = $this->find( $signals );

		$this->assertInstanceOf( Row::class, $row );
		$this->assertSame( 1, $row->request_count );
		$this->assertSame( 'Claude Code', $row->client_name );
		$this->assertSame( ClientsConstants::ROUTE_NPM, $row->route );
		$this->assertTrue( $row->has_been_used() );
		$this->assertSame( 'active', $row->status() );
	}

	/**
	 * The upsert is idempotent in rows and additive in counts. This is the
	 * invariant the UNIQUE index exists for: three sightings of one client are
	 * one row, not three.
	 */
	public function test_repeat_sightings_increment_rather_than_duplicate(): void {
		$signals = $this->signals();

		Recorder::record( $signals );
		Recorder::record( $signals );
		Recorder::record( $signals );

		$row = $this->find( $signals );

		$this->assertInstanceOf( Row::class, $row );
		$this->assertSame( 3, $row->request_count );
		$this->assertSame( 1, $this->count_rows() );
	}

	/**
	 * A later request without `clientInfo` must not blank the name an earlier
	 * `initialize` established.
	 *
	 * This is why `touch()` guards each identifying field with an `IF(... = '')`
	 * rather than assigning `VALUES()` wholesale. Without it, the SECOND
	 * request from every client would erase the only one that carried a name.
	 */
	public function test_a_later_nameless_request_does_not_erase_the_name(): void {
		$named = $this->signals();

		Recorder::record( $named );

		// Same identity: the name resolves the identity on the first call, so
		// the follow-up has to present the same one to collide — which is what
		// the adapter session recovery does in production.
		Recorder::record( $this->signals( array( 'client_version' => '' ) ) );

		$row = $this->find( $named );

		$this->assertInstanceOf( Row::class, $row );
		$this->assertSame( 'Claude Code', $row->client_name );
		$this->assertSame( '2.1.236', $row->client_version, 'A sighting without a version must not blank the stored one.' );
		$this->assertSame( 2, $row->request_count );
	}

	/** `first_seen_at` is written once and survives every later sighting. */
	public function test_first_seen_is_never_rewritten(): void {
		$signals = $this->signals( array( 'first_seen_at' => '2020-01-01 00:00:00' ) );

		Recorder::record( $signals );
		Recorder::record( $this->signals() );

		$row = $this->find( $signals );

		$this->assertInstanceOf( Row::class, $row );
		$this->assertSame( '2020-01-01 00:00:00', $row->first_seen_at );
	}

	/**
	 * A registered-but-unused client is a distinct state, and a real sighting
	 * promotes it without losing its history.
	 */
	public function test_a_registered_client_reads_as_never_used_until_it_calls(): void {
		$signals = array(
			'server_id'       => self::SERVER,
			'connection_type' => ConnectionContext::TYPE_AI_CONNECTOR,
			'client_id'       => 'mcp-abc123',
			'client_name'     => 'Claude',
			'connector_slug'  => 'claude',
		);

		$this->assertTrue( Recorder::record_registered( $signals ) );

		$row = $this->find( $signals );

		$this->assertInstanceOf( Row::class, $row );
		$this->assertFalse( $row->has_been_used() );
		$this->assertNull( $row->last_seen_at );
		$this->assertSame( 0, $row->request_count );
		$this->assertSame( 'never-used', $row->status() );

		Recorder::record( $signals );

		$row = $this->find( $signals );

		$this->assertInstanceOf( Row::class, $row );
		$this->assertTrue( $row->has_been_used() );
		$this->assertSame( 1, $row->request_count );
		$this->assertSame( 1, $this->count_rows(), 'The sighting must land on the registered row, not beside it.' );
	}

	/**
	 * A "registered, never used" write arriving AFTER a real sighting must not
	 * roll the last-seen back to null.
	 *
	 * Order-dependent bugs like this are exactly what a backfill produces, and
	 * the symptom — an active client reported as never used — is the opposite
	 * of the truth.
	 */
	public function test_a_registered_write_cannot_unset_a_real_last_seen(): void {
		$signals = array(
			'server_id'       => self::SERVER,
			'connection_type' => ConnectionContext::TYPE_OAUTH,
			'client_id'       => 'mcp-xyz789',
		);

		Recorder::record( $signals );
		Recorder::record_registered( $signals );

		$row = $this->find( $signals );

		$this->assertInstanceOf( Row::class, $row );
		$this->assertTrue( $row->has_been_used() );
	}

	/** The same client on two servers is two rows — two separate grants. */
	public function test_the_same_client_on_two_servers_stays_two_rows(): void {
		Recorder::record( $this->signals() );
		Recorder::record( $this->signals( array( 'server_id' => self::SERVER + 1 ) ) );

		$this->assertSame( 1, $this->count_rows( self::SERVER ) );
		$this->assertSame( 1, $this->count_rows( self::SERVER + 1 ) );
	}

	/** A sighting with no resolvable server is dropped, not filed under 0. */
	public function test_a_sighting_without_a_server_is_not_recorded(): void {
		$this->assertFalse( Recorder::record( $this->signals( array( 'server_id' => 0 ) ) ) );
	}

	/** Revoking keeps the row and its history, and changes the status. */
	public function test_revoking_keeps_the_row(): void {
		$signals = $this->signals();

		Recorder::record( $signals );

		$row = $this->find( $signals );

		$this->assertInstanceOf( Row::class, $row );
		$this->assertTrue( Recorder::revoke( $row->id ) );

		$row = $this->find( $signals );

		$this->assertInstanceOf( Row::class, $row );
		$this->assertTrue( $row->is_revoked() );
		$this->assertSame( 'revoked', $row->status() );
		$this->assertSame( 1, $row->request_count, 'Revoking must not lose the history.' );
	}

	/** The summary counts each state once. */
	public function test_the_summary_separates_the_three_states(): void {
		$active = $this->signals();

		Recorder::record( $active );

		Recorder::record_registered(
			array(
				'server_id'       => self::SERVER,
				'connection_type' => ConnectionContext::TYPE_OAUTH,
				'client_id'       => 'mcp-never-called',
			)
		);

		$revoked = $this->signals( array( 'client_name' => 'Cursor' ) );

		Recorder::record( $revoked );

		$row = $this->find( $revoked );

		$this->assertInstanceOf( Row::class, $row );

		Recorder::revoke( $row->id );

		$summary = Query::instance()->summary( self::SERVER );

		$this->assertSame( 3, $summary['total'] );
		$this->assertSame( 1, $summary['active'] );
		$this->assertSame( 1, $summary['never_used'] );
		$this->assertSame( 1, $summary['revoked'] );
	}

	/**
	 * The summary respects its server scope.
	 *
	 * Pinned because it did not: `summary()` bound its two `%d` placeholders in
	 * the order the values were decided rather than the order they appear in
	 * the statement, so the server id went to the idle INTERVAL and the day
	 * count to `server_id`. Both are integers and both are %d, so `prepare()`
	 * saw nothing wrong — the only symptom was a per-server tab rendering no
	 * summary line while its table showed seventeen rows.
	 */
	public function test_the_summary_is_scoped_to_one_server(): void {
		Recorder::record( $this->signals() );
		Recorder::record( $this->signals( array( 'server_id' => self::SERVER + 1 ) ) );
		Recorder::record( $this->signals( array( 'server_id' => self::SERVER + 1, 'client_name' => 'Cursor' ) ) );

		$this->assertSame( 1, Query::instance()->summary( self::SERVER )['total'] );
		$this->assertSame( 2, Query::instance()->summary( self::SERVER + 1 )['total'] );
		$this->assertSame( 1, Query::instance()->summary( self::SERVER )['active'] );
	}

	/**
	 * A new client is visible to a count issued in the same request.
	 *
	 * Pinned because it was not. BerlinDB does not key its query cache by
	 * table — it stamps each cached result with a `last_changed` value and
	 * discards the result only when that value moves. `add_item()` and friends
	 * bump it; `touch()` goes straight to `$wpdb` for the atomic upsert and so
	 * bumped nothing, and `flush_cache()` was flushing an invented group name
	 * that BerlinDB never reads.
	 *
	 * The symptom was invisible on this install — no persistent object cache,
	 * so every request starts cold and no request both counts and touches. On a
	 * site running Redis it is the opposite: `last_changed` survives between
	 * requests, so a newly connected client could stay missing from the screen
	 * until some unrelated BerlinDB write happened to bump the shared key.
	 */
	public function test_a_new_client_is_visible_to_a_count_in_the_same_request(): void {
		$before = $this->count_rows();

		Recorder::record( $this->signals() );

		$this->assertSame( $before + 1, $this->count_rows(), 'The upsert must invalidate BerlinDB\'s query cache.' );

		// And a repeat sighting must not appear as another row.
		Recorder::record( $this->signals() );

		$this->assertSame( $before + 1, $this->count_rows() );
	}

	/** A successful write clears the failure counter rather than leaving it. */
	public function test_a_successful_write_clears_the_failure_counter(): void {
		update_option( ClientsConstants::OPTION_WRITE_FAILURES, 5, false );

		Recorder::record( $this->signals() );

		$this->assertSame( 0, Recorder::failure_count() );
	}

	/** Rows for a deleted server go with it. */
	public function test_rows_are_removable_per_server(): void {
		Recorder::record( $this->signals() );
		Recorder::record( $this->signals( array( 'client_name' => 'Cursor' ) ) );

		$this->assertSame( 2, Query::instance()->delete_items_for_server( self::SERVER ) );
		$this->assertSame( 0, $this->count_rows() );
	}

	/**
	 * How many rows exist for a server.
	 *
	 * @param int|null $server_id Server to count, or the default test server.
	 */
	private function count_rows( ?int $server_id = null ): int {
		return (int) Query::instance()->query(
			array(
				'server_id' => null === $server_id ? self::SERVER : $server_id,
				'count'     => true,
				'number'    => 0,
			)
		);
	}
}
