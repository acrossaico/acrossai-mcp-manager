<?php
/**
 * Feature 069 — run a Rank Math maintenance tool.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Maintenance_Tools;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #20 — rank-math/run-maintenance-tool.
 *
 * Twelve tools behind one enum: twelve classes differing only in which method they
 * call would be pure boilerplate.
 *
 * Rank Math gates its tools screen on manage_options, so there is no granular
 * capability to compose. Every tool is irreversible to some degree, so the whole
 * ability is confirm-gated rather than trying to grade them individually.
 */
class Run_Maintenance_Tool extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'run-maintenance-tool';
	}

	protected function ability_label(): string {
		return __( 'Run Rank Math Maintenance Tool', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Run one Rank Math maintenance tool: clear transients or caches, rebuild database tables, delete the link index, clear the 404 log, delete all redirections, convert legacy Yoast or AIOSEO blocks, or reindex analytics. Every tool changes or removes data irreversibly. Discover which are currently runnable with rank-math/get-status panel=tools. Some tools continue in the background after responding — check the async flag rather than assuming success means finished.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-status';
	}

	protected function rank_math_cap(): string {
		return '';
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array(
			'tool' => array(
				'type'        => 'string',
				'enum'        => Maintenance_Tools::tool_ids(),
				'description' => __( 'Which tool to run. delete_redirections and delete_log remove ALL rows in those tables, not a selection.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'tool'         => array( 'type' => 'string' ),
			'completed'    => array( 'type' => 'boolean' ),
			'async'        => array( 'type' => 'boolean' ),
			'tool_message' => array( 'type' => 'string' ),
			'poll_hint'    => array( 'type' => 'string' ),
		);
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Rank_Math_Ability::ability().
	 */
	protected function required_input(): array {
		return array( 'tool' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => true, 'idempotent' => false );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$tool = isset( $input['tool'] ) ? sanitize_key( (string) $input['tool'] ) : '';

		$result = Maintenance_Tools::dispatch( $tool );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $result['async'] ) {
			$result['message'] = sprintf(
				/* translators: %s: tool id */
				__( 'Started the "%s" tool. Work continues in the background, so the change may not be visible yet.', 'acrossai-abilities-manager' ),
				$tool
			);
		} elseif ( $result['completed'] ) {
			$result['message'] = '' !== $result['tool_message']
				? sprintf(
					/* translators: 1: tool id, 2: message returned by Rank Math */
					__( 'Ran the "%1$s" tool: %2$s', 'acrossai-abilities-manager' ),
					$tool,
					$result['tool_message']
				)
				: sprintf(
					/* translators: %s: tool id */
					__( 'Ran the "%s" tool.', 'acrossai-abilities-manager' ),
					$tool
				);
		} else {
			$result['message'] = sprintf(
				/* translators: 1: tool id, 2: message returned by Rank Math */
				__( 'The "%1$s" tool reported a problem: %2$s', 'acrossai-abilities-manager' ),
				$tool,
				'' !== $result['tool_message'] ? $result['tool_message'] : __( 'no detail provided', 'acrossai-abilities-manager' )
			);
		}

		return $result;
	}
}
