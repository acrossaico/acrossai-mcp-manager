<?php
/**
 * Feature 106 — Export SEO Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/export-settings — Export SEO Settings.
 */
final class Export_Settings extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/export-settings';
	}

	protected function ability_label(): string {
		return __( 'Export SEO Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every writable Yoast setting as portable rows of area, key and value — for backup before a risky change, or for copying a configuration to another site. Read-only. Feed the result straight back to seo/import-settings.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-settings';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/import-settings',
		);
	}

	protected function input_properties(): array {
		return array(
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'settings' => array( 'type' => 'array' ),

			'count' => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$rows = array();

		foreach ( Settings_Repository::areas() as $area => $keys ) {
			foreach ( Settings_Repository::describe_area( (string) $area ) as $row ) {
				$rows[] = array(
					'area'  => (string) $area,
					'key'   => $row['key'],
					'value' => $row['value'],
				);
			}
		}

		return array(
			'settings' => $rows,
			'count'    => count( $rows ),
			'message'  => sprintf(
				/* translators: %d: number of settings */
				__( 'Exported %d settings. Feed this back to seo/import-settings to restore them.', 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
