<?php
/**
 * Upload_Zip_Backup ability (Feature 041).
 *
 * TODO(feature-092): migrate to WP_Filesystem. Deferred from Feature 091
 * because chunked upload uses fopen/fwrite/fclose file-handle APIs that
 * WP_Filesystem does not expose. Currently native PHP; works reliably
 * only on FS_METHOD='direct' transports.
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

defined( 'ABSPATH' ) || exit;

/**
 * Upload a zip archive to wp-content/uploads/acrossai-backups/.
 *
 * Supports three input modes:
 *   - base64  : small single-shot upload (raw base64 or data URL)
 *   - url     : server-side fetch via download_url()
 *   - chunked : session/index/is_final protocol, staged under acrossai-staging/
 *
 * The finalized archive is verified to start with the PK\x03\x04 magic bytes
 * and lives under acrossai-backups/ with a random filename. The response
 * carries file_path / file_url / size / sha256, which callers hand to
 * Extract_Zip_Backup on the destination site.
 */
class Upload_Zip_Backup extends Ability_Definition {

	public const CHUNK_SWEEP_HOOK = 'acrossai_abilities_manager_zip_upload_sweep_chunks';

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/upload-zip-backup',
			'args' => array(
				'label'               => __( 'Upload Zip Backup', 'acrossai-abilities-manager' ),
				'description'         => __( "Upload a zip archive to wp-content/uploads/acrossai-backups/ for later extraction via zip-extract. Three input modes:\n\n  1) \"data\" (base64) — single-shot, best for small zips.\n  2) \"url\" — server-side fetch via download_url().\n  3) \"data\" + \"chunk\" — session/index/is_final protocol; ≤ 8 MB base64 per chunk, ≤ 64 MB base64 per session, staged under acrossai-staging/.\n\nOn success the response carries file_path (ABSPATH-relative), file_url, size, and sha256; hand file_path to zip-extract on the destination site.", 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'url'            => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => __( 'Remote HTTP(S) URL to fetch. Mutually exclusive with "data".', 'acrossai-abilities-manager' ),
						),
						'data'           => array(
							'type'        => 'string',
							'description' => __( 'Base64-encoded zip bytes (raw or "data:application/zip;base64,…"). Pair with "chunk" to stream large uploads.', 'acrossai-abilities-manager' ),
						),
						'chunk'          => array(
							'type'                 => 'object',
							'description'          => __( 'Multi-part upload marker paired with "data". Send successive chunks under the same "session_id" with 0-based sequential "index". The final call must set "is_final":true.', 'acrossai-abilities-manager' ),
							'properties'           => array(
								'session_id' => array(
									'type'    => 'string',
									'pattern' => '^[A-Za-z0-9_-]{8,64}$',
								),
								'index'      => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
								'is_final'   => array(
									'type' => 'boolean',
								),
								'total'      => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
							),
							'required'             => array( 'session_id', 'index' ),
							'additionalProperties' => false,
						),
						'filename_hint'  => array(
							'type'        => 'string',
							'description' => __( 'Optional filename hint. Sanitized and combined with a random suffix; never used verbatim.', 'acrossai-abilities-manager' ),
						),
					),
					'anyOf'                => array(
						array( 'required' => array( 'url' ) ),
						array( 'required' => array( 'data' ) ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'finalized'      => array( 'type' => 'boolean' ),
						'file_path'      => array( 'type' => 'string' ),
						'file_url'       => array( 'type' => 'string' ),
						'size'           => array( 'type' => 'integer' ),
						'sha256'         => array( 'type' => 'string' ),
						'session_id'     => array( 'type' => 'string' ),
						'chunk_received' => array( 'type' => 'integer' ),
						'bytes_staged'   => array( 'type' => 'integer' ),
						'message'        => array( 'type' => 'string' ),
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
		$blocked = File_Mods_Guard::blocked_response( 'install' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$url  = sanitize_url( (string) ( $input['url'] ?? '' ) );
		$data = (string) ( $input['data'] ?? '' );

		if ( '' === $url && '' === $data ) {
			return array(
				'success' => false,
				'message' => __( 'Provide either "url" (remote fetch) or "data" (base64 zip bytes).', 'acrossai-abilities-manager' ),
			);
		}

		$filename_hint = sanitize_file_name( (string) ( $input['filename_hint'] ?? '' ) );

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( '' !== $url ) {
			return $this->finalize_from_url( $url, $filename_hint );
		}

		if ( isset( $input['chunk'] ) && is_array( $input['chunk'] ) ) {
			return $this->handle_chunk( $data, $input['chunk'], $filename_hint );
		}

		return $this->finalize_from_base64( $data, $filename_hint );
	}

	/**
	 * Fetch a zip from an HTTP(S) URL and finalize it into acrossai-backups/.
	 */
	private function finalize_from_url( string $url, string $filename_hint ): array {
		if ( ! wp_http_validate_url( $url ) ) {
			return array(
				'success' => false,
				'message' => __( 'A valid http(s) URL is required.', 'acrossai-abilities-manager' ),
			);
		}
		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return array(
				'success' => false,
				'message' => $tmp->get_error_message(),
			);
		}

		$magic_error = self::validate_zip_magic( $tmp );
		if ( null !== $magic_error ) {
			wp_delete_file( $tmp );
			return array(
				'success' => false,
				'message' => $magic_error,
			);
		}

		$size_cap_error = self::validate_size_cap( (int) filesize( $tmp ) );
		if ( null !== $size_cap_error ) {
			wp_delete_file( $tmp );
			return array(
				'success' => false,
				'message' => $size_cap_error,
			);
		}

		return $this->move_to_backups( $tmp, $filename_hint, 'url' );
	}

	/**
	 * Decode a base64 blob into a zip file inside acrossai-backups/.
	 */
	private function finalize_from_base64( string $raw, string $filename_hint ): array {
		$decoded = $this->decode_base64_payload( $raw );
		if ( isset( $decoded['error'] ) ) {
			return array(
				'success' => false,
				'message' => $decoded['error'],
			);
		}

		$size_cap_error = self::validate_size_cap( strlen( $decoded['decoded'] ) );
		if ( null !== $size_cap_error ) {
			return array(
				'success' => false,
				'message' => $size_cap_error,
			);
		}

		$tmp = wp_tempnam( 'acrossai-zip-upload.zip' );
		if ( ! $tmp ) {
			return array(
				'success' => false,
				'message' => __( 'Could not create a temporary file for the base64 payload.', 'acrossai-abilities-manager' ),
			);
		}
		$wrote = file_put_contents( $tmp, $decoded['decoded'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $wrote ) {
			wp_delete_file( $tmp );
			return array(
				'success' => false,
				'message' => __( 'Could not write the decoded bytes to disk.', 'acrossai-abilities-manager' ),
			);
		}

		$magic_error = self::validate_zip_magic( $tmp );
		if ( null !== $magic_error ) {
			wp_delete_file( $tmp );
			return array(
				'success' => false,
				'message' => $magic_error,
			);
		}

		return $this->move_to_backups( $tmp, $filename_hint, 'base64' );
	}

	/**
	 * Route a chunked base64 upload. Non-final chunks return a progress
	 * envelope; the final chunk assembles + validates + finalizes.
	 *
	 * @param array<string,mixed> $chunk
	 */
	private function handle_chunk( string $data, array $chunk, string $filename_hint ): array {
		$session_id = self::sanitize_session_id( (string) ( $chunk['session_id'] ?? '' ) );
		if ( '' === $session_id ) {
			return array(
				'success' => false,
				'message' => __( 'The "chunk.session_id" must match /^[A-Za-z0-9_-]{8,64}$/.', 'acrossai-abilities-manager' ),
			);
		}
		if ( ! isset( $chunk['index'] ) || ! is_numeric( $chunk['index'] ) || (int) $chunk['index'] < 0 ) {
			return array(
				'success' => false,
				'message' => __( 'The "chunk.index" is required and must be a non-negative integer.', 'acrossai-abilities-manager' ),
			);
		}
		$index    = (int) $chunk['index'];
		$is_final = ! empty( $chunk['is_final'] );

		$staging_dir = Backups_Storage::staging_path();
		if ( false === $staging_dir ) {
			return array(
				'success' => false,
				'message' => __( 'Could not create the chunk staging directory under wp-content/uploads/acrossai-staging.', 'acrossai-abilities-manager' ),
			);
		}

		list( $b64_path, $meta_path ) = self::chunk_paths( $staging_dir, $session_id );
		$meta                         = self::read_chunk_meta( $meta_path );
		$expected_index               = $meta['last_index'] + 1;

		if ( $index !== $expected_index ) {
			self::cleanup_chunk_session( $b64_path, $meta_path );
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: expected index, 2: received index */
					__( 'Chunk received out of order (expected %1$d, got %2$d). Session discarded — start over from index 0.', 'acrossai-abilities-manager' ),
					$expected_index,
					$index
				),
			);
		}

		$raw = trim( $data );
		if ( '' === $raw ) {
			self::cleanup_chunk_session( $b64_path, $meta_path );
			return array(
				'success' => false,
				'message' => __( 'The "data" field is empty for this chunk.', 'acrossai-abilities-manager' ),
			);
		}
		if ( 0 === stripos( $raw, 'data:' ) ) {
			$comma = strpos( $raw, ',' );
			if ( false === $comma ) {
				self::cleanup_chunk_session( $b64_path, $meta_path );
				return array(
					'success' => false,
					'message' => __( 'Data URL is missing the "," separator.', 'acrossai-abilities-manager' ),
				);
			}
			$header = substr( $raw, 5, $comma - 5 );
			if ( false === stripos( $header, ';base64' ) ) {
				self::cleanup_chunk_session( $b64_path, $meta_path );
				return array(
					'success' => false,
					'message' => __( 'Only base64-encoded data URLs are supported (";base64" required in header).', 'acrossai-abilities-manager' ),
				);
			}
			$raw = substr( $raw, $comma + 1 );
		}
		$raw = preg_replace( '/\s+/', '', $raw ) ?? '';
		if ( '' === $raw ) {
			self::cleanup_chunk_session( $b64_path, $meta_path );
			return array(
				'success' => false,
				'message' => __( 'Chunk contained no base64 payload after stripping whitespace.', 'acrossai-abilities-manager' ),
			);
		}

		/**
		 * Filter the per-chunk base64 size cap. Default: 8 MB.
		 *
		 * @param int $bytes Default 8 * 1024 * 1024.
		 */
		$chunk_max = (int) apply_filters( 'acrossai_abilities_manager_zip_upload_chunk_max_bytes', 8 * 1024 * 1024 );
		if ( strlen( $raw ) > $chunk_max ) {
			self::cleanup_chunk_session( $b64_path, $meta_path );
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %d: chunk cap in bytes */
					__( 'Chunk exceeds the per-chunk base64 cap (%d bytes). Split into smaller chunks.', 'acrossai-abilities-manager' ),
					$chunk_max
				),
			);
		}

		/**
		 * Filter the total per-session base64 size cap. Default: 64 MB.
		 *
		 * @param int $bytes Default 64 * 1024 * 1024.
		 */
		$session_max = (int) apply_filters( 'acrossai_abilities_manager_zip_upload_session_max_bytes', 64 * 1024 * 1024 );
		if ( ( $meta['bytes'] + strlen( $raw ) ) > $session_max ) {
			self::cleanup_chunk_session( $b64_path, $meta_path );
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %d: session cap in bytes */
					__( 'Session would exceed the total base64 cap (%d bytes). Session discarded.', 'acrossai-abilities-manager' ),
					$session_max
				),
			);
		}

		$fp = fopen( $b64_path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fp ) {
			return array(
				'success' => false,
				'message' => __( 'Could not open the chunk staging file for writing.', 'acrossai-abilities-manager' ),
			);
		}
		$locked = flock( $fp, LOCK_EX );
		$wrote  = fwrite( $fp, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( $locked ) {
			flock( $fp, LOCK_UN );
		}
		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( false === $wrote || $wrote !== strlen( $raw ) ) {
			self::cleanup_chunk_session( $b64_path, $meta_path );
			return array(
				'success' => false,
				'message' => __( 'Could not append chunk bytes to staging file.', 'acrossai-abilities-manager' ),
			);
		}

		$now                = time();
		$meta['last_index'] = $index;
		$meta['bytes']      = $meta['bytes'] + strlen( $raw );
		$meta['updated_at'] = $now;
		if ( 0 === (int) $meta['created_at'] ) {
			$meta['created_at'] = $now;
		}
		self::write_chunk_meta( $meta_path, $meta );

		if ( ! $is_final ) {
			return array(
				'success'        => true,
				'finalized'      => false,
				'session_id'     => $session_id,
				'chunk_received' => $index,
				'bytes_staged'   => (int) $meta['bytes'],
				'message'        => sprintf(
					/* translators: 1: chunk index, 2: cumulative bytes staged */
					__( 'Chunk %1$d accepted (%2$d base64 bytes staged). Send the next chunk or set "is_final":true.', 'acrossai-abilities-manager' ),
					$index,
					(int) $meta['bytes']
				),
			);
		}

		if ( isset( $chunk['total'] ) && is_numeric( $chunk['total'] ) ) {
			$expected_total = (int) $chunk['total'];
			if ( $expected_total !== ( $index + 1 ) ) {
				self::cleanup_chunk_session( $b64_path, $meta_path );
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: 1: declared total, 2: received count */
						__( 'Final chunk arrived with index=%2$d but "chunk.total"=%1$d — session discarded.', 'acrossai-abilities-manager' ),
						$expected_total,
						$index
					),
				);
			}
		}

		$raw_all = @file_get_contents( $b64_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		self::cleanup_chunk_session( $b64_path, $meta_path );
		if ( false === $raw_all || '' === $raw_all ) {
			return array(
				'success' => false,
				'message' => __( 'Chunk staging file was empty or unreadable.', 'acrossai-abilities-manager' ),
			);
		}
		$decoded = $this->decode_base64_payload( $raw_all );
		if ( isset( $decoded['error'] ) ) {
			return array(
				'success' => false,
				'message' => $decoded['error'],
			);
		}
		$size_cap_error = self::validate_size_cap( strlen( $decoded['decoded'] ) );
		if ( null !== $size_cap_error ) {
			return array(
				'success' => false,
				'message' => $size_cap_error,
			);
		}

		$tmp = wp_tempnam( 'acrossai-zip-upload.zip' );
		if ( ! $tmp ) {
			return array(
				'success' => false,
				'message' => __( 'Could not create a temporary file for the assembled zip.', 'acrossai-abilities-manager' ),
			);
		}
		if ( false === file_put_contents( $tmp, $decoded['decoded'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $tmp );
			return array(
				'success' => false,
				'message' => __( 'Could not write the assembled zip to disk.', 'acrossai-abilities-manager' ),
			);
		}
		$magic_error = self::validate_zip_magic( $tmp );
		if ( null !== $magic_error ) {
			wp_delete_file( $tmp );
			return array(
				'success' => false,
				'message' => $magic_error,
			);
		}

		return $this->move_to_backups( $tmp, $filename_hint, 'chunk' );
	}

	/**
	 * Move a validated tmp zip into acrossai-backups/ with a random filename
	 * and return the standard success envelope.
	 */
	private function move_to_backups( string $tmp, string $filename_hint, string $source_label ): array {
		$backups = Backups_Storage::backups_path();
		if ( false === $backups ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return array(
				'success' => false,
				'message' => __( 'Could not create the backups directory.', 'acrossai-abilities-manager' ),
			);
		}
		$filename = Backups_Storage::random_backup_filename( 'upload', $filename_hint );
		$dest     = trailingslashit( $backups ) . $filename;

		if ( ! @rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @copy( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				return array(
					'success' => false,
					'message' => __( 'Could not move the finalized zip into acrossai-backups/.', 'acrossai-abilities-manager' ),
				);
			}
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}

		return array(
			'success'   => true,
			'finalized' => true,
			'file_path' => Backups_Storage::to_abspath_relative( $dest ),
			'file_url'  => Backups_Storage::url_for( $dest ),
			'size'      => (int) filesize( $dest ),
			'sha256'    => Backups_Storage::sha256_of( $dest ),
			'message'   => sprintf(
				/* translators: %s: input mode ("url", "base64", "chunk") */
				__( 'Zip upload finalized (source: %s).', 'acrossai-abilities-manager' ),
				$source_label
			),
		);
	}

	/**
	 * Strip a data-URL header, strip whitespace, base64-decode.
	 *
	 * @return array{decoded: string}|array{error: string}
	 */
	private function decode_base64_payload( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array( 'error' => __( 'The "data" field is empty.', 'acrossai-abilities-manager' ) );
		}
		if ( 0 === stripos( $raw, 'data:' ) ) {
			$comma = strpos( $raw, ',' );
			if ( false === $comma ) {
				return array( 'error' => __( 'Data URL is missing the "," separator.', 'acrossai-abilities-manager' ) );
			}
			$header = substr( $raw, 5, $comma - 5 );
			if ( false === stripos( $header, ';base64' ) ) {
				return array( 'error' => __( 'Only base64-encoded data URLs are supported.', 'acrossai-abilities-manager' ) );
			}
			$raw = substr( $raw, $comma + 1 );
		}
		$raw = preg_replace( '/\s+/', '', $raw ) ?? '';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Caller-supplied base64; the result is written to a WP temp file, validated for zip magic bytes, and only then moved into acrossai-backups/.
		$decoded = base64_decode( $raw, true );
		if ( false === $decoded || '' === $decoded ) {
			return array( 'error' => __( 'The "data" field is not valid base64.', 'acrossai-abilities-manager' ) );
		}
		return array( 'decoded' => $decoded );
	}

	private static function sanitize_session_id( string $sid ): string {
		return preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $sid ) ? $sid : '';
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private static function chunk_paths( string $dir, string $session_id ): array {
		return array(
			trailingslashit( $dir ) . 'zip-' . $session_id . '.b64',
			trailingslashit( $dir ) . 'zip-' . $session_id . '.meta.json',
		);
	}

	/**
	 * @return array{last_index: int, bytes: int, created_at: int, updated_at: int}
	 */
	private static function read_chunk_meta( string $meta_path ): array {
		$defaults = array(
			'last_index' => -1,
			'bytes'      => 0,
			'created_at' => 0,
			'updated_at' => 0,
		);
		if ( ! is_file( $meta_path ) ) {
			return $defaults;
		}
		$raw = @file_get_contents( $meta_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $raw || '' === $raw ) {
			return $defaults;
		}
		$parsed = json_decode( $raw, true );
		if ( ! is_array( $parsed ) ) {
			return $defaults;
		}
		return array(
			'last_index' => isset( $parsed['last_index'] ) ? (int) $parsed['last_index'] : -1,
			'bytes'      => isset( $parsed['bytes'] ) ? (int) $parsed['bytes'] : 0,
			'created_at' => isset( $parsed['created_at'] ) ? (int) $parsed['created_at'] : 0,
			'updated_at' => isset( $parsed['updated_at'] ) ? (int) $parsed['updated_at'] : 0,
		);
	}

	/**
	 * @param array{last_index: int, bytes: int, created_at: int, updated_at: int} $meta
	 */
	private static function write_chunk_meta( string $meta_path, array $meta ): bool {
		$json = wp_json_encode( $meta );
		if ( false === $json ) {
			return false;
		}
		return false !== file_put_contents( $meta_path, $json, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	private static function cleanup_chunk_session( string $b64_path, string $meta_path ): void {
		if ( file_exists( $b64_path ) ) {
			wp_delete_file( $b64_path );
		}
		if ( file_exists( $meta_path ) ) {
			wp_delete_file( $meta_path );
		}
	}

	/**
	 * Reject archives above the configured backup cap. Returns null when OK.
	 */
	private static function validate_size_cap( int $bytes ): ?string {
		/**
		 * Same cap Create_Zip_Backup honours — anything above it is rejected.
		 *
		 * @param int $bytes Default 512 MB.
		 */
		$max = (int) apply_filters( 'acrossai_abilities_manager_zip_max_bytes', 512 * 1024 * 1024 );
		if ( $bytes > $max ) {
			return sprintf(
				/* translators: 1: submitted bytes, 2: max cap bytes */
				__( 'Zip size (%1$d bytes) exceeds the configured cap (%2$d bytes).', 'acrossai-abilities-manager' ),
				$bytes,
				$max
			);
		}
		return null;
	}

	/**
	 * Check for the standard "PK\x03\x04" zip magic. Returns null when the
	 * file starts with a valid zip signature.
	 */
	private static function validate_zip_magic( string $path ): ?string {
		if ( ! is_file( $path ) ) {
			return __( 'Uploaded file could not be located after staging.', 'acrossai-abilities-manager' );
		}
		$fp = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fp ) {
			return __( 'Could not read the uploaded file for zip validation.', 'acrossai-abilities-manager' );
		}
		$head = fread( $fp, 4 );
		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( false === $head || strlen( $head ) < 4 ) {
			return __( 'Uploaded file is too small to be a zip archive.', 'acrossai-abilities-manager' );
		}
		// Accept the standard local-file-header magic AND the empty/spanned variants.
		$magic = substr( $head, 0, 4 );
		if ( "PK\x03\x04" === $magic || "PK\x05\x06" === $magic || "PK\x07\x08" === $magic ) {
			return null;
		}
		return __( 'Uploaded file is not a valid zip archive (missing PK signature).', 'acrossai-abilities-manager' );
	}

	/**
	 * Cron callback: delete abandoned chunk sessions. Silent, best-effort.
	 */
	public static function sweep_chunk_sessions(): void {
		$staging = Backups_Storage::staging_path();
		if ( false === $staging ) {
			return;
		}
		/**
		 * TTL for abandoned zip-upload sessions. Default: 1 day.
		 *
		 * @param int $ttl Default DAY_IN_SECONDS.
		 */
		$ttl    = (int) apply_filters( 'acrossai_abilities_manager_zip_upload_session_ttl', DAY_IN_SECONDS );
		$cutoff = time() - max( $ttl, MINUTE_IN_SECONDS );

		$metas = glob( trailingslashit( $staging ) . 'zip-*.meta.json' );
		if ( ! is_array( $metas ) ) {
			return;
		}
		foreach ( $metas as $meta_path ) {
			$raw = @file_get_contents( $meta_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $raw ) {
				continue;
			}
			$parsed  = json_decode( $raw, true );
			$updated = is_array( $parsed ) && isset( $parsed['updated_at'] ) ? (int) $parsed['updated_at'] : 0;
			if ( $updated > $cutoff ) {
				continue;
			}
			$b64_path = preg_replace( '/\.meta\.json$/', '.b64', $meta_path );
			if ( is_string( $b64_path ) && file_exists( $b64_path ) ) {
				wp_delete_file( $b64_path );
			}
			wp_delete_file( $meta_path );
		}
	}

	/**
	 * Schedule the daily sweep (idempotent).
	 */
	public static function register_sweep_cron(): void {
		if ( ! wp_next_scheduled( self::CHUNK_SWEEP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CHUNK_SWEEP_HOOK );
		}
	}

	/**
	 * Unschedule the sweep — called on plugin deactivation.
	 */
	public static function unregister_sweep_cron(): void {
		wp_clear_scheduled_hook( self::CHUNK_SWEEP_HOOK );
	}
}
