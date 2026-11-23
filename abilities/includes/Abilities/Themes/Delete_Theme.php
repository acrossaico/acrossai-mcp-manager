<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Themes
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Themes;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Theme_Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Delete_Theme ability class (absorbed).
 */
class Delete_Theme extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'themes/delete-theme',
			'args' => array(
				'label'               => __( 'Delete Theme', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete an installed WordPress theme by name, stylesheet, or partial match. The active theme cannot be deleted.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-themes',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'theme'      => array(
							'type'        => 'string',
							'description' => __( 'Theme name, stylesheet directory (e.g. twentytwentyfour), or partial match.', 'acrossai-abilities-manager' ),
						),
						'slug'       => array(
							'type'        => 'string',
							'description' => __( 'Alias for "theme". If multiple are provided, "theme" wins, then "stylesheet", then "slug".', 'acrossai-abilities-manager' ),
						),
						'stylesheet' => array(
							'type'        => 'string',
							'description' => __( 'Alias for "theme" (matches WordPress core terminology).', 'acrossai-abilities-manager' ),
						),
					),
					'anyOf'                => array(
						array( 'required' => array( 'theme' ) ),
						array( 'required' => array( 'stylesheet' ) ),
						array( 'required' => array( 'slug' ) ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'message'       => array( 'type' => 'string' ),
						'matched_theme' => array( 'type' => 'string' ),
						'certainty'     => array( 'type' => 'number' ),
					),
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'updates',
						'sub_group'       => 'lifecycle',
						'sub_group_label' => __( 'Lifecycle', 'acrossai-abilities-manager' ),
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
		$blocked = File_Mods_Guard::blocked_response( 'install' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$raw_theme = ! empty( $input['theme'] )
			? $input['theme']
			: ( ! empty( $input['stylesheet'] ) ? $input['stylesheet'] : ( $input['slug'] ?? '' ) );

		if ( empty( $raw_theme ) ) {
			return array(
				'success' => false,
				'message' => __( 'No theme specified. Pass "theme" (or its aliases "stylesheet"/"slug").', 'acrossai-abilities-manager' ),
			);
		}

		return Theme_Helpers::delete_theme_by_slug( $raw_theme );
	}
}
