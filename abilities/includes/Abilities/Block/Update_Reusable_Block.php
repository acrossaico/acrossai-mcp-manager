<?php
/**
 * Feature 071 — update an existing reusable block.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Update the title and/or content of an existing reusable block (wp_block).
 */
class Update_Reusable_Block extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/update-reusable-block',
			'args' => array(
				'label'               => __( 'Update Reusable Block', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update the title and/or content of an existing reusable block (wp_block CPT). Preserves post_status unless explicitly overridden.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
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
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'blocks'  => array( 'type' => 'array' ),
						'status'  => array(
							'type' => 'string',
							'enum' => array( 'publish', 'draft' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'id'      => array( 'type' => 'integer' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'patterns',
						'sub_group_label' => __( 'Patterns', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
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
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$id   = absint( $input['id'] ?? 0 );
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			return $this->failure( $id, __( 'Reusable block not found.', 'acrossai-abilities-manager' ) );
		}
		if ( 'wp_block' !== $post->post_type ) {
			return $this->failure( $id, __( 'Post is not a reusable block (wp_block).', 'acrossai-abilities-manager' ) );
		}

		$args = array( 'ID' => (int) $post->ID );

		if ( isset( $input['title'] ) && is_string( $input['title'] ) ) {
			$args['post_title'] = sanitize_text_field( (string) $input['title'] );
		}
		if ( isset( $input['blocks'] ) && is_array( $input['blocks'] ) ) {
			$args['post_content'] = serialize_blocks( $input['blocks'] );
		} elseif ( isset( $input['content'] ) && is_string( $input['content'] ) ) {
			$args['post_content'] = (string) $input['content'];
		}
		if ( isset( $input['status'] ) && in_array( (string) $input['status'], array( 'publish', 'draft' ), true ) ) {
			$args['post_status'] = (string) $input['status'];
		}

		if ( 1 === count( $args ) ) {
			return $this->failure( $id, __( 'At least one of title, content, blocks, or status must be provided.', 'acrossai-abilities-manager' ) );
		}

		$result = wp_update_post( $args, true );
		if ( is_wp_error( $result ) ) {
			return $this->failure( $id, (string) $result->get_error_message() );
		}

		return array(
			'success' => true,
			'id'      => (int) $id,
			/* translators: %d: reusable-block post ID */
			'message' => sprintf( __( 'Reusable block #%d updated.', 'acrossai-abilities-manager' ), $id ),
		);
	}

	/**
	 * Consistent failure envelope.
	 *
	 * @param int    $id      Post ID.
	 * @param string $message Failure message.
	 * @return array<string,mixed>
	 */
	private function failure( int $id, string $message ): array {
		return array(
			'success' => false,
			'id'      => $id,
			'message' => $message,
		);
	}
}
