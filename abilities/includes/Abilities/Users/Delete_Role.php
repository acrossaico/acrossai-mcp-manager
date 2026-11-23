<?php
/**
 * Ability class for users/delete-role (Feature 062).
 *
 * Deletes a WordPress role via remove_role(). Refuses to delete any of
 * the five WordPress built-in roles (administrator/editor/author/
 * contributor/subscriber) and refuses to delete any role that is
 * currently held by one or more users.
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
 * Delete a WordPress role.
 *
 * Contract §4 in specs/062-role-caps-and-search-replace/contracts/abilities.md.
 */
class Delete_Role extends Ability_Definition {

	/**
	 * WordPress-core built-in role slugs that must never be deleted.
	 *
	 * @var string[]
	 */
	private const DEFAULT_ROLES = array(
		'administrator',
		'editor',
		'author',
		'contributor',
		'subscriber',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'users/delete-role',
			'args' => array(
				'label'               => __( 'Delete Role', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete an existing WordPress role. Refuses when the role is one of the five WordPress built-in roles, or when the role is currently held by one or more users.', 'acrossai-abilities-manager' ),
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
							'minLength'   => 1,
							'description' => __( 'Role slug to delete.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'role' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'role'           => array( 'type' => 'string' ),
						'user_count'     => array( 'type' => 'integer' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'message'        => array( 'type' => 'string' ),
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
		$role_slug = sanitize_text_field( (string) ( $input['role'] ?? '' ) );

		if ( '' === $role_slug ) {
			return array(
				'success' => false,
				'message' => __( 'role is required.', 'acrossai-abilities-manager' ),
			);
		}

		if ( in_array( $role_slug, self::DEFAULT_ROLES, true ) ) {
			return array(
				'success'        => false,
				'role'           => $role_slug,
				'blocked_reason' => 'default_role',
				/* translators: %s: role slug */
				'message'        => sprintf( __( 'Refusing to delete WordPress-core built-in role "%s".', 'acrossai-abilities-manager' ), $role_slug ),
			);
		}

		if ( null === get_role( $role_slug ) ) {
			return array(
				'success'        => false,
				'role'           => $role_slug,
				'blocked_reason' => 'role_not_found',
				/* translators: %s: role slug */
				'message'        => sprintf( __( 'Role "%s" does not exist.', 'acrossai-abilities-manager' ), $role_slug ),
			);
		}

		$holders = get_users(
			array(
				'role'   => $role_slug,
				'fields' => 'ID',
			)
		);
		$count   = count( (array) $holders );

		if ( $count > 0 ) {
			return array(
				'success'        => false,
				'role'           => $role_slug,
				'user_count'     => $count,
				'blocked_reason' => 'role_has_users',
				'message'        => sprintf(
					/* translators: 1: role slug, 2: number of users */
					_n(
						'Refusing to delete role "%1$s" — %2$d user still holds it. Reassign the user first.',
						'Refusing to delete role "%1$s" — %2$d users still hold it. Reassign the users first.',
						$count,
						'acrossai-abilities-manager'
					),
					$role_slug,
					$count
				),
			);
		}

		remove_role( $role_slug );

		return array(
			'success' => true,
			'role'    => $role_slug,
			/* translators: %s: role slug */
			'message' => sprintf( __( 'Role "%s" deleted.', 'acrossai-abilities-manager' ), $role_slug ),
		);
	}
}
