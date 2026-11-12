<?php
/**
 * The one write path into the clients registry (Feature 104).
 *
 * A15-exempt stateless helper, mirroring {@see \AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder}:
 * static methods only, no constructor, no `instance()`, never Loader-wired.
 * Every method is best-effort and swallows its own failures, because this runs
 * inside the live execution path of real MCP traffic and a registry write must
 * never change what a client receives.
 *
 * ## Why this is not the activity log's recorder
 *
 * `HandlerResolver` hands the adapter a Null handler whenever request logging
 * is off, and the log's own docs say the recording path is then "never reached
 * at all". Writing the client row from inside that handler would therefore
 * couple the registry to a toggle no operator would expect it to follow:
 * switching activity logging off would silently stop recording who connects.
 * Hence a separate recorder behind a separate, always-installed handler.
 *
 * @package AcrossAI_MCP_Manager\Includes\Database\Clients
 * @since   0.4.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\Clients;

use AcrossAI_MCP_Manager\Includes\Utilities\ClientsConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Records sightings and lifecycle events for MCP clients.
 *
 * @since 0.4.8
 */
final class Recorder {

	/**
	 * Re-entry guard.
	 *
	 * The registry is itself readable through MCP, so an ability that reads it
	 * would otherwise record itself reading it. Same reason the activity log's
	 * recorder carries one.
	 *
	 * @since 0.4.8
	 * @var   bool
	 */
	private static $recording = false;

	/**
	 * Record that a client was seen on this request.
	 *
	 * Builds the identity from the signals, then hands one upsert to the Query
	 * layer. Returns a bool rather than an id because the caller has nothing to
	 * do with either — it is on the request path and may not react.
	 *
	 * @since  0.4.8
	 * @param  array<string, mixed> $signals Request facts. See {@see Identity::resolve()}
	 *                                       for the recognised keys; `server_id`
	 *                                       is required and must be > 0.
	 * @return bool True when a row was written.
	 */
	public static function record( array $signals ): bool {
		$server_id = isset( $signals['server_id'] ) ? (int) $signals['server_id'] : 0;

		// A sighting with no server is not a client of anything. Recording it
		// under server 0 would merge every unresolvable server's callers into
		// one shared row — the exact failure Identity exists to prevent, one
		// level up.
		if ( $server_id <= 0 ) {
			return false;
		}

		return self::write( $server_id, $signals );
	}

	/**
	 * Record a client that exists but has not called yet.
	 *
	 * The row lands with `last_seen_at` NULL and `request_count` 0, which the
	 * list renders as "Never used" — the difference between a client that is
	 * set up wrong and one that is not set up yet. Used by the backfill for
	 * registered OAuth clients with no traffic, and available to any lifecycle
	 * listener that learns of a client before it connects.
	 *
	 * @since  0.4.8
	 * @param  array<string, mixed> $signals Same shape as {@see self::record()}.
	 * @return bool True when a row was written.
	 */
	public static function record_registered( array $signals ): bool {
		$server_id = isset( $signals['server_id'] ) ? (int) $signals['server_id'] : 0;

		if ( $server_id <= 0 ) {
			return false;
		}

		$signals['request_count'] = 0;
		$signals['last_seen_at']  = null;

		return self::write( $server_id, $signals );
	}

	/**
	 * Withdraw a client's access, keeping its history.
	 *
	 * Soft, per the registry's whole premise: a deleted row answers "who has
	 * connected" with silence. Revoking the underlying credential is the
	 * caller's job — this records that it happened.
	 *
	 * @since  0.4.8
	 * @param  int $id Row id.
	 * @return bool
	 */
	public static function revoke( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		try {
			return Query::instance()->revoke( $id );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Best-effort registry write; must never propagate.
			error_log( '[acrossai-mcp] Clients\\Recorder::revoke failed: ' . $e->getMessage() );

			return false;
		}
	}

	/**
	 * The single guarded write.
	 *
	 * @since  0.4.8
	 * @param  int                  $server_id Server row id, already validated.
	 * @param  array<string, mixed> $signals   Request facts.
	 * @return bool
	 */
	private static function write( int $server_id, array $signals ): bool {
		if ( self::$recording ) {
			return false;
		}

		self::$recording = true;

		try {
			$type     = isset( $signals['connection_type'] ) ? (string) $signals['connection_type'] : '';
			$identity = Identity::resolve( $signals );

			$row = array(
				'server_id'       => $server_id,
				'identity_hash'   => Identity::hash( $server_id, $type, $identity ),
				'identity'        => $identity,
				'connection_type' => $type,
				'route'           => Identity::route( $signals ),
				'client_name'     => self::text( $signals, 'client_name', 191 ),
				'client_version'  => self::text( $signals, 'client_version', 64 ),
				'connector_slug'  => self::text( $signals, 'connector_slug', 64 ),
				'client_id'       => self::text( $signals, 'client_id', 64 ),
				'credential_id'   => self::text( $signals, 'credential_id', 36 ),
				'credential_hint' => self::text( $signals, 'credential_hint', 32 ),
				'user_id'         => isset( $signals['user_id'] ) ? (int) $signals['user_id'] : 0,
				// Signals are named for what they ARE; columns for what they
				// HOLD. `user_agent` is the agent of this request, `last_user_agent`
				// is the newest one we have seen — the same value, read under
				// two different questions. Mapped here so `Identity` and the
				// row never need two spellings of one fact.
				'last_ip'         => self::text( $signals, 'ip_address', 45 ),
				'last_user_agent' => self::text( $signals, 'user_agent', 255 ),
				'request_count'   => isset( $signals['request_count'] ) ? max( 0, (int) $signals['request_count'] ) : 1,
			);

			// An empty string is not a datetime. The backfill reads its
			// timestamps out of source rows that may carry blanks, and under
			// strict mode `''` in a datetime column is a hard error that would
			// fail the whole upsert — so a blank means "no opinion", and the
			// column default takes over.
			if ( isset( $signals['first_seen_at'] ) && is_string( $signals['first_seen_at'] ) && '' !== $signals['first_seen_at'] ) {
				$row['first_seen_at'] = $signals['first_seen_at'];
			}

			// Present-but-null is meaningful here and `isset()` cannot see it:
			// `record_registered()` sets this to null on purpose, and reading it
			// with isset() would silently stamp "now" instead.
			if ( array_key_exists( 'last_seen_at', $signals ) ) {
				$seen = $signals['last_seen_at'];

				$row['last_seen_at'] = is_string( $seen ) && '' !== $seen ? $seen : null;
			}

			if ( Query::instance()->touch( $row ) ) {
				self::clear_failures();

				return true;
			}

			// `touch()` returns false rather than throwing when the table name
			// is unresolvable or a column is missing from the live table — the
			// B34 shape. Reading it as success would lose writes silently.
			self::note_failure();

			return false;
		} catch ( \Throwable $e ) {
			self::note_failure();

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Best-effort registry write; must never reach the client.
			error_log( '[acrossai-mcp] Clients\\Recorder::write failed: ' . $e->getMessage() );

			return false;
		} finally {
			self::$recording = false;
		}
	}

	/**
	 * One signal as a trimmed, width-clamped string.
	 *
	 * Clamped here rather than relying on MySQL truncation: in strict mode an
	 * over-long value is an ERROR, not a warning, so an unusually chatty user
	 * agent would fail the whole upsert and lose the sighting.
	 *
	 * @since  0.4.8
	 * @param  array<string, mixed> $signals Request facts.
	 * @param  string               $key     Signal name.
	 * @param  int                  $length  Column width.
	 * @return string
	 */
	private static function text( array $signals, string $key, int $length ): string {
		if ( ! isset( $signals[ $key ] ) || ! is_scalar( $signals[ $key ] ) ) {
			return '';
		}

		return mb_substr( trim( (string) $signals[ $key ] ), 0, $length );
	}

	/**
	 * Note that a registry write failed.
	 *
	 * The registry swallows every failure by design, which means it can stop
	 * recording while the screen still looks healthy — an empty list reads as
	 * "nobody has connected", which is the opposite of "we stopped looking".
	 * Site Health reads this counter to tell the two apart.
	 *
	 * @since  0.4.8
	 * @return void
	 */
	private static function note_failure(): void {
		try {
			$count = (int) get_option( ClientsConstants::OPTION_WRITE_FAILURES, 0 );

			// Capped: an unbounded counter on a failing write path is itself a
			// write amplifier, and the screen only needs to know that failures
			// are happening.
			if ( $count < 1000 ) {
				update_option( ClientsConstants::OPTION_WRITE_FAILURES, $count + 1, false );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Reset the counter after a successful write.
	 *
	 * @since  0.4.8
	 * @return void
	 */
	private static function clear_failures(): void {
		try {
			if ( 0 !== (int) get_option( ClientsConstants::OPTION_WRITE_FAILURES, 0 ) ) {
				update_option( ClientsConstants::OPTION_WRITE_FAILURES, 0, false );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Consecutive write failures since the last success.
	 *
	 * @since  0.4.8
	 * @return int
	 */
	public static function failure_count(): int {
		return (int) get_option( ClientsConstants::OPTION_WRITE_FAILURES, 0 );
	}
}
