<?php
/**
 * File-manager append-file ability (Feature 090).
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
 * Append_File ability class.
 *
 * Append (default) or prepend caller-supplied bytes to an existing file
 * under ABSPATH. Refuses missing files (use create-file instead) and
 * refuses wp-config.php or .htaccess at ABSPATH root.
 *
 * Both append and prepend read the current contents via WP_Filesystem,
 * concatenate in memory, then write back — not atomic. A concurrent
 * writer between the read and the write would win. Callers are warned
 * in the ability description to avoid this ability on
 * very-high-throughput logs.
 */
class Append_File extends Ability_Definition {

	/**
	 * Filenames refused as targets even when they exist.
	 * Same list as Read_File / Delete_File / Create_File / Edit_File /
	 * Copy_File / Move_File (mirrors feature 089 hardening).
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
			'name' => 'file-manager/append-file',
			'args' => array(
				'label'               => __( 'Append to File', 'acrossai-abilities-manager' ),
				'description'         => __( 'Append (default) or prepend caller-supplied bytes to an existing file inside the WordPress installation. Path is relative to the WordPress directories: a leading wp-content/ resolves against WP_CONTENT_DIR, so it reaches the live content directory even on hosts that place it outside ABSPATH. Refuses missing files (use create-file instead) and refuses wp-config.php or .htaccess at ABSPATH root. Both append and prepend read the current contents and rewrite the file via WP_Filesystem (not atomic — a concurrent writer may win; avoid on very-high-throughput logs).', 'acrossai-abilities-manager' ),
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
							'description' => __( 'File path relative to a WordPress directory. Must exist.', 'acrossai-abilities-manager' ),
						),
						'content' => array(
							'type'        => 'string',
							'description' => __( 'Bytes to append (or prepend).', 'acrossai-abilities-manager' ),
						),
						'prepend' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When true, content is written to the head of the file; otherwise appended to the tail.', 'acrossai-abilities-manager' ),
						),
						'context' => array(
							'type'        => 'string',
							'maxLength'   => 2000,
							'description' => __( 'Optional caller-supplied reason for this append. Captured in the audit log for accountability. Truncated to 500 chars in the persisted entry.', 'acrossai-abilities-manager' ),
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
						'bytes_written'  => array( 'type' => 'integer' ),
						'new_size'       => array( 'type' => 'integer' ),
						'prepended'      => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'allowed_roots'  => array( 'type' => 'array' ),
						// Feature 093 context fields.
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
						'idempotent'  => false,
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
		$content  = isset( $input['content'] ) ? (string) $input['content'] : '';
		$prepend  = ! empty( $input['prepend'] );

		// Feature 135: resolve against every root WordPress uses, not just ABSPATH. $base stays
		// ABSPATH because the protected-file guard below is specifically about wp-config.php and
		// .htaccess sitting at the install root.
		$resolved = Fs_Roots::resolve( $rel_path, true );

		if ( is_wp_error( $resolved ) ) {
			return Fs_Roots::blocked_response( $resolved );
		}

		$real = $resolved['path'];
		$base = rtrim( realpath( ABSPATH ) ?: ABSPATH, '/' );

		// Protected-file guard: refuse to write secret-holding files at
		// ABSPATH root. Same guard as Read_File / Delete_File / Create_File /
		// Edit_File / Copy_File / Move_File.
		if ( in_array( basename( $real ), self::PROTECTED_FILES, true )
			&& dirname( $real ) === $base ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'protected_write',
				/* translators: %s: filename */
				'message'        => sprintf( __( 'File "%s" is protected and cannot be written.', 'acrossai-abilities-manager' ), basename( $real ) ),
			);
		}

		if ( ! $fs->is_file( $real ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'source_not_found',
				'message'        => __( 'File does not exist. Use file-manager/create-file to create it first.', 'acrossai-abilities-manager' ),
			);
		}

		// Feature 092: admin-controlled write allowlist gate.
		$blocked = Path_Allowlist_Guard::blocked_write_response( $real );
		if ( null !== $blocked ) {
			return $blocked;
		}

		// Feature 093: content-filter enforcement. mode:append tells the
		// enforcer to (a) scan APPENDED content only for htaccess directives,
		// (b) cap on new_size = existing + appended, (c) skip mime-type check.
		$blocked = Hardening_Enforcer::check_write(
			$real,
			$content,
			array(
				'mode'          => 'append',
				'existing_size' => (int) $fs->size( $real ),
			)
		);
		if ( null !== $blocked ) {
			return $blocked;
		}

		// Feature 094: pre-image backup captures the pre-append content.
		$size_before   = (int) $fs->size( $real );
		$backup_result = Audit_Trail::write_backup( $real );
		$backup_path   = is_string( $backup_result ) ? $backup_result : null;
		$backup_status = is_string( $backup_result )
			? 'written'
			: ( false === $backup_result ? 'failed' : 'disabled' );

		// WP_Filesystem exposes no direct-append operation, so both append
		// and prepend paths use read + concat + write. Not atomic — a
		// concurrent writer between the read and the write would win.
		// Callers on very-high-throughput logs should not use this ability.
		$existing = $fs->get_contents( $real );
		if ( false === $existing ) {
			Audit_Trail::write_log(
				'APPEND',
				$real,
				array(
					'ability_slug'  => 'file-manager/append-file',
					'size_before'   => $size_before,
					'size_after'    => null,
					'backup_status' => $backup_status,
					'backup_path'   => $backup_path,
					'backup_reason' => 'could not read file for append',
					'context'       => (string) ( $input['context'] ?? '' ),
				)
			);
			return array(
				'success'     => false,
				'message'     => __( 'Could not read file for append/prepend.', 'acrossai-abilities-manager' ),
				'backup_path' => $backup_path,
			);
		}
		$new_bytes = $prepend ? ( $content . $existing ) : ( $existing . $content );
		$result    = $fs->put_contents( $real, $new_bytes, FS_CHMOD_FILE );

		if ( false === $result ) {
			Audit_Trail::write_log(
				'APPEND',
				$real,
				array(
					'ability_slug'  => 'file-manager/append-file',
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

		$new_size = (int) $fs->size( $real );

		Audit_Trail::write_log(
			'APPEND',
			$real,
			array(
				'ability_slug'  => 'file-manager/append-file',
				'size_before'   => $size_before,
				'size_after'    => $new_size,
				'backup_status' => $backup_status,
				'backup_path'   => $backup_path,
				'context'       => (string) ( $input['context'] ?? '' ),
			)
		);

		return array(
			'success'       => true,
			'path'          => $real,
			'bytes_written' => strlen( $content ),
			'new_size'      => $new_size,
			'prepended'     => $prepend,
			'message'       => $prepend
				? __( 'Content prepended.', 'acrossai-abilities-manager' )
				: __( 'Content appended.', 'acrossai-abilities-manager' ),
			'backup_path'   => $backup_path,
		);
	}
}
