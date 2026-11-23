<?php
/**
 * Feature 104 — Get Advanced Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-advanced-settings — Get Advanced Settings.
 */
final class Get_Advanced_Settings extends Base_Settings_Read_Ability {

	protected function slug(): string {
		return 'get-advanced-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Advanced Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read the advanced settings: WordPress heartbeat control for the front end, the dashboard and the editor; instant-click prefetching; and LiteSpeed\'s debug logging. The heartbeat controls are a real performance lever on a busy site and are easy to overlook.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-toolbox';
	}

	protected function areas_read(): array {
		return array(
			'advanced',
		);
	}
}
