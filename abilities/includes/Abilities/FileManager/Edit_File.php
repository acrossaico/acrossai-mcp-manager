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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Fs_Roots;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Audit_Trail;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Hardening_Enforcer;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Path_Allowlist_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Wp_Filesystem_Init;

defined( 'ABSPATH' ) || exit;

/**
 * Edit_File ability class (absorbed).
 */
class Edit_File extends Ability_Definition {

	/**
	 * Filenames refused as targets even when overwrite is the intent.
	 * Same list as Read_File::PROTECTED_FILES and Delete_File::PROTECTED_FILES.
	 * Added by Feature 089 to close a guard-gap where the generic edit
	 * path could overwrite wp-config.php or .htaccess while the read/delete
	 * paths already refused them.
	 *
	 * @var array<int,string>
	 */
	private const PROTECTED_FILES = array(
		'wp-config.php',
		'.htaccess',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/edit-file',
			'args' => array(
				'label'               => __( 'Create or Overwrite File', 'acrossai-abilities-manager' ),
				'description'         => __( 'Creates a new file or overwrites an existing one within the WordPress installation. Parent directory must already exist. Path is relative to the WordPress directories: a leading wp-content/ resolves against WP_CONTENT_DIR, so it reaches the live content directory even on hosts that place it outside ABSPATH. Refuses wp-config.php and .htaccess at ABSPATH root.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'path'    => array(
							'type'        => 'string',
							'description' => __( 'File path relative to a WordPress directory.', 'acrossai-abilities-manager' ),
						),
						'content' => array(
							'type'        => 'string',
							'description' => __( 'New file content.', 'acrossai-abilities-manager' ),
						),
						'context' => array(
							'type'        => 'string',
							'maxLength'   => 2000,
							'description' => __( 'Optional caller-supplied reason for this edit. Captured in the audit log for accountability. Truncated to 500 chars in the persisted entry.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'path', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'path'           => array( 'type' => 'string' ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'allowed_roots'  => array( 'type' => 'array' ),
						// Feature 093 context fields (union across new blocked_reason values).
						'extension'      => array( 'type' => 'string' ),
						'basename'       => array( 'type' => 'string' ),
						'directive'      => array( 'type' => 'string' ),
						'input'          => array( 'type' => 'string' ),
						'sanitized'      => array( 'type' => 'string' ),
						'size'           => array( 'type' => 'integer' ),
						'max_bytes'      => array( 'type' => 'integer' ),
						'marker'         => array( 'type' => 'string' ),
						// Feature 094 audit-trail addition.
						'backup_path'    => array( 'type' => array( 'string', 'null' ) ),
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

		$rel_path = sanitize_text_field( $input['path'] ?? '' );
		$content  = $input['content'] ?? '';
		// Feature 135: resolve against every root WordPress uses, not just ABSPATH. $base stays
		// ABSPATH because the protected-file guard below is specifically about wp-config.php and
		// .htaccess sitting at the install root.
		$resolved = Fs_Roots::resolve( $rel_path );

		if ( is_wp_error( $resolved ) ) {
			return Fs_Roots::blocked_response( $resolved );
		}

		$abs_path = $resolved['path'];
		$parent   = dirname( $abs_path );
		$base     = rtrim( realpath( ABSPATH ) ?: ABSPATH, '/' );

		// Protected-file guard: refuse to overwrite secret-holding files at
		// ABSPATH root regardless of the caller's capability. Mirrors
		// Read_File / Delete_File / Create_File / Copy_File / Move_File.
		$target_basename = basename( $abs_path );
		if ( in_array( $target_basename, self::PROTECTED_FILES, true ) && $parent === $base ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'protected_write',
				/* translators: %s: filename */
				'message'        => sprintf( __( 'File "%s" is protected and cannot be written.', 'acrossai-abilities-manager' ), $target_basename ),
			);
		}

		// Feature 092: admin-controlled write allowlist gate.
		$blocked = Path_Allowlist_Guard::blocked_write_response( $abs_path );
		if ( null !== $blocked ) {
			return $blocked;
		}

		// Feature 093: content-filter enforcement (extensions, filename, size, …).
		$blocked = Hardening_Enforcer::check_write( $abs_path, $content, array( 'mode' => 'edit' ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		// Feature 094: pre-image backup + log. Backup returns null when the
		// target didn't already exist, string on success, false on I/O failure.
		$size_before   = $fs->exists( $abs_path ) ? (int) $fs->size( $abs_path ) : 0;
		$backup_result = Audit_Trail::write_backup( $abs_path );
		$backup_path   = is_string( $backup_result ) ? $backup_result : null;
		$backup_status = is_string( $backup_result )
			? 'written'
			: ( false === $backup_result ? 'failed' : ( 0 === $size_before ? 'skipped' : 'disabled' ) );

		$result = $fs->put_contents( $abs_path, $content, FS_CHMOD_FILE );

		if ( false === $result ) {
			Audit_Trail::write_log(
				'EDIT',
				$abs_path,
				array(
					'ability_slug'  => 'file-manager/edit-file',
					'size_before'   => $size_before,
					'size_after'    => null,
					'backup_status' => $backup_status,
					'backup_path'   => $backup_path,
					'backup_reason' => 'primary write failed',
					'context'       => (string) ( $input['context'] ?? '' ),
				)
			);
			return array(
				'success'     => false,
				'message'     => __( 'Could not write file.', 'acrossai-abilities-manager' ),
				'backup_path' => $backup_path,
			);
		}

		Audit_Trail::write_log(
			'EDIT',
			$abs_path,
			array(
				'ability_slug'  => 'file-manager/edit-file',
				'size_before'   => $size_before,
				'size_after'    => strlen( $content ),
				'backup_status' => $backup_status,
				'backup_path'   => $backup_path,
				'backup_reason' => ( 'skipped' === $backup_status ) ? 'target did not exist' : '',
				'context'       => (string) ( $input['context'] ?? '' ),
			)
		);

		return array(
			'success'     => true,
			'path'        => $abs_path,
			'message'     => __( 'File saved.', 'acrossai-abilities-manager' ),
			'backup_path' => $backup_path,
		);
	}
}
