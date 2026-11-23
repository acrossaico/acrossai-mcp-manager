<?php
/**
 * File-manager file-info ability (Feature 090; migrated to WP_Filesystem in Feature 091).
 *
 * Feature 091 schema change: `ctime` and `atime` fields are no longer
 * returned. `WP_Filesystem_Base` exposes size/mtime/owner/group/getchmod
 * but not the creation- or access-time fields, and remote transports
 * cannot provide them consistently. See specs/091-wp-filesystem-migration/
 * contracts/file-info-schema-change.json.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\FileManager
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\FileManager;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Fs_Roots;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Wp_Filesystem_Init;

defined( 'ABSPATH' ) || exit;

/**
 * File_Info ability class.
 *
 * Return stat metadata for any path under ABSPATH without loading its
 * content. Owner/group names are resolved via the PHP POSIX extension
 * when available and omitted otherwise.
 */
class File_Info extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/file-info',
			'args' => array(
				'label'               => __( 'Get File Info', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return stat metadata (type, size, modification time, mode/permissions, ownership, readability, writability, symlink flag) for any path inside the WordPress installation. Path is relative to the WordPress directories: a leading wp-content/ resolves against WP_CONTENT_DIR, so it reaches the live content directory even on hosts that place it outside ABSPATH. Read-only — does not open the file. Owner/group name fields are omitted when the PHP POSIX extension is absent.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'path' => array(
							'type'        => 'string',
							'description' => __( 'File or directory path relative to a WordPress directory.', 'acrossai-abilities-manager' ),
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
						'type'           => array(
							'type' => 'string',
							'enum' => array( 'file', 'dir', 'link' ),
						),
						'size'           => array( 'type' => 'integer' ),
						'mtime'          => array( 'type' => 'integer' ),
						'mode_octal'     => array( 'type' => 'string' ),
						'owner_uid'      => array( 'type' => 'integer' ),
						'owner_name'     => array( 'type' => 'string' ),
						'group_gid'      => array( 'type' => 'integer' ),
						'group_name'     => array( 'type' => 'string' ),
						'readable'       => array( 'type' => 'boolean' ),
						'writable'       => array( 'type' => 'boolean' ),
						'is_link'        => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
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

		$rel_path = sanitize_text_field( $input['path'] ?? '' );

		// Feature 135: resolve against every WordPress root. Passing must_exist=false keeps the
		// broken-symlink case working -- the resolver falls back to containing the parent, which is
		// exactly what this ability needs when realpath() of the target itself fails.
		$resolved = Fs_Roots::resolve( $rel_path );

		if ( is_wp_error( $resolved ) ) {
			return Fs_Roots::blocked_response( $resolved );
		}

		$candidate = $resolved['path'];

		// WP_Filesystem_Direct has no is_link(); the base class doesn't define one either.
		// Use PHP's native check — file-info operates on local paths under ABSPATH.
		$is_link_ref = is_link( $candidate );
		if ( ! $fs->exists( $candidate ) && ! $is_link_ref ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'path_not_found',
				'message'        => __( 'Path does not exist.', 'acrossai-abilities-manager' ),
			);
		}

		if ( $is_link_ref && ! $fs->exists( $candidate ) ) {
			$type = 'link';
		} elseif ( $fs->is_dir( $candidate ) ) {
			$type = 'dir';
		} else {
			$type = 'file';
		}

		// WP_Filesystem_Base->getchmod() returns a string like "644" — pad to
		// 4 chars to match the pre-migration schema.
		$chmod = (string) $fs->getchmod( $candidate );
		if ( '' === $chmod ) {
			$mode_octal = '';
		} else {
			$mode_octal = 4 === strlen( $chmod ) ? $chmod : str_pad( $chmod, 4, '0', STR_PAD_LEFT );
		}

		$owner_raw = $fs->owner( $candidate );
		$group_raw = $fs->group( $candidate );

		$response = array(
			'success'    => true,
			'path'       => $candidate,
			'type'       => $type,
			'size'       => 'dir' === $type ? 0 : (int) $fs->size( $candidate ),
			'mtime'      => (int) $fs->mtime( $candidate ),
			'mode_octal' => $mode_octal,
			'owner_uid'  => (int) $owner_raw,
			'group_gid'  => (int) $group_raw,
			'readable'   => (bool) $fs->is_readable( $candidate ),
			'writable'   => (bool) $fs->is_writable( $candidate ),
			'is_link'    => (bool) $is_link_ref,
			'message'    => __( 'Path metadata retrieved.', 'acrossai-abilities-manager' ),
		);

		// If the transport returned a name string (FTP/SSH transports may),
		// expose it as owner_name / group_name.
		if ( is_string( $owner_raw ) && '' !== $owner_raw && ! ctype_digit( $owner_raw ) ) {
			$response['owner_name'] = $owner_raw;
		} elseif ( function_exists( 'posix_getpwuid' ) ) {
			$pw = posix_getpwuid( (int) $owner_raw );
			if ( is_array( $pw ) && isset( $pw['name'] ) && '' !== $pw['name'] ) {
				$response['owner_name'] = (string) $pw['name'];
			}
		}
		if ( is_string( $group_raw ) && '' !== $group_raw && ! ctype_digit( $group_raw ) ) {
			$response['group_name'] = $group_raw;
		} elseif ( function_exists( 'posix_getgrgid' ) ) {
			$gr = posix_getgrgid( (int) $group_raw );
			if ( is_array( $gr ) && isset( $gr['name'] ) && '' !== $gr['name'] ) {
				$response['group_name'] = (string) $gr['name'];
			}
		}

		return $response;
	}
}
