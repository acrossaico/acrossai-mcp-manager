<?php
/**
 * Feature 104 — Get Purge Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-purge-settings — Get Purge Settings.
 */
final class Get_Purge_Settings extends Base_Settings_Read_Ability {

	protected function slug(): string {
		return 'get-purge-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Purge Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read LiteSpeed\'s automatic purge rules: which events purge what, whether stale content is served while a page rebuilds, and the scheduled daily purge. Pair with litespeed/update-purge-settings to change them.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-purge';
	}

	protected function areas_read(): array {
		return array(
			'purge',
			'purge-timed',
		);
	}
}
