<?php
/**
 * Feature 071 — enumerate wp_navigation Site-Editor entities.
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
 * List every wp_navigation post (Site-Editor navigation entity) with id, title,
 * slug, status, and modified timestamp.
 */
class List_Navigations extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/list-navigations',
			'args' => array(
				'label'               => __( 'List Navigations', 'acrossai-abilities-manager' ),
				'description'         => __( 'Enumerate every wp_navigation Site-Editor entity with id, title, slug, status, and modified timestamp. Distinct from classic nav_menu (see menus/list-menus).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'     => array( 'type' => 'boolean' ),
						'navigations' => array( 'type' => 'array' ),
						'total'       => array( 'type' => 'integer' ),
						'message'     => array( 'type' => 'string' ),
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
		unset( $input );

		$posts = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$navigations = array();
		foreach ( (array) $posts as $post ) {
			$navigations[] = array(
				'id'       => (int) $post->ID,
				'title'    => sanitize_text_field( (string) $post->post_title ),
				'slug'     => sanitize_title( (string) $post->post_name ),
				'status'   => sanitize_key( (string) $post->post_status ),
				'modified' => sanitize_text_field( (string) $post->post_modified_gmt ),
			);
		}

		return array(
			'success'     => true,
			'navigations' => $navigations,
			'total'       => count( $navigations ),
			/* translators: %d: navigation entity count */
			'message'     => sprintf( __( 'Returned %d navigation(s).', 'acrossai-abilities-manager' ), count( $navigations ) ),
		);
	}
}
