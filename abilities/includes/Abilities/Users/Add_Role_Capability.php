<?php
/**
 * Ability class for users/add-role-capability (Feature 062).
 *
 * Grants a single capability to an existing WordPress role via
 * WP_Role::add_cap(). Refuses when the target role does not exist so
 * the caller receives an explicit blocked_reason instead of a silent
 * no-op.
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
 * Grant a single capability to an existing role.
 *
 * Contract §1 in specs/062-role-caps-and-search-replace/contracts/abilities.md.
 */
class Add_Role_Capability extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'users/add-role-capability',
			'args' => array(
				'label'               => __( 'Add Role Capability', 'acrossai-abilities-manager' ),
				'description'         => __( 'Grant a single capability to an existing WordPress role. Idempotent — regranting a capability the role already holds is a no-op success.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-users',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'role'       => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Role slug (e.g. editor, author, or a custom role).', 'acrossai-abilities-manager' ),
						),
						'capability' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Capability name to grant (e.g. upload_files, edit_others_posts).', 'acrossai-abilities-manager' ),
						),
						'grant'      => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Whether the capability is granted (true) or explicitly denied (false).', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'role', 'capability' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'role'           => array( 'type' => 'string' ),
						'capability'     => array( 'type' => 'string' ),
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
		$role_slug  = sanitize_text_field( (string) ( $input['role'] ?? '' ) );
		$capability = sanitize_text_field( (string) ( $input['capability'] ?? '' ) );
		$grant      = array_key_exists( 'grant', $input ) ? (bool) $input['grant'] : true;

		if ( '' === $role_slug ) {
			return array(
				'success' => false,
				'message' => __( 'role is required.', 'acrossai-abilities-manager' ),
			);
		}

		if ( '' === $capability ) {
			return array(
				'success' => false,
				'message' => __( 'capability is required.', 'acrossai-abilities-manager' ),
			);
		}

		$role = get_role( $role_slug );
		if ( null === $role ) {
			return array(
				'success'        => false,
				'role'           => $role_slug,
				'capability'     => $capability,
				'blocked_reason' => 'role_not_found',
				/* translators: %s: role slug */
				'message'        => sprintf( __( 'Role "%s" does not exist.', 'acrossai-abilities-manager' ), $role_slug ),
			);
		}

		$role->add_cap( $capability, $grant );

		return array(
			'success'    => true,
			'role'       => $role_slug,
			'capability' => $capability,
			/* translators: 1: capability name, 2: role slug */
			'message'    => sprintf( __( 'Capability "%1$s" granted to role "%2$s".', 'acrossai-abilities-manager' ), $capability, $role_slug ),
		);
	}
}
