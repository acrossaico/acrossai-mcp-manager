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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Template_Part\Template_Part_Db;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Template_Part\Template_Part_Detector;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Template_Part\Template_Part_File;

defined( 'ABSPATH' ) || exit;

/**
 * Updates an existing block template part. Implements the full decision tree:
 *
 *  - Detects every location, then picks one via source / theme_type / plugin_slug.
 *  - Scenario 3: refuses to write to the parent theme. Caller must pass
 *    migrate_to=child_theme (copies to child first, then updates) or
 *    migrate_to=db.
 *  - Scenarios 16, 17: migrate_to triggers cross-source migration. The source
 *    copy stays unless delete_source=true (and parent-theme files can never
 *    be deleted this way — they remain as the upstream fallback).
 *  - Scenario 12: pass new_slug to rename. File-based parts get the .html file
 *    renamed; DB parts get post_name updated. Old slug is removed after the
 *    new one is verified.
 *  - Scenario 15: file-not-writable returns a clear error with the path.
 *  - Scenario 20: if the part is referenced by templates, require confirm_usage.
 *  - Scenario 18: empty content rejected. Scenario 19: invalid area rejected.
 */
class Update_Block_Template_Part extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/update-block-template-part',
			'args' => array(
				'label'               => __( 'Update Block Template Part', 'acrossai-abilities-manager' ),
				'description'         => __( 'Updates an existing block template part. Detects the location automatically; pass source / theme_type / plugin_slug to disambiguate. Supports rename via new_slug, area change, and cross-source migration via migrate_to. Refuses parent-theme writes — copy to child or DB first.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'          => array(
							'type'        => 'string',
							'description' => __( 'Existing slug to update.', 'acrossai-abilities-manager' ),
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
						'theme'         => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Theme hint for DB row lookup.', 'acrossai-abilities-manager' ),
						),
						'title'         => array( 'type' => 'string' ),
						'description'   => array( 'type' => 'string' ),
						'content'       => array(
							'type'        => 'string',
							'description' => __( 'New content. Empty/whitespace-only content is rejected.', 'acrossai-abilities-manager' ),
						),
						'area'          => array(
							'type' => 'string',
							'enum' => array( 'header', 'footer', 'sidebar', 'uncategorized' ),
						),
						'status'        => array(
							'type' => 'string',
							'enum' => array( 'publish', 'draft', 'private', 'pending' ),
						),
						'new_slug'      => array(
							'type'        => 'string',
							'description' => __( 'Rename to this slug. For file-based parts, renames the .html file. For DB parts, updates post_name.', 'acrossai-abilities-manager' ),
						),
						'migrate_to'    => array(
							'type'        => 'string',
							'enum'        => array( '', 'db', 'child_theme' ),
							'default'     => '',
							'description' => __( '"db" = copy file content into a wp_template_part row. "child_theme" = copy DB or parent-theme content into the child theme\'s /parts. Combine with delete_source to remove the original.', 'acrossai-abilities-manager' ),
						),
						'delete_source' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'After migration, remove the source copy. Parent-theme files are never deleted.', 'acrossai-abilities-manager' ),
						),
						'confirm_usage' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Required when this part is referenced by other templates. Set true to acknowledge the change affects every template that includes it.', 'acrossai-abilities-manager' ),
						),
						'return_content' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When true, the response includes the saved part.content field. Default false: the large block markup is stripped and content_bytes is returned instead, so a small edit does not echo the whole template part body back through the tunnel.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'part'          => array( 'type' => 'object' ),
						'content_bytes' => array( 'type' => 'integer' ),
						'migrated'      => array( 'type' => 'boolean' ),
						'used_by'       => array( 'type' => 'array' ),
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
						'sub_group'       => 'template-parts',
						'sub_group_label' => __( 'Template Parts', 'acrossai-abilities-manager' ),
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
		$source        = sanitize_text_field( $input['source'] ?? '' );
		$theme_type    = sanitize_text_field( $input['theme_type'] ?? '' );
		$plugin_slug   = sanitize_key( $input['plugin_slug'] ?? '' );
		$theme_hint    = sanitize_key( $input['theme'] ?? '' );
		$migrate_to    = sanitize_text_field( $input['migrate_to'] ?? '' );
		$delete_source = ! empty( $input['delete_source'] );
		$confirm_usage = ! empty( $input['confirm_usage'] );

		if ( '' === $slug ) {
			return array(
				'success' => false,
				'message' => __( 'Slug is required.', 'acrossai-abilities-manager' ),
			);
		}

		$locations = Template_Part_Detector::locate( $slug, $theme_hint );

		if ( empty( $locations ) ) {
			return array(
				'success'   => false,
				/* translators: %s: slug */
				'message'   => sprintf( __( 'No template part with slug "%s" was found. Use template-part-create.', 'acrossai-abilities-manager' ), $slug ),
				'locations' => array(),
			);
		}

		$selected = Template_Part_Detector::select( $locations, $source, $theme_type, $plugin_slug );
		if ( is_wp_error( $selected ) ) {
			$data = $selected->get_error_data();
			return array(
				'success'    => false,
				'message'    => $selected->get_error_message(),
				'locations'  => $locations,
				'candidates' => is_array( $data ) ? ( $data['locations'] ?? array() ) : array(),
			);
		}

		// Scenario 20 — usage check.
		$used_by = Template_Part_Db::find_templates_using( $slug, $theme_hint );
		if ( ! empty( $used_by ) && ! $confirm_usage ) {
			return array(
				'success' => false,
				/* translators: %d: number of templates */
				'message' => sprintf(
					__( 'This template part is used by %d template(s). Re-run with confirm_usage=true to proceed.', 'acrossai-abilities-manager' ),
					count( $used_by )
				),
				'used_by' => $used_by,
			);
		}

		// Migration shortcuts (Scenarios 16, 17).
		if ( '' !== $migrate_to ) {
			return $this->migrate( $selected, $migrate_to, $delete_source, $input, $used_by );
		}

		// Scenario 3 — refuse parent theme write.
		if ( 'theme' === ( $selected['source'] ?? '' ) && 'parent' === ( $selected['theme_type'] ?? '' ) ) {
			return array(
				'success'   => false,
				'message'   => __( 'Refusing to edit the parent theme directly. Re-run with migrate_to=child_theme to copy the part into the active child theme first, or migrate_to=db to store it in the database.', 'acrossai-abilities-manager' ),
				'locations' => $locations,
			);
		}

		// Regular in-place update.
		switch ( $selected['source'] ?? '' ) {
			case 'db':
				return $this->update_db( $selected, $input, $used_by );

			case 'theme':
			case 'plugin':
				return $this->update_file( $selected, $input, $used_by );
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
	 * @param array $input
	 * @param array $used_by
	 * @return array
	 */
	private function update_db( array $loc, array $input, array $used_by ): array {
		$post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return array(
				'success' => false,
				'message' => __( 'Database template part not found.', 'acrossai-abilities-manager' ),
			);
		}

		$data = $this->collect_fields( $input );
		if ( isset( $input['new_slug'] ) && '' !== $input['new_slug'] ) {
			$data['new_slug'] = (string) $input['new_slug'];
		}

		$result = Template_Part_Db::update( $post, $data );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		$updated        = get_post( (int) $result );
		$content_bytes  = $updated ? strlen( (string) $updated->post_content ) : 0;
		$return_content = ! empty( $input['return_content'] );
		$row            = $updated ? Template_Part_Db::to_row( $updated ) : array();
		if ( ! $return_content ) {
			unset( $row['content'] );
		}

		return array(
			'success'       => true,
			/* translators: %s: slug */
			'message'       => sprintf( __( 'Updated DB template part "%s".', 'acrossai-abilities-manager' ), $updated ? $updated->post_name : '' ),
			'part'          => $row,
			'content_bytes' => $content_bytes,
			'used_by'       => $used_by,
			'warnings'      => array(),
		);
	}

	/**
	 * Update file.
	 *
	 * @param array $loc
	 * @param array $input
	 * @param array $used_by
	 * @return array
	 */
	private function update_file( array $loc, array $input, array $used_by ): array {
		$path = (string) ( $loc['path'] ?? '' );
		if ( '' === $path || ! is_file( $path ) ) {
			return array(
				'success' => false,
				'message' => __( 'Template part file not found.', 'acrossai-abilities-manager' ),
			);
		}

		// Plugin-source warnings.
		$warnings = array();
		if ( 'plugin' === ( $loc['source'] ?? '' ) && false === ( $loc['plugin_active'] ?? true ) ) {
			/* translators: %s: plugin slug */
			$warnings[] = sprintf( __( 'Plugin "%s" is inactive — your edit will only take effect once the plugin is activated.', 'acrossai-abilities-manager' ), $loc['plugin'] ?? '' );
		}

		$content = array_key_exists( 'content', $input ) ? (string) $input['content'] : null;
		if ( null !== $content && ! Template_Part_Db::valid_content( $content ) ) {
			return array(
				'success' => false,
				'message' => __( 'Content cannot be empty.', 'acrossai-abilities-manager' ),
			);
		}

		// Write new content if provided.
		if ( null !== $content ) {
			$bytes = Template_Part_File::write_file( $path, $content );
			if ( is_wp_error( $bytes ) ) {
				return array(
					'success' => false,
					'message' => $bytes->get_error_message(),
				);
			}
		}

		// Slug rename (Scenario 12) — rename the .html file.
		$final_path = $path;
		$final_slug = (string) ( $loc['slug'] ?? '' );
		if ( ! empty( $input['new_slug'] ) ) {
			$new_slug = sanitize_title( (string) $input['new_slug'] );
			if ( '' === $new_slug || ! Template_Part_File::is_valid_bare_slug( $new_slug ) ) {
				return array(
					'success' => false,
					'message' => __( 'new_slug is invalid.', 'acrossai-abilities-manager' ),
				);
			}
			$dir     = dirname( dirname( $path ) ); // container = /parts/<slug>.html → up two dirs
			$new_abs = Template_Part_File::resolve_part_path( $dir, $new_slug );
			if ( is_wp_error( $new_abs ) ) {
				return array(
					'success' => false,
					'message' => $new_abs->get_error_message(),
				);
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
					'message' => __( 'Failed to rename template part file.', 'acrossai-abilities-manager' ),
				);
			}
			$final_path = $new_abs;
			$final_slug = $new_slug;
		}

		$part = array(
			'source'   => (string) ( $loc['source'] ?? '' ),
			'slug'     => $final_slug,
			'path'     => $final_path,
			'writable' => is_writable( $final_path ),
		);
		if ( 'theme' === ( $loc['source'] ?? '' ) ) {
			$part['theme']      = (string) ( $loc['theme'] ?? '' );
			$part['theme_type'] = (string) ( $loc['theme_type'] ?? '' );
			$part['full_slug']  = $part['theme'] . '//' . $final_slug;
		} else {
			$part['plugin']        = (string) ( $loc['plugin'] ?? '' );
			$part['plugin_active'] = (bool) ( $loc['plugin_active'] ?? false );
		}

		return array(
			'success'  => true,
			/* translators: %s: slug */
			'message'  => sprintf( __( 'Updated template part "%s".', 'acrossai-abilities-manager' ), $final_slug ),
			'part'     => $part,
			'used_by'  => $used_by,
			'warnings' => $warnings,
		);
	}

	/**
	 * Migrates a part across sources (Scenarios 16 & 17).
	 */
	private function migrate( array $loc, string $migrate_to, bool $delete_source, array $input, array $used_by ): array {
		$slug       = (string) ( $loc['slug'] ?? '' );
		$src        = (string) ( $loc['source'] ?? '' );
		$theme_hint = sanitize_key( $input['theme'] ?? '' );
		$warnings   = array();

		// Resolve source content + metadata first.
		$content     = '';
		$source_post = null;
		$source_path = '';
		if ( 'db' === $src ) {
			$source_post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
			if ( ! $source_post ) {
				return array(
					'success' => false,
					'message' => __( 'Source DB template part not found.', 'acrossai-abilities-manager' ),
				);
			}
			$content = (string) $source_post->post_content;
		} else {
			$source_path = (string) ( $loc['path'] ?? '' );
			$read        = Template_Part_File::read_file( $source_path );
			if ( is_wp_error( $read ) ) {
				return array(
					'success' => false,
					'message' => $read->get_error_message(),
				);
			}
			$content = $read;
		}

		// Caller can override content during migration.
		if ( array_key_exists( 'content', $input ) ) {
			$content = (string) $input['content'];
		}

		if ( ! Template_Part_Db::valid_content( $content ) ) {
			return array(
				'success' => false,
				'message' => __( 'Content cannot be empty.', 'acrossai-abilities-manager' ),
			);
		}

		// Apply target.
		if ( 'db' === $migrate_to ) {
			$theme = '' !== $theme_hint ? $theme_hint : (string) get_stylesheet();
			$area  = array_key_exists( 'area', $input ) ? sanitize_text_field( $input['area'] ) : 'uncategorized';

			$existing = Template_Part_Db::find_by_slug( $slug, $theme );
			if ( $existing ) {
				return array(
					'success' => false,
					'message' => __( 'A DB template part with this slug already exists for this theme. Update it directly instead of migrating.', 'acrossai-abilities-manager' ),
				);
			}

			$result = Template_Part_Db::create(
				array(
					'slug'        => $slug,
					'title'       => (string) ( $input['title'] ?? $slug ),
					'description' => (string) ( $input['description'] ?? '' ),
					'content'     => $content,
					'area'        => $area,
					'theme'       => $theme,
				)
			);
			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'message' => $result->get_error_message(),
				);
			}

			$new_post   = get_post( (int) $result );
			$warnings[] = __( 'DB version will override the file copy from now on.', 'acrossai-abilities-manager' );

			if ( $delete_source && 'plugin' !== $src && ! ( 'theme' === $src && 'parent' === ( $loc['theme_type'] ?? '' ) ) ) {
				$del = Template_Part_File::delete_file( $source_path );
				if ( is_wp_error( $del ) ) {
					$warnings[] = $del->get_error_message();
				}
			} elseif ( $delete_source ) {
				$warnings[] = __( 'Skipped deleting source — parent-theme and plugin files are preserved.', 'acrossai-abilities-manager' );
			}

			$content_bytes  = $new_post ? strlen( (string) $new_post->post_content ) : 0;
			$return_content = ! empty( $input['return_content'] );
			$row            = $new_post ? Template_Part_Db::to_row( $new_post ) : array();
			if ( ! $return_content ) {
				unset( $row['content'] );
			}

			return array(
				'success'       => true,
				/* translators: %s: slug */
				'message'       => sprintf( __( 'Migrated "%s" from file to database.', 'acrossai-abilities-manager' ), $slug ),
				'part'          => $row,
				'content_bytes' => $content_bytes,
				'migrated'      => true,
				'used_by'       => $used_by,
				'warnings'      => $warnings,
			);
		}

		// migrate_to=child_theme
		$child_dir = Template_Part_File::get_child_theme_dir();
		if ( null === $child_dir ) {
			return array(
				'success' => false,
				'message' => __( 'No child theme is active. Cannot migrate to child theme. Create a child theme first.', 'acrossai-abilities-manager' ),
			);
		}

		$parts_dir = Template_Part_File::ensure_parts_dir( $child_dir );
		if ( is_wp_error( $parts_dir ) ) {
			return array(
				'success' => false,
				'message' => $parts_dir->get_error_message(),
			);
		}

		$abs = Template_Part_File::resolve_part_path( $child_dir, $slug );
		if ( is_wp_error( $abs ) ) {
			return array(
				'success' => false,
				'message' => $abs->get_error_message(),
			);
		}

		$bytes = Template_Part_File::write_file( $abs, $content );
		if ( is_wp_error( $bytes ) ) {
			return array(
				'success' => false,
				'message' => $bytes->get_error_message(),
			);
		}

		if ( $delete_source && 'db' === $src && null !== $source_post ) {
			Template_Part_Db::delete( $source_post );
			$warnings[] = __( 'DB record deleted — child-theme file is now the only copy.', 'acrossai-abilities-manager' );
		} elseif ( $delete_source && 'theme' === $src && 'parent' === ( $loc['theme_type'] ?? '' ) ) {
			$warnings[] = __( 'Skipped deleting parent-theme source — parent files are preserved.', 'acrossai-abilities-manager' );
		} elseif ( $delete_source && 'plugin' === $src ) {
			$warnings[] = __( 'Skipped deleting plugin source — plugin files are preserved.', 'acrossai-abilities-manager' );
		} else {
			$warnings[] = __( 'Child-theme copy will now override the original. Delete the source with delete_source=true if you want a single copy.', 'acrossai-abilities-manager' );
		}

		return array(
			'success'  => true,
			/* translators: 1: slug, 2: path */
			'message'  => sprintf( __( 'Migrated "%1$s" to child theme at %2$s.', 'acrossai-abilities-manager' ), $slug, $abs ),
			'part'     => array(
				'source'     => 'theme',
				'theme_type' => 'child',
				'theme'      => basename( $child_dir ),
				'slug'       => $slug,
				'path'       => $abs,
				'bytes'      => (int) $bytes,
			),
			'migrated' => true,
			'used_by'  => $used_by,
			'warnings' => $warnings,
		);
	}

	/**
	 * Whitelists update fields for Template_Part_Db::update().
	 */
	private function collect_fields( array $input ): array {
		$data = array();
		foreach ( array( 'title', 'description', 'content', 'status', 'area', 'theme' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$data[ $key ] = $input[ $key ];
			}
		}
		return $data;
	}
}
