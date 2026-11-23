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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Path_Allowlist_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Wp_Filesystem_Init;

defined( 'ABSPATH' ) || exit;

/**
 * Delete_File ability class (absorbed).
 */
class Delete_File extends Ability_Definition {

	/**
	 * Hardcoded filenames (at ABSPATH root) that this ability must never
	 * delete. Same list as Read_File::PROTECTED_FILES.
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
			'name' => 'file-manager/delete-file',
			'args' => array(
				'label'               => __( 'Delete File', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deletes a file within the WordPress installation. Path is relative to the WordPress directories: a leading wp-content/ resolves against WP_CONTENT_DIR, so it reaches the live content directory even on hosts that place it outside ABSPATH. Requires confirm:true, refuses wp-config.php and .htaccess, writes a .bak.<timestamp> copy before deleting, and invalidates OPcache when available.', 'acrossai-abilities-manager' ),
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
						'confirm' => array(
							'type'        => 'boolean',
							'description' => __( 'Must be true to proceed. Guards against accidental deletes.', 'acrossai-abilities-manager' ),
						),
						'context' => array(
							'type'        => 'string',
							'maxLength'   => 2000,
							'description' => __( 'Optional caller-supplied reason for this delete. Captured in the audit log for accountability. Truncated to 500 chars in the persisted entry.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'path', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'backup'         => array( 'type' => array( 'string', 'null' ) ),
						'backup_path'    => array( 'type' => array( 'string', 'null' ) ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'allowed_roots'  => array( 'type' => 'array' ),
						'path'           => array( 'type' => 'string' ),
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
		// Explicit-confirmation guard. Refuse before any I/O.
		if ( empty( $input['confirm'] ) || true !== (bool) $input['confirm'] ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'confirmation_required',
				'message'        => __( 'Deleting a file is permanent. Pass confirm:true to proceed.', 'acrossai-abilities-manager' ),
			);
		}

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
		// Feature 135: resolve against every root WordPress uses, not just ABSPATH. $base stays
		// ABSPATH because the protected-file guard below is specifically about wp-config.php and
		// .htaccess sitting at the install root.
		$resolved = Fs_Roots::resolve( $rel_path, true );

		if ( is_wp_error( $resolved ) ) {
			return Fs_Roots::blocked_response( $resolved );
		}

		$real = $resolved['path'];
		$base = rtrim( realpath( ABSPATH ) ?: ABSPATH, '/' );

		// Protected-file guard: refuse to delete secret-holding files at
		// ABSPATH root regardless of the caller's capability.
		if ( in_array( basename( $real ), self::PROTECTED_FILES, true )
			&& dirname( $real ) === $base ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'protected_write',
				/* translators: %s: filename */
				'message'        => sprintf( __( 'File "%s" is protected and cannot be deleted.', 'acrossai-abilities-manager' ), basename( $real ) ),
			);
		}

		if ( ! $fs->is_file( $real ) ) {
			return array(
				'success' => false,
				'message' => __( 'File does not exist.', 'acrossai-abilities-manager' ),
			);
		}

		// Feature 092: admin-controlled write allowlist gate.
		$blocked = Path_Allowlist_Guard::blocked_write_response( $real );
		if ( null !== $blocked ) {
			return $blocked;
		}

		// Feature 094: centralised pre-image backup. When backup_enabled is
		// false the inline .bak.<time> scheme is GONE — no backup at all.
		// Populates both the legacy `backup` field (for one transition
		// release) and the canonical `backup_path`.
		$size_before   = (int) $fs->size( $real );
		$backup_result = Audit_Trail::write_backup( $real );
		$backup_path   = is_string( $backup_result ) ? $backup_result : null;
		$backup_status = self::classify_backup( $backup_result );

		if ( ! $fs->delete( $real ) ) {
			// Log the FAILED delete so admins see the attempted mutation.
			Audit_Trail::write_log(
				'DELETE',
				$real,
				array(
					'ability_slug'  => 'file-manager/delete-file',
					'size_before'   => $size_before,
					'size_after'    => null,
					'backup_status' => $backup_status,
					'backup_path'   => $backup_path,
					'backup_reason' => 'primary delete failed',
					'context'       => (string) ( $input['context'] ?? '' ),
				)
			);
			return array(
				'success'     => false,
				'backup'      => $backup_path,
				'backup_path' => $backup_path,
				'message'     => __( 'Could not delete file.', 'acrossai-abilities-manager' ),
			);
		}

		// OPcache: invalidate the removed path so stale bytecode doesn't linger.
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $real, true );
		}

		Audit_Trail::write_log(
			'DELETE',
			$real,
			array(
				'ability_slug'  => 'file-manager/delete-file',
				'size_before'   => $size_before,
				'size_after'    => null,
				'backup_status' => $backup_status,
				'backup_path'   => $backup_path,
				'context'       => (string) ( $input['context'] ?? '' ),
			)
		);

		return array(
			'success'     => true,
			'backup'      => $backup_path, // Deprecated; mirrors backup_path this release.
			'backup_path' => $backup_path,
			'message'     => __( 'File deleted.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Map Audit_Trail::write_backup() return values to the log-writer's
	 * backup_status enum.
	 *
	 * @param mixed $result Return value from Audit_Trail::write_backup().
	 * @return string
	 */
	private static function classify_backup( $result ): string {
		if ( is_string( $result ) && '' !== $result ) {
			return 'written';
		}
		if ( false === $result ) {
			return 'failed';
		}
		return 'disabled'; // null → backup_enabled=false or nothing to back up.
	}
}
