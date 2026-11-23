<?php
/**
 * Feature 104 — Update Placeholder Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-placeholder-settings — Update Placeholder Settings.
 */
final class Update_Placeholder_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-placeholder-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Placeholder Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change the placeholder shown while a lazy-loaded image arrives: responsive placeholder on or off, its colour, and an inline SVG. Excludes LQIP, which is a QUIC.cloud service and is not part of this suite.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-media';
	}

	protected function area_written(): string {
		return 'media-placeholder';
	}
}
