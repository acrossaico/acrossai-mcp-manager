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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\User_Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Delete_User ability class (absorbed).
 */
class Delete_User extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'users/delete-user',
			'args' => array(
				'label'               => __( 'Delete User', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete a WordPress user. Optionally reassign their content to another user.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-users',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'user'     => array(
							'type'        => array( 'string', 'integer' ),
							'description' => __( 'User ID, login, email, or slug to delete.', 'acrossai-abilities-manager' ),
						),
						'reassign' => array(
							'type'        => array( 'string', 'integer' ),
							'description' => __( 'Optional user ID/login/email to reassign content to. Omit to delete content.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'user' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'message'       => array( 'type' => 'string' ),
						'deleted_user'  => array( 'type' => 'string' ),
						'reassigned_to' => array( 'type' => 'integer' ),
					),
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
		if ( empty( $input['user'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'No user specified.', 'acrossai-abilities-manager' ),
			);
		}

		$user = User_Helpers::resolve_user( $input['user'] );

		if ( null === $user ) {
			return array(
				'success' => false,
				/* translators: %s: user identifier */
				'message' => sprintf( __( 'No user found matching "%s".', 'acrossai-abilities-manager' ), (string) $input['user'] ),
			);
		}

		if ( get_current_user_id() === (int) $user->ID ) {
			return array(
				'success' => false,
				'message' => __( 'You cannot delete the currently logged-in user.', 'acrossai-abilities-manager' ),
			);
		}

		$reassign_id = null;
		if ( ! empty( $input['reassign'] ) ) {
			$reassign_user = User_Helpers::resolve_user( $input['reassign'] );
			if ( null === $reassign_user ) {
				return array(
					'success' => false,
					/* translators: %s: user identifier */
					'message' => sprintf( __( 'No reassign user found matching "%s".', 'acrossai-abilities-manager' ), (string) $input['reassign'] ),
				);
			}
			$reassign_id = (int) $reassign_user->ID;
		}

		if ( is_multisite() ) {
			if ( ! function_exists( 'wpmu_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
			}
			$result = wpmu_delete_user( (int) $user->ID );
		} else {
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			$result = wp_delete_user( (int) $user->ID, $reassign_id );
		}

		if ( ! $result ) {
			return array(
				'success' => false,
				/* translators: %s: user login */
				'message' => sprintf( __( 'Failed to delete user "%s".', 'acrossai-abilities-manager' ), $user->user_login ),
			);
		}

		$response = array(
			'success'      => true,
			/* translators: %s: user login */
			'message'      => sprintf( __( 'User "%s" deleted.', 'acrossai-abilities-manager' ), $user->user_login ),
			'deleted_user' => $user->user_login,
		);

		if ( null !== $reassign_id ) {
			$response['reassigned_to'] = $reassign_id;
		}

		return $response;
	}
}
