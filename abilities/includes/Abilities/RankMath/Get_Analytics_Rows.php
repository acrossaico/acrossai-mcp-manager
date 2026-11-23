<?php
/**
 * Feature 069 — Rank Math analytics row datasets.
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
 * Ability #30 — rank-math/get-analytics-rows.
 *
 * Three row datasets behind one enum, sharing the same paging, sorting and search
 * inputs.
 *
 * Read-only, idempotent.
 */
class Get_Analytics_Rows extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-analytics-rows';
	}

	protected function ability_label(): string {
		return __( 'Get Rank Math Analytics Rows', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return paginated analytics rows: per-post search performance, the rank-tracker keyword table, or the keywords overview with its position graph. Each row carries clicks, impressions, CTR and average position for the requested period.', 'acrossai-abilities-manager' );
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
			'dataset'    => array(
				'type'        => 'string',
				'enum'        => Analytics_Repository::DATASETS,
				'default'     => 'posts',
				'description' => __( 'Which dataset to return.', 'acrossai-abilities-manager' ),
			),
			'date_range' => array(
				'type'        => 'string',
				'enum'        => Analytics_Repository::DATE_RANGES,
				'default'     => '-30 days',
				'description' => __( 'Period to report on.', 'acrossai-abilities-manager' ),
			),
			'page'       => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
			'per_page'   => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 200 ),
			'orderby'    => array( 'type' => 'string', 'description' => __( 'Column to sort by, e.g. clicks or impressions.', 'acrossai-abilities-manager' ) ),
			'order'      => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'default' => 'DESC' ),
			'search'     => array( 'type' => 'string', 'description' => __( 'Filter rows by keyword or URL.', 'acrossai-abilities-manager' ) ),
		);
	}

	protected function output_properties(): array {
		return array(
			'dataset'    => array( 'type' => 'string' ),
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

		$dataset = isset( $input['dataset'] ) ? sanitize_key( (string) $input['dataset'] ) : 'posts';

		// Rank Math's row methods read camelCase query params, so translate.
		$params = array(
			'page'    => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
			'perPage' => isset( $input['per_page'] ) ? max( 1, min( 200, (int) $input['per_page'] ) ) : 25,
			'orderBy' => isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : '',
			'order'   => isset( $input['order'] ) && 'ASC' === strtoupper( (string) $input['order'] ) ? 'ASC' : 'DESC',
			'search'  => isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
		);

		$data = Analytics_Repository::rows( $dataset, $range, $params );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'dataset'    => $dataset,
			'date_range' => $range,
			'data'       => $data,
			'message'    => sprintf(
				/* translators: 1: dataset name, 2: date range */
				__( 'Returned the "%1$s" analytics rows for %2$s.', 'acrossai-abilities-manager' ),
				$dataset,
				$range
			),
		);
	}
}
