<?php
/**
 * Trimming a checksum verification down to what the caller needs to read.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps only the rows a checksum verification actually needs to report.
 *
 * **Why this exists.** Both checksum abilities returned a row per file, including every file that
 * matched. Measured:
 *
 *   - `plugins/verify-plugin-checksums` on query-monitor: 166 rows, all 'ok', roughly 20 KB.
 *   - `core/verify-core-checksums` on a clean install: **3,766 rows, 3,763 of them 'ok',
 *     608,677 characters** — past the MCP response limit, so the ability returned an error instead
 *     of an answer. It had never been usable through an AI client despite working correctly.
 *
 * In that 608 KB there were three rows worth reading: a deleted `hello.php` and two bundled-theme
 * files that had been updated independently of core. The summary already carried the counts, so
 * every 'ok' row was cost without information — and it was being spent straight into a context
 * window.
 *
 * Shared rather than duplicated because both abilities need exactly this, and a filter that drifts
 * between them is a filter one of them silently stops applying.
 *
 * @since 0.0.45
 */
final class Checksum_Results {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The status that means "this file is exactly what was published".
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const STATUS_OK = 'ok';

	/**
	 * Drop the rows whose status is `ok`, keeping everything that needs attention.
	 *
	 * A row with no `status` is kept. The point of the filter is to remove rows known to be fine,
	 * and an unrecognised row is not known to be fine — dropping it would hide it.
	 *
	 * @since  0.0.45
	 * @param  array<int,mixed> $results Verification rows.
	 * @return array<int,mixed> Re-indexed.
	 */
	public static function only_problems( array $results ): array {
		return array_values(
			array_filter(
				$results,
				static function ( $row ): bool {
					if ( ! is_array( $row ) ) {
						return true;
					}

					return self::STATUS_OK !== ( $row['status'] ?? '' );
				}
			)
		);
	}

	/**
	 * How many rows `only_problems()` would remove.
	 *
	 * Reported back to the caller so the omission is stated rather than silent — "3,763 matched and
	 * are omitted" is information; a short list with no explanation is a puzzle.
	 *
	 * @since  0.0.45
	 * @param  array<int,mixed> $results Verification rows.
	 * @return int
	 */
	public static function matched_count( array $results ): int {
		return count( $results ) - count( self::only_problems( $results ) );
	}
}
