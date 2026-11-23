<?php
/**
 * Feature 069 — list Rank Math redirections.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Redirections_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #51 — rank-math/list-redirections.
 *
 * The status filter must include 'trashed': without it there is no way to see what
 * is in the trash, and rank-math/delete-trashed-redirections becomes a
 * blind destructive call.
 *
 * Read-only, idempotent.
 */
class List_Redirections extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'list-redirections';
	}

	protected function ability_label(): string {
		return __( 'List Rank Math Redirections', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return a paginated list of Rank Math redirections with their sources, target, status code, status and hit count. Filter by status, including trashed — inspect the trash here before calling rank-math/delete-trashed-redirections.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-redirections';
	}

	protected function rank_math_cap(): string {
		return 'redirections';
	}

	protected function required_module(): string {
		return Redirections_Repository::MODULE;
	}

	protected function input_properties(): array {
		return array(
			'status'  => array(
				'type'        => 'string',
				'enum'        => Redirections_Repository::STATUSES,
				'default'     => 'all',
				'description' => __( 'Which redirections to return. "all" excludes trashed; pass "trashed" explicitly to inspect the trash.', 'acrossai-abilities-manager' ),
			),
			'limit'   => array(
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 200,
				'description' => __( 'Page size.', 'acrossai-abilities-manager' ),
			),
			'page'    => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( '1-based page number.', 'acrossai-abilities-manager' ),
			),
			'search'  => array(
				'type'        => 'string',
				'description' => __( 'Match against source patterns and the target URL.', 'acrossai-abilities-manager' ),
			),
			'orderby' => array(
				'type'        => 'string',
				'enum'        => array( 'id', 'hits', 'created', 'updated', 'last_accessed' ),
				'default'     => 'id',
				'description' => __( 'Sort column.', 'acrossai-abilities-manager' ),
			),
			'order'   => array(
				'type'        => 'string',
				'enum'        => array( 'ASC', 'DESC' ),
				'default'     => 'DESC',
				'description' => __( 'Sort direction.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'redirections' => array( 'type' => 'array' ),
			'count'        => array( 'type' => 'integer' ),
			'total'        => array( 'type' => 'integer' ),
			'status'       => array( 'type' => 'string' ),
			'page'         => array( 'type' => 'integer' ),
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
		$result = Redirections_Repository::listing(
			isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'all',
			isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 50,
			isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
			isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
			isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'id',
			isset( $input['order'] ) && 'ASC' === strtoupper( (string) $input['order'] ) ? 'ASC' : 'DESC'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number returned, 2: total matching, 3: status filter */
			__( 'Returned %1$d of %2$d redirections with status "%3$s".', 'acrossai-abilities-manager' ),
			$result['count'],
			$result['total'],
			$result['status']
		);

		return $result;
	}
}
