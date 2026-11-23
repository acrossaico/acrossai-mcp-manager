<?php
/**
 * Feature 104 — Update Cache Vary Rules.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-cache-vary — Update Cache Vary Rules.
 */
final class Update_Cache_Vary extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-cache-vary';
	}

	protected function ability_label(): string {
		return __( 'Update Cache Vary Rules', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change the cookies and groups that make LiteSpeed keep a separate cached copy. A wrong value here can serve one visitor\'s page to another — read litespeed/get-cache-vary first and change one thing at a time.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-cache';
	}

	protected function area_written(): string {
		return 'cache-vary';
	}
}
