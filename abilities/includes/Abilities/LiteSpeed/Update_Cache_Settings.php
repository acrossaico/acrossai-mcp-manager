<?php
/**
 * Feature 104 — Update Cache Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-cache-settings — Update Cache Settings.
 */
final class Update_Cache_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-cache-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Cache Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change the general cache settings and ESI (Edge Side Includes) behaviour. To turn caching on or off entirely use litespeed/set-cache-state, which confirms first.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-cache';
	}

	protected function area_written(): string {
		return 'cache-general';
	}
}
