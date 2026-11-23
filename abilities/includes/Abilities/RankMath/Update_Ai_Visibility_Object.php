<?php
/**
 * Feature 069 — update an AI Visibility brand or query.
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
 * Ability #44 — rank-math/update-ai-visibility-object.
 *
 * Three mutations behind one target enum: all hit the same controller with the same
 * capability, differing only in which method they call.
 *
 * Declared idempotent:false because ONE of the three is not — generate-queries appends
 * new queries and spends credits each time. Brand and query updates are in fact
 * idempotent; the annotation has to describe the whole ability, so it takes the
 * conservative value and the description states the distinction rather than leaving a
 * caller to discover it.
 *
 * Confirm-gated and declared destructive:true for the same reason as research-keyword:
 * generate-queries spends unrecoverable credits. No DATA is destroyed, but the
 * destructive annotation warns about irreversibility, and spend from a paid balance is
 * irreversible. Brand and query updates alone would not warrant it — the annotation
 * describes the whole ability, so it takes the conservative value.
 */
class Update_Ai_Visibility_Object extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'update-ai-visibility-object';
	}

	protected function ability_label(): string {
		return __( 'Update AI Visibility Brand or Query', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Update an AI Visibility brand\'s configuration, update one monitored query, or generate new baseline queries for a brand. Brand and query updates are safely repeatable; generate-queries APPENDS queries and CONSUMES CREDITS each time it runs, which is why confirmation is required. Check the credit balance first with rank-math/get-content-ai-status.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-content-ai';
	}

	protected function rank_math_cap(): string {
		return '';
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array(
			'target'   => array(
				'type'        => 'string',
				'enum'        => Entitlement_Repository::AI_TARGETS,
				'description' => __( 'brand updates the brand record; query updates one monitored query; generate-queries appends new baseline queries and spends credits.', 'acrossai-abilities-manager' ),
			),
			'brand_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'query_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Required for target=query.', 'acrossai-abilities-manager' ) ),
			'data'     => array(
				'type'                 => 'object',
				'description'          => __( 'Fields to write. Not needed for generate-queries.', 'acrossai-abilities-manager' ),
				'additionalProperties' => true,
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'target'   => array( 'type' => 'string' ),
			'brand_id' => array( 'type' => 'integer' ),
			'query_id' => array( 'type' => array( 'integer', 'null' ) ),
			'result'   => array( 'type' => array( 'object', 'array', 'null' ) ),
		);
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Rank_Math_Ability::ability().
	 */
	protected function required_input(): array {
		return array( 'target', 'brand_id' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => true, 'idempotent' => false );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Entitlement_Repository::update_ai_object(
			isset( $input['target'] ) ? sanitize_key( (string) $input['target'] ) : '',
			isset( $input['brand_id'] ) ? absint( $input['brand_id'] ) : 0,
			isset( $input['query_id'] ) ? absint( $input['query_id'] ) : 0,
			isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = 'generate-queries' === $result['target']
			? sprintf(
				/* translators: %d: brand id */
				__( 'Generated new baseline queries for AI Visibility brand %d. Credits were consumed.', 'acrossai-abilities-manager' ),
				$result['brand_id']
			)
			: sprintf(
				/* translators: 1: target name, 2: brand id */
				__( 'Updated the AI Visibility %1$s for brand %2$d.', 'acrossai-abilities-manager' ),
				$result['target'],
				$result['brand_id']
			);

		return $result;
	}
}
