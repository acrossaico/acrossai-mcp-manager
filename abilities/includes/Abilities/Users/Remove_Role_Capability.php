<?php
/**
 * Ability class for users/remove-role-capability (Feature 062).
 *
 * Revokes a single capability from an existing WordPress role via
 * WP_Role::remove_cap(). Refuses to strip a WordPress-core administrator
 * capability from the administrator role so the site owner cannot be
 * locked out through a single mis-invoked ability call.
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
 * Revoke a single capability from an existing role.
 *
 * Contract §2 in specs/062-role-caps-and-search-replace/contracts/abilities.md.
 */
class Remove_Role_Capability extends Ability_Definition {

	/**
	 * WordPress-core administrator capability baseline.
	 *
	 * Sourced verbatim from wp-admin/includes/schema.php:
	 *   populate_roles_160() admin section + populate_roles_210() +
	 *   populate_roles_230/250/260/270/280/300().
	 *
	 * Hardcoded per Decision 3 in specs/062-role-caps-and-search-replace/research.md —
	 * the block-list names the WP-core-shipped baseline as an immovable safety
	 * anchor, independent of live state.
	 *
	 * @var string[]
	 */
	private const CORE_ADMIN_CAPS = array(
		// populate_roles_160.
		'switch_themes',
		'edit_themes',
		'activate_plugins',
		'edit_plugins',
		'edit_users',
		'edit_files',
		'manage_options',
		'moderate_comments',
		'manage_categories',
		'manage_links',
		'upload_files',
		'import',
		'unfiltered_html',
		'edit_posts',
		'edit_others_posts',
		'edit_published_posts',
		'publish_posts',
		'edit_pages',
		'read',
		'level_10',
		'level_9',
		'level_8',
		'level_7',
		'level_6',
		'level_5',
		'level_4',
		'level_3',
		'level_2',
		'level_1',
		'level_0',
		// populate_roles_210.
		'edit_others_pages',
		'edit_published_pages',
		'publish_pages',
		'delete_pages',
		'delete_others_pages',
		'delete_published_pages',
		'delete_posts',
		'delete_others_posts',
		'delete_published_posts',
		'delete_private_posts',
		'edit_private_posts',
		'read_private_posts',
		'delete_private_pages',
		'edit_private_pages',
		'read_private_pages',
		'delete_users',
		'create_users',
		// populate_roles_230.
		'unfiltered_upload',
		// populate_roles_250.
		'edit_dashboard',
		// populate_roles_260.
		'update_plugins',
		'delete_plugins',
		// populate_roles_270.
		'install_plugins',
		'update_themes',
		// populate_roles_280.
		'install_themes',
		// populate_roles_300.
		'update_core',
		'list_users',
		'remove_users',
		'promote_users',
		'edit_theme_options',
		'delete_themes',
		'export',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'users/remove-role-capability',
			'args' => array(
				'label'               => __( 'Remove Role Capability', 'acrossai-abilities-manager' ),
				'description'         => __( 'Revoke a single capability from an existing WordPress role. Refuses when the target is a WordPress-core administrator capability on the administrator role (to prevent site lockout).', 'acrossai-abilities-manager' ),
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
							'description' => __( 'Capability name to revoke.', 'acrossai-abilities-manager' ),
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

		if ( 'administrator' === $role_slug && in_array( $capability, self::CORE_ADMIN_CAPS, true ) ) {
			return array(
				'success'        => false,
				'role'           => $role_slug,
				'capability'     => $capability,
				'blocked_reason' => 'core_admin_cap',
				/* translators: %s: capability name */
				'message'        => sprintf( __( 'Refusing to revoke WordPress-core administrator capability "%s" from the administrator role (would risk site lockout).', 'acrossai-abilities-manager' ), $capability ),
			);
		}

		$role->remove_cap( $capability );

		return array(
			'success'    => true,
			'role'       => $role_slug,
			'capability' => $capability,
			/* translators: 1: capability name, 2: role slug */
			'message'    => sprintf( __( 'Capability "%1$s" revoked from role "%2$s".', 'acrossai-abilities-manager' ), $capability, $role_slug ),
		);
	}
}
