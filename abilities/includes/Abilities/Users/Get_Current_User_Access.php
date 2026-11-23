<?php
/**
 * Feature 055 — expose the current user's role + capability profile.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Users
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Users;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the current user's identity, roles, and capability map.
 *
 * Complements `user-role-capabilities` (which describes a role in the abstract)
 * by returning what the *actual current caller* can do on this site.
 */
class Get_Current_User_Access extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'users/get-current-user-access',
			'args' => array(
				'label'               => __( 'Current User Access', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the current caller\'s user id, roles, capability map, and network-admin status. Read-only; useful for MCP clients that need to reason about what actions the current session is authorised to perform.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-users',
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
						'success'          => array( 'type' => 'boolean' ),
						'user_id'          => array( 'type' => 'integer' ),
						'login'            => array( 'type' => 'string' ),
						'display_name'     => array( 'type' => 'string' ),
						'roles'            => array( 'type' => 'array' ),
						'capabilities'     => array( 'type' => 'object' ),
						'is_super_admin'   => array( 'type' => 'boolean' ),
						'is_network_admin' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
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
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		unset( $input );
		$user = wp_get_current_user();
		if ( ! $user instanceof \WP_User || 0 === $user->ID ) {
			return array(
				'success' => false,
				'message' => __( 'No authenticated user in this request.', 'acrossai-abilities-manager' ),
			);
		}

		$caps = array();
		foreach ( (array) $user->allcaps as $cap => $granted ) {
			$caps[ (string) $cap ] = (bool) $granted;
		}

		return array(
			'success'          => true,
			'user_id'          => (int) $user->ID,
			'login'            => (string) $user->user_login,
			'display_name'     => (string) $user->display_name,
			'roles'            => array_values( array_map( 'strval', (array) $user->roles ) ),
			'capabilities'     => (object) $caps,
			'is_super_admin'   => function_exists( 'is_super_admin' ) && is_super_admin( (int) $user->ID ),
			'is_network_admin' => is_multisite() && current_user_can( 'manage_network' ),
		);
	}
}
