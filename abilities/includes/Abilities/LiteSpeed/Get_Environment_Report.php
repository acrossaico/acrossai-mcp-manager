<?php
/**
 * Feature 104 — Get Environment Report.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Toolbox_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-environment-report — Get Environment Report.
 */
final class Get_Environment_Report extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'get-environment-report';
	}

	protected function ability_label(): string {
		return __( 'Get Environment Report', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'LiteSpeed\'s own environment report: server software, PHP configuration, active plugins and the conflicts it detects. The diagnostic to read first when caching behaves inexplicably, and the one to attach when asking LiteSpeed support for help.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-toolbox';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'report' => array( 'type' => 'string' ),

			'bytes'  => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function run( array $input ) {
		$report = Toolbox_Repository::environment_report();

		return array(
			'report'  => $report,
			'bytes'   => strlen( $report ),
			'message' => __( 'Environment report generated.', 'acrossai-abilities-manager' ),
		);
	}
}
