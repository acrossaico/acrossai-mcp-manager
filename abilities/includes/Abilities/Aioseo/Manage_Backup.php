<?php
/**
 * Feature 132 — restore or delete an AIOSEO settings backup.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Status_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability — aioseo/manage-backup.
 *
 * Both actions are irreversible, which is why one confirm gate covers them rather than two abilities
 * with one gate each: restoring discards every current AIOSEO setting, and deleting removes the only
 * copy of a past state.
 *
 * @since 0.0.44
 */
class Manage_Backup extends Base_Aioseo_Ability {

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function slug(): string {
		return 'manage-backup';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Manage All in One SEO Settings Backup', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Restore an All in One SEO settings backup, overwriting every current AIOSEO setting, or delete one permanently. Both are irreversible: restoring discards the present configuration, and deleting removes the only copy of that state. List keys with aioseo/list-backups, and consider aioseo/create-backup before restoring so the current settings are recoverable too.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function sub_group(): string {
		return 'aioseo-status';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function aioseo_cap(): string {
		return 'aioseo_tools_settings';
	}

	/**
	 * @since  0.0.44
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'Pass confirm: true. Restoring replaces every current All in One SEO setting, and deleting removes the only copy of that backup.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'key'    => array(
				'type'        => 'string',
				'description' => __( 'Backup key, as reported by aioseo/list-backups.', 'acrossai-abilities-manager' ),
			),
			'action' => array(
				'type'        => 'string',
				'enum'        => array( 'restore', 'delete' ),
				'description' => __( 'Whether to restore this backup over the current settings, or delete it.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'key'       => array( 'type' => 'string' ),
			'action'    => array( 'type' => 'string' ),
			'remaining' => array( 'type' => 'integer' ),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'key', 'action' );
	}

	/**
	 * @since  0.0.44
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
	}

	/**
	 * @since  0.0.44
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Status_Repository::manage_backup(
			self::scalar( $input['key'] ?? '' ),
			self::scalar( $input['action'] ?? '' )
		);
	}
}
