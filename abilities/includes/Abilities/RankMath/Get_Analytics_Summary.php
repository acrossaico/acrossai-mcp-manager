<?php
/**
 * Feature 069 — Rank Math analytics summary reports.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Analytics_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #29 — rank-math/get-analytics-summary.
 *
 * Six reports behind one enum: all read-only, all needing the same capability and the
 * same date-range setup, differing only in which method they call.
 *
 * Read-only, idempotent.
 */
class Get_Analytics_Summary extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-analytics-summary';
	}

	protected function ability_label(): string {
		return __( 'Get Rank Math Analytics Summary', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return a Rank Math analytics summary for a date range, with period-over-period comparison: the dashboard rollup, search performance, keyword totals, per-post-type performance, the optimization score breakdown, or one post\'s figures. Requires a connected Search Console — without it the ability says so rather than returning empty data that reads as zero traffic.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-analytics';
	}

	protected function rank_math_cap(): string {
		return 'analytics';
	}

	protected function required_module(): string {
		return Analytics_Repository::MODULE;
	}

	protected function input_properties(): array {
		return array(
			'report'     => array(
				'type'        => 'string',
				'enum'        => Analytics_Repository::REPORTS,
				'default'     => 'dashboard',
				'description' => __( 'Which report to return. "post" additionally requires post_id.', 'acrossai-abilities-manager' ),
			),
			'date_range' => array(
				'type'        => 'string',
				'enum'        => Analytics_Repository::DATE_RANGES,
				'default'     => '-30 days',
				'description' => __( 'Period to report on. Comparison is against the immediately preceding period of the same length.', 'acrossai-abilities-manager' ),
			),
			'post_type'  => array(
				'type'        => 'string',
				'description' => __( 'Restrict to one post type, for the posts and optimization reports.', 'acrossai-abilities-manager' ),
			),
			'post_id'    => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Required for report=post.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'report'     => array( 'type' => 'string' ),
			'date_range' => array( 'type' => 'string' ),
			'data'       => array( 'type' => 'object' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$range = Analytics_Repository::normalize_range(
			isset( $input['date_range'] ) ? (string) $input['date_range'] : ''
		);
		if ( is_wp_error( $range ) ) {
			return $range;
		}

		$report = isset( $input['report'] ) ? sanitize_key( (string) $input['report'] ) : 'dashboard';
		$data   = Analytics_Repository::summary(
			$report,
			$range,
			isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '',
			isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'report'     => $report,
			'date_range' => $range,
			'data'       => $data,
			'message'    => sprintf(
				/* translators: 1: report name, 2: date range */
				__( 'Returned the "%1$s" analytics report for %2$s.', 'acrossai-abilities-manager' ),
				$report,
				$range
			),
		);
	}
}
