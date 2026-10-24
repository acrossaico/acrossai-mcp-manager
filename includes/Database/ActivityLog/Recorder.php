<?php
/**
 * Activity log static-helper recorder (Feature 096).
 *
 * A15-exempt stateless helper: static methods only, no constructor, no
 * instance(), no hooks, never Loader-wired. The class owns the audit-write
 * boundary between feature classes and the BerlinDB Query layer, so no caller
 * can bypass its try/catch shielding.
 *
 * Every public method is best-effort and returns void. A recording failure
 * MUST NOT change what an MCP client receives or the outcome of an ability
 * (FR-013) — these run inside the live execution path of real traffic.
 *
 * @package AcrossAI_MCP_Manager\Includes\Database\ActivityLog
 */

namespace AcrossAI_MCP_Manager\Includes\Database\ActivityLog;

use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

defined( 'ABSPATH' ) || exit;

/**
 * The one write path into the activity log.
 */
final class Recorder {

	/**
	 * Re-entry guard. The recorder writes rows while capture is armed, so
	 * without this an ability that touches the activity table records itself
	 * recording. easy-mcp-ai needs a whole context class for exactly this.
	 *
	 * @var bool
	 */
	private static $recording = false;

	/**
	 * Record one MCP JSON-RPC request.
	 *
	 * @param array<string, mixed> $data Column values; unknown keys are dropped.
	 * @return int The new row id, or 0 on any failure.
	 */
	public static function record_request( array $data ): int {
		$data['event_type'] = ActivityLogConstants::EVENT_REQUEST;
		$data['parent_id']  = 0;

		return self::insert( $data );
	}

	/**
	 * Record one ability run.
	 *
	 * @param array<string, mixed> $data Column values; unknown keys are dropped.
	 * @return int The new row id, or 0 on any failure.
	 */
	public static function record_ability( array $data ): int {
		$data['event_type'] = ActivityLogConstants::EVENT_ABILITY;

		return self::insert( $data );
	}

	/**
	 * Record that events were removed — A24.
	 *
	 * Written AFTER the deletion, so it survives as the first entry of the
	 * remaining history. It is the one row a purge cannot erase, and without it
	 * the destruction of the log is the single action the log cannot show.
	 *
	 * @param string $reason  `purge` | `retention` | `ceiling`.
	 * @param int    $removed How many events went.
	 * @param int    $user_id Who triggered it; 0 for cron.
	 * @return int The new row id, or 0 on any failure.
	 */
	public static function record_removal( string $reason, int $removed, int $user_id = 0 ): int {
		return self::insert(
			array(
				'event_type'     => ActivityLogConstants::EVENT_REMOVAL,
				'parent_id'      => 0,
				'component_name' => $reason,
				'status'         => 'success',
				'invoked_via'    => 0 === $user_id ? 'cli' : 'admin',
				'user_id'        => $user_id,
				'input_preview'  => (string) $removed,
			)
		);
	}

	/**
	 * The single insert path.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int The new row id, or 0 on any failure.
	 */
	private static function insert( array $data ): int {
		if ( self::$recording ) {
			return 0;
		}

		self::$recording = true;

		try {
			$row = self::filter_to_schema( $data );

			if ( ! isset( $row['created_at'] ) ) {
				$row['created_at'] = current_time( 'mysql', true );
			}

			$id = Query::instance()->add_item( $row );

			if ( is_numeric( $id ) && (int) $id > 0 ) {
				// Explicit invalidation, not TTL-only: a call that does not
				// appear in the stat tiles reads as a broken log.
				Query::flush_cache();
				self::clear_failures();

				return (int) $id;
			}

			// A falsy return is the B34 shape: `add_item()` returns false when a
			// column is missing from the live table, and a caller that read it
			// as success would lose writes silently. Count it as a failure.
			self::note_failure();

			return 0;
		} catch ( \Throwable $e ) {
			self::note_failure();

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Best-effort audit write; FR-013 forbids propagating.
			error_log( '[acrossai-mcp] ActivityLog\\Recorder::insert failed: ' . $e->getMessage() );

			return 0;
		} finally {
			self::$recording = false;
		}
	}

	/**
	 * Drop any key that is not a declared column.
	 *
	 * B7: a Query writer that passes caller-supplied keys straight through lets
	 * a forged key mass-assign. The column map is declarative so future columns
	 * are additive, which also means it must be filtered rather than trusted.
	 *
	 * @param array<string, mixed> $data Candidate column values.
	 * @return array<string, mixed> Only declared columns.
	 */
	private static function filter_to_schema( array $data ): array {
		static $allowed = null;

		if ( null === $allowed ) {
			$allowed = array();

			// BerlinDB's boot() hydrates the arrays declared in Schema.php into
			// Kern\Column OBJECTS, so array access throws here. Tolerating both
			// shapes keeps this working whether the schema has been booted yet
			// and across BerlinDB versions. Getting this wrong is invisible in
			// production: the throw is caught by insert()'s try/catch and every
			// write silently returns 0.
			foreach ( ( new Schema() )->columns as $column ) {
				$name = is_object( $column )
					? (string) ( $column->name ?? '' )
					: (string) ( is_array( $column ) && isset( $column['name'] ) ? $column['name'] : '' );

				if ( '' !== $name && 'id' !== $name ) {
					$allowed[ $name ] = true;
				}
			}
		}

		return array_intersect_key( $data, $allowed );
	}

	/**
	 * Note that a write failed.
	 *
	 * Recording swallows every failure by design (FR-013), which means the log
	 * can stop working while the screen still looks healthy — a full disk, a
	 * dropped table, or B34's silent drift all produce an empty-looking but
	 * "enabled" log. An attacker who can provoke a write failure would get
	 * unlogged activity.
	 *
	 * A counter is the cheapest honest fix: the screen can then say "recording
	 * is failing" instead of "nothing happened", which are opposite facts.
	 */
	private static function note_failure(): void {
		try {
			$count = (int) get_option( ActivityLogConstants::OPTION_WRITE_FAILURES, 0 );

			// Cap the stored value. The screen only needs to know that failures
			// are happening, and an unbounded counter on a failing write path
			// would itself become a write amplifier.
			if ( $count < 1000 ) {
				update_option( ActivityLogConstants::OPTION_WRITE_FAILURES, $count + 1, false );
			}
		} catch ( \Throwable $e ) {
			// Nothing to do: the failure counter failing is not worth a notice.
			unset( $e );
		}
	}

	/** Reset the counter after a successful write. */
	private static function clear_failures(): void {
		try {
			if ( 0 !== (int) get_option( ActivityLogConstants::OPTION_WRITE_FAILURES, 0 ) ) {
				update_option( ActivityLogConstants::OPTION_WRITE_FAILURES, 0, false );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Consecutive write failures since the last success.
	 *
	 * Read by the admin screen to distinguish "recording is failing" from
	 * "nothing happened in this period".
	 */
	public static function failure_count(): int {
		return (int) get_option( ActivityLogConstants::OPTION_WRITE_FAILURES, 0 );
	}

	/**
	 * Is this table one the recorder must never observe?
	 *
	 * Read by the capture paths before recording a write. The list is a single
	 * shared constant rather than a literal per recorder, because Feature 097
	 * adds a third recorder behind the same guard and a second copy is how one
	 * recorder ends up recording another's writes.
	 *
	 * @param string $table Table name, with or without the wpdb prefix.
	 * @return bool
	 */
	public static function is_ignored_table( string $table ): bool {
		global $wpdb;

		$bare = $table;

		if ( isset( $wpdb->prefix ) && 0 === strpos( $table, $wpdb->prefix ) ) {
			$bare = substr( $table, strlen( $wpdb->prefix ) );
		}

		return in_array( $bare, ActivityLogConstants::IGNORED_TABLES, true );
	}

	/**
	 * Is a write currently in progress? Exposed for the capture paths' guard.
	 *
	 * @return bool
	 */
	public static function is_recording(): bool {
		return self::$recording;
	}
}
