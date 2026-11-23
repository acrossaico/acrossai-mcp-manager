<?php
/**
 * Feature 104 — the LiteSpeed crawler, as the suite sees it.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over LiteSpeed's crawler.
 */
final class Crawler_Repository {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Crawl summary: position, timings and the current run's state.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function summary(): array {
		$summary = (array) \LiteSpeed\Crawler::get_summary();

		return array(
			'is_running'        => ! empty( $summary['is_running'] ),
			'current_crawler'   => (int) ( $summary['curr_crawler'] ?? 0 ),
			'position'          => (int) ( $summary['last_pos'] ?? 0 ),
			'list_size'         => (int) ( $summary['list_size'] ?? 0 ),
			'last_crawled'      => (int) ( $summary['last_crawled'] ?? 0 ),
			'last_start_time'   => (int) ( $summary['last_start_time'] ?? 0 ),
			'last_status'       => (string) ( $summary['last_status'] ?? '' ),
			'end_reason'        => (string) ( $summary['end_reason'] ?? '' ),
			'done'              => ! empty( $summary['done'] ),
			'last_full_seconds' => (int) ( $summary['last_full_time_cost'] ?? 0 ),
		);
	}

	/**
	 * Every crawler variant the configuration generates, as ROWS.
	 *
	 * A list rather than the index-keyed map LiteSpeed returns: an index-keyed PHP array with a gap —
	 * which happens as soon as one crawler is disabled — encodes as a JSON object, not an array
	 * (BUG-ARRAY-TYPED-OUTPUT-IS-A-JSON-OBJECT). The index travels inside each row instead.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function crawlers(): array {
		$crawlers = (array) \LiteSpeed\Core::cls( 'Crawler' )->list_crawlers();
		$disabled = (array) \LiteSpeed\Crawler::get_summary( 'crawler_stats' );
		$rows     = array();

		foreach ( $crawlers as $index => $crawler ) {
			$rows[] = array(
				'index'   => (int) $index,
				'label'   => is_array( $crawler ) ? (string) ( $crawler['desc'] ?? '' ) : (string) $crawler,
				'enabled' => (bool) \LiteSpeed\Core::cls( 'Crawler' )->is_active( (int) $index ),
				'stats'   => isset( $disabled[ $index ] ) && is_array( $disabled[ $index ] ) ? $disabled[ $index ] : array(),
			);
		}

		return $rows;
	}

	/**
	 * Enable or disable one crawler.
	 *
	 * @since  0.0.34
	 * @param  int  $index   Crawler index.
	 * @param  bool $enabled Desired state.
	 * @return bool|WP_Error The resulting state.
	 */
	public static function set_state( int $index, bool $enabled ) {
		$known = array_column( self::crawlers(), 'index' );

		if ( ! in_array( $index, $known, true ) ) {
			return new WP_Error(
				'unknown_crawler',
				sprintf(
					/* translators: 1: requested index, 2: comma-separated valid indexes */
					__( 'No crawler at index %1$d. Valid indexes: %2$s.', 'acrossai-abilities-manager' ),
					$index,
					implode( ', ', array_map( 'strval', $known ) )
				)
			);
		}

		$crawler = \LiteSpeed\Core::cls( 'Crawler' );

		if ( (bool) $crawler->is_active( $index ) !== $enabled ) {
			$crawler->toggle_activeness( $index );
		}

		return (bool) $crawler->is_active( $index );
	}

	/**
	 * Start a crawl now.
	 *
	 * Long-running and dispatched asynchronously by LiteSpeed, so this reports that the run began,
	 * never that it finished.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public static function run(): void {
		\LiteSpeed\Crawler::start( true );
	}

	/**
	 * Reset the crawl position so the next run starts from the beginning.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public static function reset(): void {
		\LiteSpeed\Core::cls( 'Crawler' )->reset_pos();
	}

	/**
	 * A page of the sitemap the crawler works from.
	 *
	 * @since  0.0.34
	 * @param  int $limit  Maximum rows.
	 * @param  int $offset Rows to skip.
	 * @return array{total: int, urls: array<int, string>}
	 */
	public static function map( int $limit = 100, int $offset = 0 ): array {
		$map  = \LiteSpeed\Core::cls( 'Crawler_Map' );
		$list = (array) $map->list_map( $limit, $offset );
		$urls = array();

		foreach ( $list as $row ) {
			$urls[] = is_array( $row ) ? (string) ( $row['url'] ?? '' ) : (string) $row;
		}

		return array(
			'total' => (int) $map->count_map(),
			'urls'  => $urls,
		);
	}
}
