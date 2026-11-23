<?php
/**
 * Feature 131 — write decoded image bytes into the Media Library.
 *
 * The base64 → attachment path, factored out so the only `media_handle_sideload()`
 * call in the generate-image suite lives in one place. Mirrors the AI plugin's
 * Import_Base64_Image::import_image() and the existing
 * Media\Upload_Media::stage_from_base64() flow.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Media
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Media;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Mime_Types_Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only base64 → attachment sideloader.
 */
final class Media_Sideload {

	/**
	 * Private constructor — this class is static-only.
	 */
	private function __construct() {}

	/**
	 * Decode base64 image bytes and create a Media Library attachment.
	 *
	 * Applies the Upload Media Abilities MIME allowlist for the duration of the
	 * call, exactly as Upload_Media does, so a generated image is subject to the
	 * same policy. Cleans up its temp file in every path.
	 *
	 * @param string              $base64    Raw base64 (no data: prefix).
	 * @param string              $filename  Base filename without extension.
	 * @param string              $mime      MIME type, e.g. image/png.
	 * @param int                 $post_id   Post to attach to, or 0.
	 * @param array<string,mixed> $post_data Extra wp_insert_attachment fields (post_title, meta_input, ...).
	 * @return int|WP_Error Attachment id, or an error.
	 */
	public static function from_base64( string $base64, string $filename, string $mime, int $post_id = 0, array $post_data = array() ) {
		$decoded = base64_decode( $base64, true );
		if ( false === $decoded || '' === $decoded ) {
			return new WP_Error( 'invalid_base64', __( 'The image data could not be decoded.', 'acrossai-abilities-manager' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$extension = wp_get_default_extension_for_mime_type( $mime );
		if ( ! $extension ) {
			return new WP_Error( 'unsupported_mime', __( 'The generated image is not a supported file type.', 'acrossai-abilities-manager' ) );
		}

		$base = sanitize_file_name( '' !== trim( $filename ) ? $filename : 'ai-image-' . gmdate( 'Ymd-His' ) );
		$tmp  = wp_tempnam( $base . '.' . $extension );
		if ( ! $tmp ) {
			return new WP_Error( 'temp_failed', __( 'Could not create a temporary file for the generated image.', 'acrossai-abilities-manager' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem is overkill for a one-shot temp write that is immediately sideloaded and deleted.
		$written = file_put_contents( $tmp, $decoded );
		if ( false === $written ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'write_failed', __( 'Could not write the generated image to disk.', 'acrossai-abilities-manager' ) );
		}

		$file_array = array(
			'name'     => $base . '.' . $extension,
			'type'     => $mime,
			'tmp_name' => $tmp,
		);

		$description = isset( $post_data['post_content'] ) ? (string) $post_data['post_content'] : '';

		add_filter( 'upload_mimes', array( Mime_Types_Store::class, 'filter_upload_mimes' ) );
		try {
			$id = media_handle_sideload( $file_array, $post_id, $description, $post_data );
		} finally {
			remove_filter( 'upload_mimes', array( Mime_Types_Store::class, 'filter_upload_mimes' ) );
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}

		return $id;
	}
}
