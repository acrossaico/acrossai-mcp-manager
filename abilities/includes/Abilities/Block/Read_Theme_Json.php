<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_File;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a theme.json file directly from disk. Lighter sibling of the
 * Global_Styles suite — does NOT consult the wp_global_styles DB record.
 * Use Read_Global_Style when you want the effective merged result.
 */
class Read_Theme_Json extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/read-theme-json',
			'args' => array(
				'label'               => __( 'Read theme.json', 'acrossai-abilities-manager' ),
				'description'         => __( 'Returns the raw parsed contents of a theme.json file. Defaults to the active stylesheet; pass theme_slug to target a specific theme folder, or theme_type=parent to read the parent theme when a child is active.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'theme_slug' => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Theme folder name. Defaults to the active stylesheet.', 'acrossai-abilities-manager' ),
						),
						'theme_type' => array(
							'type'        => 'string',
							'enum'        => array( '', 'child', 'parent' ),
							'default'     => '',
							'description' => __( 'When a child theme is active, "parent" forces reading the parent theme.json. Ignored when theme_slug is set.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'theme'   => array( 'type' => 'string' ),
						'path'    => array( 'type' => 'string' ),
						'data'    => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'theme-json-settings',
						'sub_group_label' => __( 'theme.json Settings', 'acrossai-abilities-manager' ),
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
	 * Feature 095 follow-up — hint that a normalized design-token summary is
	 * far cheaper than the full theme.json when the caller wants design system
	 * info, not the raw spec.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'blocks/get-style-guide',
				'reason' => __( 'If you only need design tokens — palette, typography scale, spacing scale, gradients, duotones — the style guide returns a normalized summary without the full theme.json tree and WordPress internals.', 'acrossai-abilities-manager' ),
				'saves'  => __( '~5-8x fewer tokens for typical design-system reads', 'acrossai-abilities-manager' ),
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
		$theme_slug = sanitize_key( $input['theme_slug'] ?? '' );
		$theme_type = sanitize_text_field( $input['theme_type'] ?? '' );

		if ( '' !== $theme_slug ) {
			$dir = Global_Styles_File::resolve_theme_dir( $theme_slug );
			if ( is_wp_error( $dir ) ) {
				return array(
					'success' => false,
					'message' => $dir->get_error_message(),
				);
			}
			$theme = $theme_slug;
		} elseif ( 'parent' === $theme_type ) {
			$dir   = Global_Styles_File::get_parent_theme_dir();
			$theme = basename( $dir );
		} else {
			$child = Global_Styles_File::get_child_theme_dir();
			if ( null !== $child ) {
				$dir   = $child;
				$theme = basename( $child );
			} else {
				$dir   = Global_Styles_File::get_parent_theme_dir();
				$theme = basename( $dir );
			}
		}

		$path = Global_Styles_File::theme_json_path( $dir );
		if ( ! is_file( $path ) ) {
			return array(
				'success' => false,
				/* translators: %s: file path */
				'message' => sprintf( __( 'theme.json not found at %s.', 'acrossai-abilities-manager' ), $path ),
				'theme'   => $theme,
				'path'    => $path,
			);
		}

		$data = Global_Styles_File::read_json( $path );
		if ( is_wp_error( $data ) ) {
			return array(
				'success' => false,
				'message' => $data->get_error_message(),
				'theme'   => $theme,
				'path'    => $path,
			);
		}

		return array(
			'success' => true,
			'theme'   => $theme,
			'path'    => $path,
			'data'    => $data,
		);
	}
}
