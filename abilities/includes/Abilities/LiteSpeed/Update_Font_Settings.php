<?php
/**
 * Feature 104 — Update Font Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-font-settings — Update Font Settings.
 */
final class Update_Font_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-font-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Font Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change Google Fonts handling: load them asynchronously, remove them entirely, or set the CSS font-display behaviour that controls whether text is visible while a font loads.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-optimize';
	}

	protected function area_written(): string {
		return 'optimize-font';
	}
}
