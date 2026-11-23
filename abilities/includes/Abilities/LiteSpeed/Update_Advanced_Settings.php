<?php
/**
 * Feature 104 — Update Advanced Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-advanced-settings — Update Advanced Settings.
 */
final class Update_Advanced_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-advanced-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Advanced Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change the advanced settings: WordPress heartbeat control for the front end, dashboard and editor; instant-click prefetching; and debug logging. Throttling the heartbeat is one of the cheapest wins on a busy site; leaving debug logging on in production will fill the disk.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-toolbox';
	}

	protected function area_written(): string {
		return 'advanced';
	}
}
