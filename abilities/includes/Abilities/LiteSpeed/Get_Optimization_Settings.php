<?php
/**
 * Feature 104 — Get Optimisation Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-optimization-settings — Get Optimisation Settings.
 */
final class Get_Optimization_Settings extends Base_Settings_Read_Ability {

	protected function slug(): string {
		return 'get-optimization-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Optimisation Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read the whole CSS/JS/HTML optimisation pipeline: minification, combination, deferral, font handling, the tuning exclusion lists and resource localisation. This is the area most likely to break a theme, so read before writing.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-optimize';
	}

	protected function areas_read(): array {
		return array(
			'optimize-css',
			'optimize-js',
			'optimize-html',
			'optimize-font',
			'optimize-tuning',
			'optimize-localization',
		);
	}
}
