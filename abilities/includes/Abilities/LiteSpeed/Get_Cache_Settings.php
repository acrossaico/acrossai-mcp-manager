<?php
/**
 * Feature 104 — Get Cache Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-cache-settings — Get Cache Settings.
 */
final class Get_Cache_Settings extends Base_Settings_Read_Ability {

	protected function slug(): string {
		return 'get-cache-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Cache Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read every page-cache setting in one call: the master switch and ESI, what is cached for whom, all TTLs, the six exclusion lists, the vary rules, and guest mode. The orientation call before tuning anything. Each row reports the key, its current value, its type and whether this suite can write it.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-cache';
	}

	protected function areas_read(): array {
		return array(
			'cache-general',
			'cache-scope',
			'cache-ttl',
			'cache-exclusions',
			'cache-vary',
			'guest',
		);
	}
}
