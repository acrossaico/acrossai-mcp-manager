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
 * Create_Menu_Item ability class (absorbed).
 */
class Create_Menu_Item extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'menus/create-menu-item',
			'args' => array(
				'label'               => __( 'Create Menu Item', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a menu item via POST /wp/v2/menu-items. title and (object/object_id or url) are required.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-menus',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'       => array( 'type' => 'string' ),
						'type'        => array(
							'type'    => 'string',
							'enum'    => array( 'post_type', 'taxonomy', 'custom', 'block', 'post_type_archive' ),
							'default' => 'custom',
						),
						'object'      => array( 'type' => 'string' ),
						'object_id'   => array( 'type' => 'integer' ),
						'url'         => array( 'type' => 'string' ),
						'parent'      => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'menu_order'  => array(
							'type'    => 'integer',
							'default' => 0,
						),
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
					'required'             => array( 'title' ),
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
						'idempotent'  => false,
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
		$title = (string) ( $input['title'] ?? '' );
		if ( '' === $title ) {
			return array(
				'success' => false,
				'message' => __( 'title is required.', 'acrossai-abilities-manager' ),
			);
		}
		$menu_id = (int) ( $input['menus'] ?? 0 );
		if ( $menu_id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'menus (the parent menu id) is required.', 'acrossai-abilities-manager' ),
			);
		}
		if ( ! ( wp_get_nav_menu_object( $menu_id ) instanceof \WP_Term ) ) {
			return array(
				'success' => false,
				'message' => __( 'Parent menu not found.', 'acrossai-abilities-manager' ),
			);
		}

		$args = self::build_item_args( $title, $input );

		$new_id = wp_update_nav_menu_item( $menu_id, 0, Slash_Input::slash( $args, $input ) );
		if ( is_wp_error( $new_id ) || 0 === $new_id ) {
			return Menu_Formatter::error_from( $new_id, __( 'Could not create menu item.', 'acrossai-abilities-manager' ) );
		}

		$post = get_post( (int) $new_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			return array(
				'success' => false,
				'message' => __( 'Menu item created but could not be retrieved.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'success' => true,
			'item'    => Menu_Formatter::item_to_array( $post ),
			'message' => __( 'Menu item created.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Translate the public field names into the menu-item-* args
	 * that wp_update_nav_menu_item expects.
	 *
	 * @param string               $title Item title.
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function build_item_args( string $title, array $input ): array {
		$args = array(
			'menu-item-title'  => $title,
			'menu-item-type'   => (string) ( $input['type'] ?? 'custom' ),
			'menu-item-status' => 'publish',
		);
		if ( isset( $input['object'] ) ) {
			$args['menu-item-object'] = sanitize_key( (string) $input['object'] );
		}
		if ( isset( $input['object_id'] ) ) {
			$args['menu-item-object-id'] = (int) $input['object_id'];
		}
		if ( isset( $input['url'] ) ) {
			$args['menu-item-url'] = esc_url_raw( (string) $input['url'] );
		}
		if ( isset( $input['parent'] ) ) {
			$args['menu-item-parent-id'] = (int) $input['parent'];
		}
		if ( isset( $input['menu_order'] ) ) {
			$args['menu-item-position'] = (int) $input['menu_order'];
		}
		if ( isset( $input['target'] ) ) {
			$args['menu-item-target'] = '_blank' === $input['target'] ? '_blank' : '';
		}
		if ( isset( $input['classes'] ) && is_array( $input['classes'] ) ) {
			$args['menu-item-classes'] = implode( ' ', array_map( 'sanitize_html_class', $input['classes'] ) );
		}
		if ( isset( $input['description'] ) ) {
			$args['menu-item-description'] = (string) $input['description'];
		}
		if ( isset( $input['xfn'] ) && is_array( $input['xfn'] ) ) {
			$args['menu-item-xfn'] = implode( ' ', array_map( 'sanitize_html_class', $input['xfn'] ) );
		}
		if ( isset( $input['attr_title'] ) ) {
			$args['menu-item-attr-title'] = (string) $input['attr_title'];
		}
		return $args;
	}
}
