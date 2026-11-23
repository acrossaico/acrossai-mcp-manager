<?php
/**
 * Feature 055 — rename an attachment's on-disk file safely.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Media
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Media;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Rename an attachment's on-disk file, update `_wp_attached_file`, and
 * regenerate the intermediate size metadata.
 *
 * Safety constraints:
 * - `new_filename` MUST NOT contain a directory separator, null byte, or a
 *   leading dot.
 * - The resolved new path MUST stay inside the attachment's original
 *   upload sub-directory (realpath containment).
 * - If a file already exists at the target path, the operation is refused
 *   rather than clobbering.
 */
class Rename_Media_File extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'media/rename-media-file',
			'args' => array(
				'label'               => __( 'Rename Media File', 'acrossai-abilities-manager' ),
				'description'         => __( 'Rename an attachment\'s on-disk file and update the "_wp_attached_file" post-meta + attachment guid + regenerate intermediate size metadata. new_filename may not contain a directory separator, null byte, or a leading dot; the resolved new path must stay inside the original upload sub-directory; refuses if the target filename already exists (no clobber).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-media',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'upload_files' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'attachment_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'new_filename'  => array(
							'type'      => 'string',
							'minLength' => 1,
						),
					),
					'required'             => array( 'attachment_id', 'new_filename' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'attachment_id' => array( 'type' => 'integer' ),
						'old_relative'  => array( 'type' => 'string' ),
						'new_relative'  => array( 'type' => 'string' ),
						'message'       => array( 'type' => 'string' ),
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
	 * Execute the ability.
	 *
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$attachment_id = absint( $input['attachment_id'] ?? 0 );
		$new_filename  = (string) ( $input['new_filename'] ?? '' );

		if ( $attachment_id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'A valid attachment_id is required.', 'acrossai-abilities-manager' ),
			);
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return array(
				'success' => false,
				'message' => __( 'Attachment not found.', 'acrossai-abilities-manager' ),
			);
		}

		// Feature 055 hardening — per-attachment cap check (attachments
		// respect edit_post like any other post).
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to edit this attachment.', 'acrossai-abilities-manager' ),
			);
		}

		if (
			'' === $new_filename
			|| str_contains( $new_filename, '/' )
			|| str_contains( $new_filename, '\\' )
			|| str_contains( $new_filename, "\0" )
			|| str_starts_with( $new_filename, '.' )
		) {
			return array(
				'success' => false,
				'message' => __( 'new_filename must not contain a directory separator, null byte, or a leading dot.', 'acrossai-abilities-manager' ),
			);
		}

		$sanitized = sanitize_file_name( $new_filename );
		if ( '' === $sanitized ) {
			return array(
				'success' => false,
				'message' => __( 'new_filename resolves to an empty string after sanitisation.', 'acrossai-abilities-manager' ),
			);
		}

		$old_relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $old_relative ) {
			return array(
				'success' => false,
				'message' => __( '_wp_attached_file meta is missing on this attachment.', 'acrossai-abilities-manager' ),
			);
		}

		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return array(
				'success' => false,
				'message' => (string) $uploads['error'],
			);
		}
		$base_dir = trailingslashit( (string) $uploads['basedir'] );
		$old_abs  = $base_dir . $old_relative;
		if ( ! is_file( $old_abs ) ) {
			return array(
				'success' => false,
				'message' => __( 'Original file does not exist on disk.', 'acrossai-abilities-manager' ),
			);
		}

		$dir_relative = ltrim( (string) ( dirname( $old_relative ) ?: '' ), '/.' );
		$new_relative = ( '' === $dir_relative ) ? $sanitized : $dir_relative . '/' . $sanitized;
		$new_abs      = $base_dir . $new_relative;

		$parent_real = realpath( dirname( $old_abs ) );
		if ( false === $parent_real ) {
			return array(
				'success' => false,
				'message' => __( 'Could not resolve the attachment\'s parent directory.', 'acrossai-abilities-manager' ),
			);
		}
		$target_parent_real = realpath( dirname( $new_abs ) );
		if ( false === $target_parent_real || $target_parent_real !== $parent_real ) {
			return array(
				'success' => false,
				'message' => __( 'Target path would escape the attachment\'s original upload sub-directory.', 'acrossai-abilities-manager' ),
			);
		}

		if ( file_exists( $new_abs ) ) {
			return array(
				'success' => false,
				'message' => __( 'A file already exists at the target filename; refusing to clobber.', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! @rename( $old_abs, $new_abs ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'success' => false,
				'message' => __( 'Rename failed on disk.', 'acrossai-abilities-manager' ),
			);
		}

		update_post_meta( $attachment_id, '_wp_attached_file', $new_relative );
		wp_update_post(
			array(
				'ID'   => $attachment_id,
				'guid' => trailingslashit( (string) $uploads['baseurl'] ) . $new_relative,
			)
		);

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $new_abs );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return array(
			'success'       => true,
			'attachment_id' => $attachment_id,
			'old_relative'  => $old_relative,
			'new_relative'  => $new_relative,
			/* translators: 1: old path, 2: new path */
			'message'       => sprintf( __( 'Renamed "%1$s" → "%2$s".', 'acrossai-abilities-manager' ), $old_relative, $new_relative ),
		);
	}
}
