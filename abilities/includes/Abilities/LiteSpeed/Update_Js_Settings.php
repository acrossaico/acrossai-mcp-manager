<?php
/**
 * Feature 104 — Update JS Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-js-settings — Update JS Settings.
 */
final class Update_Js_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-js-settings';
	}

	protected function ability_label(): string {
		return __( 'Update JS Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change JavaScript handling: minify, combine, defer and delay. Deferring or delaying JS commonly breaks sliders, forms and analytics; use litespeed/update-optimization-exclusions to hold specific scripts back rather than turning the feature off.',
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
		return 'optimize-js';
	}
}
