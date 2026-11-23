<?php
/**
 * Feature 104 — Export Settings.
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
 * litespeed/export-settings — Export Settings.
 */
final class Export_Settings extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'export-settings';
	}

	protected function ability_label(): string {
		return __( 'Export Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The whole LiteSpeed configuration as a portable payload, for backup or for copying to another site. Read-only. There is deliberately no matching import ability: LiteSpeed\'s importer only reads from a file on disk, and materialising caller-supplied content as a file is not a risk this suite takes — use litespeed/apply-preset and litespeed/restore-preset-backup to move between known configurations.',
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
			'data'  => array( 'type' => 'string' ),

			'bytes' => array( 'type' => 'integer' ),
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
		$data = Toolbox_Repository::export();

		return array(
			'data'    => $data,
			'bytes'   => strlen( $data ),
			'message' => sprintf(
				/* translators: %d: payload size in bytes */
				__( 'Exported %d bytes of configuration.', 'acrossai-abilities-manager' ),
				strlen( $data )
			),
		);
	}
}
