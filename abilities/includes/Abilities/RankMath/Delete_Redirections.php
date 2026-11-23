<?php
/**
 * Feature 069 — permanently delete redirections.
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
 * Ability #54 — rank-math/delete-redirections.
 *
 * Separate from change-redirection-status precisely so that ability can declare
 * destructive:false for its four reversible transitions while this one carries the
 * confirm gate.
 *
 * Deleting a live rule breaks whatever it was redirecting, so prefer
 * change-redirection-status action=trash unless removal is genuinely intended.
 */
class Delete_Redirections extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'delete-redirections';
	}

	protected function ability_label(): string {
		return __( 'Delete Rank Math Redirections', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Permanently delete redirections by id. The rules and their hit history are unrecoverable, and any URL currently relying on one will stop redirecting. Prefer rank-math/change-redirection-status with action=trash, which is reversible.', 'acrossai-abilities-manager' );
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

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array(
			'ids' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'minItems'    => 1,
				'description' => __( 'Redirection ids to delete permanently.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'ids'     => array( 'type' => 'array' ),
			'deleted' => array( 'type' => 'integer' ),
		);
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Rank_Math_Ability::ability().
	 */
	protected function required_input(): array {
		return array( 'ids' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => true, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		if ( ! isset( $input['ids'] ) || ! is_array( $input['ids'] ) ) {
			return new WP_Error( 'invalid_input', __( 'ids must be a list of redirection ids.', 'acrossai-abilities-manager' ) );
		}

		$result = Redirections_Repository::delete( $input['ids'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: %d: number of redirections deleted */
			_n( 'Permanently deleted %d redirection.', 'Permanently deleted %d redirections.', $result['deleted'], 'acrossai-abilities-manager' ),
			$result['deleted']
		);

		return $result;
	}
}
