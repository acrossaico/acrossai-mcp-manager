<?php
/**
 * Create_Zip_Backup ability (Feature 041).
 *
 * TODO(feature-092): migrate to WP_Filesystem. Deferred from Feature 091
 * because ZipArchive requires direct filesystem access and has no
 * WP_Filesystem equivalent. Currently native PHP; works reliably only on
 * FS_METHOD='direct' transports.
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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Zip_Target_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Zip a plugin, theme, uploads folder, mu-plugins folder, or arbitrary path
 * inside ABSPATH and store the archive under
 * wp-content/uploads/acrossai-backups/ with a random filename. Returns the
 * download URL, ABSPATH-relative path, size, and SHA-256 so the caller can
 * fetch the archive and hand it to a destination site.
 */
class Create_Zip_Backup extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/create-zip-backup',
			'args' => array(
				'label'               => __( 'Create Zip Backup', 'acrossai-abilities-manager' ),
				'description'         => __( 'Zip a plugin, theme, uploads folder, mu-plugins folder, or an arbitrary path under ABSPATH. The archive is stored under wp-content/uploads/acrossai-backups/ with a random filename; the response returns the download URL, ABSPATH-relative path, size, and SHA-256.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'target_type'    => array(
							'type'        => 'string',
							'enum'        => Zip_Target_Resolver::supported_types(),
							'description' => __( 'What to back up: plugin, theme, uploads, mu-plugins, or an arbitrary path under ABSPATH.', 'acrossai-abilities-manager' ),
						),
						'target'         => array(
							'type'        => 'string',
							'description' => __( 'Plugin slug (e.g. "hello-dolly"), theme stylesheet (e.g. "twentytwentyfour"), or path relative to a WordPress directory. Ignored for "uploads" and "mu-plugins".', 'acrossai-abilities-manager' ),
						),
						'include_hidden' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When true, include dotfiles / hidden directories (e.g. .git) in the archive.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'target_type' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'   => array( 'type' => 'boolean' ),
						'file_path' => array( 'type' => 'string' ),
						'file_url'  => array( 'type' => 'string' ),
						'size'      => array( 'type' => 'integer' ),
						'sha256'    => array( 'type' => 'string' ),
						'target'    => array( 'type' => 'string' ),
						'message'   => array( 'type' => 'string' ),
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
		// Honour DISALLOW_FILE_MODS the same way every other write-side
		// file-manager ability does. 'install' context (matches the other
		// zip-related abilities: Upload_Zip_Backup, Extract_Zip_Backup,
		// Delete_Zip_Backup) — checks DISALLOW_FILE_MODS only, not
		// DISALLOW_FILE_EDIT, since creating a backup is not in-place
		// theme/plugin editing.
		$blocked = File_Mods_Guard::blocked_response( 'install' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( ! class_exists( '\ZipArchive' ) ) {
			return array(
				'success' => false,
				'message' => __( 'PHP ZipArchive extension is not available on this server.', 'acrossai-abilities-manager' ),
			);
		}

		$target_type    = (string) ( $input['target_type'] ?? '' );
		$target         = (string) ( $input['target'] ?? '' );
		$include_hidden = ! empty( $input['include_hidden'] );

		$resolved = Zip_Target_Resolver::resolve_source( $target_type, $target );
		if ( is_wp_error( $resolved ) ) {
			return array(
				'success' => false,
				'message' => $resolved->get_error_message(),
			);
		}

		$src_dir = rtrim( $resolved['abs_path'], '/' );

		$backups_dir = Backups_Storage::backups_path();
		if ( false === $backups_dir ) {
			return array(
				'success' => false,
				'message' => __( 'Could not create the backups directory under wp-content/uploads/acrossai-backups.', 'acrossai-abilities-manager' ),
			);
		}

		$filename = Backups_Storage::random_backup_filename( $target_type, $target );
		$dest     = trailingslashit( $backups_dir ) . $filename;

		/**
		 * Filter the maximum backup archive size (bytes).
		 *
		 * The size of the source tree is estimated before writing the archive;
		 * anything above the cap is rejected up front. Default: 512 MB.
		 *
		 * @param int $bytes Default 512 MB.
		 */
		$max_bytes = (int) apply_filters( 'acrossai_abilities_manager_zip_max_bytes', 512 * 1024 * 1024 );

		$estimated_bytes = self::estimate_tree_size( $src_dir, $include_hidden );
		if ( $estimated_bytes > $max_bytes ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: estimated bytes, 2: cap bytes */
					__( 'Source tree is ~%1$d bytes which exceeds the configured backup cap of %2$d bytes.', 'acrossai-abilities-manager' ),
					$estimated_bytes,
					$max_bytes
				),
			);
		}

		$zip  = new \ZipArchive();
		$open = $zip->open( $dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		if ( true !== $open ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %d: ZipArchive open error code */
					__( 'Could not open a new zip archive for writing (ZipArchive error %d).', 'acrossai-abilities-manager' ),
					(int) $open
				),
			);
		}

		$prefix   = self::archive_prefix( $resolved['label'], $src_dir );
		$appended = self::append_dir_to_zip( $zip, $src_dir, $prefix, $include_hidden );

		if ( is_wp_error( $appended ) ) {
			$zip->close();
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
			return array(
				'success' => false,
				'message' => $appended->get_error_message(),
			);
		}

		$zip->close();

		if ( ! is_file( $dest ) ) {
			return array(
				'success' => false,
				'message' => __( 'Zip archive was not written to disk.', 'acrossai-abilities-manager' ),
			);
		}

		$size = (int) filesize( $dest );
		if ( $size > $max_bytes ) {
			wp_delete_file( $dest );
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: archive bytes, 2: cap bytes */
					__( 'Zip archive size (%1$d bytes) exceeds the configured cap (%2$d bytes).', 'acrossai-abilities-manager' ),
					$size,
					$max_bytes
				),
			);
		}

		return array(
			'success'   => true,
			'file_path' => Backups_Storage::to_abspath_relative( $dest ),
			'file_url'  => Backups_Storage::url_for( $dest ),
			'size'      => $size,
			'sha256'    => Backups_Storage::sha256_of( $dest ),
			'target'    => $resolved['label'],
			'message'   => sprintf(
				/* translators: 1: number of files added, 2: archive size in bytes */
				__( 'Zip created with %1$d entries (%2$d bytes).', 'acrossai-abilities-manager' ),
				(int) $appended,
				$size
			),
		);
	}

	/**
	 * Best-effort size estimate for a source directory, used purely as an
	 * up-front guard against ballooning archives. Skips unreadable entries.
	 *
	 * The include_hidden=false branch checks EVERY segment of the entry's
	 * relative path — not just the current entry's basename — so files inside
	 * a hidden directory (e.g. `.git/objects/xxx`) are also excluded, not just
	 * the top-level `.git/` entry itself.
	 */
	private static function estimate_tree_size( string $dir, bool $include_hidden ): int {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$dir   = rtrim( $dir, '/' );
		$total = 0;
		try {
			$iter = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( \Throwable $e ) {
			return 0;
		}
		/** @var \SplFileInfo $entry */
		foreach ( $iter as $entry ) {
			if ( ! $entry->isFile() ) {
				continue;
			}
			$rel = self::normalize_relative( $entry->getPathname(), $dir );
			if ( '' === $rel ) {
				continue;
			}
			if ( ! $include_hidden && self::has_hidden_segment( $rel ) ) {
				continue;
			}
			$total += (int) $entry->getSize();
		}
		return $total;
	}

	/**
	 * Decide the leading directory name inside the archive. Using a stable
	 * prefix (e.g. "hello-dolly/") makes the resulting zip easy to inspect
	 * and mirrors what WP core's Plugin/Theme upgraders expect on install.
	 */
	private static function archive_prefix( string $label, string $src_dir ): string {
		$base = basename( $src_dir );
		if ( str_starts_with( $label, 'plugin:' ) || str_starts_with( $label, 'theme:' ) ) {
			return $base . '/';
		}
		if ( 'uploads' === $label ) {
			return 'uploads/';
		}
		if ( 'mu-plugins' === $label ) {
			return 'mu-plugins/';
		}
		return $base . '/';
	}

	/**
	 * Recursively add every file/dir under $src to $zip, prefixed with $prefix.
	 * Returns the number of files added, or a WP_Error on failure.
	 *
	 * @return int|\WP_Error
	 */
	private static function append_dir_to_zip( \ZipArchive $zip, string $src, string $prefix, bool $include_hidden ) {
		$src   = rtrim( $src, '/' );
		$count = 0;

		try {
			$iter = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'zip_iterate_failed',
				sprintf(
					/* translators: %s: PHP error message */
					__( 'Could not iterate source directory: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		/** @var \SplFileInfo $entry */
		foreach ( $iter as $entry ) {
			$rel = self::normalize_relative( $entry->getPathname(), $src );
			if ( '' === $rel ) {
				continue;
			}

			// SELF_FIRST does not stop descent when we `continue` on a hidden
			// entry, so we must check every segment of the relative path here
			// — otherwise files INSIDE a hidden directory (e.g. .git/objects/x)
			// would still be added even when the top-level .git/ was skipped.
			if ( ! $include_hidden && self::has_hidden_segment( $rel ) ) {
				continue;
			}

			if ( $entry->isDir() ) {
				$zip->addEmptyDir( $prefix . $rel );
				continue;
			}
			if ( $entry->isFile() ) {
				if ( ! $zip->addFile( $entry->getPathname(), $prefix . $rel ) ) {
					return new \WP_Error(
						'zip_add_failed',
						sprintf(
							/* translators: %s: file path that failed to be added */
							__( 'Could not add file to archive: %s', 'acrossai-abilities-manager' ),
							$rel
						)
					);
				}
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Convert an absolute pathname into a forward-slashed path relative to $src.
	 */
	private static function normalize_relative( string $pathname, string $src ): string {
		$src_norm = str_replace( '\\', '/', rtrim( $src, '/' ) );
		$path     = str_replace( '\\', '/', $pathname );
		if ( 0 === strpos( $path, $src_norm . '/' ) ) {
			return substr( $path, strlen( $src_norm ) + 1 );
		}
		return ltrim( $path, '/' );
	}

	/**
	 * True when any segment of a forward-slashed relative path starts with `.`.
	 * Mirrors the every-segment check used by download-plugin's Base.php so
	 * files INSIDE a hidden directory are also skipped, not just the dir
	 * entry itself.
	 */
	private static function has_hidden_segment( string $relative ): bool {
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '' !== $segment && '.' === $segment[0] ) {
				return true;
			}
		}
		return false;
	}
}
