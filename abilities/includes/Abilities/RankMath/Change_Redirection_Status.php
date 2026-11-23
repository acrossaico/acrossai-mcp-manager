<?php
/**
 * Feature 069 — bulk redirection status transitions.
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
 * Ability #12 — rank-math/change-redirection-status.
 *
 * Declares destructive:false, deliberately. All four transitions are reversible —
 * including trash, which is undone by restore. Putting a confirm gate on reversible
 * operations trains agents to pass confirm reflexively, which devalues the gate on
 * the operations that genuinely need it.
 *
 * Hard delete is a SEPARATE ability (rank-math/delete-redirections) for
 * exactly this reason: one ability can only carry one annotation triple honestly.
 */
class Change_Redirection_Status extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'change-redirection-status';
	}

	protected function ability_label(): string {
		return __( 'Change Rank Math Redirection Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Activate, deactivate, trash or restore redirections in bulk. Every transition is reversible and nothing is deleted, so no confirmation is needed. To delete permanently use rank-math/delete-redirections, or rank-math/delete-trashed-redirections to empty the trash.', 'acrossai-abilities-manager' );
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
			'ids'    => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'minItems'    => 1,
				'description' => __( 'Redirection ids. Find them with rank-math/list-redirections.', 'acrossai-abilities-manager' ),
			),
			'action' => array(
				'type'        => 'string',
				'enum'        => array( 'activate', 'deactivate', 'trash', 'restore' ),
				'description' => __( 'Transition to apply. All four are reversible.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'ids'     => array( 'type' => 'array' ),
			'action'  => array( 'type' => 'string' ),
			'status'  => array( 'type' => 'string' ),
			'changed' => array( 'type' => 'integer' ),
		);
	}

	protected function required_input(): array {
		return array( 'ids', 'action' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		if ( ! isset( $input['ids'] ) || ! is_array( $input['ids'] ) ) {
			return new WP_Error( 'invalid_input', __( 'ids must be a list of redirection ids.', 'acrossai-abilities-manager' ) );
		}

		$result = Redirections_Repository::change_status(
			$input['ids'],
			isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : ''
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number of redirections changed, 2: resulting status */
			_n( 'Set %1$d redirection to "%2$s".', 'Set %1$d redirections to "%2$s".', $result['changed'], 'acrossai-abilities-manager' ),
			$result['changed'],
			$result['status']
		);

		return $result;
	}
}
