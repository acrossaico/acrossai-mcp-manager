<?php
/**
 * Feature 069 — redirection counts and hit statistics.
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
 * Ability #14 — rank-math/get-redirection-stats.
 *
 * Merges Rank Math's two separate reads (DB::get_counts() and DB::get_stats()) into
 * one payload, because its own UI always shows them together and a caller deciding
 * whether to clean up needs both.
 *
 * Read-only, idempotent.
 */
class Get_Redirection_Stats extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-redirection-stats';
	}

	protected function ability_label(): string {
		return __( 'Get Rank Math Redirection Stats', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return redirection counts by status — active, inactive, trashed — alongside hit statistics. Use this to size a cleanup before listing: a large trashed count means rank-math/delete-trashed-redirections has work to do, and zero-hit rules are candidates for removal.', 'acrossai-abilities-manager' );
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
		return array();
	}

	protected function output_properties(): array {
		return array(
			'counts' => array( 'type' => 'object' ),
			'stats'  => array( 'type' => 'object' ),
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
		$result = Redirections_Repository::stats();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = __( 'Returned Rank Math redirection counts and hit statistics.', 'acrossai-abilities-manager' );

		return $result;
	}
}
