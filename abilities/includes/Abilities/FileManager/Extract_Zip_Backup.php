<?php
/**
 * Extract_Zip_Backup ability (Feature 041).
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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Fs_Roots;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Zip_Target_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Extract a zip archive (already on disk or fetched from a URL) into the
 * resolved target directory (plugin / theme / uploads / mu-plugins / path).
 *
 * The zip is opened with PHP's ZipArchive so every entry can be inspected for
 * "zip slip" attempts (`..` segments, absolute paths) BEFORE extraction. Only
 * clean archives are extracted, and only after DISALLOW_FILE_MODS is checked.
 */
class Extract_Zip_Backup extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/extract-zip-backup',
			'args' => array(
				'label'               => __( 'Extract Zip Backup', 'acrossai-abilities-manager' ),
				'description'         => __( 'Extract a zip archive (already on disk or fetched from a URL) into the resolved target directory. Every entry is checked for path traversal before extraction; DISALLOW_FILE_MODS short-circuits the ability.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'source'      => array(
							'type'                 => 'object',
							'description'          => __( 'Where to read the zip from. Provide either "path" (ABSPATH-relative) or "url".', 'acrossai-abilities-manager' ),
							'properties'           => array(
								'path' => array(
									'type'        => 'string',
									'description' => __( 'ABSPATH-relative path to a zip already on disk.', 'acrossai-abilities-manager' ),
								),
								'url'  => array(
									'type'        => 'string',
									'format'      => 'uri',
									'description' => __( 'Remote HTTP(S) URL for the zip.', 'acrossai-abilities-manager' ),
								),
							),
							'anyOf'                => array(
								array( 'required' => array( 'path' ) ),
								array( 'required' => array( 'url' ) ),
							),
							'additionalProperties' => false,
						),
						'target_type' => array(
							'type'        => 'string',
							'enum'        => Zip_Target_Resolver::supported_types(),
							'description' => __( 'Destination type: plugin, theme, uploads, mu-plugins, or an arbitrary path under ABSPATH.', 'acrossai-abilities-manager' ),
						),
						'target'      => array(
							'type'        => 'string',
							'description' => __( 'Plugin slug, theme stylesheet, or ABSPATH-relative path. Ignored for "uploads" and "mu-plugins".', 'acrossai-abilities-manager' ),
						),
						'overwrite'   => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When true, existing files in the target directory are overwritten. When false (default), unzip_file() is used with clobber=false semantics.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'source', 'target_type' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'extracted_to' => array( 'type' => 'string' ),
						'files_count'  => array( 'type' => 'integer' ),
						'target'       => array( 'type' => 'string' ),
						'message'      => array( 'type' => 'string' ),
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

		$source = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();
		if ( empty( $source['path'] ) && empty( $source['url'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Provide "source.path" (zip already on disk) or "source.url" (remote fetch).', 'acrossai-abilities-manager' ),
			);
		}

		$target_type = (string) ( $input['target_type'] ?? '' );
		$target      = (string) ( $input['target'] ?? '' );
		$overwrite   = ! empty( $input['overwrite'] );

		$resolved = Zip_Target_Resolver::resolve_destination( $target_type, $target );
		if ( is_wp_error( $resolved ) ) {
			return array(
				'success' => false,
				'message' => $resolved->get_error_message(),
			);
		}
		$dest_dir = rtrim( $resolved['abs_path'], '/' );

		$fetched = $this->resolve_zip_source( $source );
		if ( isset( $fetched['error'] ) ) {
			return array(
				'success' => false,
				'message' => $fetched['error'],
			);
		}
		$zip_path        = $fetched['path'];
		$delete_zip_when = $fetched['cleanup']; // 'always' when fetched from URL; 'never' for on-disk sources.

		$audit = $this->audit_zip_entries( $zip_path );
		if ( isset( $audit['error'] ) ) {
			if ( 'always' === $delete_zip_when && file_exists( $zip_path ) ) {
				wp_delete_file( $zip_path );
			}
			return array(
				'success' => false,
				'message' => $audit['error'],
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;
		if ( ! function_exists( '\WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() ) {
			if ( 'always' === $delete_zip_when && file_exists( $zip_path ) ) {
				wp_delete_file( $zip_path );
			}
			return array(
				'success' => false,
				'message' => __( 'Could not initialize WP_Filesystem for extraction.', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! wp_mkdir_p( $dest_dir ) ) {
			if ( 'always' === $delete_zip_when && file_exists( $zip_path ) ) {
				wp_delete_file( $zip_path );
			}
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: destination directory */
					__( 'Could not create destination directory: %s', 'acrossai-abilities-manager' ),
					$dest_dir
				),
			);
		}

		if ( $overwrite ) {
			$extracted = $this->extract_with_overwrite( $zip_path, $dest_dir );
		} else {
			$extracted = unzip_file( $zip_path, $dest_dir );
		}

		if ( 'always' === $delete_zip_when && file_exists( $zip_path ) ) {
			wp_delete_file( $zip_path );
		}

		if ( is_wp_error( $extracted ) ) {
			return array(
				'success' => false,
				'message' => $extracted->get_error_message(),
			);
		}

		return array(
			'success'      => true,
			'extracted_to' => $dest_dir,
			'files_count'  => (int) $audit['files_count'],
			'target'       => $resolved['label'],
			'message'      => sprintf(
				/* translators: 1: files count, 2: destination directory */
				__( 'Extracted %1$d entries into %2$s.', 'acrossai-abilities-manager' ),
				(int) $audit['files_count'],
				$dest_dir
			),
		);
	}

	/**
	 * Turn the input `source` into a local zip file path.
	 *
	 * @param array<string,mixed> $source
	 * @return array{path: string, cleanup: 'always'|'never'}|array{error: string}
	 */
	private function resolve_zip_source( array $source ): array {
		if ( ! empty( $source['url'] ) ) {
			$url = sanitize_url( (string) $source['url'] );
			if ( ! wp_http_validate_url( $url ) ) {
				return array( 'error' => __( 'A valid http(s) URL is required.', 'acrossai-abilities-manager' ) );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$tmp = download_url( $url );
			if ( is_wp_error( $tmp ) ) {
				return array( 'error' => $tmp->get_error_message() );
			}
			return array(
				'path'    => (string) $tmp,
				'cleanup' => 'always',
			);
		}

		$rel_path = sanitize_text_field( (string) $source['path'] );
		if ( '' === $rel_path ) {
			return array( 'error' => __( 'The "source.path" is empty.', 'acrossai-abilities-manager' ) );
		}
		// Feature 135: any WordPress root, not just ABSPATH.
		$resolved = Fs_Roots::resolve( $rel_path, true );
		if ( is_wp_error( $resolved ) ) {
			return array( 'error' => $resolved->get_error_message() );
		}
		$abs = $resolved['path'];
		if ( ! is_file( $abs ) ) {
			return array( 'error' => __( 'The zip file does not exist at the given "source.path".', 'acrossai-abilities-manager' ) );
		}
		return array(
			'path'    => $abs,
			'cleanup' => 'never',
		);
	}

	/**
	 * Inspect every entry in the zip and reject the archive when any entry
	 * contains a path traversal segment, absolute path, or backslash. Also
	 * validates the total decompressed size against the size cap and counts
	 * files for the response.
	 *
	 * @return array{files_count: int, uncompressed: int}|array{error: string}
	 */
	private function audit_zip_entries( string $zip_path ): array {
		$zip = new \ZipArchive();
		$rc  = $zip->open( $zip_path, \ZipArchive::CHECKCONS );
		if ( true !== $rc ) {
			return array(
				'error' => sprintf(
					/* translators: %d: ZipArchive::open() return code */
					__( 'Could not open zip for inspection (ZipArchive error %d).', 'acrossai-abilities-manager' ),
					(int) $rc
				),
			);
		}

		/**
		 * Filter the maximum extraction size (bytes).
		 *
		 * @param int $bytes Default 512 MB.
		 */
		$max_bytes = (int) apply_filters( 'acrossai_abilities_manager_zip_max_bytes', 512 * 1024 * 1024 );

		$files_count  = 0;
		$uncompressed = 0;
		$num          = $zip->numFiles;
		for ( $i = 0; $i < $num; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) ) {
				$zip->close();
				return array(
					'error' => sprintf(
						/* translators: %d: entry index */
						__( 'Could not stat zip entry %d.', 'acrossai-abilities-manager' ),
						$i
					),
				);
			}
			$name = (string) ( $stat['name'] ?? '' );
			if ( '' === $name ) {
				$zip->close();
				return array( 'error' => __( 'Zip archive contains an unnamed entry.', 'acrossai-abilities-manager' ) );
			}
			if ( false !== strpos( $name, "\0" ) ) {
				$zip->close();
				return array( 'error' => __( 'Zip archive contains an entry with a null byte.', 'acrossai-abilities-manager' ) );
			}
			if ( false !== strpos( $name, '\\' ) ) {
				$zip->close();
				return array(
					'error' => sprintf(
						/* translators: %s: rejected entry name */
						__( 'Zip archive contains an entry with a backslash: %s', 'acrossai-abilities-manager' ),
						$name
					),
				);
			}
			if ( '/' === substr( $name, 0, 1 ) ) {
				$zip->close();
				return array(
					'error' => sprintf(
						/* translators: %s: rejected entry name */
						__( 'Zip archive contains an entry with an absolute path: %s', 'acrossai-abilities-manager' ),
						$name
					),
				);
			}
			$parts = explode( '/', $name );
			foreach ( $parts as $part ) {
				if ( '..' === $part ) {
					$zip->close();
					return array(
						'error' => sprintf(
							/* translators: %s: rejected entry name */
							__( 'Zip archive contains a path-traversal entry: %s', 'acrossai-abilities-manager' ),
							$name
						),
					);
				}
			}
			$uncompressed += (int) ( $stat['size'] ?? 0 );
			if ( '/' !== substr( $name, -1 ) ) {
				++$files_count;
			}
		}

		$zip->close();

		if ( $uncompressed > $max_bytes ) {
			return array(
				'error' => sprintf(
					/* translators: 1: uncompressed size, 2: cap */
					__( 'Zip decompresses to %1$d bytes which exceeds the configured cap of %2$d bytes.', 'acrossai-abilities-manager' ),
					$uncompressed,
					$max_bytes
				),
			);
		}

		return array(
			'files_count'  => $files_count,
			'uncompressed' => $uncompressed,
		);
	}

	/**
	 * Overwrite-friendly extraction. `unzip_file()` never overwrites; when the
	 * caller asks for overwrite=true we drop directly to ZipArchive::extractTo
	 * after the audit has already vetted every entry.
	 *
	 * @return true|\WP_Error
	 */
	private function extract_with_overwrite( string $zip_path, string $dest_dir ) {
		$zip = new \ZipArchive();
		$rc  = $zip->open( $zip_path );
		if ( true !== $rc ) {
			return new \WP_Error(
				'zip_open_failed',
				sprintf(
					/* translators: %d: ZipArchive::open() return code */
					__( 'Could not open zip for extraction (ZipArchive error %d).', 'acrossai-abilities-manager' ),
					(int) $rc
				)
			);
		}
		if ( ! $zip->extractTo( $dest_dir ) ) {
			$zip->close();
			return new \WP_Error(
				'zip_extract_failed',
				__( 'ZipArchive::extractTo() failed.', 'acrossai-abilities-manager' )
			);
		}
		$zip->close();
		return true;
	}
}
