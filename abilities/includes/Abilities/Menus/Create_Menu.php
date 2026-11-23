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
 * Create_Menu ability class (absorbed).
 */
class Create_Menu extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'menus/create-menu',
			'args' => array(
				'label'               => __( 'Create Menu', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a new nav menu via POST /wp/v2/menus.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-menus',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'locations'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'apply_wp_slash' => Slash_Input::schema_fragment()['apply_wp_slash'],
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'menu'    => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'menus',
						'sub_group_label' => __( 'Menus', 'acrossai-abilities-manager' ),
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
		$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name ) {
			return array(
				'success' => false,
				'message' => __( 'name is required.', 'acrossai-abilities-manager' ),
			);
		}

		$args = array();
		if ( ! empty( $input['slug'] ) ) {
			$args['menu-slug'] = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = (string) $input['description'];
		}

		$menu_id = wp_create_nav_menu( Slash_Input::slash( $name, $input ) );
		if ( is_wp_error( $menu_id ) ) {
			return Menu_Formatter::error_from( $menu_id, __( 'Could not create menu.', 'acrossai-abilities-manager' ) );
		}

		if ( ! empty( $args ) ) {
			wp_update_nav_menu_object( (int) $menu_id, Slash_Input::slash( $args, $input ) );
		}

		if ( ! empty( $input['locations'] ) && is_array( $input['locations'] ) ) {
			Menu_Formatter::set_menu_locations(
				(int) $menu_id,
				array_values( array_filter( array_map( 'sanitize_key', $input['locations'] ) ) )
			);
		}

		$menu = wp_get_nav_menu_object( (int) $menu_id );
		if ( ! ( $menu instanceof \WP_Term ) ) {
			return array(
				'success' => false,
				'message' => __( 'Menu created but could not be retrieved.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'success' => true,
			'menu'    => Menu_Formatter::menu_to_array( $menu ),
			'message' => __( 'Menu created.', 'acrossai-abilities-manager' ),
		);
	}
}
