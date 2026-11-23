<?php
/**
 * Feature 069 — Google URL Inspection results.
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
 * Ability #31 — rank-math/get-index-status.
 *
 * Read-only, idempotent. Reports inspections_table_missing distinctly, because that
 * needs a maintenance tool rather than a retry.
 */
class Get_Index_Status extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-index-status';
	}

	protected function ability_label(): string {
		return __( 'Get Rank Math Index Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return stored Google URL Inspection results: whether each URL is indexed, its coverage state, and any crawl or indexing problems Search Console reported. Requires a connected Search Console with URL Inspection available. If the storage table is missing, the error says so and names the maintenance tool that creates it.', 'acrossai-abilities-manager' );
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
			'page'        => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
			'per_page'    => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 200 ),
			'orderby'     => array( 'type' => 'string' ),
			'order'       => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'default' => 'DESC' ),
			'search'      => array( 'type' => 'string' ),
			'filter'      => array( 'type' => 'string', 'description' => __( 'Coverage-state filter, e.g. indexed or excluded.', 'acrossai-abilities-manager' ) ),
			'filter_type' => array( 'type' => 'string', 'description' => __( 'Which field the filter applies to.', 'acrossai-abilities-manager' ) ),
		);
	}

	protected function output_properties(): array {
		return array(
			'results' => array( 'type' => 'array' ),
			'total'   => array( 'type' => 'integer' ),
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
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 200, (int) $input['per_page'] ) ) : 25;

		$result = Analytics_Repository::inspections(
			array(
				'page'       => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
				'perPage'    => $per_page,
				'orderBy'    => isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : '',
				'order'      => isset( $input['order'] ) && 'ASC' === strtoupper( (string) $input['order'] ) ? 'ASC' : 'DESC',
				'search'     => isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
				'filter'     => isset( $input['filter'] ) ? sanitize_text_field( (string) $input['filter'] ) : '',
				'filterType' => isset( $input['filter_type'] ) ? sanitize_key( (string) $input['filter_type'] ) : '',
			),
			$per_page
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number of rows returned, 2: total rows available */
			__( 'Returned %1$d of %2$d URL Inspection results.', 'acrossai-abilities-manager' ),
			count( $result['results'] ),
			$result['total']
		);

		return $result;
	}
}
