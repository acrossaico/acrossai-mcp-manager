<?php
/**
 * Feature 069 — export Rank Math settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Status_Tools_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #21 — rank-math/export-settings.
 *
 * Read-only, idempotent. The natural safety step before any bulk settings change:
 * export, then use rank-math/import-settings to roll back if needed.
 */
class Export_Settings extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'export-settings';
	}

	protected function ability_label(): string {
		return __( 'Export Rank Math Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Export Rank Math settings as a portable payload — all panels by default, or a chosen subset of general, titles, sitemap, role-manager and redirections. Take one of these before any bulk settings change so rank-math/import-settings can roll it back.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-status';
	}

	protected function rank_math_cap(): string {
		return 'general';
	}

	protected function input_properties(): array {
		return array(
			'panels' => array(
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
					'enum' => Status_Tools_Repository::PANELS,
				),
				'description' => __( 'Panels to include. Omit for everything.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'panels' => array( 'type' => 'array' ),
			'data'   => array( 'type' => 'object' ),
			'keys'   => array( 'type' => 'array' ),
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
		$panels = isset( $input['panels'] ) && is_array( $input['panels'] ) ? $input['panels'] : array();

		$result = Status_Tools_Repository::export_settings( $panels );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: %s: comma-separated panel names */
			__( 'Exported Rank Math settings for: %s.', 'acrossai-abilities-manager' ),
			array() === $result['panels'] ? __( 'no panels', 'acrossai-abilities-manager' ) : implode( ', ', $result['panels'] )
		);

		return $result;
	}
}
