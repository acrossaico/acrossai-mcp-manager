<?php
/**
 * Feature 071 — create a new wp_navigation entity.
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
 * Create a new wp_navigation post from raw content or structured blocks.
 */
class Create_Navigation extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/create-navigation',
			'args' => array(
				'label'               => __( 'Create Navigation', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a new wp_navigation Site-Editor entity. Accepts `content` (raw markup) or `blocks` (structured). Distinct from classic nav_menu (see menus/create-menu).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'blocks'  => array( 'type' => 'array' ),
						'status'  => array(
							'type'    => 'string',
							'enum'    => array( 'publish', 'draft' ),
							'default' => 'publish',
						),
					),
					'required'             => array( 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'id'      => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string' ),
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
		$title  = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$status = in_array( (string) ( $input['status'] ?? '' ), array( 'publish', 'draft' ), true )
			? (string) $input['status']
			: 'publish';

		if ( '' === $title ) {
			return $this->failure( __( 'title is required.', 'acrossai-abilities-manager' ) );
		}

		$content = '';
		if ( isset( $input['blocks'] ) && is_array( $input['blocks'] ) ) {
			$content = serialize_blocks( $input['blocks'] );
		} elseif ( isset( $input['content'] ) && is_string( $input['content'] ) ) {
			$content = $input['content'];
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $this->failure( (string) $post_id->get_error_message() );
		}

		return array(
			'success' => true,
			'id'      => (int) $post_id,
			'title'   => $title,
			'status'  => $status,
			/* translators: %d: new navigation post ID */
			'message' => sprintf( __( 'Navigation #%d created.', 'acrossai-abilities-manager' ), (int) $post_id ),
		);
	}

	/**
	 * Consistent failure envelope.
	 *
	 * @param string $message Failure message.
	 * @return array<string,mixed>
	 */
	private function failure( string $message ): array {
		return array(
			'success' => false,
			'id'      => 0,
			'title'   => '',
			'status'  => '',
			'message' => $message,
		);
	}
}
