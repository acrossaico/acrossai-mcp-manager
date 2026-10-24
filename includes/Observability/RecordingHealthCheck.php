<?php
/**
 * Site Health: is the activity log actually recording? (Feature 096, FR-013a)
 *
 * The screen already says "recording is failing" when the counter is non-zero.
 * This is the other half, and it exists for the cases the screen cannot cover:
 *
 *   - The counter RESETS on the next successful write, so an intermittent
 *     failure can erase its own evidence before anyone opens the screen.
 *   - The screen only helps an operator who visits it. Site Health is
 *     remotely discoverable — support can ask for the report rather than
 *     talking someone through a menu.
 *
 * FR-013 requires recording to swallow its own failures so a client's call is
 * never broken. That is correct and stays. But swallowing the error and
 * reporting nothing are two different decisions, and only the first one is
 * required — a log that is FAILING must stay distinguishable from one that is
 * QUIET, which is the same reasoning that rejected sampling.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Observability
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Observability;

use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Cleanup;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Reports whether recording is working, when it is switched on.
 *
 * Hook wiring lives in Main.php per A1.
 */
final class RecordingHealthCheck {

	/** Site Health test key. */
	public const TEST_KEY = 'acrossai_mcp_activity_recording';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/** Singleton accessor. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private — wiring lives in Main.php per A1. */
	private function __construct() {}

	/**
	 * Register the test.
	 *
	 * @param array<string, mixed>|mixed $tests Site Health tests.
	 * @return array<string, mixed>|mixed
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct'][ self::TEST_KEY ] = array(
			'label' => __( 'MCP activity recording', 'acrossai-mcp-manager' ),
			'test'  => array( $this, 'run_self_check' ),
		);

		return $tests;
	}

	/**
	 * Check the four ways the log goes silently wrong.
	 *
	 * Ordered most-severe first, and each is a DIFFERENT fault with a different
	 * fix, so they are reported separately rather than collapsed into one
	 * "something is wrong": the table is gone, the table cannot be addressed,
	 * writes are failing, or nothing is trimming what gets written.
	 *
	 * Only the first three stop records being kept. The fourth is the inverse —
	 * recording works and the bounds on it do not — which is why it reports as
	 * `recommended` rather than `critical`.
	 *
	 * @return array<string, mixed>
	 */
	public function run_self_check(): array {
		// Nothing to report when the operator has switched recording off. An
		// empty log is then the correct and expected state.
		if ( ! $this->recording_enabled() ) {
			return $this->build_result(
				'good',
				__( 'MCP activity recording is switched off', 'acrossai-mcp-manager' ),
				'<p>' . esc_html__( 'Recording is turned off, so the activity log is empty by design. Turn it on from the MCP tab of the AcrossAI Settings page if you want a record of what AI clients ask this site to do.', 'acrossai-mcp-manager' ) . '</p>'
			);
		}

		// 1. The table is gone. Every write fails and the screen shows nothing.
		if ( ! Table::instance()->exists() ) {
			return $this->build_result(
				'critical',
				__( 'MCP activity recording is on, but its database table is missing', 'acrossai-mcp-manager' ),
				'<p>' . esc_html__( 'Recording is switched on and the table it writes to does not exist, so nothing is being recorded and the log will stay empty. This usually means the table was dropped outside the plugin.', 'acrossai-mcp-manager' ) . '</p>'
				. '<p>' . esc_html__( 'Loading any admin page normally recreates it. If the log stays empty after that, the database user may not be allowed to create tables.', 'acrossai-mcp-manager' ) . '</p>'
			);
		}

		// 2. The table exists but its name does not RESOLVE. This is the exact
		// shape of the defect that shipped: BerlinDB reads the real name from a
		// $wpdb property set only by the Table object's constructor, so with no
		// Table constructed the name is the empty string, every insert targets
		// `` and fails, and the recorder's catch swallows it. Worth its own
		// branch because it is invisible from everywhere else — the table is
		// present and correct, and nothing else looks wrong.
		if ( '' === Query::instance()->get_table_name() ) {
			return $this->build_result(
				'critical',
				__( 'MCP activity recording cannot reach its database table', 'acrossai-mcp-manager' ),
				'<p>' . esc_html__( 'The activity log table exists, but the plugin cannot resolve its name, so every write fails silently and the log stays empty. This is a plugin fault rather than anything you have configured.', 'acrossai-mcp-manager' ) . '</p>'
				. '<p>' . esc_html__( 'Please report it, mentioning this Site Health entry.', 'acrossai-mcp-manager' ) . '</p>'
			);
		}

		// 3. Writes are being attempted and failing.
		$failures = Recorder::failure_count();

		if ( $failures > 0 ) {
			return $this->build_result(
				'critical',
				__( 'MCP activity recording is failing', 'acrossai-mcp-manager' ),
				'<p>' . sprintf(
					esc_html(
						/* translators: %d: number of consecutive failed writes. */
						_n(
							'%d activity record has failed to save since the last successful one. The log is incomplete: what you see on the Activity Log screen is less than what happened.',
							'%d activity records have failed to save since the last successful one. The log is incomplete: what you see on the Activity Log screen is less than what happened.',
							$failures,
							'acrossai-mcp-manager'
						)
					),
					(int) $failures
				) . '</p>'
				. '<p>' . esc_html__( 'Check the PHP error log for entries beginning [acrossai-mcp]. The count resets as soon as a write succeeds, so an intermittent fault may clear itself here while still losing records.', 'acrossai-mcp-manager' ) . '</p>'
			);
		}

		// 4. Recording works, but nothing is trimming what it writes (FR-031a).
		// Every bound this feature offers — both retention periods AND the
		// ceiling — is applied by that one daily event and by nothing else, so
		// a site whose cron is not firing has an unbounded log while every
		// setting still reads as configured. Reported after the write checks
		// because a log that cannot record at all is the worse problem.
		$overdue_days = $this->sweep_overdue_days();

		if ( $overdue_days > 0 ) {
			return $this->build_result(
				'recommended',
				__( 'MCP activity log cleanup has not run', 'acrossai-mcp-manager' ),
				'<p>' . sprintf(
					esc_html(
						/* translators: %d: whole days the scheduled cleanup is late by. */
						_n(
							'The daily cleanup of the activity log is %d day overdue. Until it runs, the retention periods and the maximum event count are not being applied and the log will keep growing.',
							'The daily cleanup of the activity log is %d days overdue. Until it runs, the retention periods and the maximum event count are not being applied and the log will keep growing.',
							$overdue_days,
							'acrossai-mcp-manager'
						)
					),
					(int) $overdue_days
				) . '</p>'
				. '<p>' . esc_html__( 'This almost always means WordPress scheduled tasks are not running on this site, which affects scheduled posts and other plugins too, not just this log. Check whether WP-Cron is disabled and whether the system cron replacing it is working.', 'acrossai-mcp-manager' ) . '</p>'
			);
		}

		return $this->build_result(
			'good',
			__( 'MCP activity recording is working', 'acrossai-mcp-manager' ),
			'<p>' . esc_html__( 'Recording is switched on, the activity log table is reachable, no writes have failed since the last successful one, and the daily cleanup is running on schedule.', 'acrossai-mcp-manager' ) . '</p>'
		);
	}

	/**
	 * Whole days the cleanup sweep is late by, or 0 when it is not late.
	 *
	 * A full day of grace on top of the daily schedule, because a sweep that
	 * simply has not come round yet is not a fault and a check that cried wolf
	 * on normal operation would be worse than no check. Returns 0 when nothing
	 * is scheduled at all: that is either "no bound is configured" or a
	 * scheduling problem, and `Cleanup::sync_schedule()` owns both.
	 */
	private function sweep_overdue_days(): int {
		$next = wp_next_scheduled( Cleanup::CRON_HOOK );

		if ( ! is_numeric( $next ) ) {
			return 0;
		}

		$late = time() - (int) $next;

		if ( $late < DAY_IN_SECONDS ) {
			return 0;
		}

		return (int) floor( $late / DAY_IN_SECONDS );
	}

	/** Is either half of recording switched on? */
	private function recording_enabled(): bool {
		$requests  = (bool) get_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, ActivityLogConstants::DEFAULT_REQUEST_LOGGING );
		$abilities = (bool) get_option( ActivityLogConstants::OPTION_ABILITY_LOGGING, ActivityLogConstants::DEFAULT_ABILITY_LOGGING );

		return $requests || $abilities;
	}

	/**
	 * Shape a Site Health result.
	 *
	 * @param string $status      One of `good`, `recommended` or `critical`.
	 * @param string $label       Short headline.
	 * @param string $description Already-escaped HTML.
	 * @return array<string, mixed>
	 */
	private function build_result( string $status, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'acrossai-mcp-manager' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => '',
			'test'        => self::TEST_KEY,
		);
	}
}
