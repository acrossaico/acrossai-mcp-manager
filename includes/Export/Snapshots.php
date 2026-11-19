<?php
/**
 * The automatic restore point taken before every import (Feature 105).
 *
 * Overwrite-by-slug replaces a destination server's settings wholesale, and
 * the screen offers no per-server preview — which matches both precedents
 * (neither ACF nor Rank Math has one) and is only coherent because of this.
 * Rank Math's is the better of the two patterns because it needs nothing from
 * the operator: `pre_import` takes a snapshot whether or not anything collides.
 *
 * Two things are done deliberately better than that precedent:
 *
 *  1. **It is capped.** `rank_math_backups` grows by a full settings snapshot
 *     on every import, forever — the same class of bug as a log with no
 *     retention. Five are kept here and the rest are dropped.
 *  2. **It is not autoloaded.** A growing blob on every page load is a
 *     performance problem waiting for a busy site.
 *
 * A snapshot IS an export — same serialiser, same format — so restoring is
 * importing, and there is one code path to get wrong rather than two.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Export
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and lists pre-import restore points.
 *
 * @since 0.4.9
 */
final class Snapshots {

	/**
	 * Option holding the snapshots, keyed by unix timestamp.
	 *
	 * Swept on uninstall by the `acrossai_mcp_%` prefix rule — no explicit
	 * delete needed.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const OPTION = 'acrossai_mcp_export_snapshots';

	/**
	 * How many to keep.
	 *
	 * Enough to undo a mistake and the mistake before it; few enough that the
	 * option stays small. An operator who needs more than five has a different
	 * problem than this feature solves.
	 *
	 * @since 0.4.9
	 * @var   int
	 */
	public const KEEP = 5;

	/**
	 * Not instantiable.
	 *
	 * @since 0.4.9
	 */
	private function __construct() {}

	/**
	 * Take a snapshot of every server and every block.
	 *
	 * Deliberately NOT narrowed to the blocks being imported. A snapshot
	 * narrowed that way is not a restore point, because the blocks it left out
	 * are exactly the ones nothing else would put back.
	 *
	 * @since  0.4.9
	 * @param  string $reason Short machine-readable note, e.g. `import`.
	 * @return int The snapshot's key, or 0 when it could not be taken.
	 */
	public static function take( string $reason = 'import' ): int {
		try {
			$payload = Envelope::build_everything();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A snapshot failure must not block the operator; it is reported by take() returning 0.
			error_log( '[acrossai-mcp] Snapshots::take failed: ' . $e->getMessage() );

			return 0;
		}

		// `time()`, not `current_time( 'U' )`: this is an opaque sort key and a
		// filename-safe id, never displayed raw, so a UTC timestamp is the
		// honest value. WPCS flags the site-offset variants for exactly this
		// confusion.
		$key = time();

		$all = self::all();

		// A second import inside the same second would otherwise overwrite the
		// first one's restore point — the one moment an operator is most
		// likely to want it.
		while ( isset( $all[ $key ] ) ) {
			++$key;
		}

		$all[ $key ] = array(
			'reason'  => sanitize_key( $reason ),
			'servers' => count( $payload['servers'] ),
			'payload' => $payload,
		);

		krsort( $all, SORT_NUMERIC );

		$all = array_slice( $all, 0, self::KEEP, true );

		update_option( self::OPTION, $all, false );

		return $key;
	}

	/**
	 * Every stored snapshot, newest first.
	 *
	 * @since  0.4.9
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();

		foreach ( $stored as $key => $snapshot ) {
			if ( is_array( $snapshot ) && isset( $snapshot['payload'] ) && is_array( $snapshot['payload'] ) ) {
				$clean[ (int) $key ] = $snapshot;
			}
		}

		krsort( $clean, SORT_NUMERIC );

		return $clean;
	}

	/**
	 * One snapshot's payload, ready to be imported.
	 *
	 * @since  0.4.9
	 * @param  int $key Snapshot key.
	 * @return array<string, mixed>|null
	 */
	public static function payload( int $key ): ?array {
		$all = self::all();

		return isset( $all[ $key ]['payload'] ) && is_array( $all[ $key ]['payload'] )
			? $all[ $key ]['payload']
			: null;
	}

	/**
	 * Remove one snapshot.
	 *
	 * @since  0.4.9
	 * @param  int $key Snapshot key.
	 * @return bool
	 */
	public static function forget( int $key ): bool {
		$all = self::all();

		if ( ! isset( $all[ $key ] ) ) {
			return false;
		}

		unset( $all[ $key ] );

		update_option( self::OPTION, $all, false );

		return true;
	}
}
