<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Media
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Media;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Mime_Types_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Upload a file to the Media Library from a remote URL, a local path relative
 * to ABSPATH, or a base64-encoded byte blob. The bytes are copied into a
 * temporary file and handed to media_handle_sideload() so all standard
 * hooks/metadata generation run. media_handle_sideload() moves its tmp_name
 * into wp-content/uploads/, which is why local-path callers get a temp copy
 * instead of having their original file consumed.
 */
class Upload_Media extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'media/upload-media',
			'args' => array(
				'label'               => __( 'Upload Media', 'acrossai-abilities-manager' ),
				'description'         => __( "Sideload an attachment into the Media Library via media_handle_sideload(). Pass ONE of: \"url\" (remote HTTP(S) fetch), \"path\" (existing file relative to ABSPATH), or \"data\" (base64 bytes — supply \"filename\" or \"mime_type\" for a valid extension). Optionally attach to a post via \"post_id\".\n\nChunked \"data\" (for payloads too large for one tool call): pair \"data\" with a \"chunk\" object. Recipe:\n  1. Pick session_id matching [A-Za-z0-9_-]{8,64}.\n  2. base64-encode the file; split into pieces of ≤ 8 MB of base64 text each (~6 MB decoded). Total session ≤ 64 MB base64.\n  3. For k = 0..N-1, call upload-media with data=<piece_k> and chunk={session_id, index:k, is_final:(k == N-1)}.\n  4. Chunks MUST arrive in strict sequential order — out-of-order discards the session.\n  5. Pass filename / mime_type / post_id / title / alt_text / description / caption on the FINAL call only — mid-stream calls ignore them.\n\nNon-final responses: {success:true, session_id, chunk_received, bytes_staged}. Final response: {success:true, id, media}.", 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-media',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'url'         => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => __( 'Remote HTTP(S) URL to fetch. Mutually exclusive with "path" and "data".', 'acrossai-abilities-manager' ),
						),
						'path'        => array(
							'type'        => 'string',
							'description' => __( 'Path to an existing file on the site, relative to ABSPATH (e.g. "wp-content/uploads/my-image.png"). Mutually exclusive with "url" and "data". The original file is left in place; a copy is imported into the Media Library.', 'acrossai-abilities-manager' ),
						),
						'data'        => array(
							'type'        => 'string',
							'description' => __( 'Base64-encoded file bytes (raw or as a "data:MIME;base64,…" data URL). Mutually exclusive with "url" and "path". Pair with "filename" or "mime_type" so the resulting attachment gets a valid extension. For payloads too large for one call, pair with the "chunk" object to stream multiple parts.', 'acrossai-abilities-manager' ),
						),
						'chunk'       => array(
							'type'                 => 'object',
							'description'          => __( 'Optional multi-part upload marker paired with "data". Send successive chunks under the same "session_id" with 0-based sequential "index". The final call must set "is_final":true — that call also carries the ability-level "filename"/"mime_type"/"post_id"/"title"/etc. and triggers the sideload.', 'acrossai-abilities-manager' ),
							'properties'           => array(
								'session_id' => array(
									'type'        => 'string',
									'pattern'     => '^[A-Za-z0-9_-]{8,64}$',
									'description' => __( 'Caller-generated session identifier; 8–64 chars of [A-Za-z0-9_-].', 'acrossai-abilities-manager' ),
								),
								'index'      => array(
									'type'        => 'integer',
									'minimum'     => 0,
									'description' => __( '0-based sequence index. Must equal 0 for a fresh session or (last_received + 1) otherwise; out-of-order chunks discard the session.', 'acrossai-abilities-manager' ),
								),
								'is_final'   => array(
									'type'        => 'boolean',
									'description' => __( 'Set true on the last chunk to trigger decode + sideload. When false or omitted the chunk is staged and a partial-success envelope is returned.', 'acrossai-abilities-manager' ),
								),
								'total'      => array(
									'type'        => 'integer',
									'minimum'     => 1,
									'description' => __( 'Optional expected total number of chunks. When set, validated on the final call against (index + 1).', 'acrossai-abilities-manager' ),
								),
							),
							'required'             => array( 'session_id', 'index' ),
							'additionalProperties' => false,
						),
						'filename'    => array(
							'type'        => 'string',
							'description' => __( 'Filename for the attachment when passing "data" (e.g. "photo.png"). Optional; falls back to "acrossai-upload-<timestamp>.<ext>" derived from mime_type. Ignored for "url"/"path" (filename is taken from those inputs).', 'acrossai-abilities-manager' ),
						),
						'mime_type'   => array(
							'type'        => 'string',
							'description' => __( 'MIME type override for the "data" branch (e.g. "image/png"). Optional. Order of resolution: this input → filename extension lookup → magic-byte detection on the decoded bytes.', 'acrossai-abilities-manager' ),
						),
						'post_id'     => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'title'       => array( 'type' => 'string' ),
						'alt_text'    => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
					),
					'anyOf'                => array(
						array( 'required' => array( 'url' ) ),
						array( 'required' => array( 'path' ) ),
						array( 'required' => array( 'data' ) ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'id'             => array( 'type' => 'integer' ),
						'media'          => array( 'type' => 'object' ),
						'message'        => array( 'type' => 'string' ),
						'session_id'     => array(
							'type'        => 'string',
							'description' => __( 'Echoed back on chunked responses.', 'acrossai-abilities-manager' ),
						),
						'chunk_received' => array(
							'type'        => 'integer',
							'description' => __( '0-based index of the chunk that was just accepted (non-final chunk only).', 'acrossai-abilities-manager' ),
						),
						'bytes_staged'   => array(
							'type'        => 'integer',
							'description' => __( 'Cumulative base64 bytes staged for the session so far (non-final chunk only).', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'manage',
						'sub_group_label' => __( 'Manage', 'acrossai-abilities-manager' ),
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
	 * Point callers who have no image yet at the generator.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'media/generate-image',
				'reason' => __( 'No image yet? Generate one from a text prompt and add it to the Media Library in a single call, then optionally set it as a post\'s featured image.', 'acrossai-abilities-manager' ),
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
		add_filter( 'upload_mimes', array( Mime_Types_Store::class, 'filter_upload_mimes' ) );
		try {
			return $this->do_execute( $input );
		} finally {
			remove_filter( 'upload_mimes', array( Mime_Types_Store::class, 'filter_upload_mimes' ) );
		}
	}

	/**
	 * Do execute.
	 *
	 * @param array $input
	 * @return array
	 */
	private function do_execute( array $input ): array {
		$url  = sanitize_url( (string) ( $input['url'] ?? '' ) );
		$path = sanitize_text_field( (string) ( $input['path'] ?? '' ) );
		$data = (string) ( $input['data'] ?? '' );

		if ( '' === $url && '' === $path && '' === $data ) {
			return array(
				'success' => false,
				'message' => __( 'One of "url" (remote fetch), "path" (local file relative to ABSPATH), or "data" (base64 bytes) is required.', 'acrossai-abilities-manager' ),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( '' !== $data ) {
			if ( isset( $input['chunk'] ) && is_array( $input['chunk'] ) ) {
				$chunk_result = $this->handle_chunk( $input, $input['chunk'] );
				if ( isset( $chunk_result['response'] ) ) {
					return $chunk_result['response'];
				}
				$tmp        = $chunk_result['tmp'];
				$file_array = $chunk_result['file_array'];
			} else {
				$staged = $this->stage_from_base64( $data, (string) ( $input['filename'] ?? '' ), (string) ( $input['mime_type'] ?? '' ) );
				if ( isset( $staged['error'] ) ) {
					return array(
						'success' => false,
						'message' => $staged['error'],
					);
				}
				$tmp        = $staged['tmp'];
				$file_array = $staged['file_array'];
			}
		} elseif ( '' !== $url ) {
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

			$file_array = array(
				'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'upload' ),
				'tmp_name' => $tmp,
			);
		} else {
			$base = rtrim( realpath( ABSPATH ) ?: ABSPATH, '/' );
			$abs  = realpath( $base . '/' . ltrim( $path, '/' ) );

			if ( false === $abs || 0 !== strpos( $abs, $base . '/' ) ) {
				return array(
					'success' => false,
					'message' => __( 'Invalid or disallowed path. Must resolve to a file inside ABSPATH.', 'acrossai-abilities-manager' ),
				);
			}

			if ( ! is_file( $abs ) ) {
				return array(
					'success' => false,
					'message' => __( 'File does not exist at the given path.', 'acrossai-abilities-manager' ),
				);
			}

			$tmp = wp_tempnam( basename( $abs ) );
			if ( ! $tmp || ! copy( $abs, $tmp ) ) {
				if ( $tmp && file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				return array(
					'success' => false,
					'message' => __( 'Could not stage the local file for import.', 'acrossai-abilities-manager' ),
				);
			}

			$file_array = array(
				'name'     => basename( $abs ),
				'tmp_name' => $tmp,
			);
		}

		$mime_error = $this->mime_rejection_message( $file_array['name'] );
		if ( null !== $mime_error ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return array(
				'success' => false,
				'message' => $mime_error,
			);
		}

		$post_id = (int) ( $input['post_id'] ?? 0 );
		$desc    = sanitize_text_field( (string) ( $input['description'] ?? '' ) );
		$id      = media_handle_sideload( $file_array, $post_id, $desc );

		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			$message = $id->get_error_message();
			if ( false !== stripos( $message, 'file type' ) ) {
				$enriched = $this->mime_rejection_message( $file_array['name'] );
				if ( null !== $enriched ) {
					$message = $enriched;
				}
			}
			return array(
				'success' => false,
				'message' => $message,
			);
		}

		$updates = array();
		if ( ! empty( $input['title'] ) ) {
			$updates['post_title'] = sanitize_text_field( (string) $input['title'] );
		}
		if ( isset( $input['caption'] ) ) {
			$updates['post_excerpt'] = (string) $input['caption'];
		}
		if ( $updates ) {
			$updates['ID'] = (int) $id;
			wp_update_post( $updates );
		}
		if ( ! empty( $input['alt_text'] ) ) {
			update_post_meta( (int) $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
		}

		return array(
			'success' => true,
			'id'      => (int) $id,
			'media'   => (array) get_post( (int) $id, ARRAY_A ),
			/* translators: %d: attachment ID */
			'message' => sprintf( __( 'Uploaded attachment #%d.', 'acrossai-abilities-manager' ), $id ),
		);
	}

	/**
	 * Decode a base64 (or data-URL) payload, resolve MIME + filename, and
	 * write the bytes to a WordPress-registered temporary file. Returns
	 * either the ready-to-sideload $file_array + $tmp path, or an `error`
	 * key with a caller-friendly message. On any failure, no leftover
	 * temp file is left behind.
	 *
	 * Thin wrapper: decode_base64_payload() → stage_decoded_bytes(). The
	 * finalized-chunk path in handle_chunk() calls those two helpers
	 * directly against the assembled base64 file.
	 *
	 * @return array{tmp: string, file_array: array<string,string>}|array{error: string}
	 */
	private function stage_from_base64( string $raw, string $filename_in, string $mime_in ): array {
		$decoded = $this->decode_base64_payload( $raw );
		if ( isset( $decoded['error'] ) ) {
			return array( 'error' => $decoded['error'] );
		}
		if ( '' === $mime_in && '' !== $decoded['mime_hint'] ) {
			$mime_in = $decoded['mime_hint'];
		}
		return $this->stage_decoded_bytes( $decoded['decoded'], $filename_in, $mime_in );
	}

	/**
	 * Strip a "data:MIME;base64,…" prefix (if present), strip whitespace,
	 * and base64_decode the payload. Returns the decoded bytes and any
	 * MIME hint extracted from the data-URL header, or an `error` key.
	 *
	 * @return array{decoded: string, mime_hint: string}|array{error: string}
	 */
	private function decode_base64_payload( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array( 'error' => __( 'The "data" field is empty.', 'acrossai-abilities-manager' ) );
		}

		$mime_hint = '';

		// Strip a data:...;base64,<payload> prefix if present.
		if ( 0 === stripos( $raw, 'data:' ) ) {
			$comma = strpos( $raw, ',' );
			if ( false === $comma ) {
				return array( 'error' => __( 'The "data" field looks like a data URL but is missing the "," separator.', 'acrossai-abilities-manager' ) );
			}
			$header = substr( $raw, 5, $comma - 5 );
			if ( false === stripos( $header, ';base64' ) ) {
				return array( 'error' => __( 'Only base64-encoded data URLs are supported (header must contain ";base64").', 'acrossai-abilities-manager' ) );
			}
			$mime_hint = trim( strtolower( strtok( $header, ';' ) ) );
			$raw       = substr( $raw, $comma + 1 );
		}

		// Base64 payloads can arrive with whitespace; strip it before decoding.
		$raw = preg_replace( '/\s+/', '', $raw ) ?? '';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Caller-supplied base64 payload; the decoded bytes are staged to a WP-registered temp file and go through wp_check_filetype + media_handle_sideload before any use.
		$decoded = base64_decode( $raw, true );
		if ( false === $decoded || '' === $decoded ) {
			return array( 'error' => __( 'The "data" field is not valid base64.', 'acrossai-abilities-manager' ) );
		}

		return array(
			'decoded'   => $decoded,
			'mime_hint' => $mime_hint,
		);
	}

	/**
	 * Resolve MIME + filename for already-decoded bytes and write them to
	 * a WordPress-registered temporary file ready for media_handle_sideload().
	 *
	 * @return array{tmp: string, file_array: array<string,string>}|array{error: string}
	 */
	private function stage_decoded_bytes( string $decoded, string $filename_in, string $mime_in ): array {
		// Resolve MIME: explicit → filename extension → magic bytes.
		$mime_type = '';
		if ( '' !== $mime_in ) {
			$mime_type = strtolower( trim( $mime_in ) );
		}
		$filename_in = sanitize_file_name( $filename_in );
		if ( '' === $mime_type && '' !== $filename_in ) {
			$ext_from_name = strtolower( (string) pathinfo( $filename_in, PATHINFO_EXTENSION ) );
			if ( '' !== $ext_from_name ) {
				$mime_type = $this->guess_mime_for_ext( $ext_from_name );
				if ( 'application/octet-stream' === $mime_type ) {
					$mime_type = '';
				}
			}
		}
		if ( '' === $mime_type && function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( \FILEINFO_MIME_TYPE );
			if ( false !== $finfo ) {
				$sniffed = finfo_buffer( $finfo, $decoded );
				// finfo_close() is deprecated in PHP 8.5; the finfo object is
				// released when $finfo goes out of scope.
				if ( is_string( $sniffed ) && '' !== $sniffed ) {
					$mime_type = strtolower( $sniffed );
				}
			}
		}
		if ( '' === $mime_type ) {
			return array( 'error' => __( 'Could not determine the file MIME type from "mime_type", "filename", or the decoded bytes. Supply at least one of "mime_type" or a "filename" with an extension.', 'acrossai-abilities-manager' ) );
		}

		// Resolve filename. If the caller passed one, use it verbatim (sanitized).
		// Otherwise synthesize "acrossai-upload-<timestamp>.<ext>" from the MIME.
		if ( '' === $filename_in ) {
			$ext = (string) wp_get_default_extension_for_mime_type( $mime_type );
			if ( '' === $ext ) {
				return array(
					'error' => sprintf(
						/* translators: %s: MIME type that has no default extension mapping */
						__( 'Could not derive a filename for MIME type "%s". Supply "filename" explicitly.', 'acrossai-abilities-manager' ),
						$mime_type
					),
				);
			}
			$filename_in = 'acrossai-upload-' . time() . '.' . $ext;
		}

		$tmp = wp_tempnam( $filename_in );
		if ( ! $tmp ) {
			return array( 'error' => __( 'Could not create a temporary file to stage the base64 payload.', 'acrossai-abilities-manager' ) );
		}

		$bytes = file_put_contents( $tmp, $decoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes ) {
			wp_delete_file( $tmp );
			return array( 'error' => __( 'Could not write the decoded bytes to the temporary file.', 'acrossai-abilities-manager' ) );
		}

		return array(
			'tmp'        => $tmp,
			'file_array' => array(
				'name'     => $filename_in,
				'tmp_name' => $tmp,
				'type'     => $mime_type,
			),
		);
	}

	/**
	 * If the filename's extension is not in the site's allowed MIME allowlist,
	 * build a caller-friendly rejection message that names the offending
	 * extension AND lists every extension the site actually accepts. Returns
	 * null when the extension is allowed.
	 */
	private function mime_rejection_message( string $filename ): ?string {
		$check = wp_check_filetype( $filename );
		if ( ! empty( $check['type'] ) ) {
			return null;
		}

		$ext = $check['ext'] ?: strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			$ext = 'unknown';
		}

		$allowed = array();
		foreach ( array_keys( get_allowed_mime_types() ) as $key ) {
			foreach ( explode( '|', (string) $key ) as $variant ) {
				$variant = trim( $variant );
				if ( '' !== $variant ) {
					$allowed[ $variant ] = true;
				}
			}
		}
		$allowed_list = array_keys( $allowed );
		sort( $allowed_list );

		$hint_ability = sprintf(
			/* translators: 1: extension, 2: guessed MIME */
			__( 'To fix: call media/update-upload-mime-types with {"add":{"%1$s":"%2$s"}} and then retry this upload.', 'acrossai-abilities-manager' ),
			$ext,
			$this->guess_mime_for_ext( $ext )
		);
		$hint_admin = __( 'Or add it manually under Settings → AcrossAI → Core → Upload Media Abilities and try again. This extra allowlist only applies to uploads made through the upload-media ability — regular Media Library uploads via wp-admin are unaffected.', 'acrossai-abilities-manager' );

		return sprintf(
			/* translators: 1: file extension the caller tried, 2: comma-separated list of allowed extensions, 3: ability-hint sentence, 4: admin-hint sentence */
			__( 'The file type ".%1$s" is not allowed for the upload-media ability on this site. Currently allowed: %2$s. %3$s %4$s', 'acrossai-abilities-manager' ),
			$ext,
			implode( ', ', $allowed_list ),
			$hint_ability,
			$hint_admin
		);
	}

	/**
	 * Best-effort MIME suggestion for the hint sentence so the caller can
	 * paste the media-mimes-update payload verbatim. Falls back to
	 * "application/octet-stream" when WordPress core can't guess.
	 */
	private function guess_mime_for_ext( string $ext ): string {
		$guess = wp_get_mime_types();
		foreach ( $guess as $ext_key => $mime ) {
			foreach ( explode( '|', (string) $ext_key ) as $part ) {
				if ( strtolower( trim( $part ) ) === $ext ) {
					return (string) $mime;
				}
			}
		}
		if ( 'svg' === $ext ) {
			return 'image/svg+xml';
		}
		return 'application/octet-stream';
	}

	/**
	 * Cron hook fired daily to sweep abandoned chunk sessions.
	 */
	public const CHUNK_SWEEP_HOOK = 'acrossai_core_abilities_upload_media_sweep_chunks';

	/**
	 * Route a chunked `data` upload. On non-final chunks returns
	 * `['response' => <envelope>]`; on the final chunk returns the
	 * staged `tmp` + `file_array` so the caller can continue the
	 * standard sideload flow. Errors are always returned via
	 * `['response' => <envelope with success=false>]`.
	 *
	 * @param array<string,mixed> $input The full ability input.
	 * @param array<string,mixed> $chunk The chunk sub-object.
	 * @return array{response: array<string,mixed>}|array{tmp: string, file_array: array<string,string>}
	 */
	private function handle_chunk( array $input, array $chunk ): array {
		$session_id = $this->sanitize_session_id( (string) ( $chunk['session_id'] ?? '' ) );
		if ( '' === $session_id ) {
			return array(
				'response' => array(
					'success' => false,
					'message' => __( 'The "chunk.session_id" must match /^[A-Za-z0-9_-]{8,64}$/.', 'acrossai-abilities-manager' ),
				),
			);
		}

		if ( ! isset( $chunk['index'] ) || ! is_numeric( $chunk['index'] ) || (int) $chunk['index'] < 0 ) {
			return array(
				'response' => array(
					'success' => false,
					'message' => __( 'The "chunk.index" is required and must be a non-negative integer.', 'acrossai-abilities-manager' ),
				),
			);
		}
		$index    = (int) $chunk['index'];
		$is_final = ! empty( $chunk['is_final'] );

		$staging_dir = $this->resolve_staging_dir();
		if ( false === $staging_dir ) {
			return array(
				'response' => array(
					'success' => false,
					'message' => __( 'Could not create the chunk staging directory under wp-content/uploads/acrossai-staging.', 'acrossai-abilities-manager' ),
				),
			);
		}

		list( $b64_path, $meta_path ) = $this->chunk_paths( $staging_dir, $session_id );
		$meta                         = $this->read_chunk_meta( $meta_path );
		$expected_index               = $meta['last_index'] + 1;

		if ( $index !== $expected_index ) {
			// Fresh session started with a non-zero index, or out-of-order chunk.
			$this->cleanup_chunk_session( $b64_path, $meta_path );
			return array(
				'response' => array(
					'success' => false,
					'message' => sprintf(
						/* translators: 1: expected index, 2: received index */
						__( 'Chunk received out of order (expected index %1$d, got %2$d). The session has been discarded — start over from index 0.', 'acrossai-abilities-manager' ),
						$expected_index,
						$index
					),
				),
			);
		}

		// Decode the base64 chunk payload (strip data-URL header, strip whitespace).
		$raw = trim( (string) ( $input['data'] ?? '' ) );
		if ( '' === $raw ) {
			$this->cleanup_chunk_session( $b64_path, $meta_path );
			return array(
				'response' => array(
					'success' => false,
					'message' => __( 'The "data" field is empty for this chunk.', 'acrossai-abilities-manager' ),
				),
			);
		}
		$mime_hint = $meta['mime_hint'];
		if ( 0 === stripos( $raw, 'data:' ) ) {
			$comma = strpos( $raw, ',' );
			if ( false === $comma ) {
				$this->cleanup_chunk_session( $b64_path, $meta_path );
				return array(
					'response' => array(
						'success' => false,
						'message' => __( 'The "data" chunk looks like a data URL but is missing the "," separator.', 'acrossai-abilities-manager' ),
					),
				);
			}
			$header = substr( $raw, 5, $comma - 5 );
			if ( false === stripos( $header, ';base64' ) ) {
				$this->cleanup_chunk_session( $b64_path, $meta_path );
				return array(
					'response' => array(
						'success' => false,
						'message' => __( 'Only base64-encoded data URLs are supported (header must contain ";base64").', 'acrossai-abilities-manager' ),
					),
				);
			}
			if ( 0 === $index ) {
				$mime_hint = trim( strtolower( strtok( $header, ';' ) ) );
			}
			$raw = substr( $raw, $comma + 1 );
		}
		$raw = preg_replace( '/\s+/', '', $raw ) ?? '';

		if ( '' === $raw ) {
			$this->cleanup_chunk_session( $b64_path, $meta_path );
			return array(
				'response' => array(
					'success' => false,
					'message' => __( 'The chunk contained no base64 payload after stripping whitespace and any data-URL prefix.', 'acrossai-abilities-manager' ),
				),
			);
		}

		/**
		 * Filter the per-chunk base64 size cap (post whitespace/prefix strip).
		 *
		 * @param int $bytes Default 8 MB.
		 */
		$chunk_max = (int) apply_filters( 'acrossai_core_abilities_upload_media_chunk_max_bytes', 8 * 1024 * 1024 );
		if ( strlen( $raw ) > $chunk_max ) {
			$this->cleanup_chunk_session( $b64_path, $meta_path );
			return array(
				'response' => array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: maximum chunk size in bytes */
						__( 'Chunk exceeds the per-chunk base64 size cap (%d bytes). Split into smaller chunks.', 'acrossai-abilities-manager' ),
						$chunk_max
					),
				),
			);
		}

		/**
		 * Filter the total per-session base64 size cap.
		 *
		 * @param int $bytes Default 64 MB.
		 */
		$session_max = (int) apply_filters( 'acrossai_core_abilities_upload_media_session_max_bytes', 64 * 1024 * 1024 );
		if ( ( $meta['bytes'] + strlen( $raw ) ) > $session_max ) {
			$this->cleanup_chunk_session( $b64_path, $meta_path );
			return array(
				'response' => array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: maximum session size in bytes */
						__( 'Session would exceed the total base64 size cap (%d bytes). The session has been discarded.', 'acrossai-abilities-manager' ),
						$session_max
					),
				),
			);
		}

		// Append the chunk to the staging file under an exclusive lock.
		$fp = fopen( $b64_path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fp ) {
			return array(
				'response' => array(
					'success' => false,
					'message' => __( 'Could not open the chunk staging file for writing.', 'acrossai-abilities-manager' ),
				),
			);
		}
		$locked = flock( $fp, LOCK_EX );
		$wrote  = fwrite( $fp, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( $locked ) {
			flock( $fp, LOCK_UN );
		}
		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $wrote || $wrote !== strlen( $raw ) ) {
			$this->cleanup_chunk_session( $b64_path, $meta_path );
			return array(
				'response' => array(
					'success' => false,
					'message' => __( 'Could not append the chunk bytes to the staging file.', 'acrossai-abilities-manager' ),
				),
			);
		}

		$now                = time();
		$meta['last_index'] = $index;
		$meta['bytes']      = $meta['bytes'] + strlen( $raw );
		$meta['updated_at'] = $now;
		$meta['mime_hint']  = $mime_hint;
		if ( 0 === (int) $meta['created_at'] ) {
			$meta['created_at'] = $now;
		}
		$this->write_chunk_meta( $meta_path, $meta );

		if ( ! $is_final ) {
			return array(
				'response' => array(
					'success'        => true,
					'session_id'     => $session_id,
					'chunk_received' => $index,
					'bytes_staged'   => (int) $meta['bytes'],
					/* translators: 1: chunk index, 2: cumulative base64 bytes staged */
					'message'        => sprintf( __( 'Chunk %1$d accepted (%2$d base64 bytes staged). Send the next chunk or set "is_final":true.', 'acrossai-abilities-manager' ), $index, (int) $meta['bytes'] ),
				),
			);
		}

		if ( isset( $chunk['total'] ) && is_numeric( $chunk['total'] ) ) {
			$expected_total = (int) $chunk['total'];
			if ( $expected_total !== ( $index + 1 ) ) {
				$this->cleanup_chunk_session( $b64_path, $meta_path );
				return array(
					'response' => array(
						'success' => false,
						'message' => sprintf(
							/* translators: 1: declared total, 2: received count */
							__( 'Final chunk arrived with index=%2$d but "chunk.total"=%1$d — session discarded.', 'acrossai-abilities-manager' ),
							$expected_total,
							$index
						),
					),
				);
			}
		}

		$finalized = $this->finalize_chunk_session(
			$b64_path,
			$meta['mime_hint'],
			(string) ( $input['filename'] ?? '' ),
			(string) ( $input['mime_type'] ?? '' )
		);
		$this->cleanup_chunk_session( $b64_path, $meta_path );

		if ( isset( $finalized['error'] ) ) {
			return array(
				'response' => array(
					'success' => false,
					'message' => $finalized['error'],
				),
			);
		}

		return array(
			'tmp'        => $finalized['tmp'],
			'file_array' => $finalized['file_array'],
		);
	}

	/**
	 * Ensure the staging directory exists under wp-content/uploads/,
	 * hardened with `.htaccess` + `index.html`. Returns the absolute
	 * path, or false on failure.
	 *
	 * @return string|false
	 */
	private function resolve_staging_dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'acrossai-staging';
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// Best-effort hardening — write once, ignore failure.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged
				$htaccess,
				"Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			);
		}
		$index_html = $dir . '/index.html';
		if ( ! file_exists( $index_html ) ) {
			@file_put_contents( $index_html, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $dir;
	}

	/**
	 * Regex-allowlist a caller-supplied session id. Returns '' when the
	 * input does not match /^[A-Za-z0-9_-]{8,64}$/ — reject rather than
	 * silently transform.
	 */
	private function sanitize_session_id( string $sid ): string {
		return preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $sid ) ? $sid : '';
	}

	/**
	 * @return array{0: string, 1: string} [ $b64_path, $meta_path ]
	 */
	private function chunk_paths( string $dir, string $session_id ): array {
		return array(
			trailingslashit( $dir ) . $session_id . '.b64',
			trailingslashit( $dir ) . $session_id . '.meta.json',
		);
	}

	/**
	 * @return array{last_index: int, bytes: int, created_at: int, updated_at: int, mime_hint: string}
	 */
	private function read_chunk_meta( string $meta_path ): array {
		$defaults = array(
			'last_index' => -1,
			'bytes'      => 0,
			'created_at' => 0,
			'updated_at' => 0,
			'mime_hint'  => '',
		);
		if ( ! is_file( $meta_path ) ) {
			return $defaults;
		}
		$raw = @file_get_contents( $meta_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged
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
			'mime_hint'  => isset( $parsed['mime_hint'] ) ? (string) $parsed['mime_hint'] : '',
		);
	}

	/**
	 * Write chunk meta.
	 *
	 * @param string $meta_path
	 * @param array $meta
	 * @return bool
	 */
	private function write_chunk_meta( string $meta_path, array $meta ): bool {
		$json = wp_json_encode( $meta );
		if ( false === $json ) {
			return false;
		}
		return false !== file_put_contents( $meta_path, $json, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Cleanup chunk session.
	 *
	 * @param string $b64_path
	 * @param string $meta_path
	 * @return void
	 */
	private function cleanup_chunk_session( string $b64_path, string $meta_path ): void {
		if ( file_exists( $b64_path ) ) {
			wp_delete_file( $b64_path );
		}
		if ( file_exists( $meta_path ) ) {
			wp_delete_file( $meta_path );
		}
	}

	/**
	 * Read the assembled base64 staging file, decode it, and stage the
	 * decoded bytes into a WP tempfile ready for media_handle_sideload().
	 *
	 * @return array{tmp: string, file_array: array<string,string>}|array{error: string}
	 */
	private function finalize_chunk_session( string $b64_path, string $mime_hint, string $filename_in, string $mime_in ): array {
		if ( ! is_file( $b64_path ) ) {
			return array( 'error' => __( 'Chunk staging file disappeared before finalization.', 'acrossai-abilities-manager' ) );
		}
		$raw = @file_get_contents( $b64_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $raw || '' === $raw ) {
			return array( 'error' => __( 'Chunk staging file was empty or unreadable.', 'acrossai-abilities-manager' ) );
		}
		$decoded = $this->decode_base64_payload( $raw );
		if ( isset( $decoded['error'] ) ) {
			return array( 'error' => $decoded['error'] );
		}
		if ( '' === $mime_in && '' !== $mime_hint ) {
			$mime_in = $mime_hint;
		}
		return $this->stage_decoded_bytes( $decoded['decoded'], $filename_in, $mime_in );
	}

	/**
	 * Cron callback: delete `.b64` + `.meta.json` pairs whose meta
	 * `updated_at` is older than the session TTL. Best-effort — silent
	 * failures are ignored to keep the cron tick fast.
	 */
	public static function sweep_chunk_sessions(): void {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'acrossai-staging';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		/**
		 * Filter the session TTL. Sessions with `updated_at` older than
		 * (now - ttl) are swept.
		 *
		 * @param int $ttl Default 1 day (DAY_IN_SECONDS).
		 */
		$ttl    = (int) apply_filters( 'acrossai_core_abilities_upload_media_session_ttl', DAY_IN_SECONDS );
		$cutoff = time() - max( $ttl, MINUTE_IN_SECONDS );

		$metas = glob( trailingslashit( $dir ) . '*.meta.json' );
		if ( ! is_array( $metas ) ) {
			return;
		}
		foreach ( $metas as $meta_path ) {
			$raw = @file_get_contents( $meta_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged
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
	 * Schedule the daily sweep if not already scheduled. Safe to call
	 * repeatedly (WP dedupes by hook + args).
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
