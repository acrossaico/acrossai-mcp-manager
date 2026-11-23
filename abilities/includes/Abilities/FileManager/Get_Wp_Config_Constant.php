<?php
/**
 * Site introspection read — value of a named wp-config.php constant (Feature 063).
 *
 * The ability rejects any name in a hardcoded block-list of nine
 * authentication keys/salts + the database password, guarding against
 * a credential-exfiltration primitive if a caller's session is
 * compromised.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\FileManager
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\FileManager;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Get_Wp_Config_Constant ability class.
 */
class Get_Wp_Config_Constant extends Ability_Definition {

	/**
	 * Names of constants whose values must never be disclosed.
	 *
	 * The nine WordPress-core auth keys/salts plus DB_PASSWORD. Case-sensitive
	 * match — mirrors PHP defined()/constant() semantics.
	 *
	 * @var array<int,string>
	 */
	private const BLOCKED_CONSTANTS = array(
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
		'DB_PASSWORD',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/get-wp-config-constant',
			'args' => array(
				'label'               => __( 'Get wp-config Constant', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the value of a named PHP constant (typically defined in wp-config.php). Refuses to disclose auth keys, salts, or DB_PASSWORD.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'constant' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Name of the PHP constant to read.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'constant' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'constant'       => array( 'type' => 'string' ),
						'defined'        => array( 'type' => 'boolean' ),
						'value'          => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'null' ) ),
						'blocked_reason' => array( 'type' => 'string' ),
						'message'        => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'files',
						'sub_group'       => 'wp-config',
						'sub_group_label' => __( 'WP Config', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
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
		// Feature 091 note: this ability reads the RUNTIME PHP constant table
		// via defined()/constant(). It performs no filesystem I/O, so the
		// WP_Filesystem migration is a no-op for this class — no
		// Wp_Filesystem_Init call is needed.
		$constant = isset( $input['constant'] ) ? sanitize_text_field( (string) $input['constant'] ) : '';

		if ( '' === $constant ) {
			return array(
				'success' => false,
				'message' => __( 'No constant name provided.', 'acrossai-abilities-manager' ),
			);
		}

		if ( in_array( $constant, self::BLOCKED_CONSTANTS, true ) ) {
			return array(
				'success'        => false,
				'constant'       => $constant,
				'blocked_reason' => 'sensitive_constant',
				'message'        => __( 'Constant is blocked from disclosure.', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! defined( $constant ) ) {
			return array(
				'success'  => true,
				'constant' => $constant,
				'defined'  => false,
				'message'  => __( 'Constant is not defined.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'success'  => true,
			'constant' => $constant,
			'defined'  => true,
			'value'    => constant( $constant ),
			'message'  => __( 'Constant fetched.', 'acrossai-abilities-manager' ),
		);
	}
}
