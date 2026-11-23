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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Pattern\Pattern_Db;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Pattern\Pattern_Detector;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Pattern\Pattern_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Updates an existing block pattern. Implements the full decision tree:
 *
 *  Scenario 1 — Pattern in child theme:           edit child file directly.
 *  Scenario 2 — Parent only:                      copy to child, then edit child.
 *  Scenario 3 — Both parent + child:              edit child, ignore parent.
 *  Scenario 4/5/6 — Pattern in multiple sources:  require "source" disambiguator.
 *  Scenario 7 — Not found:                        not_found error, suggest create.
 *  Scenario 9 — Slug change:                      write new, delete old at same source.
 *  Scenario 10 — No child theme but parent edit:  error unless theme_type=parent.
 *  Scenario 12 — File read-only:                  file_not_writable error.
 *  Scenario 13/14 — Migration:                    target_source moves the pattern.
 *  Scenario 15 — Empty content:                   empty_content error.
 *
 * Partial update — any unspecified field is preserved.
 */
class Update_Block_Pattern extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/update-block-pattern',
			'args' => array(
				'label'               => __( 'Update Block Pattern', 'acrossai-abilities-manager' ),
				'description'         => __( 'Updates a block pattern at its current storage location. Auto-detects where it lives; on multi-location ambiguity returns error_code=multiple_locations with the list. For parent-theme-only patterns, copies the file to the child theme first and edits the copy (the parent file is never touched). Pass new_slug to rename (old slug is removed after the new one is written), or target_source to migrate (delete_original controls cleanup).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'               => array( 'type' => 'string' ),
						'source'             => array(
							'type' => 'string',
							'enum' => array( 'db', 'theme', 'plugin' ),
						),
						'theme_type'         => array(
							'type' => 'string',
							'enum' => array( 'child', 'parent', 'theme' ),
						),
						'plugin_slug'        => array( 'type' => 'string' ),

						// Editable fields. Omitted keys are preserved.
						'title'              => array( 'type' => 'string' ),
						'content'            => array( 'type' => 'string' ),
						'description'        => array( 'type' => 'string' ),
						'viewport_width'     => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'inserter'           => array(
							'type' => 'string',
							'enum' => array( 'yes', 'no' ),
						),
						'categories'         => array( 'type' => 'string' ),
						'keywords'           => array( 'type' => 'string' ),
						'block_types'        => array( 'type' => 'string' ),
						'post_types'         => array( 'type' => 'string' ),
						'template_types'     => array( 'type' => 'string' ),
						'status'             => array(
							'type' => 'string',
							'enum' => array( 'publish', 'draft', 'private', 'pending' ),
						),
						'sync_status'        => array(
							'type' => 'string',
							'enum' => array( 'synced', 'unsynced' ),
						),

						// Scenario 9: rename
						'new_slug'           => array(
							'type'        => 'string',
							'description' => __( 'Rename the pattern. The old slug is removed at the same source after the new one is written.', 'acrossai-abilities-manager' ),
						),

						// Scenarios 13 / 14: migrate
						'target_source'      => array(
							'type'        => 'string',
							'enum'        => array( 'db', 'theme', 'plugin' ),
							'description' => __( 'Migrate the pattern to a different storage layer. The new copy is written first; delete_original controls whether the source copy is removed afterwards.', 'acrossai-abilities-manager' ),
						),
						'target_theme_slug'  => array( 'type' => 'string' ),
						'target_plugin_slug' => array( 'type' => 'string' ),
						'delete_original'    => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'          => array( 'type' => 'boolean' ),
						'message'          => array( 'type' => 'string' ),
						'error_code'       => array( 'type' => 'string' ),
						'locations'        => array( 'type' => 'array' ),
						'pattern'          => array( 'type' => 'object' ),
						'copied_to_child'  => array( 'type' => 'boolean' ),
						'renamed_from'     => array( 'type' => 'string' ),
						'migrated_from'    => array( 'type' => 'object' ),
						'original_removed' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'success' ),
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'patterns',
						'sub_group_label' => __( 'Patterns', 'acrossai-abilities-manager' ),
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

		$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return array(
				'success'    => false,
				'message'    => __( 'slug is required.', 'acrossai-abilities-manager' ),
				'error_code' => 'invalid_slug',
			);
		}
		if ( array_key_exists( 'content', $input ) && ! Pattern_Helper::is_valid_content( (string) $input['content'] ) ) {
			return array(
				'success'    => false,
				'message'    => __( 'content may not be empty.', 'acrossai-abilities-manager' ),
				'error_code' => 'empty_content',
			);
		}

		$source      = sanitize_text_field( $input['source'] ?? '' );
		$theme_type  = sanitize_text_field( $input['theme_type'] ?? '' );
		$plugin_slug = sanitize_key( $input['plugin_slug'] ?? '' );

		$locations = Pattern_Detector::locate( $slug );

		// Scenario 3: prefer child over parent automatically when no explicit theme_type was given.
		if ( '' === $theme_type && 'theme' === $source ) {
			$child = array_values( array_filter( $locations, static fn( $l ) => ( $l['source'] ?? '' ) === 'theme' && ( $l['theme_type'] ?? '' ) === 'child' ) );
			if ( ! empty( $child ) ) {
				$theme_type = 'child';
			}
		}

		$selected = Pattern_Detector::select( $locations, $source, $theme_type, $plugin_slug );

		// Scenario 2: parent-only pattern + user asked for source=theme (or no source) → copy to child first.
		$copied_to_child = false;
		if ( is_wp_error( $selected ) ) {
			$code = $selected->get_error_code();
			$data = $selected->get_error_data();

			if ( 'multiple_locations' === $code ) {
				return array(
					'success'    => false,
					'message'    => $selected->get_error_message(),
					'error_code' => $code,
					'locations'  => $data['locations'] ?? $locations,
				);
			}
			if ( 'not_found' === $code || 'not_found_at_source' === $code ) {
				return array(
					'success'    => false,
					'message'    => $selected->get_error_message(),
					'error_code' => $code,
					'locations'  => $locations,
				);
			}
			return array(
				'success'    => false,
				'message'    => $selected->get_error_message(),
				'error_code' => $code,
				'locations'  => $locations,
			);
		}

		// Scenario 2 + 3: when editing a theme pattern that's parent-only and the caller
		// didn't explicitly target the parent, copy to child theme and edit the copy.
		if ( 'theme' === $selected['source'] && 'parent' === ( $selected['theme_type'] ?? '' ) && 'parent' !== $theme_type ) {
			$copied = $this->copy_parent_to_child( $selected );
			if ( is_wp_error( $copied ) ) {
				return array(
					'success'    => false,
					'message'    => $copied->get_error_message(),
					'error_code' => $copied->get_error_code(),
				);
			}
			$selected        = $copied;
			$copied_to_child = true;
		}

		// Edit in place.
		$edit_result = $this->edit_at_source( $selected, $input );
		if ( ! $edit_result['success'] ) {
			return $edit_result;
		}

		// Scenario 9: rename — write new slug, then delete the old file/post.
		$renamed_from = '';
		if ( ! empty( $input['new_slug'] ) ) {
			$new_slug = sanitize_title( (string) $input['new_slug'] );
			if ( '' === $new_slug || ! Pattern_Helper::is_valid_bare_slug( $new_slug ) ) {
				return array(
					'success'    => false,
					'message'    => __( 'new_slug is invalid.', 'acrossai-abilities-manager' ),
					'error_code' => 'invalid_new_slug',
				);
			}
			if ( $new_slug !== $slug ) {
				$rename = $this->rename_at_source( $selected, $new_slug, $input );
				if ( ! $rename['success'] ) {
					return $rename;
				}
				$selected     = $rename['pattern_location'];
				$renamed_from = $slug;
			}
		}

		// Scenarios 13 / 14: migrate to a different source.
		$migrated_from    = null;
		$original_removed = false;
		if ( ! empty( $input['target_source'] ) ) {
			$target_source = sanitize_text_field( (string) $input['target_source'] );
			if ( $target_source !== $selected['source'] ) {
				$migrate = $this->migrate( $selected, $target_source, $input );
				if ( ! $migrate['success'] ) {
					return $migrate;
				}
				$migrated_from    = array(
					'source'     => $selected['source'],
					'theme_type' => $selected['theme_type'] ?? null,
					'theme'      => $selected['theme'] ?? null,
					'plugin'     => $selected['plugin'] ?? null,
					'path'       => $selected['path'] ?? null,
					'post_id'    => $selected['post_id'] ?? null,
				);
				$original_removed = ! empty( $migrate['original_removed'] );
				$selected         = $migrate['pattern_location'];
			}
		}

		return array(
			'success'          => true,
			'message'          => __( 'Pattern updated.', 'acrossai-abilities-manager' ),
			'pattern'          => $selected,
			'copied_to_child'  => $copied_to_child,
			'renamed_from'     => $renamed_from,
			'migrated_from'    => $migrated_from,
			'original_removed' => $original_removed,
		);
	}

	// -------------------------------------------------------------------------
	// Sub-operations
	// -------------------------------------------------------------------------

	/**
	 * Scenario 2: copy a parent-theme file into the child-theme /patterns dir.
	 *
	 * @return array<string, mixed>|\WP_Error  New location descriptor on success.
	 */
	private function copy_parent_to_child( array $parent_location ) {
		$child_dir = Pattern_Helper::get_child_theme_dir();
		if ( null === $child_dir ) {
			return new \WP_Error( 'no_child_theme', __( 'No child theme is active. Create a child theme before editing this parent-theme pattern, or pass theme_type=parent to edit the parent directly.', 'acrossai-abilities-manager' ) );
		}

		$source_file = (string) $parent_location['path'];
		$dest_dir    = $child_dir . '/patterns';
		if ( ! is_dir( $dest_dir ) && ! wp_mkdir_p( $dest_dir ) ) {
			return new \WP_Error( 'mkdir_failed', __( 'Could not create /patterns directory in the child theme.', 'acrossai-abilities-manager' ) );
		}

		$dest_file = $dest_dir . '/' . basename( $source_file );
		if ( ! copy( $source_file, $dest_file ) ) {
			return new \WP_Error( 'copy_failed', __( 'Could not copy the parent-theme pattern into the child theme.', 'acrossai-abilities-manager' ) );
		}

		return array(
			'source'     => 'theme',
			'theme_type' => 'child',
			'theme'      => basename( $child_dir ),
			'path'       => $dest_file,
			'slug'       => $parent_location['slug'],
			'writable'   => is_writable( $dest_file ),
		);
	}

	/**
	 * Edit at source.
	 *
	 * @param array $location
	 * @param array $input
	 * @return array
	 */
	private function edit_at_source( array $location, array $input ): array {
		if ( 'db' === $location['source'] ) {
			$post = get_post( (int) $location['post_id'] );
			if ( ! $post ) {
				return array(
					'success'    => false,
					'message'    => __( 'Pattern post not found.', 'acrossai-abilities-manager' ),
					'error_code' => 'not_found',
				);
			}
			$result = Pattern_Db::update( $post, $input );
			if ( is_wp_error( $result ) ) {
				return array(
					'success'    => false,
					'message'    => $result->get_error_message(),
					'error_code' => $result->get_error_code(),
				);
			}
			return array( 'success' => true );
		}

		// File-based source (theme or plugin)
		$abs = (string) $location['path'];
		if ( ! is_writable( $abs ) ) {
			return array(
				'success'    => false,
				'message'    => sprintf(
					/* translators: %s: file path */
					__( 'Pattern file is not writable (%s).', 'acrossai-abilities-manager' ),
					$abs
				),
				'error_code' => 'file_not_writable',
			);
		}

		$existing = file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $existing ) {
			return array(
				'success'    => false,
				'message'    => __( 'Could not read existing pattern file.', 'acrossai-abilities-manager' ),
				'error_code' => 'read_failed',
			);
		}
		$parsed  = Pattern_Helper::parse_file( $existing );
		$headers = $this->merge_headers( $parsed['headers'], $input );
		$body    = array_key_exists( 'content', $input ) ? (string) $input['content'] : $parsed['body'];

		$bytes = file_put_contents( $abs, Pattern_Helper::build_file( $headers, $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes ) {
			return array(
				'success'    => false,
				'message'    => __( 'Could not write pattern file.', 'acrossai-abilities-manager' ),
				'error_code' => 'file_not_writable',
			);
		}
		return array( 'success' => true );
	}

	/**
	 * @return array{success:bool, message?:string, error_code?:string, pattern_location?:array<string,mixed>}
	 */
	private function rename_at_source( array $location, string $new_slug, array $input ): array {
		// Reject if the new slug already exists at the SAME source.
		$existing_at_target = Pattern_Detector::locate( $new_slug );
		foreach ( $existing_at_target as $other ) {
			if ( $this->same_source_bucket( $other, $location ) ) {
				return array(
					'success'    => false,
					'message'    => __( 'A pattern with new_slug already exists at the target source.', 'acrossai-abilities-manager' ),
					'error_code' => 'new_slug_conflict',
				);
			}
		}

		if ( 'db' === $location['source'] ) {
			$post = get_post( (int) $location['post_id'] );
			if ( ! $post ) {
				return array(
					'success'    => false,
					'message'    => __( 'Pattern post not found.', 'acrossai-abilities-manager' ),
					'error_code' => 'not_found',
				);
			}
			$result = Pattern_Db::update( $post, array( 'new_slug' => $new_slug ) );
			if ( is_wp_error( $result ) ) {
				return array(
					'success'    => false,
					'message'    => $result->get_error_message(),
					'error_code' => $result->get_error_code(),
				);
			}
			$new_location         = $location;
			$new_location['slug'] = $new_slug;
			return array(
				'success'          => true,
				'pattern_location' => $new_location,
			);
		}

		// File-based: write new file then delete old.
		$container_dir = dirname( dirname( (string) $location['path'] ) );
		$new_path      = $container_dir . '/patterns/' . $new_slug . '.php';

		$existing = file_get_contents( (string) $location['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $existing ) {
			return array(
				'success'    => false,
				'message'    => __( 'Could not read pattern file for rename.', 'acrossai-abilities-manager' ),
				'error_code' => 'read_failed',
			);
		}
		$parsed          = Pattern_Helper::parse_file( $existing );
		$prefix          = (string) ( $location['theme'] ?? $location['plugin'] ?? 'theme' );
		$headers         = $parsed['headers'];
		$headers['Slug'] = Pattern_Helper::build_full_slug( $prefix, $new_slug );
		$body            = array_key_exists( 'content', $input ) ? (string) $input['content'] : $parsed['body'];

		$bytes = file_put_contents( $new_path, Pattern_Helper::build_file( $headers, $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes ) {
			return array(
				'success'    => false,
				'message'    => __( 'Could not write renamed pattern file.', 'acrossai-abilities-manager' ),
				'error_code' => 'file_not_writable',
			);
		}
		if ( ! @unlink( (string) $location['path'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			// Roll back to avoid two files holding old + new.
			@unlink( $new_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'success'    => false,
				'message'    => __( 'Could not delete old pattern file after rename; rolled back.', 'acrossai-abilities-manager' ),
				'error_code' => 'file_not_writable',
			);
		}

		$new_location         = $location;
		$new_location['slug'] = $new_slug;
		$new_location['path'] = $new_path;
		return array(
			'success'          => true,
			'pattern_location' => $new_location,
		);
	}

	/**
	 * @return array{success:bool, message?:string, error_code?:string, pattern_location?:array<string,mixed>, original_removed?:bool}
	 */
	private function migrate( array $location, string $target_source, array $input ): array {
		// Read the current pattern content to seed the new copy.
		$existing_content = '';
		$existing_title   = '';
		$existing_desc    = '';
		$existing_headers = array();

		if ( 'db' === $location['source'] ) {
			$post = get_post( (int) $location['post_id'] );
			if ( ! $post ) {
				return array(
					'success'    => false,
					'message'    => __( 'Pattern post not found for migration.', 'acrossai-abilities-manager' ),
					'error_code' => 'not_found',
				);
			}
			$existing_content = (string) $post->post_content;
			$existing_title   = (string) $post->post_title;
			$existing_desc    = (string) $post->post_excerpt;
		} else {
			$raw = file_get_contents( (string) $location['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $raw ) {
				return array(
					'success'    => false,
					'message'    => __( 'Could not read pattern file for migration.', 'acrossai-abilities-manager' ),
					'error_code' => 'read_failed',
				);
			}
			$parsed           = Pattern_Helper::parse_file( $raw );
			$existing_headers = $parsed['headers'];
			$existing_content = $parsed['body'];
			$existing_title   = (string) ( $existing_headers['Title'] ?? '' );
			$existing_desc    = (string) ( $existing_headers['Description'] ?? '' );
		}

		$slug    = (string) $location['slug'];
		$title   = array_key_exists( 'title', $input ) ? sanitize_text_field( (string) $input['title'] ) : $existing_title;
		$desc    = array_key_exists( 'description', $input ) ? sanitize_text_field( (string) $input['description'] ) : $existing_desc;
		$content = array_key_exists( 'content', $input ) ? (string) $input['content'] : $existing_content;

		$create_input = array_merge(
			$input,
			array(
				'source'      => $target_source,
				'slug'        => $slug,
				'title'       => $title,
				'description' => $desc,
				'content'     => $content,
			)
		);
		if ( 'theme' === $target_source && ! empty( $input['target_theme_slug'] ) ) {
			$create_input['theme_slug'] = sanitize_text_field( (string) $input['target_theme_slug'] );
		}
		if ( 'plugin' === $target_source && ! empty( $input['target_plugin_slug'] ) ) {
			$create_input['plugin_slug'] = sanitize_key( (string) $input['target_plugin_slug'] );
		}

		$creator = new Create_Block_Pattern();
		$created = $creator->execute( $create_input );
		if ( empty( $created['success'] ) ) {
			return array(
				'success'    => false,
				'message'    => $created['message'] ?? __( 'Migration failed while writing the target copy.', 'acrossai-abilities-manager' ),
				'error_code' => $created['error_code'] ?? 'migrate_failed',
			);
		}

		$original_removed = false;
		if ( ! empty( $input['delete_original'] ) ) {
			$original_removed = $this->remove_at_source( $location );
		}

		return array(
			'success'          => true,
			'pattern_location' => $created['pattern'] ?? array(
				'source' => $target_source,
				'slug'   => $slug,
			),
			'original_removed' => $original_removed,
		);
	}

	/**
	 * Remove at source.
	 *
	 * @param array $location
	 * @return bool
	 */
	private function remove_at_source( array $location ): bool {
		if ( 'db' === $location['source'] ) {
			$post = get_post( (int) $location['post_id'] );
			return $post && Pattern_Db::delete( $post );
		}
		$path = (string) ( $location['path'] ?? '' );
		if ( '' === $path || ! is_file( $path ) ) {
			return false;
		}
		return @unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Same source bucket.
	 *
	 * @param array $a
	 * @param array $b
	 * @return bool
	 */
	private function same_source_bucket( array $a, array $b ): bool {
		if ( ( $a['source'] ?? '' ) !== ( $b['source'] ?? '' ) ) {
			return false;
		}
		if ( 'theme' === ( $a['source'] ?? '' ) ) {
			return ( $a['theme'] ?? '' ) === ( $b['theme'] ?? '' );
		}
		if ( 'plugin' === ( $a['source'] ?? '' ) ) {
			return ( $a['plugin'] ?? '' ) === ( $b['plugin'] ?? '' );
		}
		return true; // db has a single bucket
	}

	/**
	 * Merge headers.
	 *
	 * @param array $existing
	 * @param array $input
	 * @return array
	 */
	private function merge_headers( array $existing, array $input ): array {
		$map = Pattern_Helper::input_to_header_map();
		foreach ( $map as $key => $header ) {
			if ( 'slug_full' === $key ) {
				continue;
			}
			if ( array_key_exists( $key, $input ) ) {
				$value               = is_int( $input[ $key ] ) ? (string) $input[ $key ] : sanitize_text_field( (string) $input[ $key ] );
				$existing[ $header ] = $value;
			}
		}
		return $existing;
	}
}
