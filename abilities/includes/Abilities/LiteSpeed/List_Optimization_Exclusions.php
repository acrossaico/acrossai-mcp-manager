<?php
/**
 * Feature 104 — List Optimisation Exclusions.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/list-optimization-exclusions — List Optimisation Exclusions.
 */
final class List_Optimization_Exclusions extends Base_Settings_Read_Ability {

	protected function slug(): string {
		return 'list-optimization-exclusions';
	}

	protected function ability_label(): string {
		return __( 'List Optimisation Exclusions', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read the tuning exclusion lists — which files, URIs and roles are held back from CSS/JS optimisation. The first place to look when a script breaks after enabling combine or defer.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-optimize';
	}

	protected function areas_read(): array {
		return array(
			'optimize-tuning',
		);
	}
}
