<?php
/**
 * Feature 104 — Update Viewport Image Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-viewport-settings — Update Viewport Image Settings.
 */
final class Update_Viewport_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-viewport-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Viewport Image Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change the viewport-image toggles, which stop above-the-fold images being lazy loaded. Note these only take effect once viewport data has been generated, which is a QUIC.cloud service outside this suite — the settings are writable here but will not change rendering on their own.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-media';
	}

	protected function area_written(): string {
		return 'media-viewport';
	}
}
