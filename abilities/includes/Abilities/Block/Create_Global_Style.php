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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_Detector;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_File;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a Global Styles record. source=db (default) inserts a wp_global_styles
 * post; source=child_theme / theme / plugin writes a theme.json file.
 *
 * Scenarios enforced:
 *  - 12: refuses source=child_theme when no child theme is active.
 *  - 14, 15: every file write routes through Global_Styles_File::write_*,
 *    which calls File_Mods_Guard (DISALLOW_FILE_MODS, DISALLOW_FILE_EDIT,
 *    read-only path).
 *  - 18: section names validated against the canonical list.
 *  - 19: JSON parsed and structure-checked before saving.
 *  - 20: block names under styles.blocks must be registered.
 *  - 21: empty content rejected.
 *  - 22, 23: theme-association + multisite warnings surfaced.
 */
class Create_Global_Style extends Ability_Definition {

	/**
	 * Dotted JSON paths the section filter discarded on this call.
	 *
	 * @var array<int, string>
	 */
	private array $ignored = array();

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/create-global-style',
			'args' => array(
				'label'               => __( 'Create Global Style', 'acrossai-abilities-manager' ),
				'description'         => __( 'Creates Global Styles for a theme. Defaults to the database (wp_global_styles). Pass source=child_theme / theme / plugin to write theme.json. Provide either "content" (full theme.json JSON object or string) or "section" + "data" to seed a single section.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'theme'       => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Theme slug. Defaults to the active stylesheet.', 'acrossai-abilities-manager' ),
						),
						'source'      => array(
							'type'    => 'string',
							'enum'    => array( 'db', 'theme', 'child_theme', 'plugin' ),
							'default' => 'db',
						),
						'theme_slug'  => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Container theme folder for source=theme. Ignored otherwise.', 'acrossai-abilities-manager' ),
						),
						'plugin_slug' => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Container plugin folder for source=plugin.', 'acrossai-abilities-manager' ),
						),
						'content'     => array(
							'type'        => array( 'string', 'object' ),
							'description' => __( 'Full theme.json content as a JSON string or object. Cannot be empty.', 'acrossai-abilities-manager' ),
						),
						'section'     => array(
							'type' => 'string',
							'enum' => array( '', 'colors', 'typography', 'spacing', 'layout', 'blockStyles', 'elements', 'customCss' ),
						),
						'data'        => array(
							'type'        => array( 'string', 'object' ),
							'description' => __( 'Section data (string or object). Required when "section" is provided; ignored otherwise.', 'acrossai-abilities-manager' ),
						),
						'return_content' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When true, the response includes the saved theme.json JSON as record.data. Default false: the large JSON blob is stripped and content_bytes is returned instead, so a small edit does not echo the whole record back through the tunnel.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'record'        => array( 'type' => 'object' ),
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
						'sub_group'       => 'global-styles',
						'sub_group_label' => __( 'Global Styles', 'acrossai-abilities-manager' ),
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
		$this->ignored = array();

		$source      = sanitize_text_field( $input['source'] ?? 'db' );
		$theme       = sanitize_key( $input['theme'] ?? '' );
		$theme_slug  = sanitize_key( $input['theme_slug'] ?? '' );
		$plugin_slug = sanitize_key( $input['plugin_slug'] ?? '' );

		// File mods guard for any file destination.
		if ( in_array( $source, array( 'theme', 'child_theme', 'plugin' ), true ) ) {
			$blocked = File_Mods_Guard::blocked_response();
			if ( null !== $blocked ) {
				return $blocked;
			}
		}

		// Resolve the JSON payload from either "content" or "section"+"data".
		$payload = $this->resolve_payload( $input );
		if ( is_wp_error( $payload ) ) {
			return $this->error_response( $payload );
		}

		// Scenario 11 — refuse to overwrite an existing copy anywhere.
		$existing = Global_Styles_Detector::locate( $theme );
		if ( ! empty( $existing ) ) {
			return array(
				'success'   => false,
				/* translators: %s: theme */
				'message'   => sprintf( __( 'Global Styles already exist for theme "%s". Use global-styles-update.', 'acrossai-abilities-manager' ), '' !== $theme ? $theme : (string) get_stylesheet() ),
				'locations' => $existing,
			);
		}

		$return_content = ! empty( $input['return_content'] );

		switch ( $source ) {
			case 'db':
				return $this->create_db( $theme, $payload, $return_content );

			case 'child_theme':
				return $this->create_theme_file( $payload, true, '' );

			case 'theme':
				return $this->create_theme_file( $payload, false, $theme_slug );

			case 'plugin':
				return $this->create_plugin_file( $payload, $plugin_slug );
		}

		return array(
			'success' => false,
			'message' => __( 'Unknown source.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Returns the JSON payload as an array, applying section-only input when present.
	 *
	 * @return array|\WP_Error
	 */
	private function resolve_payload( array $input ) {
		$section = (string) ( $input['section'] ?? '' );
		if ( '' !== $section ) {
			$norm = Global_Styles_Db::normalize_section( $section );
			if ( ! Global_Styles_Db::valid_section( $norm ) ) {
				return new \WP_Error(
					'invalid_section',
					/* translators: %s: list of valid sections */
					sprintf( __( 'Invalid section. Allowed: %s.', 'acrossai-abilities-manager' ), implode( ', ', Global_Styles_Db::valid_sections() ) )
				);
			}
			$data = $this->coerce_array( $input['data'] ?? null );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( empty( $data ) ) {
				return new \WP_Error( 'empty_section_data', __( 'Section data is required when "section" is provided.', 'acrossai-abilities-manager' ) );
			}

			// Re-shape: place data at the section's paths.
			$payload = array();
			foreach ( Global_Styles_Db::SECTION_PATHS[ $norm ] as $path ) {
				$value = Global_Styles_Db::path_get( $data, $path );
				if ( null !== $value ) {
					Global_Styles_Db::path_set( $payload, $path, $value );
				}
			}

			// Same rule as update: data that falls outside the named section is not saved, so it is
			// named back to the caller rather than dropped in silence.
			$ignored = Update_Global_Style::dropped_paths( $data, $payload );
			if ( ! empty( $ignored ) ) {
				$this->ignored = $ignored;
			}

			if ( empty( $payload ) ) {
				return new \WP_Error(
					'section_data_outside_section',
					sprintf(
						/* translators: 1: section name, 2: comma-separated list of JSON paths. */
						__( 'Nothing to create: none of the data you sent belongs to section "%1$s". Ignored: %2$s.', 'acrossai-abilities-manager' ),
						$norm,
						implode( ', ', $ignored )
					)
				);
			}

			return $payload;
		}

		return $this->coerce_array( $input['content'] ?? null );
	}

	/**
	 * Accepts an array, an object, or a JSON string and returns an array.
	 *
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
	 * @param string $theme
	 * @param array $payload
	 * @param bool $return_content
	 * @return array
	 */
	private function create_db( string $theme, array $payload, bool $return_content ): array {
		$theme = '' !== $theme ? $theme : (string) get_stylesheet();

		$id = Global_Styles_Db::create( $theme, $payload );
		if ( is_wp_error( $id ) ) {
			return $this->error_response( $id );
		}

		$post     = get_post( (int) $id );
		$warnings = $this->ignored_warnings();
		if ( is_multisite() ) {
			$warnings[] = __( 'On multisite, this DB record is scoped to the current site only.', 'acrossai-abilities-manager' );
		}
		if ( $theme !== (string) get_stylesheet() ) {
			$warnings[] = __( 'You are creating styles for a non-active theme. Site visitors will only see them after that theme is activated.', 'acrossai-abilities-manager' );
		}

		$content_bytes = $post ? strlen( (string) $post->post_content ) : 0;

		return array(
			'success'       => true,
			/* translators: %s: theme */
			'message'       => sprintf( __( 'Created Global Styles record for theme "%s".', 'acrossai-abilities-manager' ), $theme ),
			'record'        => $post ? Global_Styles_Db::to_row( $post, $return_content ) : array(),
			'content_bytes' => $content_bytes,
			'warnings'      => $warnings,
		);
	}

	/**
	 * Create theme file.
	 *
	 * @param array $payload
	 * @param bool $force_child
	 * @param string $theme_slug
	 * @return array
	 */
	private function create_theme_file( array $payload, bool $force_child, string $theme_slug ): array {
		$warnings = array();
		if ( $force_child ) {
			$dir = Global_Styles_File::get_child_theme_dir();
			if ( null === $dir ) {
				return array(
					'success' => false,
					'message' => __( 'No child theme is active. Create a child theme first, or use source=db.', 'acrossai-abilities-manager' ),
				);
			}
		} else {
			$dir = '' !== $theme_slug ? Global_Styles_File::resolve_theme_dir( $theme_slug ) : Global_Styles_File::get_parent_theme_dir();
			if ( is_wp_error( $dir ) ) {
				return $this->error_response( $dir );
			}
			$child = Global_Styles_File::get_child_theme_dir();
			if ( null !== $child && $child !== $dir ) {
				$warnings[] = __( 'Writing to the parent theme — your changes will be lost when the theme updates. Prefer source=child_theme.', 'acrossai-abilities-manager' );
			}
		}

		// Validate before touching the disk.
		$valid = Global_Styles_Db::validate_data( $payload );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}
		$valid = Global_Styles_Db::validate_block_styles( $payload );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}

		$path = Global_Styles_File::theme_json_path( $dir );
		if ( file_exists( $path ) ) {
			return array(
				'success' => false,
				/* translators: %s: file path */
				'message' => sprintf( __( 'theme.json already exists at %s. Use global-styles-update.', 'acrossai-abilities-manager' ), $path ),
			);
		}

		$bytes = Global_Styles_File::write_json( $path, $payload );
		if ( is_wp_error( $bytes ) ) {
			return $this->error_response( $bytes );
		}

		$warnings   = array_merge( $warnings, $this->ignored_warnings() );
		$warnings[] = __( 'Site Editor saves will create a DB record that overrides this file copy on the next save.', 'acrossai-abilities-manager' );

		return array(
			'success'  => true,
			/* translators: %s: file path */
			'message'  => sprintf( __( 'Wrote theme.json to %s.', 'acrossai-abilities-manager' ), $path ),
			'record'   => array(
				'source'   => 'theme',
				'theme'    => basename( $dir ),
				'path'     => $path,
				'bytes'    => (int) $bytes,
				'writable' => is_writable( $path ),
			),
			'warnings' => $warnings,
		);
	}

	/**
	 * Create plugin file.
	 *
	 * @param array $payload
	 * @param string $plugin_slug
	 * @return array
	 */
	private function create_plugin_file( array $payload, string $plugin_slug ): array {
		if ( '' === $plugin_slug ) {
			return array(
				'success' => false,
				'message' => __( 'plugin_slug is required when source=plugin.', 'acrossai-abilities-manager' ),
			);
		}

		$plugin = Global_Styles_File::resolve_plugin_dir( $plugin_slug );
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

		$path = Global_Styles_File::theme_json_path( $plugin['path'] );
		if ( file_exists( $path ) ) {
			return array(
				'success' => false,
				/* translators: %s: file path */
				'message' => sprintf( __( 'theme.json already exists at %s. Use global-styles-update.', 'acrossai-abilities-manager' ), $path ),
			);
		}

		$bytes = Global_Styles_File::write_json( $path, $payload );
		if ( is_wp_error( $bytes ) ) {
			return $this->error_response( $bytes );
		}

		$warnings = $this->ignored_warnings();
		if ( ! $plugin['active'] ) {
			/* translators: %s: plugin slug */
			$warnings[] = sprintf( __( 'Plugin "%s" is inactive — its theme.json will not register until the plugin is activated.', 'acrossai-abilities-manager' ), $plugin_slug );
		}

		return array(
			'success'  => true,
			/* translators: %s: file path */
			'message'  => sprintf( __( 'Wrote plugin theme.json to %s.', 'acrossai-abilities-manager' ), $path ),
			'record'   => array(
				'source'        => 'plugin',
				'plugin'        => $plugin_slug,
				'plugin_active' => (bool) $plugin['active'],
				'path'          => $path,
				'bytes'         => (int) $bytes,
			),
			'warnings' => $warnings,
		);
	}

	/**
	 * Warnings for the keys the section filter discarded.
	 *
	 * @since  0.0.41
	 * @return array<int, string>
	 */
	private function ignored_warnings(): array {
		$warnings = array();
		foreach ( $this->ignored as $path ) {
			/* translators: %s: JSON path that was not saved. */
			$warnings[] = sprintf( __( 'ignored: %s — outside the section you named, so it was not saved.', 'acrossai-abilities-manager' ), $path );
		}

		return $warnings;
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
