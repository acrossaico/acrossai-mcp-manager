<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Content;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;

defined( 'ABSPATH' ) || exit;

/**
 * Update_Page ability class (absorbed).
 */
class Update_Page extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/update-page',
			'args' => array(
				'label'               => __( 'Update Page', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update an existing page (post_type=page) via wp_update_post(). Only the supplied fields are changed.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'         => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'title'      => array( 'type' => 'string' ),
						'content'    => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string' ),
						'parent'     => array( 'type' => 'integer' ),
						'menu_order' => array( 'type' => 'integer' ),
						'slug'       => array( 'type' => 'string' ),
						'meta'       => array( 'type' => 'object' ),
						'return_content' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When true, the response includes the saved post_content / post_excerpt / post_content_filtered fields. Default false: those large fields are stripped and content_bytes is returned instead, so a "one-word edit" round-trip does not echo the whole page body back through the tunnel. Callers who need the saved bytes should pass true explicitly.', 'acrossai-abilities-manager' ),
						),
						'apply_wp_slash' => Slash_Input::schema_fragment()['apply_wp_slash'],
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'id'            => array( 'type' => 'integer' ),
						'page'          => array( 'type' => 'object' ),
						'content_bytes' => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'pages',
						'sub_group_label' => __( 'Pages', 'acrossai-abilities-manager' ),
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
					'input_flags'  => Slash_Input::meta_flags(),
				),
			),
		);
	}

	/**
	 * Feature 095 — hint that narrow edits can be done far more cheaply
	 * through the block-outline + surgical-write pair instead of shipping
	 * a full replacement post_content.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'content/inspect-post-builder',
				'reason' => __( 'Run first on an existing post. A page built with Elementor or another page builder keeps its layout outside post_content, so this write can report success, change nothing a visitor sees, and be reverted the next time the page is saved in the builder.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'blocks/outline-post-blocks',
				'reason' => __( 'For narrow edits, outline first to locate the target block cheaply — the outline is kilobytes even when the page is hundreds.', 'acrossai-abilities-manager' ),
				'saves'  => __( '~29K tokens vs full page rewrite on a 97 KB page', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'blocks/update-post-block',
				'reason' => __( 'Update just the located block without re-serializing the whole post_content.', 'acrossai-abilities-manager' ),
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
		$id   = (int) ( $input['id'] ?? 0 );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || 'page' !== $post->post_type ) {
			return array(
				'success' => false,
				'message' => __( 'Page not found.', 'acrossai-abilities-manager' ),
			);
		}

		$args = array( 'ID' => $id );
		if ( isset( $input['title'] ) ) {
			$args['post_title'] = sanitize_text_field( (string) $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			$args['post_content'] = (string) $input['content'];
		}
		if ( isset( $input['status'] ) ) {
			$args['post_status'] = sanitize_key( (string) $input['status'] );
		}
		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = (int) $input['parent'];
		}
		if ( isset( $input['menu_order'] ) ) {
			$args['menu_order'] = (int) $input['menu_order'];
		}
		if ( isset( $input['slug'] ) ) {
			$args['post_name'] = sanitize_title( (string) $input['slug'] );
		}
		if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
			$args['meta_input'] = $input['meta'];
		}

		$result = wp_update_post( Slash_Input::slash( $args, $input ), true );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		$fetched        = (array) get_post( (int) $result, ARRAY_A );
		$content_bytes  = strlen( (string) ( $fetched['post_content'] ?? '' ) );
		$return_content = ! empty( $input['return_content'] );
		if ( ! $return_content ) {
			// Strip the three large fields so a one-word edit doesn't echo the
			// whole page body back to the caller. Callers who need the saved
			// bytes pass return_content:true explicitly.
			unset(
				$fetched['post_content'],
				$fetched['post_content_filtered'],
				$fetched['post_excerpt']
			);
		}

		return array(
			'success'       => true,
			'id'            => (int) $result,
			'page'          => $fetched,
			'content_bytes' => $content_bytes,
			/* translators: %d: page ID */
			'message'       => sprintf( __( 'Updated page #%d.', 'acrossai-abilities-manager' ), $result ),
		);
	}
}
