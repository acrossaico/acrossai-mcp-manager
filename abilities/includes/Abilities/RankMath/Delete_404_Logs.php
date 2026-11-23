<?php
/**
 * Feature 069 — delete specific 404 log entries.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Log_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #56 — rank-math/delete-404-logs.
 *
 * Deletes by id only. Clearing the whole log is
 * rank-math/run-maintenance-tool with tool=delete_log — exposing it here as
 * well would give two paths to one destructive operation.
 */
class Delete_404_Logs extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'delete-404-logs';
	}

	protected function ability_label(): string {
		return __( 'Delete Rank Math 404 Log Entries', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Permanently delete specific 404 log entries by id, typically after creating a redirection for them. The entries and their hit counts cannot be recovered. To clear the entire log use rank-math/run-maintenance-tool with tool=delete_log.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-redirections';
	}

	protected function rank_math_cap(): string {
		return '404-monitor';
	}

	protected function required_module(): string {
		return Log_Repository::MODULE;
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
				'description' => __( 'Log entry ids. Find them with rank-math/list-404-logs.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'ids'       => array( 'type' => 'array' ),
			'deleted'   => array( 'type' => 'integer' ),
			'remaining' => array( 'type' => 'integer' ),
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
			return new WP_Error( 'invalid_input', __( 'ids must be a list of log entry ids.', 'acrossai-abilities-manager' ) );
		}

		$result = Log_Repository::delete( $input['ids'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number deleted, 2: number remaining */
			__( 'Deleted %1$d 404 log entries; %2$d remain.', 'acrossai-abilities-manager' ),
			$result['deleted'],
			$result['remaining']
		);

		return $result;
	}
}
