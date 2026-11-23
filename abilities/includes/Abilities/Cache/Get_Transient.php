<?php
/**
 * Feature 064 — Read one transient by name.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Cache
 * @since      0.0.23
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Cache;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Read one transient by name.
 *
 * Distinguishes an unset transient from a transient whose stored value is
 * literally `false` by consulting the underlying option row for existence.
 * Reads go through the WP core transient APIs so external object caches
 * (Redis / Memcached) are honoured.
 */
class Get_Transient extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cache/get-transient',
			'args' => array(
				'label'               => __( 'Get Transient', 'acrossai-abilities-manager' ),
				'description'         => __( 'Read one transient by name via get_transient() (or get_site_transient() when site:true). Returns exists:false for absent entries so callers can distinguish that state from a transient whose stored value is literally false.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cache',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'key'  => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'site' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'key' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'exists'     => array( 'type' => 'boolean' ),
						'value'      => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ) ),
						'expires_at' => array( 'type' => array( 'integer', 'null' ) ),
						'message'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group' => 'cache',
						'sub_group' => 'cache',
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
		$key = sanitize_text_field( (string) ( $input['key'] ?? '' ) );
		if ( '' === $key ) {
			return array(
				'success' => false,
				'message' => __( 'key is required.', 'acrossai-abilities-manager' ),
			);
		}

		$site        = ! empty( $input['site'] );
		$option_name = $site ? "_site_transient_{$key}" : "_transient_{$key}";
		$timeout_key = $site ? "_site_transient_timeout_{$key}" : "_transient_timeout_{$key}";

		$exists = null !== get_option( $option_name, null );
		if ( ! $exists ) {
			return array(
				'success'    => true,
				'exists'     => false,
				'value'      => null,
				'expires_at' => null,
				'message'    => sprintf(
					/* translators: %s: transient key */
					__( 'Transient "%s" does not exist.', 'acrossai-abilities-manager' ),
					$key
				),
			);
		}

		$value      = $site ? get_site_transient( $key ) : get_transient( $key );
		$timeout    = (int) get_option( $timeout_key, 0 );
		$expires_at = $timeout > 0 ? $timeout : null;

		return array(
			'success'    => true,
			'exists'     => true,
			'value'      => $value,
			'expires_at' => $expires_at,
			'message'    => sprintf(
				/* translators: %s: transient key */
				__( 'Read transient "%s".', 'acrossai-abilities-manager' ),
				$key
			),
		);
	}
}
