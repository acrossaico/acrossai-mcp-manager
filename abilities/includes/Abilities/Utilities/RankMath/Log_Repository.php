<?php
/**
 * Feature 069 — Rank Math 404 monitor log access.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only accessor for the rank_math_404_logs table.
 *
 * Clearing the whole log is deliberately NOT here: that is one of the twelve
 * maintenance tools (tool=delete_log), so exposing it a second time would give two
 * paths to one destructive operation.
 */
final class Log_Repository {

	/**
	 * Module slug.
	 */
	public const MODULE = '404-monitor';

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether Rank Math's 404 log class is loadable.
	 *
	 * @return true|WP_Error
	 */
	private static function assert_available() {
		if ( ! class_exists( '\RankMath\Monitor\DB' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math 404 monitor module is not available.', 'acrossai-abilities-manager' ) );
		}
		return true;
	}

	/**
	 * Paginated 404 log.
	 *
	 * @param int    $limit   Page size.
	 * @param int    $page    1-based page number.
	 * @param string $search  Optional search term.
	 * @param string $orderby Column to sort by.
	 * @param string $order   ASC or DESC.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function listing( int $limit, int $page, string $search, string $orderby, string $order ) {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$result = \RankMath\Monitor\DB::get_logs(
			array(
				'limit'   => $limit,
				'paged'   => max( 1, $page ),
				'search'  => $search,
				'orderby' => $orderby,
				'order'   => $order,
			)
		);

		$rows  = isset( $result['logs'] ) && is_array( $result['logs'] ) ? $result['logs'] : array();
		$total = isset( $result['count'] ) ? (int) $result['count'] : count( $rows );

		$logs = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$logs[] = array(
				'id'          => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'uri'         => isset( $row['uri'] ) ? (string) $row['uri'] : '',
				'accessed'    => isset( $row['accessed'] ) ? (string) $row['accessed'] : '',
				'times_accessed' => isset( $row['times_accessed'] ) ? (int) $row['times_accessed'] : 0,
				'referer'     => isset( $row['referer'] ) ? (string) $row['referer'] : '',
				'user_agent'  => isset( $row['user_agent'] ) ? (string) $row['user_agent'] : '',
				'ip'          => isset( $row['ip'] ) ? (string) $row['ip'] : '',
			);
		}

		return array(
			'logs'  => $logs,
			'count' => count( $logs ),
			'total' => $total,
			'page'  => max( 1, $page ),
		);
	}

	/**
	 * Total logged 404s.
	 *
	 * @return int
	 */
	public static function count(): int {
		if ( ! class_exists( '\RankMath\Monitor\DB' ) ) {
			return 0;
		}
		return (int) \RankMath\Monitor\DB::get_count();
	}

	/**
	 * Delete specific log entries.
	 *
	 * @param int[] $ids Log ids.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function delete( array $ids ) {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one log id.', 'acrossai-abilities-manager' ) );
		}

		$before = self::count();
		\RankMath\Monitor\DB::delete_log( $ids );
		$after = self::count();

		return array(
			'ids'       => $ids,
			'deleted'   => max( 0, $before - $after ),
			'remaining' => $after,
		);
	}
}
