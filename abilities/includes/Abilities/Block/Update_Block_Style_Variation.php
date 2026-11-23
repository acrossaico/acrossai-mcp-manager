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
 * Updates an existing Block Style Variation.
 *
 *  - Auto-detects the location; pass source / theme_type / plugin_slug to disambiguate.
 *  - Scenario 3: refuses to write to the parent theme directly.
 *  - Scenario 12: pass new_slug to rename (DB post_name OR .json filename).
 *  - Scenarios 30, 31: migrate_to=db|child_theme + delete_source for cross-source moves.
 *  - Scenarios 15, 16: file writes routed through Variation_File → File_Mods_Guard.
 *  - Scenarios 19, 20, 21, 22: JSON / block-style / section / empty validation.
 *  - merge=true (default) deep-merges; merge=false replaces.
 */
class Update_Block_Style_Variation extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/update-block-style-variation',
			'args' => array(
				'label'               => __( 'Update Block Style Variation', 'acrossai-abilities-manager' ),
				'description'         => __( 'Updates a Block Style Variation. Auto-detects location; supports section-scoped updates, rename via new_slug, and cross-source migration via migrate_to.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'          => array( 'type' => 'string' ),
						'theme'         => array(
							'type'    => 'string',
							'default' => '',
						),
						'source'        => array(
							'type'    => 'string',
							'enum'    => array( '', 'db', 'theme', 'child_theme', 'plugin' ),
							'default' => '',
						),
						'theme_type'    => array(
							'type'    => 'string',
							'enum'    => array( '', 'child', 'parent', 'theme' ),
							'default' => '',
						),
						'plugin_slug'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'content'       => array(
							'type'        => array( 'string', 'object' ),
							'description' => __( 'Full or partial variation content.', 'acrossai-abilities-manager' ),
						),
						'section'       => array(
							'type' => 'string',
							'enum' => array( '', 'colors', 'typography', 'spacing', 'layout', 'blockStyles' ),
						),
						'data'          => array(
							'type'        => array( 'string', 'object' ),
							'description' => __( 'Section data; required when "section" is provided.', 'acrossai-abilities-manager' ),
						),
						'merge'         => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'title'         => array( 'type' => 'string' ),
						'description'   => array( 'type' => 'string' ),
						'new_slug'      => array(
							'type'        => 'string',
							'description' => __( 'Rename to this slug. Renames the .json file or DB post_name.', 'acrossai-abilities-manager' ),
						),
						'migrate_to'    => array(
							'type'    => 'string',
							'enum'    => array( '', 'db', 'child_theme' ),
							'default' => '',
						),
						'delete_source' => array(
							'type'    => 'boolean',
							'default' => false,
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
						'migrated'      => array( 'type' => 'boolean' ),
						'warnings'      => array( 'type' => 'array' ),
						'locations'     => array( 'type' => 'array' ),
						'candidates'    => array( 'type' => 'array' ),
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
		$slug          = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$theme         = sanitize_key( $input['theme'] ?? '' );
		$source        = sanitize_text_field( $input['source'] ?? '' );
		$theme_type    = sanitize_text_field( $input['theme_type'] ?? '' );
		$plugin_slug   = sanitize_key( $input['plugin_slug'] ?? '' );
		$migrate_to    = sanitize_text_field( $input['migrate_to'] ?? '' );
		$delete_source = ! empty( $input['delete_source'] );
		$merge         = ! isset( $input['merge'] ) || (bool) $input['merge'];

		if ( '' === $slug ) {
			return array(
				'success' => false,
				'message' => __( 'Slug is required.', 'acrossai-abilities-manager' ),
			);
		}

		$locations = Variation_Detector::locate( $slug, $theme );
		if ( empty( $locations ) ) {
			return array(
				'success'   => false,
				/* translators: %s: slug */
				'message'   => sprintf( __( 'No Block Style Variation with slug "%s" was found. Use block-style-variations-create.', 'acrossai-abilities-manager' ), $slug ),
				'locations' => array(),
			);
		}

		$selected = Variation_Detector::select( $locations, $source, $theme_type, $plugin_slug );
		if ( is_wp_error( $selected ) ) {
			$data = $selected->get_error_data();
			return array(
				'success'    => false,
				'message'    => $selected->get_error_message(),
				'locations'  => $locations,
				'candidates' => is_array( $data ) ? ( $data['locations'] ?? array() ) : array(),
			);
		}

		$selected_src = (string) ( $selected['source'] ?? '' );
		if ( ( 'theme' === $selected_src || 'plugin' === $selected_src ) || '' !== $migrate_to ) {
			$blocked = File_Mods_Guard::blocked_response();
			if ( null !== $blocked ) {
				return $blocked;
			}
		}

		$payload = $this->resolve_payload( $input );
		if ( is_wp_error( $payload ) ) {
			return $this->error_response( $payload );
		}

		$return_content = ! empty( $input['return_content'] );

		if ( '' !== $migrate_to ) {
			return $this->migrate( $selected, $migrate_to, $delete_source, $payload, $input, $return_content );
		}

		if ( 'theme' === $selected_src && 'parent' === ( $selected['theme_type'] ?? '' ) ) {
			return array(
				'success'   => false,
				'message'   => __( 'Refusing to edit the parent theme directly. Re-run with migrate_to=child_theme or migrate_to=db.', 'acrossai-abilities-manager' ),
				'locations' => $locations,
			);
		}

		switch ( $selected_src ) {
			case 'db':
				return $this->update_db( $selected, $payload, $merge, $input, $return_content );
			case 'theme':
			case 'plugin':
				return $this->update_file( $selected, $payload, $merge, $input );
		}

		return array(
			'success' => false,
			'message' => __( 'Unknown source.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Update db.
	 *
	 * @param array $loc
	 * @param array $payload
	 * @param bool $merge
	 * @param array $input
	 * @param bool $return_content
	 * @return array
	 */
	private function update_db( array $loc, array $payload, bool $merge, array $input, bool $return_content ): array {
		$post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return array(
				'success' => false,
				'message' => __( 'wp_global_styles variation post not found.', 'acrossai-abilities-manager' ),
			);
		}

		$extras = array();
		foreach ( array( 'title', 'description', 'new_slug' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$extras[ $key ] = (string) $input[ $key ];
			}
		}

		$result = Variation_Db::update( $post, $payload, $merge, $extras );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		$updated       = get_post( (int) $result );
		$content_bytes = $updated ? strlen( (string) $updated->post_content ) : 0;

		return array(
			'success'       => true,
			'message'       => __( 'Updated DB variation.', 'acrossai-abilities-manager' ),
			'variation'     => $updated ? Variation_Db::to_row( $updated, $return_content ) : array(),
			'content_bytes' => $content_bytes,
			'warnings'      => array(),
		);
	}

	/**
	 * Update file.
	 *
	 * @param array $loc
	 * @param array $payload
	 * @param bool $merge
	 * @param array $input
	 * @return array
	 */
	private function update_file( array $loc, array $payload, bool $merge, array $input ): array {
		$path     = (string) ( $loc['path'] ?? '' );
		$existing = Variation_File::read_json( $path );
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

		$bytes = Variation_File::write_json( $path, $next );
		if ( is_wp_error( $bytes ) ) {
			return $this->error_response( $bytes );
		}

		// Scenario 12 — rename .json file when new_slug provided.
		$final_path = $path;
		$final_slug = (string) ( $loc['slug'] ?? '' );
		if ( ! empty( $input['new_slug'] ) ) {
			$new_slug = sanitize_title( (string) $input['new_slug'] );
			if ( '' === $new_slug || ! Variation_File::is_valid_bare_slug( $new_slug ) ) {
				return array(
					'success' => false,
					'message' => __( 'new_slug is invalid.', 'acrossai-abilities-manager' ),
				);
			}
			$container = dirname( dirname( $path ) );
			$new_abs   = Variation_File::resolve_variation_path( $container, $new_slug );
			if ( is_wp_error( $new_abs ) ) {
				return $this->error_response( $new_abs );
			}
			if ( file_exists( $new_abs ) && $new_abs !== $path ) {
				return array(
					'success' => false,
					/* translators: %s: target path */
					'message' => sprintf( __( 'Target slug already has a file at %s.', 'acrossai-abilities-manager' ), $new_abs ),
				);
			}
			if ( ! @rename( $path, $new_abs ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return array(
					'success' => false,
					'message' => __( 'Failed to rename variation file.', 'acrossai-abilities-manager' ),
				);
			}
			$final_path = $new_abs;
			$final_slug = $new_slug;
		}

		$warnings = array();
		if ( 'plugin' === ( $loc['source'] ?? '' ) && false === ( $loc['plugin_active'] ?? true ) ) {
			/* translators: %s: plugin slug */
			$warnings[] = sprintf( __( 'Plugin "%s" is inactive — your edit will only take effect once the plugin is activated.', 'acrossai-abilities-manager' ), $loc['plugin'] ?? '' );
		}

		return array(
			'success'   => true,
			/* translators: %s: slug */
			'message'   => sprintf( __( 'Updated variation "%s".', 'acrossai-abilities-manager' ), $final_slug ),
			'variation' => array(
				'source'        => (string) ( $loc['source'] ?? '' ),
				'theme'         => (string) ( $loc['theme'] ?? '' ),
				'theme_type'    => (string) ( $loc['theme_type'] ?? '' ),
				'plugin'        => (string) ( $loc['plugin'] ?? '' ),
				'plugin_active' => (bool) ( $loc['plugin_active'] ?? false ),
				'slug'          => $final_slug,
				'path'          => $final_path,
				'bytes'         => (int) $bytes,
			),
			'warnings'  => $warnings,
		);
	}

	/**
	 * Cross-source migration (Scenarios 30, 31).
	 */
	private function migrate( array $loc, string $migrate_to, bool $delete_source, array $payload, array $input, bool $return_content ): array {
		$slug     = (string) ( $loc['slug'] ?? '' );
		$src      = (string) ( $loc['source'] ?? '' );
		$theme    = sanitize_key( $input['theme'] ?? '' );
		$warnings = array();

		if ( 'db' === $src ) {
			$post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
			if ( ! $post ) {
				return array(
					'success' => false,
					'message' => __( 'Source DB variation not found.', 'acrossai-abilities-manager' ),
				);
			}
			$existing = Variation_Db::decode_content( $post );
		} else {
			$read = Variation_File::read_json( (string) ( $loc['path'] ?? '' ) );
			if ( is_wp_error( $read ) ) {
				return $this->error_response( $read );
			}
			$existing = $read;
		}

		$merged = empty( $payload ) ? ( is_array( $existing ) ? $existing : array() ) : Global_Styles_Db::deep_merge( is_array( $existing ) ? $existing : array(), $payload );

		$valid = Global_Styles_Db::validate_data( $merged );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}
		$valid = Global_Styles_Db::validate_block_styles( $merged );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}

		if ( 'db' === $migrate_to ) {
			$target_theme = '' !== $theme ? $theme : (string) get_stylesheet();
			if ( Variation_Db::find_by_slug( $slug, $target_theme ) ) {
				return array(
					'success' => false,
					'message' => __( 'A DB variation with this slug already exists for this theme. Update it directly instead of migrating.', 'acrossai-abilities-manager' ),
				);
			}
			$id = Variation_Db::create(
				$target_theme,
				$slug,
				$merged,
				array(
					'title'       => (string) ( $input['title'] ?? '' ),
					'description' => (string) ( $input['description'] ?? '' ),
				)
			);
			if ( is_wp_error( $id ) ) {
				return $this->error_response( $id );
			}
			$warnings[] = __( 'DB version will override the file copy from now on.', 'acrossai-abilities-manager' );

			if ( $delete_source && 'theme' === $src && 'parent' !== ( $loc['theme_type'] ?? '' ) ) {
				$del = Variation_File::delete_file( (string) $loc['path'] );
				if ( is_wp_error( $del ) ) {
					$warnings[] = $del->get_error_message();
				}
			} elseif ( $delete_source ) {
				$warnings[] = __( 'Skipped deleting source — parent-theme and plugin files are preserved.', 'acrossai-abilities-manager' );
			}

			$new_post      = get_post( (int) $id );
			$content_bytes = $new_post ? strlen( (string) $new_post->post_content ) : 0;

			return array(
				'success'       => true,
				/* translators: %s: slug */
				'message'       => sprintf( __( 'Migrated variation "%s" from file to database.', 'acrossai-abilities-manager' ), $slug ),
				'variation'     => $new_post ? Variation_Db::to_row( $new_post, $return_content ) : array(),
				'content_bytes' => $content_bytes,
				'migrated'      => true,
				'warnings'      => $warnings,
			);
		}

		// migrate_to = child_theme
		$child_dir = Variation_File::get_child_theme_dir();
		if ( null === $child_dir ) {
			return array(
				'success' => false,
				'message' => __( 'No child theme is active. Create one before migrating to child theme.', 'acrossai-abilities-manager' ),
			);
		}
		$styles_dir = Variation_File::ensure_styles_dir( $child_dir );
		if ( is_wp_error( $styles_dir ) ) {
			return $this->error_response( $styles_dir );
		}
		$abs = Variation_File::resolve_variation_path( $child_dir, $slug );
		if ( is_wp_error( $abs ) ) {
			return $this->error_response( $abs );
		}
		$bytes = Variation_File::write_json( $abs, $merged );
		if ( is_wp_error( $bytes ) ) {
			return $this->error_response( $bytes );
		}

		if ( $delete_source && 'db' === $src ) {
			$post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
			if ( $post ) {
				Variation_Db::delete( $post );
				$warnings[] = __( 'DB record deleted — child-theme file is now the only copy.', 'acrossai-abilities-manager' );
			}
		} elseif ( $delete_source && 'theme' === $src && 'parent' === ( $loc['theme_type'] ?? '' ) ) {
			$warnings[] = __( 'Skipped deleting parent-theme source — parent files are preserved.', 'acrossai-abilities-manager' );
		} elseif ( $delete_source && 'plugin' === $src ) {
			$warnings[] = __( 'Skipped deleting plugin source — plugin files are preserved.', 'acrossai-abilities-manager' );
		}

		return array(
			'success'   => true,
			/* translators: 1: slug, 2: path */
			'message'   => sprintf( __( 'Migrated variation "%1$s" to child theme at %2$s.', 'acrossai-abilities-manager' ), $slug, $abs ),
			'variation' => array(
				'source'     => 'theme',
				'theme_type' => 'child',
				'theme'      => basename( $child_dir ),
				'slug'       => $slug,
				'path'       => $abs,
				'bytes'      => (int) $bytes,
			),
			'migrated'  => true,
			'warnings'  => $warnings,
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

		if ( ! isset( $input['content'] ) ) {
			return array();
		}
		return $this->coerce_array( $input['content'] );
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
