<?php
/**
 * Feature 104 — Update Localisation Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-localization-settings — Update Localisation Settings.
 */
final class Update_Localization_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-localization-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Localisation Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change resource localisation: pull third-party JavaScript and gravatars onto this server so they are cached locally rather than fetched from a remote host on every visit.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-optimize';
	}

	protected function area_written(): string {
		return 'optimize-localization';
	}
}
