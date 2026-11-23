<?php
/**
 * Feature 104 — List Cache Exclusions.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/list-cache-exclusions — List Cache Exclusions.
 */
final class List_Cache_Exclusions extends Base_Settings_Read_Ability {

	protected function slug(): string {
		return 'list-cache-exclusions';
	}

	protected function ability_label(): string {
		return __( 'List Cache Exclusions', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read all six cache exclusion lists at once — URIs, categories, tags, cookies, user agents and roles — plus the forced-cache and forced-private URI lists and the dropped query strings. Use litespeed/update-cache-exclusions to change one list.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-cache';
	}

	protected function areas_read(): array {
		return array(
			'cache-exclusions',
		);
	}
}
