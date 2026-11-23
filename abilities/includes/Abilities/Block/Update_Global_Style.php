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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Updates Global Styles. Implements the full decision tree:
 *  - Auto-detects location; pass source / theme_type / plugin_slug to disambiguate.
 *  - Scenario 3: refuses to write to parent theme directly.
 *  - Scenario 14, 15: every file write routes through Global_Styles_File which
 *    invokes File_Mods_Guard (DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT / read-only).
 *  - Scenarios 16, 17 (delete handled by global-styles-delete): supports
 *    section-scoped updates via "section" + "data".
 *  - migrate_to=db / child_theme moves the record across sources, optionally
 *    deleting the source via delete_source (parent-theme / plugin files are
 *    never deleted).
 *  - Scenarios 18, 19, 20, 21: validation in Global_Styles_Db.
 *  - merge=true (default) deep-merges into the existing record; merge=false
 *    replaces the record outright.
 */
class Update_Global_Style extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/update-global-style',
			'args' => array(
				'label'               => __( 'Update Global Style', 'acrossai-abilities-manager' ),
				'description'         => __( 'Updates Global Styles. By default deep-merges new data into the existing record; pass merge=false to replace. Use "section" + "data" to update one section only. Supports cross-source migration via migrate_to.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
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
							'description' => __( 'Full or partial theme.json content. Object or JSON string.', 'acrossai-abilities-manager' ),
						),
						'section'       => array(
							'type' => 'string',
							'enum' => array( '', 'colors', 'typography', 'spacing', 'layout', 'blockStyles', 'elements', 'customCss' ),
						),
						'data'          => array(
							'type'        => array( 'string', 'object' ),
							'description' => __( 'Section data; required when "section" is provided.', 'acrossai-abilities-manager' ),
						),
						'merge'         => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'true deep-merges; false replaces.', 'acrossai-abilities-manager' ),
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
						'repair'        => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'DB source only. Adds the version + isGlobalStylesUserThemeJSON keys WordPress requires to a record written without them, changing nothing else. Records written by earlier versions of this plugin are stored but ignored by WordPress until this runs — any ordinary write repairs them too.', 'acrossai-abilities-manager' ),
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
		$theme         = sanitize_key( $input['theme'] ?? '' );
		$source        = sanitize_text_field( $input['source'] ?? '' );
		$theme_type    = sanitize_text_field( $input['theme_type'] ?? '' );
		$plugin_slug   = sanitize_key( $input['plugin_slug'] ?? '' );
		$migrate_to    = sanitize_text_field( $input['migrate_to'] ?? '' );
		$delete_source = ! empty( $input['delete_source'] );
		$repair        = ! empty( $input['repair'] );
		$merge         = ! isset( $input['merge'] ) || (bool) $input['merge'];

		$locations = Global_Styles_Detector::locate( $theme );
		if ( empty( $locations ) ) {
			return array(
				'success'   => false,
				/* translators: %s: theme */
				'message'   => sprintf( __( 'No Global Styles record exists for theme "%s". Use global-styles-create.', 'acrossai-abilities-manager' ), '' !== $theme ? $theme : (string) get_stylesheet() ),
				'locations' => array(),
			);
		}

		$selected = Global_Styles_Detector::select( $locations, $source, $theme_type, $plugin_slug );
		if ( is_wp_error( $selected ) ) {
			$data = $selected->get_error_data();
			return array(
				'success'    => false,
				'message'    => $selected->get_error_message(),
				'locations'  => $locations,
				'candidates' => is_array( $data ) ? ( $data['locations'] ?? array() ) : array(),
			);
		}

		// File-mods guard once we know we'll be writing files.
		$selected_src = (string) ( $selected['source'] ?? '' );
		if ( ( 'theme' === $selected_src || 'plugin' === $selected_src ) || '' !== $migrate_to ) {
			$blocked = File_Mods_Guard::blocked_response();
			if ( null !== $blocked ) {
				return $blocked;
			}
		}

		$resolved = $this->resolve_payload( $input );
		if ( is_wp_error( $resolved ) ) {
			return $this->error_response( $resolved );
		}
		$payload = $resolved['payload'];
		$ignored = $resolved['ignored'];

		$return_content = ! empty( $input['return_content'] );

		if ( '' !== $migrate_to ) {
			return $this->migrate( $selected, $migrate_to, $delete_source, $payload, $return_content );
		}

		if ( $repair ) {
			if ( 'db' !== $selected_src ) {
				return array(
					'success' => false,
					'message' => __( 'repair=true applies to the database record only — theme.json files never carry the isGlobalStylesUserThemeJSON flag.', 'acrossai-abilities-manager' ),
				);
			}
			if ( ! empty( $payload ) ) {
				return array(
					'success' => false,
					'message' => __( 'repair=true changes nothing but the flags, so it cannot be combined with "content" or "section". Run it on its own, then send the edit.', 'acrossai-abilities-manager' ),
				);
			}

			return $this->repair_db( $selected, $return_content );
		}

		// A section write whose data landed entirely outside that section saved nothing at all and
		// used to report success. Say so, and name what was ignored.
		if ( '' !== (string) ( $input['section'] ?? '' ) && empty( $payload ) ) {
			return array(
				'success'  => false,
				'message'  => sprintf(
					/* translators: 1: section name, 2: comma-separated list of JSON paths. */
					__( 'Nothing was saved: none of the data you sent belongs to section "%1$s". Ignored: %2$s. Send those keys under the section that owns them, or omit "section" and pass the whole record as "content".', 'acrossai-abilities-manager' ),
					Global_Styles_Db::normalize_section( (string) $input['section'] ),
					implode( ', ', $ignored )
				),
				'warnings' => $this->ignored_warnings( $ignored, (string) $input['section'] ),
			);
		}

		// Scenario 3 — refuse parent theme write.
		if ( 'theme' === $selected_src && 'parent' === ( $selected['theme_type'] ?? '' ) ) {
			return array(
				'success'   => false,
				'message'   => __( 'Refusing to edit the parent theme directly. Re-run with migrate_to=child_theme or migrate_to=db.', 'acrossai-abilities-manager' ),
				'locations' => $locations,
			);
		}

		$warnings = $this->ignored_warnings( $ignored, (string) ( $input['section'] ?? '' ) );

		switch ( $selected_src ) {
			case 'db':
				return $this->update_db( $selected, $payload, $merge, $return_content, $warnings );
			case 'theme':
			case 'plugin':
				return $this->update_file( $selected, $payload, $merge, $warnings );
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
	 * @param bool $return_content
	 * @return array
	 */
	private function update_db( array $loc, array $payload, bool $merge, bool $return_content, array $warnings = array() ): array {
		$post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return array(
				'success' => false,
				'message' => __( 'wp_global_styles post not found.', 'acrossai-abilities-manager' ),
			);
		}

		$was_inert = ! Global_Styles_Db::is_applied_by_wordpress( $post );

		$result = Global_Styles_Db::update( $post, $payload, $merge );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		$updated       = get_post( (int) $result );
		$content_bytes = $updated ? strlen( (string) $updated->post_content ) : 0;

		if ( $was_inert ) {
			$warnings[] = __( 'This record was previously missing the keys WordPress requires, so nothing stored in it was being applied. They were added by this write and the record is now live.', 'acrossai-abilities-manager' );
		}

		return array(
			'success'       => true,
			'message'       => __( 'Updated DB Global Styles record.', 'acrossai-abilities-manager' ),
			'record'        => $updated ? Global_Styles_Db::to_row( $updated, $return_content ) : array(),
			'content_bytes' => $content_bytes,
			'warnings'      => $warnings,
		);
	}

	/**
	 * Add the flags a pre-0.0.41 record is missing, changing nothing else.
	 *
	 * @since  0.0.41
	 * @param  array<string, mixed> $loc            Selected location.
	 * @param  bool                 $return_content Whether to echo the record back.
	 * @return array<string, mixed>
	 */
	private function repair_db( array $loc, bool $return_content ): array {
		$post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return array(
				'success' => false,
				'message' => __( 'wp_global_styles post not found.', 'acrossai-abilities-manager' ),
			);
		}

		$result = Global_Styles_Db::repair( $post );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		$updated = get_post( (int) $result['post_id'] );

		return array(
			'success'       => true,
			'message'       => $result['changed']
				? __( 'Added the version and isGlobalStylesUserThemeJSON keys. WordPress now applies this record; no styles were changed.', 'acrossai-abilities-manager' )
				: __( 'Nothing to repair — this record already carries the keys WordPress requires.', 'acrossai-abilities-manager' ),
			'record'        => $updated ? Global_Styles_Db::to_row( $updated, $return_content ) : array(),
			'content_bytes' => $updated ? strlen( (string) $updated->post_content ) : 0,
			'warnings'      => array(),
		);
	}

	/**
	 * Update file.
	 *
	 * @param array $loc
	 * @param array $payload
	 * @param bool $merge
	 * @return array
	 */
	private function update_file( array $loc, array $payload, bool $merge, array $warnings = array() ): array {
		$path     = (string) ( $loc['path'] ?? '' );
		$existing = Global_Styles_File::read_json( $path );
		if ( is_wp_error( $existing ) ) {
			return $this->error_response( $existing );
		}

		$new = $merge ? Global_Styles_Db::deep_merge( is_array( $existing ) ? $existing : array(), $payload ) : $payload;

		$valid = Global_Styles_Db::validate_data( $new );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}
		$valid = Global_Styles_Db::validate_block_styles( $new );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}

		$bytes = Global_Styles_File::write_json( $path, $new );
		if ( is_wp_error( $bytes ) ) {
			return $this->error_response( $bytes );
		}

		if ( 'plugin' === ( $loc['source'] ?? '' ) && false === ( $loc['plugin_active'] ?? true ) ) {
			/* translators: %s: plugin slug */
			$warnings[] = sprintf( __( 'Plugin "%s" is inactive — your edit will only take effect once the plugin is activated.', 'acrossai-abilities-manager' ), $loc['plugin'] ?? '' );
		}

		return array(
			'success'  => true,
			/* translators: %s: file path */
			'message'  => sprintf( __( 'Updated theme.json at %s.', 'acrossai-abilities-manager' ), $path ),
			'record'   => array(
				'source'        => (string) ( $loc['source'] ?? '' ),
				'theme'         => (string) ( $loc['theme'] ?? '' ),
				'theme_type'    => (string) ( $loc['theme_type'] ?? '' ),
				'plugin'        => (string) ( $loc['plugin'] ?? '' ),
				'plugin_active' => (bool) ( $loc['plugin_active'] ?? false ),
				'path'          => $path,
				'bytes'         => (int) $bytes,
			),
			'warnings' => $warnings,
		);
	}

	/**
	 * Migrate.
	 *
	 * @param array $loc
	 * @param string $migrate_to
	 * @param bool $delete_source
	 * @param array $payload
	 * @param bool $return_content
	 * @return array
	 */
	private function migrate( array $loc, string $migrate_to, bool $delete_source, array $payload, bool $return_content ): array {
		$src      = (string) ( $loc['source'] ?? '' );
		$theme    = (string) ( $loc['theme'] ?? get_stylesheet() );
		$warnings = array();

		// Resolve source content first.
		if ( 'db' === $src ) {
			$post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
			if ( ! $post ) {
				return array(
					'success' => false,
					'message' => __( 'Source DB record not found.', 'acrossai-abilities-manager' ),
				);
			}
			$existing = Global_Styles_Writer::decode( (string) $post->post_content );
			if ( is_wp_error( $existing ) ) {
				return $this->error_response( $existing );
			}
		} else {
			$read = Global_Styles_File::read_json( (string) ( $loc['path'] ?? '' ) );
			if ( is_wp_error( $read ) ) {
				return $this->error_response( $read );
			}
			$existing = $read;
		}

		$merged = empty( $payload )
			? ( is_array( $existing ) ? $existing : array() )
			: Global_Styles_Db::deep_merge( is_array( $existing ) ? $existing : array(), $payload );

		if ( 'db' === $migrate_to ) {
			// theme.json files legitimately carry keys the user record cannot: core reads only
			// version/flag/settings/styles/title from the database and drops the rest. Dropping them
			// here — loudly — is better than refusing the migration over keys that were valid where
			// they came from.
			foreach ( array( 'customTemplates', 'templateParts', 'patterns', 'blockTypes', 'description', '$schema' ) as $file_only ) {
				if ( array_key_exists( $file_only, $merged ) ) {
					unset( $merged[ $file_only ] );
					$warnings[] = sprintf(
						/* translators: %s: theme.json key name. */
						__( 'Dropped "%s": WordPress ignores it in the database Global Styles record. It is still in the theme.json file it came from.', 'acrossai-abilities-manager' ),
						$file_only
					);
				}
			}
		}

		$valid = Global_Styles_Db::validate_data( $merged, 'db' === $migrate_to ? Global_Styles_Db::ORIGIN_USER : Global_Styles_Db::ORIGIN_FILE );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}
		$valid = Global_Styles_Db::validate_block_styles( $merged );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}

		if ( 'db' === $migrate_to ) {
			if ( Global_Styles_Db::find_by_theme( $theme ) ) {
				return array(
					'success' => false,
					'message' => __( 'A DB Global Styles record already exists for this theme. Update it directly instead of migrating.', 'acrossai-abilities-manager' ),
				);
			}
			$id = Global_Styles_Db::create( $theme, $merged );
			if ( is_wp_error( $id ) ) {
				return $this->error_response( $id );
			}
			$new_post   = get_post( (int) $id );
			$warnings[] = __( 'DB version will override the file copy from now on.', 'acrossai-abilities-manager' );

			if ( $delete_source && 'theme' === $src && 'parent' !== ( $loc['theme_type'] ?? '' ) ) {
				$del = Global_Styles_File::delete_file( (string) $loc['path'] );
				if ( is_wp_error( $del ) ) {
					$warnings[] = $del->get_error_message();
				}
			} elseif ( $delete_source ) {
				$warnings[] = __( 'Skipped deleting source — parent-theme and plugin files are preserved.', 'acrossai-abilities-manager' );
			}

			$content_bytes = $new_post ? strlen( (string) $new_post->post_content ) : 0;

			return array(
				'success'       => true,
				/* translators: %s: theme */
				'message'       => sprintf( __( 'Migrated Global Styles for "%s" from file to database.', 'acrossai-abilities-manager' ), $theme ),
				'record'        => $new_post ? Global_Styles_Db::to_row( $new_post, $return_content ) : array(),
				'content_bytes' => $content_bytes,
				'migrated'      => true,
				'warnings'      => $warnings,
			);
		}

		// migrate_to = child_theme. The user-record flag is meaningless in a theme.json file — core
		// only looks for it on the database record — and leaving it in a shipped file is misleading,
		// so it is stripped on the way out.
		unset( $merged[ Global_Styles_Writer::USER_FLAG ] );

		$child_dir = Global_Styles_File::get_child_theme_dir();
		if ( null === $child_dir ) {
			return array(
				'success' => false,
				'message' => __( 'No child theme is active. Create one before migrating to child theme.', 'acrossai-abilities-manager' ),
			);
		}

		$path  = Global_Styles_File::theme_json_path( $child_dir );
		$bytes = Global_Styles_File::write_json( $path, $merged );
		if ( is_wp_error( $bytes ) ) {
			return $this->error_response( $bytes );
		}

		if ( $delete_source && 'db' === $src ) {
			$post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
			if ( $post ) {
				Global_Styles_Db::delete( $post );
				$warnings[] = __( 'DB record deleted — child-theme theme.json is now the only copy.', 'acrossai-abilities-manager' );
			}
		} elseif ( $delete_source && 'theme' === $src && 'parent' === ( $loc['theme_type'] ?? '' ) ) {
			$warnings[] = __( 'Skipped deleting parent-theme source — parent files are preserved.', 'acrossai-abilities-manager' );
		} elseif ( $delete_source && 'plugin' === $src ) {
			$warnings[] = __( 'Skipped deleting plugin source — plugin files are preserved.', 'acrossai-abilities-manager' );
		}

		return array(
			'success'  => true,
			/* translators: %s: path */
			'message'  => sprintf( __( 'Migrated Global Styles to child theme at %s.', 'acrossai-abilities-manager' ), $path ),
			'record'   => array(
				'source'     => 'theme',
				'theme_type' => 'child',
				'theme'      => basename( $child_dir ),
				'path'       => $path,
				'bytes'      => (int) $bytes,
			),
			'migrated' => true,
			'warnings' => $warnings,
		);
	}

	/**
	 * Resolve the payload to write, and record everything the section filter dropped.
	 *
	 * A section write keeps only the paths that section owns, which is correct — but the dropped
	 * keys used to vanish without a word. A `section: "typography"` write carrying `styles.elements`
	 * saved the typography and silently discarded every heading, link and button style in the same
	 * call, and reported success. The ignored paths now come back with the payload so the caller is
	 * told, and a write that saved nothing at all is refused outright.
	 *
	 * @since  0.0.41
	 * @param  array<string, mixed> $input Ability input.
	 * @return array{payload: array<string, mixed>, ignored: array<int, string>}|\WP_Error
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

			// customCss is a single scalar string (theme.json: styles.css) — accept
			// the raw CSS directly so callers don't have to hand-build the JSON wrapper.
			if ( 'customCss' === $norm && isset( $input['data'] ) && is_string( $input['data'] ) ) {
				return array(
					'payload' => array( 'styles' => array( 'css' => (string) $input['data'] ) ),
					'ignored' => array(),
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
			foreach ( Global_Styles_Db::SECTION_PATHS[ $norm ] as $path ) {
				$value = Global_Styles_Db::path_get( $data, $path );
				if ( null !== $value ) {
					Global_Styles_Db::path_set( $payload, $path, $value );
				}
			}

			return array(
				'payload' => $payload,
				'ignored' => self::dropped_paths( $data, $payload ),
			);
		}

		// migrate-only calls don't need content.
		if ( ! isset( $input['content'] ) ) {
			return array(
				'payload' => array(),
				'ignored' => array(),
			);
		}

		$content = $this->coerce_array( $input['content'] );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return array(
			'payload' => $content,
			'ignored' => array(),
		);
	}

	/**
	 * Dotted paths present in the caller's data but absent from what will be written.
	 *
	 * Public-by-visibility-of-test: this is the only thing standing between a caller and a silently
	 * half-applied write, so it is a static helper that can be exercised without WordPress.
	 *
	 * @since  0.0.41
	 * Reported two levels deep and no deeper, because that is the depth at which sections are
	 * defined (`settings.color`, `styles.elements`). Naming the top-level branch alone would say
	 * "styles" when only the element styles were dropped; recursing further would bury the answer
	 * under every individual property.
	 *
	 * @param  array<string, mixed> $sent   What the caller supplied.
	 * @param  array<string, mixed> $kept   What survived the section filter.
	 * @param  string               $prefix Path prefix during recursion.
	 * @param  int                  $depth  Current depth.
	 * @return array<int, string>
	 */
	public static function dropped_paths( array $sent, array $kept, string $prefix = '', int $depth = 0 ): array {
		$dropped = array();

		foreach ( $sent as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( ! array_key_exists( $key, $kept ) ) {
				if ( $depth < 1 && is_array( $value ) && ! empty( $value ) && ! array_key_exists( 0, $value ) ) {
					$dropped = array_merge( $dropped, self::dropped_paths( $value, array(), $path, $depth + 1 ) );
					continue;
				}

				$dropped[] = $path;
				continue;
			}

			if ( $depth < 1 && is_array( $value ) && is_array( $kept[ $key ] ) ) {
				$dropped = array_merge( $dropped, self::dropped_paths( $value, $kept[ $key ], $path, $depth + 1 ) );
			}
		}

		return $dropped;
	}

	/**
	 * Turn dropped paths into caller-facing warnings.
	 *
	 * @since  0.0.41
	 * @param  array<int, string> $ignored Dotted paths that were not saved.
	 * @param  string             $section The section that was written.
	 * @return array<int, string>
	 */
	private function ignored_warnings( array $ignored, string $section ): array {
		if ( empty( $ignored ) ) {
			return array();
		}

		$warnings = array();
		foreach ( $ignored as $path ) {
			$warnings[] = sprintf(
				/* translators: 1: JSON path that was not saved, 2: section name. */
				__( 'ignored: %1$s — not part of section "%2$s", so it was not saved.', 'acrossai-abilities-manager' ),
				$path,
				Global_Styles_Db::normalize_section( $section )
			);
		}

		if ( in_array( 'styles.elements', $ignored, true ) ) {
			$warnings[] = __( 'Element styles (heading, h1-h6, link, button, caption) have their own section: pass section="elements".', 'acrossai-abilities-manager' );
		}

		return $warnings;
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
