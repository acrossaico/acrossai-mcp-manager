<?php
/**
 * File-manager delete-directory ability (Feature 090).
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
 * Delete_Directory ability class.
 *
 * Remove a directory under ABSPATH. Requires confirm:true. Default
 * recursive:false refuses non-empty directories. Refuses a hardcoded
 * list of critical WordPress directories regardless of inputs.
 * Symlinks are unlinked (not followed).
 */
class Delete_Directory extends Ability_Definition {

	/**
	 * ABSPATH-relative directory paths that this ability refuses to delete
	 * regardless of confirm/recursive inputs. Deleting any of these would
	 * irreversibly break the site.
	 *
	 * Empty string means ABSPATH itself.
	 *
	 * @var array<int,string>
	 */
	private const PROTECTED_DIRS = array(
		'',
		'wp-admin',
		'wp-includes',
		'wp-content',
		'wp-content/plugins',
		'wp-content/themes',
		'wp-content/mu-plugins',
		'wp-content/uploads',
		'wp-content/plugins/acrossai-abilities-manager',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/delete-directory',
			'args' => array(
				'label'               => __( 'Delete Directory', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete a directory inside the WordPress installation. Path is relative to the WordPress directories: a leading wp-content/ resolves against WP_CONTENT_DIR, so it reaches the live content directory even on hosts that place it outside ABSPATH. Requires confirm:true. Default recursive:false refuses non-empty directories. Refuses a hardcoded list of critical WordPress directories (ABSPATH, wp-admin, wp-includes, wp-content, wp-content/plugins, wp-content/themes, wp-content/mu-plugins, wp-content/uploads, and this plugin\'s directory). Symlinks are not followed during recursive walks. Idempotent — a missing target returns success with entries_removed:0.', 'acrossai-abilities-manager' ),
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
							'default'     => false,
							'description' => __( 'When true, contents are removed bottom-up before the directory itself. Default false requires the directory to be empty.', 'acrossai-abilities-manager' ),
						),
						'confirm'   => array(
							'type'        => 'boolean',
							'description' => __( 'Must be true to proceed. Guards against accidental deletes.', 'acrossai-abilities-manager' ),
						),
						'context'   => array(
							'type'        => 'string',
							'maxLength'   => 2000,
							'description' => __( 'Optional caller-supplied reason for this rmdir. Captured in the audit log. Truncated to 500 chars in the persisted entry.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'path', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'         => array( 'type' => 'boolean' ),
						'path'            => array( 'type' => 'string' ),
						'entries_removed' => array( 'type' => 'integer' ),
						'message'         => array( 'type' => 'string' ),
						'blocked_reason'  => array( 'type' => 'string' ),
						'allowed_roots'   => array( 'type' => 'array' ),
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
				'message'        => __( 'Deleting a directory is permanent. Pass confirm:true to proceed.', 'acrossai-abilities-manager' ),
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

		$rel_path  = sanitize_text_field( $input['path'] ?? '' );
		$recursive = ! empty( $input['recursive'] );

		$base = rtrim( realpath( ABSPATH ) ?: ABSPATH, '/' );

		// Feature 135: resolve_deep() so a delete of an already-missing nested path still reports
		// idempotent success rather than failing containment on an unresolvable string.
		$resolved = Fs_Roots::resolve_deep( $rel_path );

		if ( is_wp_error( $resolved ) ) {
			return Fs_Roots::blocked_response( $resolved );
		}

		$abs  = $resolved['path'];
		$real = realpath( $abs );

		// Missing target: idempotent success — but still enforce ABSPATH scope
		// and the write allowlist so the ability doesn't report success for a
		// path a caller isn't permitted to touch. This matters when an admin
		// tightens the allowlist and then checks whether a stale target path is
		// still reachable — reporting success would be misleading.
		if ( false === $real ) {
			$blocked = Path_Allowlist_Guard::blocked_write_response( $abs );
			if ( null !== $blocked ) {
				return $blocked;
			}
			return array(
				'success'         => true,
				'path'            => $abs,
				'entries_removed' => 0,
				'message'         => __( 'Directory does not exist.', 'acrossai-abilities-manager' ),
			);
		}

		if ( null === Fs_Roots::match( $real ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'invalid_path',
				'message'        => __( 'Invalid or disallowed directory path.', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! $fs->is_dir( $real ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'not_a_directory',
				'message'        => __( 'Path exists but is not a directory.', 'acrossai-abilities-manager' ),
			);
		}

		// Protected-directory guard: compare $real against each protected entry resolved to its
		// absolute path. Refuses ABSPATH itself and the eight critical WordPress subdirectories.
		//
		// Feature 135: each entry resolves through Fs_Roots, not through ABSPATH. The old join made
		// this guard miss on hosts that place WP_CONTENT_DIR outside ABSPATH: 'wp-content/plugins'
		// resolved to the unused copy under the core directory, so the *live* plugins directory was
		// not in the protected set at all and a recursive delete of it would have been permitted.
		foreach ( self::PROTECTED_DIRS as $rel ) {
			if ( '' === $rel ) {
				$protected_real = realpath( $base );
			} else {
				$protected = Fs_Roots::resolve( (string) $rel, true );
				$protected_real = is_wp_error( $protected ) ? false : $protected['path'];
			}

			if ( false !== $protected_real && $protected_real === $real ) {
				return array(
					'success'        => false,
					'blocked_reason' => 'protected_directory',
					/* translators: %s: relative directory path */
					'message'        => sprintf( __( 'Directory "%s" is protected and cannot be deleted.', 'acrossai-abilities-manager' ), '' === $rel ? 'ABSPATH' : $rel ),
				);
			}
		}

		// Feature 092: admin-controlled write allowlist gate.
		$blocked = Path_Allowlist_Guard::blocked_write_response( $real );
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( ! $recursive ) {
			if ( ! $fs->rmdir( $real ) ) {
				return array(
					'success'         => false,
					'blocked_reason'  => 'not_empty',
					'entries_removed' => 0,
					'message'         => __( 'Directory is not empty. Pass recursive:true to delete its contents.', 'acrossai-abilities-manager' ),
				);
			}
			Audit_Trail::write_log(
				'RMDIR',
				$real,
				array(
					'ability_slug'    => 'file-manager/delete-directory',
					'size_before'     => null,
					'size_after'      => null,
					'backup_status'   => 'disabled',
					'entries_removed' => 1,
					'context'         => (string) ( $input['context'] ?? '' ),
				)
			);
			return array(
				'success'         => true,
				'path'            => $real,
				'entries_removed' => 1,
				'message'         => __( 'Empty directory removed.', 'acrossai-abilities-manager' ),
			);
		}

		// Recursive walk, bottom-up. Feature 091 replaces the earlier
		// SPL-iterator pattern (feature 090) with a WP_Filesystem-compatible
		// $fs->dirlist() walk.
		$entries_removed = 0;
		$fail_message    = null;
		$this->walk_delete( $fs, $real, $entries_removed, $fail_message );

		if ( null !== $fail_message ) {
			return array(
				'success'         => false,
				'entries_removed' => $entries_removed,
				'message'         => $fail_message,
			);
		}

		if ( ! $fs->rmdir( $real ) ) {
			return array(
				'success'         => false,
				'entries_removed' => $entries_removed,
				'message'         => __( 'Could not remove the top-level directory after clearing its contents.', 'acrossai-abilities-manager' ),
			);
		}
		++$entries_removed;

		Audit_Trail::write_log(
			'RMDIR',
			$real,
			array(
				'ability_slug'    => 'file-manager/delete-directory',
				'size_before'     => null,
				'size_after'      => null,
				'backup_status'   => 'disabled',
				'entries_removed' => $entries_removed,
				'context'         => (string) ( $input['context'] ?? '' ),
			)
		);

		return array(
			'success'         => true,
			'path'            => $real,
			'entries_removed' => $entries_removed,
			/* translators: %d: entries removed */
			'message'         => sprintf( __( 'Directory removed (%d entries).', 'acrossai-abilities-manager' ), $entries_removed ),
		);
	}

	/**
	 * Recursive bottom-up delete using WP_Filesystem dirlist().
	 *
	 * Deletes files first, then recurses into directories, then rmdirs each
	 * directory once empty. Skips symlinks (unlinks the reference, never
	 * follows). On first failure captures $fail_message and returns; callers
	 * then decide how to surface the partial result.
	 *
	 * @param \WP_Filesystem_Base $fs              Initialised filesystem transport.
	 * @param string              $dir_abs         Absolute directory path to walk.
	 * @param int                 $entries_removed Output counter.
	 * @param string|null         $fail_message    Output — set to a human message on first failure.
	 * @return void
	 */
	private function walk_delete( \WP_Filesystem_Base $fs, string $dir_abs, int &$entries_removed, ?string &$fail_message ): void {
		if ( null !== $fail_message ) {
			return;
		}

		$dirlist = $fs->dirlist( $dir_abs );
		if ( ! is_array( $dirlist ) ) {
			return;
		}

		foreach ( $dirlist as $name => $info ) {
			if ( null !== $fail_message ) {
				return;
			}
			$entry_path = rtrim( $dir_abs, '/' ) . '/' . $name;
			$type       = isset( $info['type'] ) ? (string) $info['type'] : 'f';

			if ( 'l' === $type ) {
				// Symlink — delete the reference, never follow.
				if ( ! $fs->delete( $entry_path ) ) {
					/* translators: %s: entry path */
					$fail_message = sprintf( __( 'Could not remove symlink at "%s".', 'acrossai-abilities-manager' ), $entry_path );
					return;
				}
				++$entries_removed;
				continue;
			}

			if ( 'd' === $type ) {
				// Recurse into subdir first, then rmdir it.
				$this->walk_delete( $fs, $entry_path, $entries_removed, $fail_message );
				if ( null !== $fail_message ) {
					return;
				}
				if ( ! $fs->rmdir( $entry_path ) ) {
					/* translators: %s: entry path */
					$fail_message = sprintf( __( 'Could not remove directory at "%s".', 'acrossai-abilities-manager' ), $entry_path );
					return;
				}
				++$entries_removed;
				continue;
			}

			// Regular file.
			if ( ! $fs->delete( $entry_path ) ) {
				/* translators: %s: entry path */
				$fail_message = sprintf( __( 'Could not remove file at "%s".', 'acrossai-abilities-manager' ), $entry_path );
				return;
			}
			++$entries_removed;
		}
	}
}
