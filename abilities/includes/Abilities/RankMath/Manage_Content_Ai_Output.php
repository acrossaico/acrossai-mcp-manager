<?php
/**
 * Feature 069 — manage stored Content AI outputs.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Entitlement_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #41 — rank-math/manage-content-ai-output.
 *
 * Local storage only: no credits, no remote request. Deleting an output removes a
 * record of work already paid for, so re-creating it would cost credits again — but
 * the output itself is a convenience cache rather than primary data, so this is not
 * confirm-gated.
 */
class Manage_Content_Ai_Output extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'manage-content-ai-output';
	}

	protected function ability_label(): string {
		return __( 'Manage Content AI Output', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Save or delete a stored Content AI output on this site. Local storage only — no credits are spent. Note that deleting an output discards work already paid for, so regenerating it would consume credits again.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-content-ai';
	}

	protected function rank_math_cap(): string {
		return 'content_ai';
	}

	protected function required_module(): string {
		return Entitlement_Repository::CONTENT_AI_MODULE;
	}

	protected function input_properties(): array {
		return array(
			'action' => array(
				'type'        => 'string',
				'enum'        => Entitlement_Repository::OUTPUT_ACTIONS,
				'description' => __( 'Whether to store or remove an output.', 'acrossai-abilities-manager' ),
			),
			'data'   => array(
				'type'                 => 'object',
				'description'          => __( 'The output to save, or an identifier for the one to delete.', 'acrossai-abilities-manager' ),
				'additionalProperties' => true,
			),
		);
	}

	protected function output_properties(): array {
		return array( 'action' => array( 'type' => 'string' ) );
	}

	protected function required_input(): array {
		return array( 'action', 'data' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		if ( ! isset( $input['data'] ) || ! is_array( $input['data'] ) ) {
			return new WP_Error( 'invalid_input', __( 'data must be an object.', 'acrossai-abilities-manager' ) );
		}

		$result = Entitlement_Repository::manage_output(
			isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '',
			$input['data']
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = 'save' === $result['action']
			? __( 'Stored the Content AI output.', 'acrossai-abilities-manager' )
			: __( 'Deleted the Content AI output.', 'acrossai-abilities-manager' );

		return $result;
	}
}
