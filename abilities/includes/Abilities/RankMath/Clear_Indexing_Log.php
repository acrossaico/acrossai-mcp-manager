<?php
/**
 * Feature 069 — clear the IndexNow submission log.
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
 * Ability #9 — rank-math/clear-indexing-log.
 *
 * Destructive: the submission history cannot be recovered, so it requires
 * confirm: true.
 */
class Clear_Indexing_Log extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'clear-indexing-log';
	}

	protected function ability_label(): string {
		return __( 'Clear IndexNow Submission Log', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Permanently delete the entire IndexNow submission history. The log is the only record of what was submitted and when, and it cannot be recovered. Read it first with rank-math/get-indexing-log.', 'acrossai-abilities-manager' );
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

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'cleared'         => array( 'type' => 'boolean' ),
			'entries_removed' => array( 'type' => 'integer' ),
		);
	}

	/**
	 * Deliberately empty. 'confirm' must not be schema-required — see
	 * Base_Rank_Math_Ability::ability(). A required confirm would make an
	 * unconfirmed call fail core schema validation before execute() runs, so the
	 * caller would get a generic ability_invalid_input instead of
	 * confirmation_required and the message naming the flag.
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
		$result = Instant_Indexing_Repository::clear_log();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: %d: number of log entries removed */
			_n( 'Removed %d IndexNow log entry.', 'Removed %d IndexNow log entries.', $result['entries_removed'], 'acrossai-abilities-manager' ),
			$result['entries_removed']
		);

		return $result;
	}
}
