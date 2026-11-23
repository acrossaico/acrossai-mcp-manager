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
 * Returns the capability map for a single role.
 *
 * Replaces the dropped acrossai-abilities-manager-roles category.
 */
class Get_Role_Capabilities extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'users/get-role-capabilities',
			'args' => array(
				'label'               => __( 'Get Role Capabilities', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the full capability map for a single registered role. Useful before granting a role via user-create / user-update.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-users',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'role' => array(
							'type'        => 'string',
							'description' => __( 'Role slug (e.g. administrator, editor).', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'role' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'message'      => array( 'type' => 'string' ),
						'role'         => array( 'type' => 'string' ),
						'label'        => array( 'type' => 'string' ),
						'capabilities' => array( 'type' => 'object' ),
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
		if ( empty( $input['role'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'No role specified.', 'acrossai-abilities-manager' ),
			);
		}

		$slug = sanitize_key( $input['role'] );
		$role = get_role( $slug );

		if ( null === $role ) {
			return array(
				'success' => false,
				/* translators: %s: role slug */
				'message' => sprintf( __( 'Role "%s" does not exist.', 'acrossai-abilities-manager' ), $slug ),
			);
		}

		$wp_roles = wp_roles();
		$details  = $wp_roles->roles[ $slug ] ?? array();
		$label    = isset( $details['name'] ) ? translate_user_role( $details['name'] ) : $slug;

		return array(
			'success'      => true,
			/* translators: %s: role label */
			'message'      => sprintf( __( 'Capabilities for role "%s".', 'acrossai-abilities-manager' ), $label ),
			'role'         => $slug,
			'label'        => $label,
			'capabilities' => (object) $role->capabilities,
		);
	}
}
