<?php
/**
 * Feature 104 — Update Tuning Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-tuning-settings — Update Tuning Settings.
 */
final class Update_Tuning_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-tuning-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Tuning Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change the optimisation tuning options: which URIs and roles skip optimisation entirely, and whether optimisation applies to guests only.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-optimize';
	}

	protected function area_written(): string {
		return 'optimize-tuning';
	}
}
