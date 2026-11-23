<?php
/**
 * Download_Zip_Backup ability (Feature 041).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\FileManager
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\FileManager;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Backups_Storage;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Wp_Filesystem_Init;

defined( 'ABSPATH' ) || exit;

/**
 * Return a fresh download URL and metadata for a zip already stored under
 * acrossai-backups/ or acrossai-staging/. Callers use this to retrieve an
 * older backup they didn't just create.
 */
class Download_Zip_Backup extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/download-zip-backup',
			'args' => array(
				'label'               => __( 'Download Zip Backup', 'acrossai-abilities-manager' ),
				'description'         => __( 'Look up a zip already stored under acrossai-backups/ or acrossai-staging/ and return its download URL plus metadata (size, sha256, created_at).', 'acrossai-abilities-manager' ),
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
							'description' => __( 'ABSPATH-relative path (or bare filename) that resolves inside acrossai-backups/ or acrossai-staging/.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'file_path' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'file_path'      => array( 'type' => 'string' ),
						'file_url'       => array( 'type' => 'string' ),
						'size'           => array( 'type' => 'integer' ),
						'sha256'         => array( 'type' => 'string' ),
						'created_at'     => array( 'type' => 'string' ),
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
						'readonly'    => true,
						'destructive' => false,
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

		if ( ! $fs->is_file( $resolved ) ) {
			return array(
				'success' => false,
				'message' => __( 'File does not exist.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'success'    => true,
			'file_path'  => Backups_Storage::to_abspath_relative( $resolved ),
			'file_url'   => Backups_Storage::url_for( $resolved ),
			'size'       => (int) $fs->size( $resolved ),
			'sha256'     => Backups_Storage::sha256_of( $resolved ),
			'created_at' => gmdate( 'c', (int) $fs->mtime( $resolved ) ),
		);
	}
}
