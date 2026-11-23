<?php
/**
 * Feature 069 — manage the local Content AI prompt library.
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
 * Ability #40 — rank-math/manage-content-ai-prompts.
 *
 * Three endpoints behind one action enum. All are LOCAL option writes: no credits are
 * spent and no remote request is made, which is why this is not confirm-gated.
 */
class Manage_Content_Ai_Prompts extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'manage-content-ai-prompts';
	}

	protected function ability_label(): string {
		return __( 'Manage Content AI Prompts', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Save, update or mark-recent entries in the local Content AI prompt library. These are stored on this site only — no credits are spent and nothing is sent to Rank Math\'s service.', 'acrossai-abilities-manager' );
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
				'enum'        => Entitlement_Repository::PROMPT_ACTIONS,
				'description' => __( 'save replaces the library, update changes one prompt, update-recent records recent use.', 'acrossai-abilities-manager' ),
			),
			'data'   => array(
				'type'                 => 'object',
				'description'          => __( 'Payload for the chosen action, e.g. the prompt list or a single prompt object.', 'acrossai-abilities-manager' ),
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

		$result = Entitlement_Repository::manage_prompts(
			isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '',
			$input['data']
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: %s: action name */
			__( 'Content AI prompt library updated (%s).', 'acrossai-abilities-manager' ),
			$result['action']
		);

		return $result;
	}
}
