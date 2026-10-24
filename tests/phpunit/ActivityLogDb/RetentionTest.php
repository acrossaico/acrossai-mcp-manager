<?php
/**
 * Retention, ceiling and recorded-removal contract (Feature 096, T083).
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ActivityLogDb;

use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Cleanup;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Row;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Cleanup
 */
class RetentionTest extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Table::instance()->maybe_upgrade();
		wp_clear_scheduled_hook( Cleanup::CRON_HOOK );
	}

	public function tear_down() {
		wp_clear_scheduled_hook( Cleanup::CRON_HOOK );

		foreach (
			array(
				ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS,
				ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS,
				ActivityLogConstants::OPTION_MAX_EVENTS,
			) as $option
		) {
			delete_option( $option );
		}

		parent::tear_down();
	}

	/** Age every row past any plausible cutoff. */
	private function age_everything( int $days = 90 ): void {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET created_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )",
				$days
			)
		);
	}

	private function count_of( string $event_type ): int {
		$count = Query::instance()->query(
			array(
				'event_type' => $event_type,
				'count'      => true,
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	// --- Scheduling ---

	/**
	 * A daily event that deletes nothing is noise in an operator's cron list,
	 * so the schedule follows the settings rather than existing unconditionally.
	 */
	public function test_the_cron_is_scheduled_only_while_a_bound_is_set(): void {
		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 0 );
		update_option( ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS, 0 );
		update_option( ActivityLogConstants::OPTION_MAX_EVENTS, 0 );

		$this->assertFalse( Cleanup::has_any_bound() );

		Cleanup::sync_schedule();
		$this->assertFalse( (bool) wp_next_scheduled( Cleanup::CRON_HOOK ), 'No bound, no event.' );

		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 30 );
		Cleanup::sync_schedule();

		$this->assertNotFalse( wp_next_scheduled( Cleanup::CRON_HOOK ), 'A bound schedules the sweep.' );
	}

	/**
	 * An untouched install is bounded: defaults alone schedule the sweep.
	 *
	 * The 2026-10-23 default change turned recording ON before anyone chooses,
	 * which made this the load-bearing case. With every bound option ABSENT —
	 * the state of a site whose operator never opened the settings form — the
	 * shipped defaults (30 / 7 / 50,000) must still constitute a bound, or the
	 * site logs from its first request with nothing ever deleting a row.
	 *
	 * Deleting the options is the whole point of the test. `has_any_bound()`
	 * reads them through `get_option()` with the constants as fallbacks, and it
	 * is exactly that fallback path which decides whether an untouched install
	 * prunes.
	 */
	public function test_an_untouched_install_is_bounded_by_its_defaults(): void {
		delete_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS );
		delete_option( ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS );
		delete_option( ActivityLogConstants::OPTION_MAX_EVENTS );
		wp_clear_scheduled_hook( Cleanup::CRON_HOOK );

		$this->assertTrue(
			Cleanup::has_any_bound(),
			'With nothing configured the shipped defaults must still bound the log.'
		);

		Cleanup::sync_schedule();

		$this->assertNotFalse(
			wp_next_scheduled( Cleanup::CRON_HOOK ),
			'A site that never opened the settings form must still get the daily sweep.'
		);
	}

	/**
	 * `sync_schedule()` is reachable on a normal boot, not only on activation.
	 *
	 * WordPress skips activation hooks on a plugin UPDATE (B68), and an
	 * operator content with the defaults never saves the settings form — so the
	 * only two callers that existed before this change, `Activator` and the
	 * `update_option_*` hooks, would both be missed. Harmless while recording
	 * was off; with it on by default the same gap means unbounded growth.
	 *
	 * Asserts the registration rather than the effect, because the effect is
	 * already covered above — what can regress is someone removing the hook.
	 */
	public function test_the_schedule_is_reconciled_on_admin_init(): void {
		$main = new \ReflectionClass( \AcrossAI_MCP_Manager\Includes\Main::class );
		$body = file_get_contents( $main->getFileName() );

		$this->assertMatchesRegularExpression(
			'/add_action\(\s*\n?\s*[\'"]admin_init[\'"],\s*\n?\s*[^;]*Cleanup::class,\s*\n?\s*[\'"]sync_schedule[\'"]/s',
			(string) $body,
			'Main must reconcile the cleanup schedule on admin_init; activation alone is skipped on update (B68).'
		);
	}

	/**
	 * `purge_all()` empties the table and invalidates the cache.
	 *
	 * The purge moved out of the REST controller and into the Query layer
	 * (architecture review V1), so it needs coverage HERE rather than only
	 * through the route — the point of the move was that the Data layer owns
	 * the statement, and a route-only test would keep asserting that from the
	 * wrong side of the boundary.
	 *
	 * The cache assertion is the load-bearing half: a purge that empties the
	 * table while `summary()` keeps serving counts from a transient reads as a
	 * log that refused to be cleared.
	 */
	public function test_purge_all_empties_the_table_and_drops_cached_aggregates(): void {
		Recorder::record_request( array( 'method' => 'tools/call' ) );
		Recorder::record_request( array( 'method' => 'tools/list' ) );

		// Warm the aggregate cache so the invalidation has something to clear.
		Query::instance()->summary( 1 );

		$removed = Query::instance()->purge_all();

		$this->assertSame( 2, $removed, 'purge_all() reports what it removed.' );
		$this->assertSame( 0, (int) Query::instance()->query( array( 'count' => true ) ), 'The table is empty.' );

		// `requests`, not `total` — summary() returns
		// {requests, avg_duration_ms, success_rate, active_clients, context_bytes}.
		$this->assertSame(
			0,
			Query::instance()->summary( 1 )['requests'],
			'Aggregates must not keep serving pre-purge counts from cache.'
		);
	}

	/** Purging an already-empty log removes nothing and does not fail. */
	public function test_purge_all_on_an_empty_log_is_a_no_op(): void {
		Query::instance()->purge_all();

		$this->assertSame( 0, Query::instance()->purge_all() );
	}

	/** And it is unscheduled again when every bound is turned off. */
	public function test_turning_every_bound_off_unschedules_the_cron(): void {
		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 30 );
		Cleanup::sync_schedule();
		$this->assertNotFalse( wp_next_scheduled( Cleanup::CRON_HOOK ) );

		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 0 );
		update_option( ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS, 0 );
		update_option( ActivityLogConstants::OPTION_MAX_EVENTS, 0 );
		Cleanup::sync_schedule();

		$this->assertFalse( (bool) wp_next_scheduled( Cleanup::CRON_HOOK ) );
	}

	public function test_sync_is_idempotent(): void {
		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 30 );

		Cleanup::sync_schedule();
		$first = wp_next_scheduled( Cleanup::CRON_HOOK );
		Cleanup::sync_schedule();

		$this->assertSame( $first, wp_next_scheduled( Cleanup::CRON_HOOK ), 'No duplicate events.' );
	}

	// --- Per-kind retention ---

	/**
	 * The one real obligation the merged table creates. Ability events
	 * outnumber request events by a wide margin; a shared cutoff would force
	 * the noisy half's retention onto the quiet half.
	 */
	public function test_each_event_kind_ages_out_on_its_own_cutoff(): void {
		Recorder::record_request( array( 'method' => 'tools/call' ) );
		Recorder::record_ability( array( 'component_name' => 'content/read' ) );
		$this->age_everything( 20 );

		// Abilities kept 7 days, requests kept 60: only abilities should go.
		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 60 );
		update_option( ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS, 7 );
		update_option( ActivityLogConstants::OPTION_MAX_EVENTS, 0 );

		Cleanup::instance()->run();

		$this->assertSame( 1, $this->count_of( ActivityLogConstants::EVENT_REQUEST ), 'Requests are inside their window.' );
		$this->assertSame( 0, $this->count_of( ActivityLogConstants::EVENT_ABILITY ), 'Abilities are past theirs.' );
	}

	public function test_a_zero_retention_means_keep_indefinitely(): void {
		Recorder::record_request( array( 'method' => 'tools/call' ) );
		$this->age_everything( 3650 );

		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 0 );
		update_option( ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS, 0 );
		update_option( ActivityLogConstants::OPTION_MAX_EVENTS, 0 );

		Cleanup::instance()->run();

		$this->assertSame( 1, $this->count_of( ActivityLogConstants::EVENT_REQUEST ), '0 is "forever", not "immediately".' );
	}

	// --- Ceiling ---

	public function test_the_ceiling_sheds_oldest_first_and_keeps_newest(): void {
		$ids = array();

		for ( $i = 0; $i < 8; $i++ ) {
			$ids[] = Recorder::record_request( array( 'method' => 'ping', 'request_id' => (string) $i ) );
		}

		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 0 );
		update_option( ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS, 0 );
		update_option( ActivityLogConstants::OPTION_MAX_EVENTS, 4 );

		Cleanup::instance()->run();

		$this->assertNotInstanceOf( Row::class, Query::instance()->get_item( $ids[0] ), 'Oldest goes first.' );
		$this->assertInstanceOf( Row::class, Query::instance()->get_item( $ids[7] ), 'Newest survives.' );
	}

	// --- A24: recorded removal ---

	/**
	 * A24 — the log records its own destruction.
	 *
	 * Written AFTER the deletes, so it survives as the first entry of what
	 * remains. Without it, the retention job quietly removing history is the one
	 * action the log cannot show.
	 */
	public function test_the_sweep_records_what_it_removed(): void {
		Recorder::record_request( array( 'method' => 'tools/call' ) );
		Recorder::record_request( array( 'method' => 'tools/list' ) );
		$this->age_everything( 90 );

		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 30 );
		update_option( ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS, 30 );
		update_option( ActivityLogConstants::OPTION_MAX_EVENTS, 0 );

		Cleanup::instance()->run();

		$removals = Query::instance()->query(
			array(
				'event_type' => ActivityLogConstants::EVENT_REMOVAL,
				'number'     => 5,
			)
		);

		$this->assertCount( 1, $removals, 'One removal record per sweep.' );
		$this->assertSame( 'retention', $removals[0]->component_name );
		$this->assertSame( '2', $removals[0]->input_preview, 'The count removed must be recoverable.' );
		$this->assertSame( 0, $removals[0]->user_id, 'Cron has no acting user.' );
	}

	/**
	 * "retention" and "ceiling" mean different things to an operator: the second
	 * says the log is shedding history faster than its retention window, which
	 * is worth noticing.
	 */
	public function test_the_removal_record_names_which_bound_did_the_work(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Recorder::record_request( array( 'method' => 'ping', 'request_id' => (string) $i ) );
		}

		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 0 );
		update_option( ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS, 0 );
		update_option( ActivityLogConstants::OPTION_MAX_EVENTS, 2 );

		Cleanup::instance()->run();

		$removals = Query::instance()->query(
			array(
				'event_type' => ActivityLogConstants::EVENT_REMOVAL,
				'number'     => 5,
			)
		);

		$this->assertCount( 1, $removals );
		$this->assertSame( 'ceiling', $removals[0]->component_name );
	}

	/** A sweep that removes nothing must not write a record claiming it did. */
	public function test_a_sweep_with_nothing_to_remove_records_nothing(): void {
		Recorder::record_request( array( 'method' => 'tools/call' ) );

		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 30 );
		update_option( ActivityLogConstants::OPTION_MAX_EVENTS, 0 );

		Cleanup::instance()->run();

		$this->assertSame( 0, $this->count_of( ActivityLogConstants::EVENT_REMOVAL ) );
	}

	/** A cron failure must not become a fatal on a scheduled event. */
	public function test_the_sweep_swallows_a_storage_failure(): void {
		global $wpdb;

		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 1 );

		$break = static function ( $query ) {
			return false !== stripos( (string) $query, 'acrossai_mcp_activity_log' )
				? 'SELECT not_valid FROM nowhere'
				: $query;
		};

		add_filter( 'query', $break );
		$suppressed = $wpdb->suppress_errors( true );

		Cleanup::instance()->run();

		$wpdb->suppress_errors( $suppressed );
		remove_filter( 'query', $break );

		$this->assertTrue( true, 'No exception escaped the cron callback.' );
	}

	// --- SC-C10: isolation from Feature 097 ---

	/**
	 * Neither bound may reach another feature's table. F097's change log has its
	 * own retention, and a sweep that crossed the boundary would delete history
	 * its owner still considered current.
	 */
	public function test_the_sweep_touches_only_this_tables_rows(): void {
		global $wpdb;

		$other = $wpdb->prefix . 'acrossai_mcp_cli_auth_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$other}`" );

		Recorder::record_request( array( 'method' => 'tools/call' ) );
		$this->age_everything( 90 );

		update_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, 1 );
		Cleanup::instance()->run();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$other}`" );

		$this->assertSame( $before, $after, 'A sibling log table must be untouched.' );
	}
}
