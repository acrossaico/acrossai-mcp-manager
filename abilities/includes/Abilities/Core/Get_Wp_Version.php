<?php
/**
 * Site introspection read — WordPress core version (Feature 063).
 *
 * Returns the currently-installed WordPress core version string alongside
 * a boolean indicating whether the install is a multisite network.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Core
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Core;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Get_Wp_Version ability class.
 */
class Get_Wp_Version extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'core/get-wp-version',
			'args' => array(
				'label'               => __( 'Get WordPress Version', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the currently-installed WordPress core version string and a boolean indicating whether the install is multisite.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-core',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'version'      => array( 'type' => 'string' ),
						'is_multisite' => array( 'type' => 'boolean' ),
						'message'      => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'updates',
						'sub_group'       => 'introspection',
						'sub_group_label' => __( 'Introspection', 'acrossai-abilities-manager' ),
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
		return array(
			'success'      => true,
			'version'      => (string) get_bloginfo( 'version' ),
			'is_multisite' => is_multisite(),
			'message'      => __( 'WordPress version fetched.', 'acrossai-abilities-manager' ),
		);
	}
}
