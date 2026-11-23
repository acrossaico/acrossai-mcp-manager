<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Menus
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Menus;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;

defined( 'ABSPATH' ) || exit;

/**
 * Update_Menu_Item ability class (absorbed).
 */
class Update_Menu_Item extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'menus/update-menu-item',
			'args' => array(
				'label'               => __( 'Update Menu Item', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update a menu item via POST /wp/v2/menu-items/{id}. Only the supplied fields are touched.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-menus',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'title'       => array( 'type' => 'string' ),
						'url'         => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
						'menu_order'  => array( 'type' => 'integer' ),
						'menus'       => array( 'type' => 'integer' ),
						'target'      => array(
							'type' => 'string',
							'enum' => array( '', '_blank' ),
						),
						'classes'     => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'description' => array( 'type' => 'string' ),
						'xfn'         => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'apply_wp_slash' => Slash_Input::schema_fragment()['apply_wp_slash'],
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'item'    => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'menu-items',
						'sub_group_label' => __( 'Menu Items', 'acrossai-abilities-manager' ),
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

		$post = get_post( $id );
		if ( ! ( $post instanceof \WP_Post ) || 'nav_menu_item' !== $post->post_type ) {
			return array(
				'success' => false,
				'message' => __( 'Menu item not found.', 'acrossai-abilities-manager' ),
			);
		}

		// Determine the containing menu — either the new one passed in or the
		// item's current nav_menu term. wp_update_nav_menu_item REQUIRES a menu id.
		$menu_id = (int) ( $input['menus'] ?? 0 );
		if ( $menu_id <= 0 ) {
			$terms = get_the_terms( $post, 'nav_menu' );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$first   = array_shift( $terms );
				$menu_id = $first instanceof \WP_Term ? (int) $first->term_id : 0;
			}
		}
		if ( $menu_id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'Containing menu could not be determined.', 'acrossai-abilities-manager' ),
			);
		}

		// Seed args from current state so omitted fields don't get wiped, then overlay $input.
		$current = wp_setup_nav_menu_item( $post );
		$seed    = array(
			'title'       => isset( $current->title ) ? (string) $current->title : '',
			'type'        => isset( $current->type ) ? (string) $current->type : 'custom',
			'object'      => isset( $current->object ) ? (string) $current->object : '',
			'object_id'   => isset( $current->object_id ) ? (int) $current->object_id : 0,
			'url'         => isset( $current->url ) ? (string) $current->url : '',
			'parent'      => isset( $current->menu_item_parent ) ? (int) $current->menu_item_parent : 0,
			'menu_order'  => isset( $current->menu_order ) ? (int) $current->menu_order : 0,
			'target'      => isset( $current->target ) ? (string) $current->target : '',
			'description' => isset( $current->description ) ? (string) $current->description : '',
			'attr_title'  => isset( $current->attr_title ) ? (string) $current->attr_title : '',
			'classes'     => isset( $current->classes ) ? (array) $current->classes : array(),
			'xfn'         => isset( $current->xfn ) ? explode( ' ', (string) $current->xfn ) : array(),
		);
		$merged  = array_merge( $seed, $input );

		$args = Create_Menu_Item::build_item_args( (string) $merged['title'], $merged );

		$result = wp_update_nav_menu_item( $menu_id, $id, Slash_Input::slash( $args, $input ) );
		if ( is_wp_error( $result ) || 0 === $result ) {
			return Menu_Formatter::error_from(
				$result,
				/* translators: %d: menu item ID */
				sprintf( __( 'Could not update menu item #%d.', 'acrossai-abilities-manager' ), $id )
			);
		}

		$updated = get_post( $id );
		return array(
			'success' => true,
			'item'    => $updated instanceof \WP_Post ? Menu_Formatter::item_to_array( $updated ) : array(),
			/* translators: %d: menu item ID */
			'message' => sprintf( __( 'Updated menu item #%d.', 'acrossai-abilities-manager' ), $id ),
		);
	}
}
