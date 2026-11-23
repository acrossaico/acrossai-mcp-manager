<?php
/**
 * Feature 069 — bulk-write Rank Math meta across posts or terms.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Post_Meta_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #33 — rank-math/bulk-update-meta.
 *
 * Rank Math's own bulk endpoint silently skips rows it cannot process and always
 * returns success, so this ability computes processed/skipped itself with a reason per
 * skipped row. Without that a caller cannot tell a 500-row success from a 500-row
 * no-op.
 */
class Bulk_Update_Meta extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'bulk-update-meta';
	}

	protected function ability_label(): string {
		return __( 'Bulk Update Rank Math Meta', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Write Rank Math meta for many posts or terms in one call. Accepts only the five columns Rank Math\'s bulk editor supports: focus_keyword, title, description, image_alt and image_title. Returns which rows were applied and which were skipped with a reason, because Rank Math itself reports success even for rows it ignored.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-content';
	}

	protected function rank_math_cap(): string {
		return 'onpage_general';
	}

	protected function input_properties(): array {
		return array(
			'object_type' => array(
				'type'        => 'string',
				'enum'        => array( 'post', 'term' ),
				'default'     => 'post',
				'description' => __( 'Whether the ids are posts or terms.', 'acrossai-abilities-manager' ),
			),
			'rows'        => array(
				'type'                 => 'object',
				'description'          => __( 'Object id => { column: value }. Allowed columns: focus_keyword, title, description, image_alt, image_title.', 'acrossai-abilities-manager' ),
				'additionalProperties' => true,
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'object_type' => array( 'type' => 'string' ),
			'processed'   => array( 'type' => 'array' ),
			'skipped'     => array( 'type' => 'array' ),
		);
	}

	protected function required_input(): array {
		return array( 'rows' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		if ( ! isset( $input['rows'] ) || ! is_array( $input['rows'] ) ) {
			return new WP_Error( 'invalid_input', __( 'rows must be an object keyed by object id.', 'acrossai-abilities-manager' ) );
		}

		$result = Post_Meta_Repository::bulk_update_meta(
			isset( $input['object_type'] ) ? sanitize_key( (string) $input['object_type'] ) : 'post',
			$input['rows']
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number applied, 2: number skipped */
			__( 'Applied %1$d rows, skipped %2$d.', 'acrossai-abilities-manager' ),
			count( $result['processed'] ),
			count( $result['skipped'] )
		);

		return $result;
	}
}
