<?php
/**
 * Feature 104 — List Preset Backups.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Toolbox_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/list-preset-backups — List Preset Backups.
 */
final class List_Preset_Backups extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'list-preset-backups';
	}

	protected function ability_label(): string {
		return __( 'List Preset Backups', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every configuration backup LiteSpeed took automatically before a preset was applied, newest first, each with the timestamp that litespeed/restore-preset-backup takes. This is the recovery path for litespeed/apply-preset — read it before applying a preset so you know what you can roll back to.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-toolbox';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'backups' => array( 'type' => 'array' ),

			'count'   => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function run( array $input ) {
		$rows = Toolbox_Repository::backups();

		return array(
			'backups' => $rows,
			'count'   => count( $rows ),
			'message' => array() === $rows
				? __( 'No preset backups yet — one is taken automatically the first time a preset is applied.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of backups */
					_n( '%d backup.', '%d backups.', count( $rows ), 'acrossai-abilities-manager' ),
					count( $rows )
				),
		);
	}
}
