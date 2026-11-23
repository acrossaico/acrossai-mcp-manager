<?php
/**
 * Feature 104 — Update Purge Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-purge-settings — Update Purge Settings.
 */
final class Update_Purge_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-purge-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Purge Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change which events automatically purge the cache, and whether stale content is served while a page rebuilds. Turning auto-purge off means edits will not appear until something purges manually.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-purge';
	}

	protected function area_written(): string {
		return 'purge';
	}
}
