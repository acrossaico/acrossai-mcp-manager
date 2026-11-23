<?php
/**
 * Feature 069 — empty the redirection trash.
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
 * Ability #13 — rank-math/delete-trashed-redirections.
 *
 * The discovery path for this is
 * rank-math/list-redirections with status=trashed, which is why that filter
 * had to be exposed — otherwise this would be a blind destructive call.
 */
class Delete_Trashed_Redirections extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'delete-trashed-redirections';
	}

	protected function ability_label(): string {
		return __( 'Empty Rank Math Redirection Trash', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Permanently delete every trashed redirection. Inspect what will be removed first with rank-math/list-redirections using status=trashed. Trashed rules are inactive but restorable until this runs.', 'acrossai-abilities-manager' );
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
		return array();
	}

	protected function output_properties(): array {
		return array( 'deleted' => array( 'type' => 'integer' ) );
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Rank_Math_Ability::ability().
	 */
	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => true, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Redirections_Repository::clear_trashed();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = 0 === $result['deleted']
			? __( 'The redirection trash was already empty.', 'acrossai-abilities-manager' )
			: sprintf(
				/* translators: %d: number of redirections deleted */
				_n( 'Permanently deleted %d trashed redirection.', 'Permanently deleted %d trashed redirections.', $result['deleted'], 'acrossai-abilities-manager' ),
				$result['deleted']
			);

		return $result;
	}
}
