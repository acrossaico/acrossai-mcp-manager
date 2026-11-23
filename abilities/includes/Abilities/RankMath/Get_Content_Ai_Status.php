<?php
/**
 * Feature 069 — Content AI connection, plan and credit balance.
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
 * Ability #39 — rank-math/get-content-ai-status.
 *
 * The probe that makes the other Content AI abilities safe to attempt: it reads
 * locally, spends nothing, and tells a caller whether an account is connected and how
 * many credits remain before anything is committed.
 *
 * Read-only, idempotent.
 */
class Get_Content_Ai_Status extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-content-ai-status';
	}

	protected function ability_label(): string {
		return __( 'Get Rank Math Content AI Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Report whether Content AI is usable: module state, whether a Rank Math account is connected, the current plan, and the remaining credit balance with usage details. Reads locally and spends no credits, so call this before rank-math/research-keyword to know whether that request can succeed.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-content-ai';
	}

	protected function rank_math_cap(): string {
		return 'content_ai';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'module_active' => array( 'type' => 'boolean' ),
			'connected'     => array( 'type' => 'boolean' ),
			'credits'       => array( 'type' => array( 'integer', 'null' ) ),
			'plan'          => array( 'type' => 'string' ),
			'usage'         => array( 'type' => 'object' ),
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
		$status = Entitlement_Repository::content_ai_status();
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		if ( ! $status['module_active'] ) {
			$status['message'] = __( 'The Content AI module is inactive. Enable it with rank-math/set-module-status.', 'acrossai-abilities-manager' );
		} elseif ( ! $status['connected'] ) {
			$status['message'] = __( 'Content AI needs a connected Rank Math account. Connect the site at Rank Math → Dashboard.', 'acrossai-abilities-manager' );
		} elseif ( 0 === (int) $status['credits'] ) {
			$status['message'] = __( 'Content AI is connected but has no credits remaining, so credit-consuming abilities will fail.', 'acrossai-abilities-manager' );
		} else {
			$status['message'] = sprintf(
				/* translators: %d: remaining credits */
				__( 'Content AI is connected with %d credits remaining.', 'acrossai-abilities-manager' ),
				(int) $status['credits']
			);
		}

		return $status;
	}
}
