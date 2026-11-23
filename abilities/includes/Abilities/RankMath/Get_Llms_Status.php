<?php
/**
 * Feature 069 — llms.txt module state, settings, route and live preview.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Routes_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #59 — rank-math/get-llms-status.
 *
 * Returns state, settings, rewrite status AND a live preview in one call, so no
 * separate preview ability is needed.
 *
 * Read-only, idempotent.
 */
class Get_Llms_Status extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-llms-status';
	}

	protected function ability_label(): string {
		return __( 'Get Rank Math llms.txt Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return everything about the Rank Math llms.txt route in one call: whether the module is active, which post types and taxonomies are included, whether the rewrite rule is actually persisted, and a live preview of the served output. If the module is active but the rule is missing the route will 404 — repair that with rank-math/refresh-llms-route.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-sitemap';
	}

	protected function rank_math_cap(): string {
		return 'general';
	}

	protected function input_properties(): array {
		return array(
			'preview_lines' => array(
				'type'        => 'integer',
				'default'     => 12,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'How many lines of live output to include. The preview is skipped entirely when the module is inactive.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'module'        => array( 'type' => 'string' ),
			'module_active' => array( 'type' => 'boolean' ),
			'route_url'     => array( 'type' => 'string' ),
			'rewrite'       => array( 'type' => 'object' ),
			'settings'      => array( 'type' => 'object' ),
			'live_preview'  => array( 'type' => array( 'object', 'null' ) ),
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
		$lines  = isset( $input['preview_lines'] ) ? (int) $input['preview_lines'] : 12;
		$lines  = max( 1, min( 100, $lines ) );
		$status = Routes_Repository::llms_status( $lines );

		if ( ! $status['module_active'] ) {
			$status['message'] = __( 'The Rank Math llms.txt module is inactive, so /llms.txt is not served. Enable it with rank-math/set-module-status.', 'acrossai-abilities-manager' );
		} elseif ( empty( $status['rewrite']['present'] ) ) {
			$status['message'] = __( 'The llms.txt module is active but its rewrite rule is not persisted, so the route will 404. Run rank-math/refresh-llms-route.', 'acrossai-abilities-manager' );
		} else {
			$status['message'] = __( 'Returned llms.txt status, settings and a live preview.', 'acrossai-abilities-manager' );
		}

		return $status;
	}
}
