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
 * Reset_User_Password ability class (absorbed).
 */
class Reset_User_Password extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'users/reset-user-password',
			'args' => array(
				'label'               => __( 'Reset User Password', 'acrossai-abilities-manager' ),
				'description'         => __( 'Send a password reset email to a user, or set a new password directly. Email notification is configurable.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-users',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'user'       => array(
							'type'        => array( 'string', 'integer' ),
							'description' => __( 'User ID, login, email, or slug.', 'acrossai-abilities-manager' ),
						),
						'method'     => array(
							'type'        => 'string',
							'enum'        => array( 'email', 'direct' ),
							'default'     => 'email',
							'description' => __( 'How to reset: "email" generates a reset link; "direct" sets a new password immediately.', 'acrossai-abilities-manager' ),
						),
						'password'   => array(
							'type'        => 'string',
							'description' => __( 'New password (only used when method=direct). Auto-generated if omitted.', 'acrossai-abilities-manager' ),
						),
						'send_email' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Whether to email the user. With method=email, false returns the reset link in the response instead. With method=direct, false skips the password-change notice.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'user' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'            => array( 'type' => 'boolean' ),
						'message'            => array( 'type' => 'string' ),
						'method'             => array( 'type' => 'string' ),
						'user_id'            => array( 'type' => 'integer' ),
						'email_sent'         => array( 'type' => 'boolean' ),
						'reset_link'         => array( 'type' => 'string' ),
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

		$method = isset( $input['method'] ) ? sanitize_text_field( $input['method'] ) : 'email';
		if ( ! in_array( $method, array( 'email', 'direct' ), true ) ) {
			$method = 'email';
		}

		$send_email = array_key_exists( 'send_email', $input ) ? (bool) $input['send_email'] : true;

		if ( 'direct' === $method ) {
			return $this->reset_direct( $user, $input, $send_email );
		}

		return $this->reset_via_email( $user, $send_email );
	}

	/**
	 * Set the user's password directly, optionally emailing them a change notice.
	 *
	 * @param \WP_User $user
	 * @param array    $input
	 * @param bool     $send_email
	 * @return array
	 */
	private function reset_direct( \WP_User $user, array $input, bool $send_email ): array {
		$generated = false;
		$password  = $input['password'] ?? '';
		if ( '' === $password ) {
			$password  = wp_generate_password( 16, true, true );
			$generated = true;
		}

		wp_set_password( $password, (int) $user->ID );

		$email_sent = false;
		if ( $send_email ) {
			$email_sent = $this->send_password_changed_notice( $user, $generated ? $password : '' );
		}

		$response = array(
			'success'    => true,
			/* translators: %s: user login */
			'message'    => sprintf( __( 'Password for "%s" has been updated.', 'acrossai-abilities-manager' ), $user->user_login ),
			'method'     => 'direct',
			'user_id'    => (int) $user->ID,
			'email_sent' => $email_sent,
		);

		if ( $send_email && ! $email_sent ) {
			/* translators: %s: user email */
			$response['message'] .= ' ' . sprintf( __( '(Notification email to %s failed to send.)', 'acrossai-abilities-manager' ), $user->user_email );
		}

		if ( $generated ) {
			$response['generated_password'] = $password;
		}

		return $response;
	}

	/**
	 * Generate a password reset link, optionally emailing it via WordPress.
	 *
	 * @param \WP_User $user
	 * @param bool     $send_email
	 * @return array
	 */
	private function reset_via_email( \WP_User $user, bool $send_email ): array {
		if ( $send_email ) {
			$result = retrieve_password( $user->user_login );

			if ( is_wp_error( $result ) ) {
				return array(
					'success'    => false,
					/* translators: %s: error message */
					'message'    => sprintf( __( 'Failed to send reset email: %s', 'acrossai-abilities-manager' ), $result->get_error_message() ),
					'method'     => 'email',
					'user_id'    => (int) $user->ID,
					'email_sent' => false,
				);
			}

			return array(
				'success'    => true,
				/* translators: %s: user email */
				'message'    => sprintf( __( 'Password reset email sent to %s.', 'acrossai-abilities-manager' ), $user->user_email ),
				'method'     => 'email',
				'user_id'    => (int) $user->ID,
				'email_sent' => true,
			);
		}

		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			return array(
				'success'    => false,
				/* translators: %s: error message */
				'message'    => sprintf( __( 'Failed to generate reset link: %s', 'acrossai-abilities-manager' ), $key->get_error_message() ),
				'method'     => 'email',
				'user_id'    => (int) $user->ID,
				'email_sent' => false,
			);
		}

		$reset_link = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);

		return array(
			'success'    => true,
			/* translators: %s: user login */
			'message'    => sprintf( __( 'Password reset link generated for "%s" (no email sent).', 'acrossai-abilities-manager' ), $user->user_login ),
			'method'     => 'email',
			'user_id'    => (int) $user->ID,
			'email_sent' => false,
			'reset_link' => $reset_link,
		);
	}

	/**
	 * Send a "your password was changed" notice to the user.
	 *
	 * @param \WP_User $user
	 * @param string   $generated_password Only included in the email when non-empty.
	 * @return bool Whether the email was accepted for delivery.
	 */
	private function send_password_changed_notice( \WP_User $user, string $generated_password ): bool {
		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your password has been changed', 'acrossai-abilities-manager' ), $blogname );

		$display_name = $user->display_name ? $user->display_name : $user->user_login;

		/* translators: %s: display name */
		$message = sprintf( __( 'Hi %s,', 'acrossai-abilities-manager' ), $display_name ) . "\r\n\r\n";
		/* translators: %s: site URL */
		$message .= sprintf( __( 'Your password on %s has been reset by an administrator.', 'acrossai-abilities-manager' ), home_url() ) . "\r\n\r\n";

		if ( '' !== $generated_password ) {
			/* translators: %s: new password */
			$message .= sprintf( __( 'Your new password is: %s', 'acrossai-abilities-manager' ), $generated_password ) . "\r\n\r\n";
			$message .= __( 'Please log in and change it as soon as possible.', 'acrossai-abilities-manager' ) . "\r\n\r\n";
		}

		$message .= __( 'If you did not expect this change, please contact the site administrator immediately.', 'acrossai-abilities-manager' ) . "\r\n";

		return (bool) wp_mail( $user->user_email, $subject, $message );
	}
}
