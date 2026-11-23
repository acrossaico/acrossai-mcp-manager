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

defined( 'ABSPATH' ) || exit;

/**
 * Delete_Menu ability class (absorbed).
 */
class Delete_Menu extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'menus/delete-menu',
			'args' => array(
				'label'               => __( 'Delete Menu', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete a nav menu via DELETE /wp/v2/menus/{id}. Menus do not support trash — force=true is sent implicitly.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-menus',
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
						'deleted' => array( 'type' => 'boolean' ),
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

		$menu = wp_get_nav_menu_object( $id );
		if ( ! ( $menu instanceof \WP_Term ) ) {
			return array(
				'success' => false,
				'message' => __( 'Menu not found.', 'acrossai-abilities-manager' ),
			);
		}

		$snapshot = Menu_Formatter::menu_to_array( $menu );
		$result   = wp_delete_nav_menu( $id );

		if ( is_wp_error( $result ) || false === $result ) {
			return Menu_Formatter::error_from(
				$result,
				/* translators: %d: menu ID */
				sprintf( __( 'Could not delete menu #%d.', 'acrossai-abilities-manager' ), $id )
			);
		}

		return array(
			'success' => true,
			'deleted' => true,
			'menu'    => $snapshot,
			/* translators: %d: menu ID */
			'message' => sprintf( __( 'Deleted menu #%d.', 'acrossai-abilities-manager' ), $id ),
		);
	}
}
