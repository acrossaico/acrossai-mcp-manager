<?php
/**
 * Feature 104 — Update Cache TTLs.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-cache-ttl — Update Cache TTLs.
 */
final class Update_Cache_Ttl extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-cache-ttl';
	}

	protected function ability_label(): string {
		return __( 'Update Cache TTLs', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change how long each kind of response stays cached: public and private pages, the front page, feeds, REST responses, AJAX, and the error-status pages. Values are in seconds.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-cache';
	}

	protected function area_written(): string {
		return 'cache-ttl';
	}
}
