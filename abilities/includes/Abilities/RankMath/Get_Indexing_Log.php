<?php
/**
 * Feature 069 — read the IndexNow submission log.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Instant_Indexing_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #8 — rank-math/get-indexing-log.
 *
 * Read-only, idempotent.
 */
class Get_Indexing_Log extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-indexing-log';
	}

	protected function ability_label(): string {
		return __( 'Get IndexNow Submission Log', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return the IndexNow submission history, newest first, with formatted and human-readable timestamps and the response code for each batch. Filter to manual submissions (made through an ability or the Rank Math UI) or automatic ones (triggered on publish).', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-instant-indexing';
	}

	protected function rank_math_cap(): string {
		return 'general';
	}

	protected function required_module(): string {
		return Instant_Indexing_Repository::MODULE;
	}

	protected function input_properties(): array {
		return array(
			'filter' => array(
				'type'        => 'string',
				'enum'        => array( 'all', 'manual', 'auto' ),
				'default'     => 'all',
				'description' => __( 'Which submissions to include.', 'acrossai-abilities-manager' ),
			),
			'limit'  => array(
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 500,
				'description' => __( 'Maximum entries to return.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'entries'  => array( 'type' => 'array' ),
			'count'    => array( 'type' => 'integer' ),
			'filtered' => array( 'type' => 'integer' ),
			'total'    => array( 'type' => 'integer' ),
			'filter'   => array( 'type' => 'string' ),
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
		$filter = isset( $input['filter'] ) ? sanitize_key( (string) $input['filter'] ) : 'all';
		if ( ! in_array( $filter, array( 'all', 'manual', 'auto' ), true ) ) {
			return new WP_Error( 'invalid_input', __( 'filter must be all, manual or auto.', 'acrossai-abilities-manager' ) );
		}

		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
		$limit = max( 1, min( 500, $limit ) );

		$result = Instant_Indexing_Repository::log( $filter, $limit );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number of entries returned, 2: total entries in the log */
			__( 'Returned %1$d of %2$d IndexNow log entries.', 'acrossai-abilities-manager' ),
			$result['count'],
			$result['total']
		);

		return $result;
	}
}
