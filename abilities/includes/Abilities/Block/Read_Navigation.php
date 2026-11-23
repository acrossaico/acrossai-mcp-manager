<?php
/**
 * Feature 071 — read a single wp_navigation entity by ID.
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
 * Return one wp_navigation post with parsed block tree.
 */
class Read_Navigation extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/read-navigation',
			'args' => array(
				'label'               => __( 'Read Navigation', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return one wp_navigation Site-Editor entity by ID: title, status, raw content, and the parsed block tree.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
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
						'title'   => array( 'type' => 'string' ),
						'slug'    => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'blocks'  => array( 'type' => 'array' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'site-editor',
						'sub_group_label' => __( 'Site Editor', 'acrossai-abilities-manager' ),
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
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$id   = absint( $input['id'] ?? 0 );
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			return $this->failure( $id, __( 'Navigation not found.', 'acrossai-abilities-manager' ) );
		}
		if ( 'wp_navigation' !== $post->post_type ) {
			return $this->failure( $id, __( 'Post is not a wp_navigation entity.', 'acrossai-abilities-manager' ) );
		}

		return array(
			'success' => true,
			'id'      => (int) $post->ID,
			'title'   => sanitize_text_field( (string) $post->post_title ),
			'slug'    => sanitize_title( (string) $post->post_name ),
			'status'  => sanitize_key( (string) $post->post_status ),
			'content' => (string) $post->post_content,
			'blocks'  => parse_blocks( (string) $post->post_content ),
			'message' => __( 'Navigation returned.', 'acrossai-abilities-manager' ),
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
			'title'   => '',
			'slug'    => '',
			'status'  => '',
			'content' => '',
			'blocks'  => array(),
			'message' => $message,
		);
	}
}
