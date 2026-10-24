<?php
/**
 * Site Health reporting for the activity log (FR-013a, FR-013b, SC-030).
 *
 * The thing under test is not "the counter is right" — that is covered
 * elsewhere. It is that an operator is TOLD. A log that is failing and a log
 * that is quiet produce the same empty screen, and the defect that made every
 * write fail silently was found by querying MySQL directly, not by any surface
 * the plugin offers.
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ActivityLogDb;

use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Cleanup;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table;
use AcrossAI_MCP_Manager\Includes\Database\Support\SchemaFailureHealthCheck;
use AcrossAI_MCP_Manager\Includes\Observability\RecordingHealthCheck;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Observability\RecordingHealthCheck
 * @covers \AcrossAI_MCP_Manager\Includes\Database\Support\SchemaFailureHealthCheck
 */
class HealthCheckTest extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Table::instance()->maybe_upgrade();
		delete_option( ActivityLogConstants::OPTION_WRITE_FAILURES );
		delete_option( SchemaFailureHealthCheck::OPTION_FAILURES );
	}

	public function tear_down() {
		delete_option( ActivityLogConstants::OPTION_WRITE_FAILURES );
		delete_option( SchemaFailureHealthCheck::OPTION_FAILURES );
		delete_option( ActivityLogConstants::OPTION_REQUEST_LOGGING );
		delete_option( ActivityLogConstants::OPTION_ABILITY_LOGGING );
		parent::tear_down();
	}

	// --- FR-013a: a failing log is distinguishable from a quiet one ---

	/** Both tests register themselves under `direct`. */
	public function test_both_checks_register_with_site_health(): void {
		$tests = RecordingHealthCheck::instance()->add_test( array() );
		$tests = SchemaFailureHealthCheck::instance()->add_test( $tests );

		$this->assertArrayHasKey( RecordingHealthCheck::TEST_KEY, $tests['direct'] );
		$this->assertArrayHasKey( SchemaFailureHealthCheck::TEST_KEY, $tests['direct'] );
		$this->assertIsCallable( $tests['direct'][ RecordingHealthCheck::TEST_KEY ]['test'] );
		$this->assertIsCallable( $tests['direct'][ SchemaFailureHealthCheck::TEST_KEY ]['test'] );
	}

	/** A filter that handed us a non-array must pass through untouched. */
	public function test_registration_tolerates_a_non_array_filter_value(): void {
		$this->assertSame( 'not-an-array', RecordingHealthCheck::instance()->add_test( 'not-an-array' ) );
		$this->assertSame( 'not-an-array', SchemaFailureHealthCheck::instance()->add_test( 'not-an-array' ) );
	}

	/** Recording on, table reachable, no failures — the ordinary case. */
	public function test_a_working_log_reports_good(): void {
		update_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, true );

		$result = RecordingHealthCheck::instance()->run_self_check();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( RecordingHealthCheck::TEST_KEY, $result['test'] );
	}

	/**
	 * Failing writes report CRITICAL and say so in words.
	 *
	 * SC-030. Asserting the status alone would pass against a check that says
	 * "something is wrong" — the requirement is that the operator can tell a
	 * failing log from a quiet one, so the text has to carry that.
	 */
	public function test_failing_writes_are_reported_as_critical(): void {
		update_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, true );
		update_option( ActivityLogConstants::OPTION_WRITE_FAILURES, 7 );

		$result = RecordingHealthCheck::instance()->run_self_check();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'failing', strtolower( (string) $result['label'] ) );
		$this->assertStringContainsString( '7', (string) $result['description'] );
		$this->assertStringContainsString( 'incomplete', strtolower( (string) $result['description'] ) );
	}

	/**
	 * With recording switched off, an empty log is correct and not a fault.
	 *
	 * The counter is left non-zero on purpose: a stale count from before the
	 * operator turned recording off must not raise an alarm about a log nobody
	 * asked to be written.
	 */
	public function test_recording_switched_off_is_not_a_fault(): void {
		// Registering first is load-bearing, not ceremony. `update_option( $o,
		// false )` against an ABSENT option is a silent no-op: update_option()
		// compares the new value with `get_option( $o )`, which is itself false
		// when nothing is stored and no default is registered, finds them equal
		// and writes nothing. The option would stay absent, the readers' `true`
		// default would win, and this test would report recording as ON. The
		// admin registers these on `admin_init` before any save, so this also
		// matches what really happens.
		$this->register_toggles();

		update_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, false );
		update_option( ActivityLogConstants::OPTION_ABILITY_LOGGING, false );
		update_option( ActivityLogConstants::OPTION_WRITE_FAILURES, 3 );

		$this->assertFalse(
			(bool) get_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, ActivityLogConstants::DEFAULT_REQUEST_LOGGING ),
			'Precondition: recording really is off, not merely asked to be.'
		);

		$result = RecordingHealthCheck::instance()->run_self_check();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'switched off', strtolower( (string) $result['label'] ) );
	}

	/**
	 * The exact defect that shipped: table present, name unresolvable.
	 *
	 * BerlinDB reads the real table name from a `$wpdb` property set only by
	 * the Table object's constructor. Unset it and every insert targets `` and
	 * fails silently — which is invisible from everywhere else, because the
	 * table itself is present and correct. This check is the only thing that
	 * would have caught it, so it gets its own test.
	 */
	public function test_an_unresolvable_table_name_is_reported_as_critical(): void {
		global $wpdb;

		update_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, true );

		$key   = ActivityLogConstants::TABLE;
		$saved = $wpdb->{$key} ?? null;
		unset( $wpdb->{$key} );

		$result = RecordingHealthCheck::instance()->run_self_check();

		if ( null !== $saved ) {
			$wpdb->{$key} = $saved;
		}

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'cannot reach', strtolower( (string) $result['label'] ) );
	}

	/**
	 * SC-031 — an overdue sweep is reported.
	 *
	 * Every bound this feature offers is applied by that one daily event, so a
	 * site whose cron is not firing has an unbounded log while every setting
	 * still reads as configured. Driven by moving the scheduled time into the
	 * past rather than by disabling cron: the claim is about the report, not
	 * about the host.
	 */
	public function test_an_overdue_cleanup_sweep_is_reported(): void {
		update_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, true );

		wp_clear_scheduled_hook( Cleanup::CRON_HOOK );
		wp_schedule_event( time() - ( 3 * DAY_IN_SECONDS ), 'daily', Cleanup::CRON_HOOK );

		$result = RecordingHealthCheck::instance()->run_self_check();

		wp_clear_scheduled_hook( Cleanup::CRON_HOOK );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'cleanup has not run', strtolower( (string) $result['label'] ) );
		$this->assertStringContainsString( 'keep growing', strtolower( (string) $result['description'] ) );
	}

	/** A sweep due later today is not late, and must not cry wolf. */
	public function test_a_sweep_due_soon_is_not_reported_as_overdue(): void {
		update_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, true );

		wp_clear_scheduled_hook( Cleanup::CRON_HOOK );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Cleanup::CRON_HOOK );

		$result = RecordingHealthCheck::instance()->run_self_check();

		wp_clear_scheduled_hook( Cleanup::CRON_HOOK );

		$this->assertSame( 'good', $result['status'] );
	}

	/** Declare the toggles the way `admin_init` does, so writing false sticks. */
	private function register_toggles(): void {
		foreach (
			array(
				ActivityLogConstants::OPTION_REQUEST_LOGGING => ActivityLogConstants::DEFAULT_REQUEST_LOGGING,
				ActivityLogConstants::OPTION_ABILITY_LOGGING => ActivityLogConstants::DEFAULT_ABILITY_LOGGING,
			) as $option => $default
		) {
			register_setting(
				'acrossai_mcp_settings',
				$option,
				array(
					'sanitize_callback' => 'rest_sanitize_boolean',
					'default'           => $default,
				)
			);
		}
	}

	// --- FR-013b: a failed migration is surfaced, not merely signalled ---

	/** Nothing recorded means nothing to report. */
	public function test_no_schema_failures_reports_good(): void {
		$result = SchemaFailureHealthCheck::instance()->run_self_check();

		$this->assertSame( 'good', $result['status'] );
	}

	/**
	 * The listener persists what the action carries, and the check reports it.
	 *
	 * Driven through `record_failure()` with the action's own argument shape
	 * rather than by writing the option directly — the wiring is the part that
	 * was missing, and a test that seeds the option would not notice it.
	 */
	public function test_a_failed_upgrade_is_recorded_and_reported(): void {
		SchemaFailureHealthCheck::instance()->record_failure(
			ActivityLogConstants::TABLE,
			'1.1.0',
			'Duplicate key name type_status_created'
		);

		$result = SchemaFailureHealthCheck::instance()->run_self_check();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( ActivityLogConstants::TABLE, (string) $result['description'] );
		$this->assertStringContainsString( '1.1.0', (string) $result['description'] );
		$this->assertStringContainsString( 'Duplicate key name', (string) $result['description'] );
	}

	/** The action actually reaches the listener once Main has wired it. */
	public function test_the_action_reaches_the_listener(): void {
		$check = SchemaFailureHealthCheck::instance();

		add_action( 'acrossai_mcp_schema_upgrade_failed', array( $check, 'record_failure' ), 10, 3 );
		do_action( 'acrossai_mcp_schema_upgrade_failed', 'acrossai_mcp_servers', '1.1.8', 'ALTER denied' );
		remove_action( 'acrossai_mcp_schema_upgrade_failed', array( $check, 'record_failure' ), 10 );

		$result = $check->run_self_check();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'acrossai_mcp_servers', (string) $result['description'] );
	}

	/**
	 * A migration retrying overwrites its entry instead of appending.
	 *
	 * Without this the option grows on every admin page load for as long as the
	 * migration keeps failing — an unbounded write driven by a failure, which
	 * is the shape of problem that turns a recoverable fault into an outage.
	 */
	public function test_a_retrying_migration_does_not_grow_the_record(): void {
		$check = SchemaFailureHealthCheck::instance();

		for ( $i = 0; $i < 25; $i++ ) {
			$check->record_failure( ActivityLogConstants::TABLE, '1.1.0', 'ALTER denied' );
		}

		$stored = get_option( SchemaFailureHealthCheck::OPTION_FAILURES, array() );

		$this->assertCount( 1, $stored, 'The same table and version must occupy one entry however often it retries.' );
	}

	/** Resolved failures stop being reported. */
	public function test_clearing_removes_a_resolved_failure(): void {
		$check = SchemaFailureHealthCheck::instance();

		$check->record_failure( ActivityLogConstants::TABLE, '1.1.0', 'ALTER denied' );
		$this->assertSame( 'critical', $check->run_self_check()['status'] );

		$check->clear_all();

		$this->assertSame(
			'good',
			$check->run_self_check()['status'],
			'A failure that has since resolved must not leave a permanent red mark.'
		);
	}

	/** A nameless failure is ignored rather than stored as an empty row. */
	public function test_a_failure_without_a_table_is_ignored(): void {
		SchemaFailureHealthCheck::instance()->record_failure( '', '1.0.0', 'nothing useful' );

		$this->assertSame( 'good', SchemaFailureHealthCheck::instance()->run_self_check()['status'] );
	}
}
