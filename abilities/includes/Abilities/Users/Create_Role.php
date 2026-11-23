<?php
/**
 * Ability class for users/create-role (Feature 062).
 *
 * Creates a new WordPress role, optionally cloning capabilities from
 * an existing role. Refuses with blocked_reason=role_exists when the
 * target slug already exists, and blocked_reason=clone_source_not_found
 * when clone_from names a role that does not exist.
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
 * Create a new WordPress role.
 *
 * Contract §3 in specs/062-role-caps-and-search-replace/contracts/abilities.md.
 */
class Create_Role extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'users/create-role',
			'args' => array(
				'label'               => __( 'Create Role', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a new WordPress role with a caller-supplied slug and display name. Optionally clone capabilities from an existing role via the clone_from field.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-users',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'role'         => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Slug for the new role (lowercase, no spaces).', 'acrossai-abilities-manager' ),
						),
						'display_name' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Human-readable label for the new role.', 'acrossai-abilities-manager' ),
						),
						'clone_from'   => array(
							'type'        => 'string',
							'description' => __( 'Optional existing role slug to clone capabilities from. When omitted, the new role starts with no capabilities.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'role', 'display_name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'role'           => array( 'type' => 'string' ),
						'capabilities'   => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'boolean' ),
						),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
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
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
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
		$role_slug    = sanitize_text_field( (string) ( $input['role'] ?? '' ) );
		$display_name = sanitize_text_field( (string) ( $input['display_name'] ?? '' ) );
		$clone_from   = isset( $input['clone_from'] ) ? sanitize_text_field( (string) $input['clone_from'] ) : '';

		if ( '' === $role_slug ) {
			return array(
				'success' => false,
				'message' => __( 'role is required.', 'acrossai-abilities-manager' ),
			);
		}

		if ( '' === $display_name ) {
			return array(
				'success' => false,
				'message' => __( 'display_name is required.', 'acrossai-abilities-manager' ),
			);
		}

		if ( null !== get_role( $role_slug ) ) {
			return array(
				'success'        => false,
				'role'           => $role_slug,
				'blocked_reason' => 'role_exists',
				/* translators: %s: role slug */
				'message'        => sprintf( __( 'Role "%s" already exists.', 'acrossai-abilities-manager' ), $role_slug ),
			);
		}

		$capabilities = array();
		if ( '' !== $clone_from ) {
			$source = get_role( $clone_from );
			if ( null === $source ) {
				return array(
					'success'        => false,
					'role'           => $role_slug,
					'blocked_reason' => 'clone_source_not_found',
					/* translators: %s: source role slug */
					'message'        => sprintf( __( 'Clone source role "%s" does not exist.', 'acrossai-abilities-manager' ), $clone_from ),
				);
			}
			$capabilities = (array) $source->capabilities;
		}

		$new_role = add_role( $role_slug, $display_name, $capabilities );
		if ( null === $new_role ) {
			return array(
				'success'        => false,
				'role'           => $role_slug,
				'blocked_reason' => 'role_exists',
				/* translators: %s: role slug */
				'message'        => sprintf( __( 'Role "%s" could not be created (WordPress reported it already exists).', 'acrossai-abilities-manager' ), $role_slug ),
			);
		}

		return array(
			'success'      => true,
			'role'         => $role_slug,
			'capabilities' => (object) $new_role->capabilities,
			/* translators: %s: role slug */
			'message'      => sprintf( __( 'Role "%s" created.', 'acrossai-abilities-manager' ), $role_slug ),
		);
	}
}
