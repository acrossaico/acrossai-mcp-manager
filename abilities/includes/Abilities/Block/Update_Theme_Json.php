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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_Db;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_File;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a theme.json file directly to disk. Lighter sibling of the
 * Global_Styles suite — bypasses the wp_global_styles DB record.
 *
 * Refuses to edit the parent theme (creates the child theme.json instead is
 * NOT done here; use Update_Global_Style with migrate_to=child_theme for
 * that flow). DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT honoured via
 * File_Mods_Guard. Content is validated as JSON and against the basic
 * theme.json shape before any write.
 */
class Update_Theme_Json extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/update-theme-json',
			'args' => array(
				'label'               => __( 'Update theme.json', 'acrossai-abilities-manager' ),
				'description'         => __( 'Writes a theme.json file directly. Defaults to the active child theme (or single theme); refuses to edit the parent theme. By default deep-merges into the existing file; pass merge=false to replace.', 'acrossai-abilities-manager' ),
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
							'description' => __( 'Target theme folder. Defaults to the active child theme; falls back to the parent when no child is active.', 'acrossai-abilities-manager' ),
						),
						'content'    => array(
							'type'        => array( 'string', 'object' ),
							'description' => __( 'theme.json content as a JSON string or object. Cannot be empty.', 'acrossai-abilities-manager' ),
						),
						'merge'      => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'true deep-merges with the existing file; false replaces it.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'theme'    => array( 'type' => 'string' ),
						'path'     => array( 'type' => 'string' ),
						'bytes'    => array( 'type' => 'integer' ),
						'warnings' => array( 'type' => 'array' ),
						'message'  => array( 'type' => 'string' ),
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

		$theme_slug = sanitize_key( $input['theme_slug'] ?? '' );
		$merge      = ! isset( $input['merge'] ) || (bool) $input['merge'];

		// Resolve target directory.
		$warnings = array();
		if ( '' !== $theme_slug ) {
			$dir = Global_Styles_File::resolve_theme_dir( $theme_slug );
			if ( is_wp_error( $dir ) ) {
				return array(
					'success' => false,
					'message' => $dir->get_error_message(),
				);
			}
			$child      = Global_Styles_File::get_child_theme_dir();
			$parent_dir = Global_Styles_File::get_parent_theme_dir();
			if ( null !== $child && $dir === $parent_dir ) {
				return array(
					'success' => false,
					'message' => __( 'Refusing to edit the parent theme directly when a child theme is active. Write to the child theme or use Global Styles instead.', 'acrossai-abilities-manager' ),
				);
			}
		} else {
			$dir = Global_Styles_File::get_child_theme_dir();
			if ( null === $dir ) {
				$dir        = Global_Styles_File::get_parent_theme_dir();
				$warnings[] = __( 'No child theme is active — writing to the parent theme. Updates will be lost when the theme is upgraded. Create a child theme to manage theme.json safely.', 'acrossai-abilities-manager' );
			}
		}

		// Coerce + validate payload.
		$payload = $this->coerce_array( $input['content'] ?? null );
		if ( is_wp_error( $payload ) ) {
			return $this->error_response( $payload );
		}

		$path     = Global_Styles_File::theme_json_path( $dir );
		$existing = is_file( $path ) ? Global_Styles_File::read_json( $path ) : array();
		if ( is_wp_error( $existing ) ) {
			return $this->error_response( $existing );
		}

		$next = $merge ? Global_Styles_Db::deep_merge( is_array( $existing ) ? $existing : array(), $payload ) : $payload;

		$valid = Global_Styles_Db::validate_data( $next );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}
		$valid = Global_Styles_Db::validate_block_styles( $next );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}

		$bytes = Global_Styles_File::write_json( $path, $next );
		if ( is_wp_error( $bytes ) ) {
			return $this->error_response( $bytes );
		}

		$warnings[] = __( 'Site Editor saves will create a wp_global_styles DB record that overrides this file on the next save.', 'acrossai-abilities-manager' );

		return array(
			'success'  => true,
			/* translators: %s: file path */
			'message'  => sprintf( __( 'Wrote theme.json to %s.', 'acrossai-abilities-manager' ), $path ),
			'theme'    => basename( $dir ),
			'path'     => $path,
			'bytes'    => (int) $bytes,
			'warnings' => $warnings,
		);
	}

	/**
	 * @return array|\WP_Error
	 */
	private function coerce_array( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return Global_Styles_Db::parse_json( $value );
		}
		if ( is_object( $value ) ) {
			return json_decode( wp_json_encode( $value ), true ) ?: array();
		}
		return new \WP_Error( 'missing_content', __( 'Content is required.', 'acrossai-abilities-manager' ) );
	}

	/**
	 * Error response.
	 *
	 * @param \WP_Error $err
	 * @return array
	 */
	private function error_response( \WP_Error $err ): array {
		return array(
			'success' => false,
			'message' => $err->get_error_message(),
		);
	}
}
