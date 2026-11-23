<?php
/**
 * Feature 136 — turning WordPress.org's version map into something a caller can act on.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\WpRollback
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback;

defined( 'ABSPATH' ) || exit;

/**
 * Pure handling of a WordPress.org version list.
 *
 * Deliberately knows nothing about HTTP, WP Rollback or `$wpdb`, so every rule here is covered by
 * tests that actually run. The abilities keep the `plugins_api()` / `themes_api()` call; this keeps
 * the decisions.
 *
 * Measured against the real API before writing any of it:
 *
 *   akismet          94 entries, and one of them is `trunk`
 *   twentytwenty     24 entries
 *   acf-pro          WP_Error plugins_api_failed  (not published there)
 *
 * Both of those first two facts shape the code. `trunk` is not a release and must never be offered
 * as a rollback target — it is whatever was last committed. And 94 entries for a small plugin means
 * a large one returns hundreds, so a version list is paginated from the start rather than after
 * someone's context window is full.
 *
 * @since 0.0.45
 */
final class Asset_Versions {

	/**
	 * Entries WordPress.org includes that are not releases.
	 *
	 * @since 0.0.45
	 * @var   string[]
	 */
	public const NOT_RELEASES = array( 'trunk' );

	/**
	 * Default page size for a version listing.
	 *
	 * @since 0.0.45
	 * @var   int
	 */
	public const DEFAULT_LIMIT = 25;

	/**
	 * Ceiling on page size.
	 *
	 * @since 0.0.45
	 * @var   int
	 */
	public const MAX_LIMIT = 200;

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Real releases only, newest first.
	 *
	 * `trunk` is dropped because it is not a release. Anything that does not look like a version is
	 * dropped too: the keys come from an external API, and offering a caller a rollback target that
	 * `version_compare()` cannot reason about is worse than omitting it.
	 *
	 * @since  0.0.45
	 * @param  array<string,mixed> $versions WordPress.org version map, version => package URL.
	 * @return string[] Version strings, newest first.
	 */
	public static function releases( array $versions ): array {
		$releases = array();

		foreach ( array_keys( $versions ) as $version ) {
			$version = (string) $version;

			if ( '' === $version || in_array( strtolower( $version ), self::NOT_RELEASES, true ) ) {
				continue;
			}

			// Must begin with a digit, or version_compare() has nothing to work with.
			if ( 1 !== preg_match( '/^\d/', $version ) ) {
				continue;
			}

			$releases[] = $version;
		}

		$releases = array_values( array_unique( $releases ) );

		usort(
			$releases,
			static function ( string $a, string $b ): int {
				return version_compare( $b, $a );
			}
		);

		return $releases;
	}

	/**
	 * One page of a version list.
	 *
	 * @since  0.0.45
	 * @param  string[] $releases Releases, newest first.
	 * @param  int      $limit    Page size; clamped to 1..MAX_LIMIT.
	 * @param  int      $offset   Zero-based offset; negatives treated as zero.
	 * @return array{versions:string[],total:int,limit:int,offset:int,has_more:bool}
	 */
	public static function page( array $releases, int $limit = self::DEFAULT_LIMIT, int $offset = 0 ): array {
		$total  = count( $releases );
		$limit  = max( 1, min( $limit, self::MAX_LIMIT ) );
		$offset = max( 0, $offset );
		$slice  = array_slice( $releases, $offset, $limit );

		return array(
			'versions' => array_values( $slice ),
			'total'    => $total,
			'limit'    => $limit,
			'offset'   => $offset,
			'has_more' => ( $offset + count( $slice ) ) < $total,
		);
	}

	/**
	 * How a candidate version relates to what is installed.
	 *
	 * Named rather than returned as a number because the caller's question is "is this a downgrade?"
	 * and `-1` does not answer it legibly in a response payload.
	 *
	 * @since  0.0.45
	 * @param  string $candidate Target version.
	 * @param  string $installed Currently installed version.
	 * @return string `older`, `same`, `newer`, or `unknown` when either cannot be compared.
	 */
	public static function relation( string $candidate, string $installed ): string {
		if ( '' === $candidate || '' === $installed ) {
			return 'unknown';
		}

		$compared = version_compare( $candidate, $installed );

		if ( 0 === $compared ) {
			return 'same';
		}

		return $compared < 0 ? 'older' : 'newer';
	}

	/**
	 * Whether a version appears in the published release list.
	 *
	 * Exact string match, not a `version_compare` equality: WordPress.org keys the package map by
	 * the exact tag, and `1.0` and `1.0.0` are different keys there even though `version_compare`
	 * calls them equal. Asking for a tag that does not exist must fail rather than resolve to a
	 * neighbour.
	 *
	 * @since  0.0.45
	 * @param  string   $version  Candidate.
	 * @param  string[] $releases Published releases.
	 * @return bool
	 */
	public static function is_published( string $version, array $releases ): bool {
		return in_array( $version, $releases, true );
	}
}
