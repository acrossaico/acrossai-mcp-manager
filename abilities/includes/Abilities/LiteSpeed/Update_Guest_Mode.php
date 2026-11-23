<?php
/**
 * Feature 104 — Update Guest Mode.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-guest-mode — Update Guest Mode.
 */
final class Update_Guest_Mode extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-guest-mode';
	}

	protected function ability_label(): string {
		return __( 'Update Guest Mode', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Turn guest mode on or off. Guest mode serves a pre-built page to first-time visitors before their real page is ready, which improves first-visit speed but can show a generic page briefly.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-cache';
	}

	protected function area_written(): string {
		return 'guest';
	}
}
