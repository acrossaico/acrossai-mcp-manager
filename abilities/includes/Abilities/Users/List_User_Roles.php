<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Users
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Users;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every registered WordPress role.
 *
 * Replaces the dropped acrossai-abilities-manager-roles category — role
 * management is part of user administration here.
 */
class List_User_Roles extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'users/list-user-roles',
			'args' => array(
				'label'               => __( 'List User Roles', 'acrossai-abilities-manager' ),
				'description'         => __( 'List all registered WordPress roles, optionally with their capability maps. Use these slugs as input to user-create / user-update.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-users',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'include_capabilities' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Include the full capability map for each role.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'roles' => array( 'type' => 'array' ),
						'total' => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'users',
						'sub_group'       => 'roles',
						'sub_group_label' => __( 'Roles', 'acrossai-abilities-manager' ),
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
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$include_caps = ! empty( $input['include_capabilities'] );

		$wp_roles = wp_roles();
		$roles    = array();

		foreach ( $wp_roles->roles as $slug => $details ) {
			$entry = array(
				'name'  => $slug,
				'label' => isset( $details['name'] ) ? translate_user_role( $details['name'] ) : $slug,
			);

			if ( $include_caps ) {
				$entry['capabilities'] = isset( $details['capabilities'] ) ? (object) $details['capabilities'] : (object) array();
			}

			$roles[] = $entry;
		}

		return array(
			'roles' => $roles,
			'total' => count( $roles ),
		);
	}
}
