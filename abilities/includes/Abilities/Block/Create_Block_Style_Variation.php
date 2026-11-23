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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Style_Variations\Variation_Db;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Style_Variations\Variation_Detector;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Style_Variations\Variation_File;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_Db;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a new Block Style Variation. source=db (default) inserts a
 * wp_global_styles variation; source=child_theme / theme / plugin writes
 * a <slug>.json file under /styles.
 *
 * Scenarios enforced:
 *  - 12, 13: child-theme presence checked when source=child_theme.
 *  - 15, 16: file writes routed through Variation_File which calls
 *    File_Mods_Guard.
 *  - 19, 20, 21: section/JSON/block-styles validation.
 *  - 22: empty content rejected.
 *  - 23: duplicate-slug check against every location.
 */
class Create_Block_Style_Variation extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/create-block-style-variation',
			'args' => array(
				'label'               => __( 'Create Block Style Variation', 'acrossai-abilities-manager' ),
				'description'         => __( 'Creates a Block Style Variation. Defaults to the database. Pass source=child_theme / theme / plugin to write a <slug>.json file. Provide "content" (full variation JSON) or "section"+"data" to seed one section.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'Variation slug (e.g. "dark", "high-contrast"). Lowercase letters, digits, dashes, underscores.', 'acrossai-abilities-manager' ),
						),
						'title'       => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'theme'       => array(
							'type'    => 'string',
							'default' => '',
						),
						'source'      => array(
							'type'    => 'string',
							'enum'    => array( 'db', 'theme', 'child_theme', 'plugin' ),
							'default' => 'db',
						),
						'theme_slug'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'plugin_slug' => array(
							'type'    => 'string',
							'default' => '',
						),
						'content'     => array(
							'type'        => array( 'string', 'object' ),
							'description' => __( 'Full variation content as JSON string or object.', 'acrossai-abilities-manager' ),
						),
						'section'     => array(
							'type' => 'string',
							'enum' => array( '', 'colors', 'typography', 'spacing', 'layout', 'blockStyles' ),
						),
						'data'        => array(
							'type'        => array( 'string', 'object' ),
							'description' => __( 'Section data; required when "section" is provided.', 'acrossai-abilities-manager' ),
						),
						'return_content' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When true, the response includes the saved variation JSON as variation.data. Default false: the large JSON blob is stripped and content_bytes is returned instead, so a small edit does not echo the whole variation back through the tunnel.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'variation'     => array( 'type' => 'object' ),
						'content_bytes' => array( 'type' => 'integer' ),
						'warnings'      => array( 'type' => 'array' ),
						'locations'     => array( 'type' => 'array' ),
						'message'       => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'block-style-variations',
						'sub_group_label' => __( 'Block Style Variations', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
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
		$slug        = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$source      = sanitize_text_field( $input['source'] ?? 'db' );
		$theme       = sanitize_key( $input['theme'] ?? '' );
		$theme_slug  = sanitize_key( $input['theme_slug'] ?? '' );
		$plugin_slug = sanitize_key( $input['plugin_slug'] ?? '' );

		if ( '' === $slug || ! Variation_File::is_valid_bare_slug( $slug ) ) {
			return array(
				'success' => false,
				'message' => __( 'Slug is invalid. Use lowercase letters, digits, dashes, or underscores.', 'acrossai-abilities-manager' ),
			);
		}

		// File-mods guard for any file destination (Scenario 16).
		if ( in_array( $source, array( 'theme', 'child_theme', 'plugin' ), true ) ) {
			$blocked = File_Mods_Guard::blocked_response();
			if ( null !== $blocked ) {
				return $blocked;
			}
		}

		$payload = $this->resolve_payload( $input );
		if ( is_wp_error( $payload ) ) {
			return $this->error_response( $payload );
		}

		// Scenario 23 — duplicate-slug check across every location.
		$existing = Variation_Detector::locate( $slug, $theme );
		if ( ! empty( $existing ) ) {
			return array(
				'success'   => false,
				/* translators: %s: slug */
				'message'   => sprintf( __( 'A Block Style Variation with slug "%s" already exists. Use update or pick a different slug.', 'acrossai-abilities-manager' ), $slug ),
				'locations' => $existing,
			);
		}

		$return_content = ! empty( $input['return_content'] );

		switch ( $source ) {
			case 'db':
				return $this->create_db( $slug, $payload, $theme, $input, $return_content );
			case 'child_theme':
				return $this->create_theme_file( $slug, $payload, true, '', $input );
			case 'theme':
				return $this->create_theme_file( $slug, $payload, false, $theme_slug, $input );
			case 'plugin':
				return $this->create_plugin_file( $slug, $payload, $plugin_slug, $input );
		}

		return array(
			'success' => false,
			'message' => __( 'Unknown source.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @return array|\WP_Error
	 */
	private function resolve_payload( array $input ) {
		$section = (string) ( $input['section'] ?? '' );
		if ( '' !== $section ) {
			$norm = Variation_Db::normalize_section( $section );
			if ( ! Variation_Db::valid_section( $norm ) ) {
				return new \WP_Error(
					'invalid_section',
					/* translators: %s: list of valid sections */
					sprintf( __( 'Invalid section. Allowed: %s.', 'acrossai-abilities-manager' ), implode( ', ', Variation_Db::valid_sections() ) )
				);
			}
			$data = $this->coerce_array( $input['data'] ?? null );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( empty( $data ) ) {
				return new \WP_Error( 'empty_section_data', __( 'Section data is required when "section" is provided.', 'acrossai-abilities-manager' ) );
			}
			$payload = array();
			foreach ( Variation_Db::SECTION_PATHS[ $norm ] as $path ) {
				$value = Global_Styles_Db::path_get( $data, $path );
				if ( null !== $value ) {
					Global_Styles_Db::path_set( $payload, $path, $value );
				}
			}
			return $payload;
		}

		return $this->coerce_array( $input['content'] ?? null );
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
	 * Create db.
	 *
	 * @param string $slug
	 * @param array $payload
	 * @param string $theme
	 * @param array $input
	 * @param bool $return_content
	 * @return array
	 */
	private function create_db( string $slug, array $payload, string $theme, array $input, bool $return_content ): array {
		$theme = '' !== $theme ? $theme : (string) get_stylesheet();
		$id    = Variation_Db::create(
			$theme,
			$slug,
			$payload,
			array(
				'title'       => (string) ( $input['title'] ?? '' ),
				'description' => (string) ( $input['description'] ?? '' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $this->error_response( $id );
		}

		$post     = get_post( (int) $id );
		$warnings = array();
		if ( is_multisite() ) {
			$warnings[] = __( 'On multisite, this DB variation is scoped to the current site only.', 'acrossai-abilities-manager' );
		}
		if ( $theme !== (string) get_stylesheet() ) {
			$warnings[] = __( 'You are creating a variation for a non-active theme — it will only appear after that theme is activated.', 'acrossai-abilities-manager' );
		}

		$content_bytes = $post ? strlen( (string) $post->post_content ) : 0;

		return array(
			'success'       => true,
			/* translators: 1: slug, 2: theme */
			'message'       => sprintf( __( 'Created variation "%1$s" for theme "%2$s".', 'acrossai-abilities-manager' ), $slug, $theme ),
			'variation'     => $post ? Variation_Db::to_row( $post, $return_content ) : array(),
			'content_bytes' => $content_bytes,
			'warnings'      => $warnings,
		);
	}

	/**
	 * Create theme file.
	 *
	 * @param string $slug
	 * @param array $payload
	 * @param bool $force_child
	 * @param string $theme_slug
	 * @param array $input
	 * @return array
	 */
	private function create_theme_file( string $slug, array $payload, bool $force_child, string $theme_slug, array $input ): array {
		$warnings = array();
		if ( $force_child ) {
			$dir = Variation_File::get_child_theme_dir();
			if ( null === $dir ) {
				return array(
					'success' => false,
					'message' => __( 'No child theme is active. Create a child theme first, or use source=db.', 'acrossai-abilities-manager' ),
				);
			}
		} else {
			$dir = '' !== $theme_slug ? Variation_File::resolve_theme_dir( $theme_slug ) : Variation_File::get_parent_theme_dir();
			if ( is_wp_error( $dir ) ) {
				return $this->error_response( $dir );
			}
			$child = Variation_File::get_child_theme_dir();
			if ( null !== $child && $child !== $dir ) {
				$warnings[] = __( 'Writing to the parent theme — changes will be lost when the theme updates. Prefer source=child_theme.', 'acrossai-abilities-manager' );
			}
		}

		$valid = Global_Styles_Db::validate_data( $payload );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}
		$valid = Global_Styles_Db::validate_block_styles( $payload );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}

		$styles_dir = Variation_File::ensure_styles_dir( $dir );
		if ( is_wp_error( $styles_dir ) ) {
			return $this->error_response( $styles_dir );
		}

		$abs = Variation_File::resolve_variation_path( $dir, $slug );
		if ( is_wp_error( $abs ) ) {
			return $this->error_response( $abs );
		}

		$bytes = Variation_File::write_json( $abs, $payload );
		if ( is_wp_error( $bytes ) ) {
			return $this->error_response( $bytes );
		}

		$child      = Variation_File::get_child_theme_dir();
		$is_child   = ( null !== $child && $dir === $child );
		$warnings[] = __( 'Site Editor saves create a wp_global_styles DB record that will override this file copy.', 'acrossai-abilities-manager' );

		return array(
			'success'   => true,
			/* translators: 1: slug, 2: file path */
			'message'   => sprintf( __( 'Wrote variation "%1$s" to %2$s.', 'acrossai-abilities-manager' ), $slug, $abs ),
			'variation' => array(
				'source'     => 'theme',
				'theme_type' => $is_child ? 'child' : ( null !== $child ? 'parent' : 'theme' ),
				'theme'      => basename( $dir ),
				'slug'       => $slug,
				'path'       => $abs,
				'bytes'      => (int) $bytes,
			),
			'warnings'  => $warnings,
		);
	}

	/**
	 * Create plugin file.
	 *
	 * @param string $slug
	 * @param array $payload
	 * @param string $plugin_slug
	 * @param array $input
	 * @return array
	 */
	private function create_plugin_file( string $slug, array $payload, string $plugin_slug, array $input ): array {
		if ( '' === $plugin_slug ) {
			return array(
				'success' => false,
				'message' => __( 'plugin_slug is required when source=plugin.', 'acrossai-abilities-manager' ),
			);
		}

		$plugin = Variation_File::resolve_plugin_dir( $plugin_slug );
		if ( is_wp_error( $plugin ) ) {
			return $this->error_response( $plugin );
		}

		$valid = Global_Styles_Db::validate_data( $payload );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}
		$valid = Global_Styles_Db::validate_block_styles( $payload );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}

		$warnings = array();
		if ( ! $plugin['active'] ) {
			/* translators: %s: plugin slug */
			$warnings[] = sprintf( __( 'Plugin "%s" is inactive — the variation will not register until the plugin is activated.', 'acrossai-abilities-manager' ), $plugin_slug );
		}

		$styles_dir = Variation_File::ensure_styles_dir( $plugin['path'] );
		if ( is_wp_error( $styles_dir ) ) {
			return $this->error_response( $styles_dir );
		}

		$abs = Variation_File::resolve_variation_path( $plugin['path'], $slug );
		if ( is_wp_error( $abs ) ) {
			return $this->error_response( $abs );
		}

		$bytes = Variation_File::write_json( $abs, $payload );
		if ( is_wp_error( $bytes ) ) {
			return $this->error_response( $bytes );
		}

		return array(
			'success'   => true,
			/* translators: 1: slug, 2: file path */
			'message'   => sprintf( __( 'Wrote variation "%1$s" to %2$s.', 'acrossai-abilities-manager' ), $slug, $abs ),
			'variation' => array(
				'source'        => 'plugin',
				'plugin'        => $plugin_slug,
				'plugin_active' => (bool) $plugin['active'],
				'slug'          => $slug,
				'path'          => $abs,
				'bytes'         => (int) $bytes,
			),
			'warnings'  => $warnings,
		);
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
