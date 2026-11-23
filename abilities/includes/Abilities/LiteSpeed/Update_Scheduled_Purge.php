<?php
/**
 * Feature 104 — Update Scheduled Purge.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-scheduled-purge — Update Scheduled Purge.
 */
final class Update_Scheduled_Purge extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-scheduled-purge';
	}

	protected function ability_label(): string {
		return __( 'Update Scheduled Purge', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Configure the daily scheduled purge: a list of URLs and the time of day to purge them. Useful for pages that go stale on a known schedule.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-purge';
	}

	protected function area_written(): string {
		return 'purge-timed';
	}
}
