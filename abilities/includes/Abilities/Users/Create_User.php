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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;

defined( 'ABSPATH' ) || exit;

/**
 * Create_User ability class (absorbed).
 */
class Create_User extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'users/create-user',
			'args' => array(
				'label'               => __( 'Create User', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a new WordPress user. If no password is provided, a strong one is generated and returned.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-users',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'username'   => array(
							'type'        => 'string',
							'description' => __( 'Username (login) for the new user.', 'acrossai-abilities-manager' ),
						),
						'email'      => array(
							'type'        => 'string',
							'description' => __( 'Email address for the new user.', 'acrossai-abilities-manager' ),
						),
						'password'   => array(
							'type'        => 'string',
							'description' => __( 'Optional password. Auto-generated if omitted.', 'acrossai-abilities-manager' ),
						),
						'first_name' => array(
							'type'        => 'string',
							'description' => __( 'First name.', 'acrossai-abilities-manager' ),
						),
						'last_name'  => array(
							'type'        => 'string',
							'description' => __( 'Last name.', 'acrossai-abilities-manager' ),
						),
						'role'       => array(
							'type'        => 'string',
							'description' => __( 'Role slug to assign (defaults to default_role option).', 'acrossai-abilities-manager' ),
						),
						'apply_wp_slash' => Slash_Input::schema_fragment()['apply_wp_slash'],
					),
					'required'             => array( 'username', 'email' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'            => array( 'type' => 'boolean' ),
						'message'            => array( 'type' => 'string' ),
						'user_id'            => array( 'type' => 'integer' ),
						'user_login'         => array( 'type' => 'string' ),
						'generated_password' => array( 'type' => 'string' ),
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
						'destructive' => false,
						'idempotent'  => false,
					),
					'input_flags'  => Slash_Input::meta_flags(),
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
		if ( empty( $input['username'] ) || empty( $input['email'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Both "username" and "email" are required.', 'acrossai-abilities-manager' ),
			);
		}

		$username = sanitize_user( $input['username'], true );
		if ( '' === $username || ! validate_username( $username ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid username.', 'acrossai-abilities-manager' ),
			);
		}

		$email = sanitize_email( $input['email'] );
		if ( '' === $email || ! is_email( $email ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid email address.', 'acrossai-abilities-manager' ),
			);
		}

		if ( username_exists( $username ) ) {
			return array(
				'success' => false,
				/* translators: %s: username */
				'message' => sprintf( __( 'Username "%s" already exists.', 'acrossai-abilities-manager' ), $username ),
			);
		}

		if ( email_exists( $email ) ) {
			return array(
				'success' => false,
				/* translators: %s: email */
				'message' => sprintf( __( 'Email "%s" is already registered.', 'acrossai-abilities-manager' ), $email ),
			);
		}

		$generated = false;
		$password  = $input['password'] ?? '';
		if ( '' === $password ) {
			$password  = wp_generate_password( 16, true, true );
			$generated = true;
		}

		$user_data = array(
			'user_login' => $username,
			'user_email' => $email,
			'user_pass'  => $password,
		);

		if ( ! empty( $input['first_name'] ) ) {
			$user_data['first_name'] = sanitize_text_field( $input['first_name'] );
		}
		if ( ! empty( $input['last_name'] ) ) {
			$user_data['last_name'] = sanitize_text_field( $input['last_name'] );
		}
		if ( ! empty( $input['role'] ) ) {
			$role = sanitize_key( $input['role'] );
			if ( null === get_role( $role ) ) {
				return array(
					'success' => false,
					/* translators: %s: role slug */
					'message' => sprintf( __( 'Role "%s" does not exist.', 'acrossai-abilities-manager' ), $role ),
				);
			}
			$user_data['role'] = $role;
		}

		$user_id = wp_insert_user( Slash_Input::slash( $user_data, $input ) );

		if ( is_wp_error( $user_id ) ) {
			return array(
				'success' => false,
				/* translators: %s: error message */
				'message' => sprintf( __( 'Failed to create user: %s', 'acrossai-abilities-manager' ), $user_id->get_error_message() ),
			);
		}

		$response = array(
			'success'    => true,
			/* translators: %s: username */
			'message'    => sprintf( __( 'User "%s" created successfully.', 'acrossai-abilities-manager' ), $username ),
			'user_id'    => (int) $user_id,
			'user_login' => $username,
		);

		if ( $generated ) {
			$response['generated_password'] = $password;
		}

		return $response;
	}
}
