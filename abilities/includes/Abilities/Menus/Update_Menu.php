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
 * Update_Menu ability class (absorbed).
 */
class Update_Menu extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'menus/update-menu',
			'args' => array(
				'label'               => __( 'Update Menu', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update a nav menu via POST /wp/v2/menus/{id}. Only the supplied fields are touched.', 'acrossai-abilities-manager' ),
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
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'locations'   => array(
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

		$menu = wp_get_nav_menu_object( $id );
		if ( ! ( $menu instanceof \WP_Term ) ) {
			return array(
				'success' => false,
				'message' => __( 'Menu not found.', 'acrossai-abilities-manager' ),
			);
		}

		$args = array();
		if ( isset( $input['name'] ) ) {
			$args['menu-name'] = sanitize_text_field( (string) $input['name'] );
		}
		if ( isset( $input['slug'] ) ) {
			$args['menu-slug'] = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = (string) $input['description'];
		}

		if ( ! empty( $args ) ) {
			$result = wp_update_nav_menu_object( $id, Slash_Input::slash( $args, $input ) );
			if ( is_wp_error( $result ) ) {
				return Menu_Formatter::error_from(
					$result,
					/* translators: %d: menu ID */
					sprintf( __( 'Could not update menu #%d.', 'acrossai-abilities-manager' ), $id )
				);
			}
		}

		if ( isset( $input['locations'] ) && is_array( $input['locations'] ) ) {
			Menu_Formatter::set_menu_locations(
				$id,
				array_values( array_filter( array_map( 'sanitize_key', $input['locations'] ) ) )
			);
		}

		$updated = wp_get_nav_menu_object( $id );
		return array(
			'success' => true,
			'menu'    => $updated instanceof \WP_Term ? Menu_Formatter::menu_to_array( $updated ) : array(),
			/* translators: %d: menu ID */
			'message' => sprintf( __( 'Updated menu #%d.', 'acrossai-abilities-manager' ), $id ),
		);
	}
}
