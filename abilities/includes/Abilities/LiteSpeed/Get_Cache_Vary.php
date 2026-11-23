<?php
/**
 * Feature 104 — Get Cache Vary Rules.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-cache-vary — Get Cache Vary Rules.
 */
final class Get_Cache_Vary extends Base_Settings_Read_Ability {

	protected function slug(): string {
		return 'get-cache-vary';
	}

	protected function ability_label(): string {
		return __( 'Get Cache Vary Rules', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read the vary configuration — the cookies and groups that decide when LiteSpeed keeps a separate cached copy of a page. Read this before changing it: a wrong vary rule is how one visitor\'s page ends up served to another.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-cache';
	}

	protected function areas_read(): array {
		return array(
			'cache-vary',
		);
	}
}
