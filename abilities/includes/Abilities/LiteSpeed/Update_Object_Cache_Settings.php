<?php
/**
 * Feature 104 — Update Object Cache Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-object-cache-settings — Update Object Cache Settings.
 */
final class Update_Object_Cache_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-object-cache-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Object Cache Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change the object cache configuration: backend, host, port, credentials, database, lifetime and the global and non-persistent group lists. Call litespeed/test-object-cache-connection afterwards — a wrong host silently falls back to the database and the site just gets slower.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-object';
	}

	protected function suggested_abilities(): array {
		return array(
			'litespeed/test-object-cache-connection',
			'litespeed/get-object-cache-status',
		);
	}

	protected function area_written(): string {
		return 'object-cache';
	}
}
