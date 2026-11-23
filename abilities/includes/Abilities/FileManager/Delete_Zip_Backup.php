<?php
/**
 * Delete_Zip_Backup ability (Feature 041).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\FileManager
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\FileManager;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Backups_Storage;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Wp_Filesystem_Init;

defined( 'ABSPATH' ) || exit;

/**
 * Delete a zip stored under acrossai-backups/ or acrossai-staging/. Idempotent
 * — deleting a missing file returns success with a note.
 */
class Delete_Zip_Backup extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/delete-zip-backup',
			'args' => array(
				'label'               => __( 'Delete Zip Backup', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete a zip stored under acrossai-backups/ or acrossai-staging/. Path outside those two directories is rejected; deleting a missing file returns success with a note.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'file_path' => array(
							'type'        => 'string',
							'description' => __( 'ABSPATH-relative path or bare filename inside acrossai-backups/ or acrossai-staging/.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'file_path' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'deleted_path'   => array( 'type' => 'string' ),
						'existed'        => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'files',
						'sub_group'       => 'backups',
						'sub_group_label' => __( 'Backups', 'acrossai-abilities-manager' ),
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
		$blocked = File_Mods_Guard::blocked_response( 'install' );
		if ( null !== $blocked ) {
			return $blocked;
		}
		$blocked = Wp_Filesystem_Init::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}
		$fs = Wp_Filesystem_Init::get();

		$file_path = sanitize_text_field( (string) ( $input['file_path'] ?? '' ) );

		$resolved = Backups_Storage::resolve_managed_path( $file_path );
		if ( is_wp_error( $resolved ) ) {
			return array(
				'success' => false,
				'message' => $resolved->get_error_message(),
			);
		}

		$rel      = Backups_Storage::to_abspath_relative( $resolved );
		$existed  = $fs->is_file( $resolved );

		if ( ! $existed ) {
			return array(
				'success'      => true,
				'deleted_path' => $rel,
				'existed'      => false,
				'message'      => __( 'File was already absent — nothing to delete.', 'acrossai-abilities-manager' ),
			);
		}

		$fs->delete( $resolved );

		if ( $fs->is_file( $resolved ) ) {
			return array(
				'success'      => false,
				'deleted_path' => $rel,
				'existed'      => true,
				'message'      => __( 'File could not be deleted.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'success'      => true,
			'deleted_path' => $rel,
			'existed'      => true,
			'message'      => __( 'File deleted.', 'acrossai-abilities-manager' ),
		);
	}
}
