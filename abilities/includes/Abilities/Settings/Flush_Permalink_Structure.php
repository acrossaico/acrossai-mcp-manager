<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Settings
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Settings;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Resets / flushes WordPress rewrite rules. Equivalent to clicking
 * "Save Changes" on Settings → Permalinks without changing the structure.
 *
 * hard=true regenerates .htaccess on Apache hosts (and equivalent on IIS);
 * hard=false (default) only rebuilds the in-DB rewrite cache.
 */
class Flush_Permalink_Structure extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'settings/flush-permalink-structure',
			'args' => array(
				'label'               => __( 'Reset / Flush Permalinks', 'acrossai-abilities-manager' ),
				'description'         => __( 'Rebuilds WordPress rewrite rules — useful after registering custom post types, taxonomies, or rewrite endpoints. Pass hard=true to also regenerate .htaccess (Apache) where supported.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-settings',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'hard' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Regenerate .htaccess on Apache (and equivalent on IIS).', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'   => array( 'type' => 'boolean' ),
						'message'   => array( 'type' => 'string' ),
						'hard'      => array( 'type' => 'boolean' ),
						'structure' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'configuration',
						'sub_group'       => 'permalinks',
						'sub_group_label' => __( 'Permalinks', 'acrossai-abilities-manager' ),
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
		$hard = ! empty( $input['hard'] );

		flush_rewrite_rules( $hard );

		return array(
			'success'   => true,
			'message'   => $hard
				? __( 'Rewrite rules flushed and .htaccess regenerated where supported.', 'acrossai-abilities-manager' )
				: __( 'Rewrite rules flushed.', 'acrossai-abilities-manager' ),
			'hard'      => $hard,
			'structure' => (string) get_option( 'permalink_structure', '' ),
		);
	}
}
