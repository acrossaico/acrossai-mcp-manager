<?php
/**
 * Feature 104 — Update HTML Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-html-settings — Update HTML Settings.
 */
final class Update_Html_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-html-settings';
	}

	protected function ability_label(): string {
		return __( 'Update HTML Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change HTML handling: minification, DNS prefetch and preconnect, and the removal toggles for query strings, emoji scripts and noscript tags.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-optimize';
	}

	protected function area_written(): string {
		return 'optimize-html';
	}
}
