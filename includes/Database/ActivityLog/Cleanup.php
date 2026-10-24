<?php
/**
 * Retention and ceiling enforcement for the activity log (Feature 096).
 *
 * Two bounds, not one. Retention alone leaves worst-case size a function of
 * traffic: a chatty client inside a 30-day window can still produce millions
 * of rows. The ceiling bounds it deterministically. Neither samples — every
 * event the toggles cover is recorded in full or not at all, because a sampled
 * log makes the success-rate tile and the context-size ranking estimates rather
 * than facts, and an operator cannot tell a sampled log from a quiet one.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\ActivityLog
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\ActivityLog;

use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Daily cleanup. A11-style stateless service; wired in Main.php per A1.
 */
final class Cleanup {

	/** Cron hook name. Cleared by name in Deactivator, so it is a contract. */
	public const CRON_HOOK = 'acrossai_mcp_manager_activity_cleanup';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/** Singleton accessor. */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/** Private — wiring lives in Main.php per A1. */
	private function __construct() {}

	/**
	 * Is any bound configured?
	 *
	 * The cron is scheduled only while this is true, and unscheduled when it
	 * stops being true — an event that fires daily to delete nothing is pure
	 * noise in an operator's cron list.
	 */
	public static function has_any_bound(): bool {
		$request = (int) get_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, ActivityLogConstants::DEFAULT_RETENTION_REQUEST_DAYS );
		$ability = (int) get_option( ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS, ActivityLogConstants::DEFAULT_RETENTION_ABILITY_DAYS );
		$ceiling = (int) get_option( ActivityLogConstants::OPTION_MAX_EVENTS, ActivityLogConstants::DEFAULT_MAX_EVENTS );

		return $request > 0 || $ability > 0 || $ceiling > 0;
	}

	/**
	 * Schedule or unschedule the daily event to match the current settings.
	 *
	 * Idempotent, and safe to call on every settings save as well as on
	 * activation.
	 */
	public static function sync_schedule(): void {
		$scheduled = (bool) wp_next_scheduled( self::CRON_HOOK );

		if ( self::has_any_bound() ) {
			if ( ! $scheduled ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
			}

			return;
		}

		if ( $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Run the sweep.
	 *
	 * Order matters: age-based pruning first, then the ceiling. Doing it the
	 * other way round would have the ceiling shed rows that retention was about
	 * to remove anyway, inflating the recorded count and making the numbers an
	 * operator reads harder to interpret.
	 *
	 * Never throws — this runs on cron, where an exception becomes a silently
	 * failing scheduled event.
	 */
	public function run(): void {
		try {
			$query = Query::instance();

			// Per event kind, never one global cutoff. Ability events outnumber
			// request events by a wide margin and should age out sooner; a
			// shared cutoff would force the noisy half's retention onto the
			// quiet half.
			$removed = array(
				'retention_request' => $query->prune_by_event_type(
					ActivityLogConstants::EVENT_REQUEST,
					(int) get_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, ActivityLogConstants::DEFAULT_RETENTION_REQUEST_DAYS )
				),
				'retention_ability' => $query->prune_by_event_type(
					ActivityLogConstants::EVENT_ABILITY,
					(int) get_option( ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS, ActivityLogConstants::DEFAULT_RETENTION_ABILITY_DAYS )
				),
				'ceiling'           => $query->enforce_ceiling(
					(int) get_option( ActivityLogConstants::OPTION_MAX_EVENTS, ActivityLogConstants::DEFAULT_MAX_EVENTS )
				),
			);

			$total = array_sum( $removed );

			if ( $total < 1 ) {
				return;
			}

			Query::flush_cache();

			// A24: the log records its own destruction. Written AFTER the
			// deletes, so it survives as the first entry of what remains — and
			// it is the one row a later purge cannot erase, because a purge
			// writes its own.
			//
			// user_id 0, because cron has no acting user. `invoked_via` resolves
			// to `cli` for that case, which is honest: nobody clicked anything.
			Recorder::record_removal( $this->reason_for( $removed ), $total, 0 );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A cron failure must not become a fatal.
			error_log( '[acrossai-mcp] ActivityLog\\Cleanup::run failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Name which bound did the work, for the removal record.
	 *
	 * "retention" and "ceiling" mean different things to an operator reading
	 * the history: one says events aged out, the other says the log hit its
	 * size limit and is shedding history faster than the retention window.
	 * The second is worth noticing.
	 *
	 * @param array<string, int> $removed Counts per bound.
	 */
	private function reason_for( array $removed ): string {
		$by_age     = (int) $removed['retention_request'] + (int) $removed['retention_ability'];
		$by_ceiling = (int) $removed['ceiling'];

		if ( $by_age > 0 && $by_ceiling > 0 ) {
			return 'retention+ceiling';
		}

		return $by_ceiling > 0 ? 'ceiling' : 'retention';
	}
}
