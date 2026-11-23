<?php
/**
 * Feature 069 — Rank Math Analytics / Search Console access.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only accessor for Rank Math's analytics layer.
 *
 * Everything routes through \RankMath\Analytics\Stats::get(), which is the single
 * accessor for the whole inheritance chain
 * (Stats extends Keywords extends Posts extends Objects), so summary, row and
 * keyword methods are all reachable from one object.
 *
 * TWO HAZARDS this class exists to contain:
 *
 * 1. Stats::get() derives its date range from a BROWSER COOKIE
 *    (get_date_from_cookie('date_range','-30 days') at class-stats.php:81). An
 *    ability has no cookie, so the range must be set explicitly on every call —
 *    otherwise every report silently reports the last 30 days regardless of what
 *    was asked for. The instance is never cached on our side, because the range is
 *    process-wide mutable state.
 *
 * 2. Several methods take a WP_REST_Request rather than scalars
 *    (get_keywords_rows, get_posts_rows_by_objects, get_post), so one is
 *    synthesized from the ability input.
 */
final class Analytics_Repository {

	/**
	 * Module slug.
	 */
	public const MODULE = 'analytics';

	/**
	 * Date ranges Rank Math's UI offers.
	 */
	public const DATE_RANGES = array( '-7 days', '-15 days', '-30 days', '-3 months', '-6 months', '-1 year' );

	/**
	 * Reports available through summary().
	 */
	public const REPORTS = array( 'dashboard', 'analytics', 'keywords', 'posts', 'optimization', 'post' );

	/**
	 * Datasets available through rows().
	 */
	public const DATASETS = array( 'posts', 'keywords', 'keywords-overview' );

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Assert analytics is usable: module active and Search Console connected.
	 *
	 * Without the connection check, every report returns empty data that reads as
	 * "no traffic" rather than "not connected" — a materially misleading answer.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_ready() {
		if ( ! class_exists( '\RankMath\Analytics\Stats' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math analytics module is not available.', 'acrossai-abilities-manager' ) );
		}
		return Rank_Math_Guard::assert_console();
	}

	/**
	 * The Stats singleton with an explicit date range applied.
	 *
	 * Never cache the return value: set_date_range() mutates process-wide state, so a
	 * held reference can silently answer for the wrong period.
	 *
	 * @param string $range One of self::DATE_RANGES.
	 * @return object|null
	 */
	private static function stats( string $range ): ?object {
		if ( ! class_exists( '\RankMath\Analytics\Stats' ) ) {
			return null;
		}
		$stats = \RankMath\Analytics\Stats::get();
		if ( ! is_object( $stats ) ) {
			return null;
		}
		if ( method_exists( $stats, 'set_date_range' ) ) {
			$stats->set_date_range( $range );
		}
		return $stats;
	}

	/**
	 * Synthesize a WP_REST_Request for the methods that require one.
	 *
	 * @param array<string,mixed> $params Query parameters.
	 * @return WP_REST_Request
	 */
	private static function request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET' );
		$request->set_query_params( $params );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Normalise and validate a date range.
	 *
	 * @param string $range Submitted range.
	 * @return string|WP_Error
	 */
	public static function normalize_range( string $range ) {
		if ( '' === $range ) {
			return '-30 days';
		}
		if ( ! in_array( $range, self::DATE_RANGES, true ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: submitted range, 2: comma-separated valid ranges */
					__( 'Unknown date_range "%1$s". Valid ranges: %2$s.', 'acrossai-abilities-manager' ),
					$range,
					implode( ', ', self::DATE_RANGES )
				)
			);
		}
		return $range;
	}

	/**
	 * A summary report.
	 *
	 * @param string $report    One of self::REPORTS.
	 * @param string $range     Date range.
	 * @param string $post_type Optional post type filter.
	 * @param int    $post_id   Required for report=post.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function summary( string $report, string $range, string $post_type = '', int $post_id = 0 ) {
		$ready = self::assert_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$stats = self::stats( $range );
		if ( null === $stats ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'Rank Math analytics could not be initialised.', 'acrossai-abilities-manager' ) );
		}

		switch ( $report ) {
			case 'dashboard':
				return array(
					'stats'        => self::call( $stats, 'get_analytics_summary' ),
					'optimization' => self::call( $stats, 'get_optimization_summary' ),
				);

			case 'analytics':
				return array(
					'summary'      => self::call( $stats, 'get_analytics_summary' ),
					'graph'        => self::call( $stats, 'get_analytics_summary_graph' ),
					'intervals'    => self::call( $stats, 'get_intervals' ),
				);

			case 'keywords':
				return array( 'summary' => self::call( $stats, 'get_keywords_summary' ) );

			case 'posts':
				return array(
					'summary'      => self::call( $stats, 'get_posts_summary', array( $post_type ) ),
					'optimization' => self::call( $stats, 'get_optimization_summary', array( $post_type ) ),
				);

			case 'optimization':
				return array( 'optimization' => self::call( $stats, 'get_optimization_summary', array( $post_type ) ) );

			case 'post':
				if ( $post_id < 1 ) {
					return new WP_Error( 'invalid_input', __( 'report=post requires a post_id.', 'acrossai-abilities-manager' ) );
				}
				if ( null === get_post( $post_id ) ) {
					return new WP_Error(
						'not_found',
						sprintf(
							/* translators: %d: post id */
							__( 'Post %d does not exist.', 'acrossai-abilities-manager' ),
							$post_id
						)
					);
				}
				return array( 'post' => self::call( $stats, 'get_post', array( self::request( array( 'id' => $post_id ) ) ) ) );
		}

		return new WP_Error(
			'invalid_input',
			sprintf(
				/* translators: 1: submitted report, 2: comma-separated valid reports */
				__( 'Unknown report "%1$s". Valid reports: %2$s.', 'acrossai-abilities-manager' ),
				$report,
				implode( ', ', self::REPORTS )
			)
		);
	}

	/**
	 * A paginated row dataset.
	 *
	 * @param string              $dataset One of self::DATASETS.
	 * @param string              $range   Date range.
	 * @param array<string,mixed> $params  Paging / sorting / search parameters.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function rows( string $dataset, string $range, array $params ) {
		$ready = self::assert_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$stats = self::stats( $range );
		if ( null === $stats ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'Rank Math analytics could not be initialised.', 'acrossai-abilities-manager' ) );
		}

		switch ( $dataset ) {
			case 'posts':
				return array( 'rows' => self::call( $stats, 'get_posts_rows_by_objects', array( self::request( $params ) ) ) );

			case 'keywords':
				return array( 'rows' => self::call( $stats, 'get_keywords_rows', array( self::request( $params ) ) ) );

			case 'keywords-overview':
				return array(
					'top_keywords'   => self::call( $stats, 'get_top_keywords' ),
					'position_graph' => self::call( $stats, 'get_top_position_graph' ),
				);
		}

		return new WP_Error(
			'invalid_input',
			sprintf(
				/* translators: 1: submitted dataset, 2: comma-separated valid datasets */
				__( 'Unknown dataset "%1$s". Valid datasets: %2$s.', 'acrossai-abilities-manager' ),
				$dataset,
				implode( ', ', self::DATASETS )
			)
		);
	}

	/**
	 * URL Inspection results.
	 *
	 * @param array<string,mixed> $params   Filter / paging parameters.
	 * @param int                 $per_page Page size.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function inspections( array $params, int $per_page ) {
		$ready = self::assert_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! class_exists( '\RankMath\Analytics\URL_Inspection' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'Rank Math URL Inspection is not available.', 'acrossai-abilities-manager' ) );
		}
		if ( ! \RankMath\Analytics\URL_Inspection::is_enabled() ) {
			return new WP_Error( 'google_console_not_connected', __( 'URL Inspection is not enabled. It requires a connected Search Console property with the Inspection API available.', 'acrossai-abilities-manager' ) );
		}

		$inspection = \RankMath\Analytics\URL_Inspection::get();
		if ( ! is_object( $inspection ) || ! method_exists( $inspection, 'get_inspections' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'Rank Math URL Inspection is not available.', 'acrossai-abilities-manager' ) );
		}

		$results = $inspection->get_inspections( self::request( $params ), $per_page );

		// get_inspections() does a bare `return;` when the
		// rank_math_analytics_inspections table is missing, so null means "storage
		// absent", not "no rows" — and the fix is a maintenance tool, not a retry.
		if ( null === $results ) {
			return new WP_Error(
				'inspections_table_missing',
				__( 'The URL Inspection storage table does not exist. Create it with rank-math/run-maintenance-tool using tool=recreate_tables, then try again.', 'acrossai-abilities-manager' )
			);
		}

		$rows = is_array( $results ) ? $results : array();

		return array(
			'results' => isset( $rows['rows'] ) && is_array( $rows['rows'] ) ? $rows['rows'] : $rows,
			'total'   => isset( $rows['rowsFound'] ) ? (int) $rows['rowsFound'] : count( $rows ),
		);
	}

	/**
	 * Schedule or immediately run a URL inspection.
	 *
	 * @param string $url  URL to inspect.
	 * @param string $mode 'schedule' or 'now'.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function inspect_url( string $url, string $mode ) {
		$ready = self::assert_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! class_exists( '\RankMath\Analytics\URL_Inspection' ) || ! \RankMath\Analytics\URL_Inspection::is_enabled() ) {
			return new WP_Error( 'google_console_not_connected', __( 'URL Inspection is not enabled for this site.', 'acrossai-abilities-manager' ) );
		}
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'invalid_input', __( 'url must be a valid absolute URL.', 'acrossai-abilities-manager' ) );
		}

		$inspection = \RankMath\Analytics\URL_Inspection::get();
		if ( ! is_object( $inspection ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'Rank Math URL Inspection is not available.', 'acrossai-abilities-manager' ) );
		}

		if ( 'now' === $mode ) {
			if ( ! method_exists( $inspection, 'inspect' ) ) {
				return new WP_Error( 'rank_math_module_inactive', __( 'This Rank Math build cannot inspect a URL immediately.', 'acrossai-abilities-manager' ) );
			}
			// Consumes Google Search Console daily inspection quota.
			$result = $inspection->inspect( $url );
			return array(
				'url'       => $url,
				'mode'      => 'now',
				'scheduled' => false,
				'result'    => is_array( $result ) ? $result : array(),
			);
		}

		if ( ! method_exists( $inspection, 'schedule_inspection' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'This Rank Math build cannot schedule an inspection.', 'acrossai-abilities-manager' ) );
		}
		$inspection->schedule_inspection( $url );

		return array(
			'url'       => $url,
			'mode'      => 'schedule',
			'scheduled' => true,
			'result'    => array(),
		);
	}

	/**
	 * Call a Stats method defensively, so a Rank Math build lacking one degrades to
	 * null rather than fataling.
	 *
	 * @param object       $stats  Stats instance.
	 * @param string       $method Method name.
	 * @param array<mixed> $args   Arguments.
	 * @return mixed
	 */
	private static function call( object $stats, string $method, array $args = array() ) {
		if ( ! method_exists( $stats, $method ) ) {
			return null;
		}
		return $stats->$method( ...$args );
	}
}
