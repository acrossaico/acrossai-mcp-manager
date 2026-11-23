<?php
/**
 * Feature 104 — Update Cache Scope.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-cache-scope — Update Cache Scope.
 */
final class Update_Cache_Scope extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-cache-scope';
	}

	protected function ability_label(): string {
		return __( 'Update Cache Scope', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change what gets cached for whom: logged-in users, commenters, REST requests, the login page and a separate mobile copy. Widening scope to logged-in users is the setting most likely to leak one user\'s page to another, so change it deliberately.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-cache';
	}

	protected function area_written(): string {
		return 'cache-scope';
	}
}
