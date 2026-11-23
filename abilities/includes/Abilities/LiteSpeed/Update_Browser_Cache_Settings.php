<?php
/**
 * Feature 104 — Update Browser Cache Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-browser-cache-settings — Update Browser Cache Settings.
 */
final class Update_Browser_Cache_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-browser-cache-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Browser Cache Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Turn browser caching on or off and set its TTL. This controls the expiry headers sent to visitors\' browsers for static assets.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-object';
	}

	protected function area_written(): string {
		return 'browser';
	}
}
