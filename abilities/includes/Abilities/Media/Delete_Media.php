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

defined( 'ABSPATH' ) || exit;

/**
 * Delete_Media ability class (absorbed).
 */
class Delete_Media extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'media/delete-media',
			'args' => array(
				'label'               => __( 'Delete Media', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete a media attachment. Requires confirm:true. Honours MEDIA_TRASH when defined; pass force:true to skip trash and delete permanently.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-media',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => __( 'Must be true to proceed. Guards against accidental hard-deletes.', 'acrossai-abilities-manager' ),
						),
						'force'   => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Skip trash even when MEDIA_TRASH is defined.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'deleted'        => array(
							'type' => 'string',
							'enum' => array( 'deleted', 'trashed' ),
						),
						'media'          => array( 'type' => 'object' ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
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
		$id = (int) ( $input['id'] ?? 0 );
		if ( $id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'A valid id is required.', 'acrossai-abilities-manager' ),
			);
		}

		// Explicit-confirmation guard. Refuse without mutating state.
		if ( empty( $input['confirm'] ) || true !== (bool) $input['confirm'] ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'confirmation_required',
				'message'        => __( 'Deleting media is permanent unless MEDIA_TRASH is defined. Pass confirm:true to proceed.', 'acrossai-abilities-manager' ),
			);
		}

		$post = get_post( $id );
		if ( ! ( $post instanceof \WP_Post ) || 'attachment' !== $post->post_type ) {
			return array(
				'success' => false,
				'message' => __( 'Attachment not found.', 'acrossai-abilities-manager' ),
			);
		}

		$snapshot = Media_Formatter::to_array( $post );

		// Honour MEDIA_TRASH unless the caller explicitly forces a hard delete.
		$force   = ! empty( $input['force'] );
		$trashed = ! $force && defined( 'MEDIA_TRASH' ) && MEDIA_TRASH;

		// wp_delete_attachment second argument is $force_delete — true when we
		// bypass the trash. When $trashed is true we want the trash path, so
		// pass false; when $trashed is false we want a hard delete, so pass true.
		$deleted = wp_delete_attachment( $id, ! $trashed );
		if ( ! $deleted ) {
			return Media_Formatter::error_from(
				false,
				/* translators: %d: attachment ID */
				sprintf( __( 'Could not delete attachment #%d.', 'acrossai-abilities-manager' ), $id )
			);
		}

		return array(
			'success' => true,
			'deleted' => $trashed ? 'trashed' : 'deleted',
			'media'   => $snapshot,
			'message' => $trashed
				/* translators: %d: attachment ID */
				? sprintf( __( 'Trashed attachment #%d.', 'acrossai-abilities-manager' ), $id )
				/* translators: %d: attachment ID */
				: sprintf( __( 'Deleted attachment #%d.', 'acrossai-abilities-manager' ), $id ),
		);
	}
}
