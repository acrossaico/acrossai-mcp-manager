<?php
/**
 * File-manager create-directory ability (Feature 090).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\FileManager
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\FileManager;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Fs_Roots;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Audit_Trail;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Path_Allowlist_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Wp_Filesystem_Init;

defined( 'ABSPATH' ) || exit;

/**
 * Create_Directory ability class.
 *
 * Create a directory under ABSPATH. Recursive by default (wp_mkdir_p);
 * pass recursive:false for a single-level mkdir. Idempotent — returns
 * success with created:false when the target already exists.
 */
class Create_Directory extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/create-directory',
			'args' => array(
				'label'               => __( 'Create Directory', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a directory inside the WordPress installation. Path is relative to the WordPress directories: a leading wp-content/ resolves against WP_CONTENT_DIR, so it reaches the live content directory even on hosts that place it outside ABSPATH. Default recursive:true uses wp_mkdir_p (creates any missing parents); recursive:false requires the parent to exist. Idempotent — returns success with created:false when the target already exists as a directory. Refuses when the target exists as a file.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'path'      => array(
							'type'        => 'string',
							'description' => __( 'Directory path relative to a WordPress directory.', 'acrossai-abilities-manager' ),
						),
						'recursive' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'When true (default), missing parent directories are created via wp_mkdir_p. When false, a single mkdir is used and refuses if the parent does not exist.', 'acrossai-abilities-manager' ),
						),
						'context'   => array(
							'type'        => 'string',
							'maxLength'   => 2000,
							'description' => __( 'Optional caller-supplied reason for this mkdir. Captured in the audit log for accountability. Truncated to 500 chars in the persisted entry.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'path' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'path'           => array( 'type' => 'string' ),
						'created'        => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'allowed_roots'  => array( 'type' => 'array' ),
					),
					'required'             => array( 'success', 'message' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'files',
						'sub_group'       => 'files',
						'sub_group_label' => __( 'Files', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
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
		$blocked = File_Mods_Guard::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}
		$blocked = Wp_Filesystem_Init::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}
		$fs = Wp_Filesystem_Init::get();

		$rel_path  = sanitize_text_field( $input['path'] ?? '' );
		$recursive = array_key_exists( 'recursive', $input ) ? (bool) $input['recursive'] : true;

		// Feature 135: resolve_deep() so a recursive create may legitimately name a path whose
		// intermediate levels do not exist yet; containment is decided against the nearest real
		// ancestor rather than against an unresolved string.
		$resolved = Fs_Roots::resolve_deep( $rel_path );

		if ( is_wp_error( $resolved ) ) {
			return Fs_Roots::blocked_response( $resolved );
		}

		$abs = $resolved['path'];

		// If the target already exists as a directory, treat as idempotent success.
		if ( $fs->is_dir( $abs ) ) {
			$real = realpath( $abs );
			if ( false === $real || null === Fs_Roots::match( $real ) ) {
				return array(
					'success'        => false,
					'blocked_reason' => 'invalid_path',
					'message'        => __( 'Invalid or disallowed directory path.', 'acrossai-abilities-manager' ),
				);
			}
			return array(
				'success' => true,
				'path'    => $real,
				'created' => false,
				'message' => __( 'Directory already exists.', 'acrossai-abilities-manager' ),
			);
		}

		if ( $fs->exists( $abs ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'path_is_file',
				'message'        => __( 'Path exists as a file; cannot create a directory here.', 'acrossai-abilities-manager' ),
			);
		}

		// Feature 092: admin-controlled write allowlist gate.
		$blocked = Path_Allowlist_Guard::blocked_write_response( $abs );
		if ( null !== $blocked ) {
			return $blocked;
		}

		// Parent-scope check for the (not-yet-created) target.
		if ( $recursive ) {
			// wp_mkdir_p handles missing parents; resolve_deep() has already bounded the requested
			// path against a real ancestor inside a WordPress root.
			if ( ! wp_mkdir_p( $abs ) ) {
				return array(
					'success' => false,
					'message' => __( 'Could not create directory.', 'acrossai-abilities-manager' ),
				);
			}
		} else {
			$parent = realpath( dirname( $abs ) );
			if ( false === $parent ) {
				return array(
					'success'        => false,
					'blocked_reason' => 'parent_missing',
					'message'        => __( 'Parent directory does not exist. Pass recursive:true to create it.', 'acrossai-abilities-manager' ),
				);
			}
			if ( null === Fs_Roots::match( $parent ) ) {
				return array(
					'success'        => false,
					'blocked_reason' => 'invalid_path',
					'message'        => __( 'Invalid or disallowed directory path.', 'acrossai-abilities-manager' ),
				);
			}
			if ( ! $fs->mkdir( $abs ) ) {
				return array(
					'success' => false,
					'message' => __( 'Could not create directory.', 'acrossai-abilities-manager' ),
				);
			}
		}

		// Feature 094: log the mkdir. No backup (no content to preserve).
		Audit_Trail::write_log(
			'MKDIR',
			realpath( $abs ) ?: $abs,
			array(
				'ability_slug'  => 'file-manager/create-directory',
				'size_before'   => null,
				'size_after'    => null,
				'backup_status' => 'disabled', // MKDIR has no content to back up.
				'context'       => (string) ( $input['context'] ?? '' ),
			)
		);

		return array(
			'success' => true,
			'path'    => realpath( $abs ) ?: $abs,
			'created' => true,
			'message' => __( 'Directory created.', 'acrossai-abilities-manager' ),
		);
	}
}
