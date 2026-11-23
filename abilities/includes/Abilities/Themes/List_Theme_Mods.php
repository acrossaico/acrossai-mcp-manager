<?php
/**
 * Site introspection read — stored theme modifications (Feature 063).
 *
 * Returns the active theme's stylesheet identifier and the full map of
 * stored theme modifications from the theme_mods_<stylesheet> option.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Themes
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Themes;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * List_Theme_Mods ability class.
 */
class List_Theme_Mods extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'themes/list-theme-mods',
			'args' => array(
				'label'               => __( 'List Theme Mods', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the active theme stylesheet identifier and the full map of stored theme modifications (Customizer values, header image, etc.).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-themes',
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
						'success' => array( 'type' => 'boolean' ),
						'theme'   => array( 'type' => 'string' ),
						'mods'    => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
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
		$mods = get_theme_mods();
		if ( ! is_array( $mods ) ) {
			$mods = array();
		}

		return array(
			'success' => true,
			'theme'   => (string) get_stylesheet(),
			'mods'    => (object) $mods,
			'message' => __( 'Theme modifications fetched.', 'acrossai-abilities-manager' ),
		);
	}
}
