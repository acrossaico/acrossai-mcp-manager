<?php
/**
 * Feature 104 — Restore A Preset Backup.
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
 * litespeed/restore-preset-backup — Restore A Preset Backup.
 */
final class Restore_Preset_Backup extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'restore-preset-backup';
	}

	protected function ability_label(): string {
		return __( 'Restore A Preset Backup', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Roll the configuration back to a backup taken before a preset was applied. Takes the timestamp reported by litespeed/list-preset-backups; an unknown timestamp is refused rather than silently doing nothing.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-toolbox';
	}

	protected function input_properties(): array {
		return array(
			'timestamp' => array(
				'type'        => 'integer',
				'description' => __( 'Backup timestamp from litespeed/list-preset-backups.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'timestamp',
		);
	}

	protected function output_properties(): array {
		return array(
			'timestamp' => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function confirmation_message(): string {
		return __(
			'Restoring a backup replaces the entire current LiteSpeed configuration. Pass confirm: true to proceed.',
			'acrossai-abilities-manager'
		);
	}

	protected function run( array $input ) {
		$timestamp = isset( $input['timestamp'] ) ? (int) $input['timestamp'] : 0;
		$restored  = Toolbox_Repository::restore_backup( $timestamp );

		if ( is_wp_error( $restored ) ) {
			return $restored;
		}

		return array(
			'timestamp' => $timestamp,
			'message'   => sprintf(
				/* translators: %d: backup timestamp */
				__( 'Configuration restored from backup %d.', 'acrossai-abilities-manager' ),
				$timestamp
			),
		);
	}
}
