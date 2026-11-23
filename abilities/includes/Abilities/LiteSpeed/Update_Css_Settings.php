<?php
/**
 * Feature 104 — Update CSS Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-css-settings — Update CSS Settings.
 */
final class Update_Css_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-css-settings';
	}

	protected function ability_label(): string {
		return __( 'Update CSS Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change CSS handling: minify, combine, inline, async loading and the unique-CSS toggles. Combining CSS is the single most common cause of a broken layout after enabling optimisation — purge and check the front end afterwards.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-optimize';
	}

	protected function suggested_abilities(): array {
		return array(
			'litespeed/update-optimization-exclusions',
			'litespeed/purge-optimization-cache',
		);
	}

	protected function area_written(): string {
		return 'optimize-css';
	}
}
