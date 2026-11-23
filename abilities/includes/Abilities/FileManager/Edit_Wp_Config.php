<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\FileManager
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\FileManager;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Audit_Trail;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Wp_Filesystem_Init;

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Wp_Config ability class (absorbed).
 */
class Edit_Wp_Config extends Ability_Definition {

	/**
	 * Constants that may not be modified via this ability.
	 */
	private const PROTECTED = array(
		'DB_NAME',
		'DB_USER',
		'DB_PASSWORD',
		'DB_HOST',
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
		'SECRET_KEY',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/edit-wp-config',
			'args' => array(
				'label'               => __( 'Edit wp-config.php', 'acrossai-abilities-manager' ),
				'description'         => __( 'Updates the value of an existing non-sensitive constant in wp-config.php. Protected credential and secret constants cannot be modified.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'constant_name' => array(
							'type'        => 'string',
							'description' => __( 'Name of the constant to update (e.g. WP_DEBUG).', 'acrossai-abilities-manager' ),
						),
						'value'         => array(
							'type'        => 'string',
							'description' => __( 'New string value for the constant.', 'acrossai-abilities-manager' ),
						),
						'context'       => array(
							'type'        => 'string',
							'maxLength'   => 2000,
							'description' => __( 'Optional caller-supplied reason for this wp-config edit. Captured in the audit log. Truncated to 500 chars in the persisted entry.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'constant_name', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
						// Feature 094 audit-trail addition.
						'backup_path'    => array( 'type' => array( 'string', 'null' ) ),
					),
					'required'             => array( 'success', 'message' ),
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
						'readonly'    => false,
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
		$blocked = File_Mods_Guard::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}
		$blocked = Wp_Filesystem_Init::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}
		$fs = Wp_Filesystem_Init::get();

		$name  = strtoupper( sanitize_text_field( $input['constant_name'] ?? '' ) );
		$value = $input['value'] ?? '';

		if ( '' === $name || ! preg_match( '/^[A-Z_][A-Z0-9_]*$/', $name ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid constant name.', 'acrossai-abilities-manager' ),
			);
		}

		if ( in_array( $name, self::PROTECTED, true ) ) {
			return array(
				'success' => false,
				'message' => __( 'This constant is protected and cannot be modified.', 'acrossai-abilities-manager' ),
			);
		}

		$config_path = $this->locate_wp_config( $fs );

		if ( null === $config_path ) {
			return array(
				'success' => false,
				'message' => __( 'wp-config.php not found.', 'acrossai-abilities-manager' ),
			);
		}

		$raw     = $fs->get_contents( $config_path );
		$escaped = addslashes( $value );
		$pattern = "/define\(\s*(['\"])" . preg_quote( $name, '/' ) . "\\1\s*,\s*(?:'[^']*'|\"[^\"]*\"|[^)]+)\s*\)/";
		$updated = preg_replace( $pattern, "define( '{$name}', '{$escaped}' )", $raw, -1, $count );

		if ( 0 === $count ) {
			return array(
				'success' => false,
				'message' => __( 'Constant not found in wp-config.php.', 'acrossai-abilities-manager' ),
			);
		}

		// Feature 094: pre-image backup + audit log.
		$size_before   = strlen( $raw );
		$backup_result = Audit_Trail::write_backup( $config_path );
		$backup_path   = is_string( $backup_result ) ? $backup_result : null;
		$backup_status = is_string( $backup_result )
			? 'written'
			: ( false === $backup_result ? 'failed' : 'disabled' );

		if ( false === $fs->put_contents( $config_path, $updated, FS_CHMOD_FILE ) ) {
			Audit_Trail::write_log(
				'EDIT_WP_CONFIG',
				$config_path,
				array(
					'ability_slug'  => 'file-manager/edit-wp-config',
					'size_before'   => $size_before,
					'size_after'    => null,
					'backup_status' => $backup_status,
					'backup_path'   => $backup_path,
					'backup_reason' => 'primary write failed',
					'context'       => (string) ( $input['context'] ?? '' ),
				)
			);
			return array(
				'success'     => false,
				'message'     => __( 'Could not write wp-config.php.', 'acrossai-abilities-manager' ),
				'backup_path' => $backup_path,
			);
		}

		Audit_Trail::write_log(
			'EDIT_WP_CONFIG',
			$config_path,
			array(
				'ability_slug'  => 'file-manager/edit-wp-config',
				'size_before'   => $size_before,
				'size_after'    => strlen( $updated ),
				'backup_status' => $backup_status,
				'backup_path'   => $backup_path,
				'context'       => (string) ( $input['context'] ?? '' ),
			)
		);

		return array(
			'success'     => true,
			/* translators: constant name */
			'message'     => sprintf( __( 'Constant %s updated.', 'acrossai-abilities-manager' ), $name ),
			'backup_path' => $backup_path,
		);
	}

	/**
	 * Locate wp config via the initialised filesystem transport.
	 *
	 * @param \WP_Filesystem_Base $fs Initialised filesystem transport.
	 * @return ?string
	 */
	private function locate_wp_config( \WP_Filesystem_Base $fs ): ?string {
		$candidates = array(
			ABSPATH . 'wp-config.php',
			dirname( rtrim( ABSPATH, '/' ) ) . '/wp-config.php',
		);
		foreach ( $candidates as $path ) {
			if ( $fs->is_file( $path ) ) {
				return $path;
			}
		}
		return null;
	}
}
