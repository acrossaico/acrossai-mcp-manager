<?php
/**
 * Ability class for users/reset-role (Feature 062).
 *
 * Resets any of the five WordPress built-in roles back to its
 * WordPress-core default capabilities by removing the role and
 * re-invoking populate_roles(). The reset is deliberately coarse
 * (re-seeds every default role) but is idempotent and correct.
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
 * Reset a WordPress-core built-in role to its shipped capability set.
 *
 * Contract §5 in specs/062-role-caps-and-search-replace/contracts/abilities.md.
 */
class Reset_Role extends Ability_Definition {

	/**
	 * Role slugs that the WordPress-core populate_roles() function
	 * re-seeds — the exact set that can be reset by this ability.
	 *
	 * @var string[]
	 */
	private const RESETTABLE_ROLES = array(
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
			'name' => 'users/reset-role',
			'args' => array(
				'label'               => __( 'Reset Role', 'acrossai-abilities-manager' ),
				'description'         => __( 'Reset any of the five WordPress-core built-in roles (administrator, editor, author, contributor, subscriber) back to its shipped default capability set.', 'acrossai-abilities-manager' ),
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
							'enum'        => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
							'description' => __( 'One of the five WordPress-core built-in role slugs.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'role' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'               => array( 'type' => 'boolean' ),
						'role'                  => array( 'type' => 'string' ),
						'restored_capabilities' => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'boolean' ),
						),
						'message'               => array( 'type' => 'string' ),
						'blocked_reason'        => array( 'type' => 'string' ),
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

		if ( ! in_array( $role_slug, self::RESETTABLE_ROLES, true ) ) {
			return array(
				'success'        => false,
				'role'           => $role_slug,
				'blocked_reason' => 'not_default_role',
				/* translators: %s: role slug */
				'message'        => sprintf( __( 'Reset applies only to WordPress-core built-in roles; "%s" is not one of them.', 'acrossai-abilities-manager' ), $role_slug ),
			);
		}

		remove_role( $role_slug );

		if ( ! function_exists( 'populate_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/schema.php';
		}
		populate_roles();

		$restored = get_role( $role_slug );

		return array(
			'success'               => true,
			'role'                  => $role_slug,
			'restored_capabilities' => null === $restored ? (object) array() : (object) $restored->capabilities,
			/* translators: %s: role slug */
			'message'               => sprintf( __( 'Role "%s" reset to WordPress-core defaults.', 'acrossai-abilities-manager' ), $role_slug ),
		);
	}
}
