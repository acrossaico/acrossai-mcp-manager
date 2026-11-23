<?php
/**
 * Ability class for users/remove-user-capability (Feature 062).
 *
 * Revokes a single capability directly from an individual user via
 * WP_User::remove_cap(). Refuses when the target is the last remaining
 * administrator and the capability being revoked is a WordPress-core
 * administrator capability — this last-admin protection prevents the
 * site from being left without an administrator.
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
 * Revoke a single capability from a specific user.
 *
 * Contract §7 in specs/062-role-caps-and-search-replace/contracts/abilities.md.
 */
class Remove_User_Capability extends Ability_Definition {

	/**
	 * WordPress-core administrator capability baseline.
	 *
	 * Duplicated from Remove_Role_Capability::CORE_ADMIN_CAPS per Decision 3
	 * in specs/062-role-caps-and-search-replace/research.md — three similar
	 * lines is better than a premature abstraction.
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
			'name' => 'users/remove-user-capability',
			'args' => array(
				'label'               => __( 'Remove User Capability', 'acrossai-abilities-manager' ),
				'description'         => __( 'Revoke a single capability directly from a specific user. Refuses when the target is the last remaining site administrator and the capability is a WordPress-core administrator capability (would leave the site without an admin).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-users',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'user_id'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Numeric user ID.', 'acrossai-abilities-manager' ),
						),
						'capability' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Capability name to revoke.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'user_id', 'capability' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'user_id'        => array( 'type' => 'integer' ),
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
						'sub_group'       => 'users',
						'sub_group_label' => __( 'Users', 'acrossai-abilities-manager' ),
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
		$user_id    = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
		$capability = sanitize_text_field( (string) ( $input['capability'] ?? '' ) );

		if ( $user_id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'user_id is required.', 'acrossai-abilities-manager' ),
			);
		}

		if ( '' === $capability ) {
			return array(
				'success' => false,
				'message' => __( 'capability is required.', 'acrossai-abilities-manager' ),
			);
		}

		$user = get_userdata( $user_id );
		if ( false === $user ) {
			return array(
				'success'        => false,
				'user_id'        => $user_id,
				'capability'     => $capability,
				'blocked_reason' => 'user_not_found',
				/* translators: %d: user id */
				'message'        => sprintf( __( 'No user found matching id %d.', 'acrossai-abilities-manager' ), $user_id ),
			);
		}

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);
		$admins = array_map( 'intval', (array) $admins );

		if (
			1 === count( $admins )
			&& $user_id === (int) $admins[0]
			&& in_array( $capability, self::CORE_ADMIN_CAPS, true )
		) {
			return array(
				'success'        => false,
				'user_id'        => $user_id,
				'capability'     => $capability,
				'blocked_reason' => 'last_admin_core_cap',
				/* translators: 1: capability name, 2: user id */
				'message'        => sprintf( __( 'Refusing to revoke WordPress-core administrator capability "%1$s" from the last remaining administrator (user #%2$d) — would leave the site without an admin.', 'acrossai-abilities-manager' ), $capability, $user_id ),
			);
		}

		$user->remove_cap( $capability );

		return array(
			'success'    => true,
			'user_id'    => $user_id,
			'capability' => $capability,
			/* translators: 1: capability name, 2: user id */
			'message'    => sprintf( __( 'Capability "%1$s" revoked from user #%2$d.', 'acrossai-abilities-manager' ), $capability, $user_id ),
		);
	}
}
