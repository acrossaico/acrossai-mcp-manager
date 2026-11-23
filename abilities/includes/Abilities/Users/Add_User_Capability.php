<?php
/**
 * Ability class for users/add-user-capability (Feature 062).
 *
 * Grants a single capability directly to an individual user via
 * WP_User::add_cap(). Overrides the user's role-derived permissions
 * for that capability only. Refuses when the user id does not
 * resolve to a real user.
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
 * Grant a single capability to a specific user.
 *
 * Contract §6 in specs/062-role-caps-and-search-replace/contracts/abilities.md.
 */
class Add_User_Capability extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'users/add-user-capability',
			'args' => array(
				'label'               => __( 'Add User Capability', 'acrossai-abilities-manager' ),
				'description'         => __( 'Grant a single capability directly to a specific user, overriding role-derived permissions for that capability only.', 'acrossai-abilities-manager' ),
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
							'description' => __( 'Capability name to grant.', 'acrossai-abilities-manager' ),
						),
						'grant'      => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Whether the capability is granted (true) or explicitly denied (false).', 'acrossai-abilities-manager' ),
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
		$grant      = array_key_exists( 'grant', $input ) ? (bool) $input['grant'] : true;

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

		$user->add_cap( $capability, $grant );

		return array(
			'success'    => true,
			'user_id'    => $user_id,
			'capability' => $capability,
			/* translators: 1: capability name, 2: user id */
			'message'    => sprintf( __( 'Capability "%1$s" granted to user #%2$d.', 'acrossai-abilities-manager' ), $capability, $user_id ),
		);
	}
}
