<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\FileManager
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\FileManager;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Audit_Trail;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Wp_Filesystem_Init;

defined( 'ABSPATH' ) || exit;

/**
 * Clear_Debug_Log ability class (absorbed).
 */
class Clear_Debug_Log extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/clear-debug-log',
			'args' => array(
				'label'               => __( 'Clear Debug Log', 'acrossai-abilities-manager' ),
				'description'         => __( 'Truncates wp-content/debug.log to zero bytes.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'context' => array(
							'type'        => 'string',
							'maxLength'   => 2000,
							'description' => __( 'Optional caller-supplied reason for clearing debug.log. Captured in the audit log. Truncated to 500 chars in the persisted entry.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
						// Feature 094 audit-trail addition.
						'backup_path'    => array( 'type' => array( 'string', 'null' ) ),
					),
					'required'             => array( 'success', 'message' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'files',
						'sub_group'       => 'debug',
						'sub_group_label' => __( 'Debug', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$blocked = File_Mods_Guard::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}
		$blocked = Wp_Filesystem_Init::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}
		$fs = Wp_Filesystem_Init::get();

		$log_path = WP_CONTENT_DIR . '/debug.log';

		if ( ! $fs->is_file( $log_path ) ) {
			Audit_Trail::write_log(
				'CLEAR_DEBUG_LOG',
				$log_path,
				array(
					'ability_slug'  => 'file-manager/clear-debug-log',
					'size_before'   => null,
					'size_after'    => null,
					'backup_status' => 'skipped',
					'backup_reason' => 'debug.log does not exist',
					'context'       => (string) ( $input['context'] ?? '' ),
				)
			);
			return array(
				'success'     => true,
				'message'     => __( 'debug.log does not exist; nothing to clear.', 'acrossai-abilities-manager' ),
				'backup_path' => null,
			);
		}

		// Feature 094: pre-image backup captures the debug log content
		// before truncation, so admins who accidentally cleared away
		// diagnostics can recover.
		$size_before   = (int) $fs->size( $log_path );
		$backup_result = Audit_Trail::write_backup( $log_path );
		$backup_path   = is_string( $backup_result ) ? $backup_result : null;
		$backup_status = is_string( $backup_result )
			? 'written'
			: ( false === $backup_result ? 'failed' : 'disabled' );

		if ( false === $fs->put_contents( $log_path, '', FS_CHMOD_FILE ) ) {
			Audit_Trail::write_log(
				'CLEAR_DEBUG_LOG',
				$log_path,
				array(
					'ability_slug'  => 'file-manager/clear-debug-log',
					'size_before'   => $size_before,
					'size_after'    => null,
					'backup_status' => $backup_status,
					'backup_path'   => $backup_path,
					'backup_reason' => 'primary truncation failed',
					'context'       => (string) ( $input['context'] ?? '' ),
				)
			);
			return array(
				'success'     => false,
				'message'     => __( 'Could not clear debug.log.', 'acrossai-abilities-manager' ),
				'backup_path' => $backup_path,
			);
		}

		Audit_Trail::write_log(
			'CLEAR_DEBUG_LOG',
			$log_path,
			array(
				'ability_slug'  => 'file-manager/clear-debug-log',
				'size_before'   => $size_before,
				'size_after'    => 0,
				'backup_status' => $backup_status,
				'backup_path'   => $backup_path,
				'context'       => (string) ( $input['context'] ?? '' ),
			)
		);

		return array(
			'success'     => true,
			'message'     => __( 'debug.log cleared.', 'acrossai-abilities-manager' ),
			'backup_path' => $backup_path,
		);
	}
}
