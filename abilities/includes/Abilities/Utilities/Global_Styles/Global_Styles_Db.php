<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Global_Styles
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles;

defined( 'ABSPATH' ) || exit;

/**
 * Database storage for wp_global_styles posts (the Site Editor's per-theme
 * Global Styles record). Mirrors the Template_Db structure but works on
 * raw theme.json-shaped JSON in post_content.
 *
 * Section abstraction:
 *  - User-facing section names (colors, typography, spacing, layout,
 *    blockStyles, customCss) map to theme.json sub-paths via SECTION_PATHS.
 *  - This lets List/Read/Update/Delete operate on a whole record OR on a
 *    single section without exposing theme.json's nested settings/styles
 *    split.
 */
final class Global_Styles_Db {

	public const POST_TYPE = 'wp_global_styles';
	public const THEME_TAX = 'wp_theme';

	/**
	 * Which shape a payload is validated against.
	 *
	 * A theme.json *file* and the *user record* in the database are different documents with
	 * different legal keys, and validating one against the other was a real defect: the DB source
	 * rejected `isGlobalStylesUserThemeJSON` as an unknown key — the very key it must store — while
	 * accepting `templateParts`, `customTemplates` and `patterns`, which mean nothing there and are
	 * dropped on the floor by core.
	 *
	 * @var string
	 */
	public const ORIGIN_FILE = 'file';

	/**
	 * The user record in wp_global_styles. See {@see self::ORIGIN_FILE}.
	 *
	 * @var string
	 */
	public const ORIGIN_USER = 'user';

	/**
	 * Top-level keys a theme.json file may carry.
	 *
	 * @var array<int, string>
	 */
	public const FILE_KEYS = array( 'version', 'settings', 'styles', 'customTemplates', 'templateParts', 'patterns', '$schema', 'title', 'description', 'blockTypes' );

	/**
	 * Top-level keys the user record in wp_global_styles may carry.
	 *
	 * Matches what core itself stores and reads: `WP_REST_Global_Styles_Controller` persists
	 * `settings`, `styles` and a title, and `WP_Theme_JSON_Resolver::get_user_data()` reads `version`
	 * plus the flag. Nothing else has any effect from this source.
	 *
	 * @var array<int, string>
	 */
	public const USER_KEYS = array( 'version', Global_Styles_Writer::USER_FLAG, 'settings', 'styles', 'title' );

	/**
	 * Canonical section names exposed by the abilities.
	 */
	public const SECTIONS = array( 'colors', 'typography', 'spacing', 'layout', 'blockStyles', 'elements', 'customCss' );

	/**
	 * Maps each section to the list of theme.json paths it owns. Used by
	 * get_section / update_section / delete_section / customized_sections.
	 *
	 * @var array<string, array<int, array<int, string>>>
	 */
	public const SECTION_PATHS = array(
		'colors'      => array( array( 'settings', 'color' ), array( 'styles', 'color' ) ),
		'typography'  => array( array( 'settings', 'typography' ), array( 'styles', 'typography' ) ),
		'spacing'     => array( array( 'settings', 'spacing' ), array( 'styles', 'spacing' ) ),
		'layout'      => array( array( 'settings', 'layout' ) ),
		'blockStyles' => array( array( 'settings', 'blocks' ), array( 'styles', 'blocks' ) ),
		'elements'    => array( array( 'styles', 'elements' ) ),
		'customCss'   => array( array( 'styles', 'css' ) ),
	);

	/**
	 * Valid sections.
	 *
	 * @return array
	 */
	public static function valid_sections(): array {
		return self::SECTIONS;
	}

	/**
	 * Valid section.
	 *
	 * @param string $section
	 * @return bool
	 */
	public static function valid_section( string $section ): bool {
		return in_array( self::normalize_section( $section ), self::SECTIONS, true );
	}

	/**
	 * Accepts "blockStyles", "block_styles", "block-styles", "BlockStyles", etc.
	 */
	public static function normalize_section( string $section ): string {
		$section = strtolower( trim( $section ) );
		$section = str_replace( array( '_', '-', ' ' ), '', $section );
		$map     = array(
			'colors'      => 'colors',
			'typography'  => 'typography',
			'spacing'     => 'spacing',
			'layout'      => 'layout',
			'blockstyles' => 'blockStyles',
			'elements'    => 'elements',
			'customcss'   => 'customCss',
		);
		return $map[ $section ] ?? $section;
	}

	// -------------------------------------------------------------------------
	// Lookups
	// -------------------------------------------------------------------------

	/**
	 * Finds the wp_global_styles post for a theme. There is at most one per theme.
	 */
	public static function find_by_theme( string $theme = '' ): ?\WP_Post {
		$theme = '' !== $theme ? sanitize_key( $theme ) : (string) get_stylesheet();
		if ( '' === $theme ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => self::THEME_TAX,
						'field'    => 'name',
						'terms'    => $theme,
					),
				),
			)
		);
		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * @return \WP_Post[]
	 */
	public static function list_all( int $limit = 200 ): array {
		return (array) get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => max( 1, min( 500, $limit ) ),
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Create / Update / Delete (whole record)
	// -------------------------------------------------------------------------

	/**
	 * Creates a new wp_global_styles record for $theme.
	 *
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function create( string $theme, array $data ) {
		$theme = '' !== $theme ? sanitize_key( $theme ) : (string) get_stylesheet();
		if ( '' === $theme ) {
			return new \WP_Error( 'invalid_theme', __( 'Theme is required.', 'acrossai-abilities-manager' ) );
		}

		$valid = self::validate_data( $data, self::ORIGIN_USER );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$valid = self::validate_block_styles( $data );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( self::find_by_theme( $theme ) ) {
			return new \WP_Error( 'exists', __( 'A Global Styles record already exists for this theme. Use update instead.', 'acrossai-abilities-manager' ) );
		}

		// Global_Styles_Writer stamps version + isGlobalStylesUserThemeJSON and slashes the JSON;
		// see that class for why a hand-built wp_insert_post() call cannot be used here.
		$post_id = Global_Styles_Writer::insert(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				/* translators: %s: theme slug */
				'post_title'  => sprintf( __( 'Custom Styles - %s', 'acrossai-abilities-manager' ), $theme ),
				'post_name'   => 'wp-global-styles-' . $theme,
			),
			$data
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_set_object_terms( (int) $post_id, $theme, self::THEME_TAX );
		return (int) $post_id;
	}

	/**
	 * Replaces (or deep-merges) the entire record with new data.
	 *
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function update( \WP_Post $post, array $data, bool $merge = true ) {
		if ( $merge ) {
			// A record that does not decode must never be merged into. Treating it as empty — which
			// is what decode_content() reports — silently discards every section stored before the
			// write that broke it, turning one bad write into total loss. Replacing the record
			// outright (merge=false) is still allowed, because that is the repair path.
			$existing = Global_Styles_Writer::decode( (string) $post->post_content );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			$new = self::deep_merge( $existing, $data );
		} else {
			$new = $data;
		}

		$valid = self::validate_data( $new, self::ORIGIN_USER );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$valid = self::validate_block_styles( $new );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return Global_Styles_Writer::update( (int) $post->ID, $new );
	}

	/**
	 * Add the flags WordPress requires to a record that is otherwise left alone.
	 *
	 * The repair path for records written by versions of this plugin that stored `settings`/`styles`
	 * with no `isGlobalStylesUserThemeJSON`: WordPress ignored those records entirely. Every ordinary
	 * write now repairs them as a side effect; this exists for the case where an operator wants the
	 * flags added without changing a single style.
	 *
	 * @since  0.0.41
	 * @param  \WP_Post $post Target record.
	 * @return array{post_id: int, changed: bool}|\WP_Error
	 */
	public static function repair( \WP_Post $post ) {
		$existing = Global_Styles_Writer::decode( (string) $post->post_content );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$already = Global_Styles_Writer::has_user_flags( $existing )
			&& isset( $existing['version'] )
			&& Global_Styles_Writer::latest_schema() === (int) $existing['version'];

		if ( $already ) {
			return array(
				'post_id' => (int) $post->ID,
				'changed' => false,
			);
		}

		$result = Global_Styles_Writer::update( (int) $post->ID, $existing );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'post_id' => (int) $result,
			'changed' => true,
		);
	}

	/**
	 * Delete.
	 *
	 * @param \WP_Post $post
	 * @return bool
	 */
	public static function delete( \WP_Post $post ): bool {
		$result = wp_delete_post( (int) $post->ID, true );
		return false !== $result && null !== $result;
	}

	// -------------------------------------------------------------------------
	// Section-scoped operations (Scenarios 17, 25)
	// -------------------------------------------------------------------------

	/**
	 * Extracts a section's data from the record. Returns an array shaped the
	 * same way it would appear in theme.json (i.e. with the settings/styles
	 * wrapper preserved).
	 */
	public static function get_section( \WP_Post $post, string $section ): array {
		$section = self::normalize_section( $section );
		$data    = self::decode_content( $post );
		$out     = array();

		foreach ( ( self::SECTION_PATHS[ $section ] ?? array() ) as $path ) {
			$value = self::path_get( $data, $path );
			if ( null !== $value ) {
				self::path_set( $out, $path, $value );
			}
		}
		return $out;
	}

	/**
	 * Sets a section's data into the record. $section_data must follow theme.json
	 * shape (e.g. for "colors", { settings: { color: {...} }, styles: { color: {...} } }).
	 *
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function update_section( \WP_Post $post, string $section, array $section_data ) {
		$section = self::normalize_section( $section );
		if ( ! in_array( $section, self::SECTIONS, true ) ) {
			return new \WP_Error(
				'invalid_section',
				/* translators: %s: list of valid sections */
				sprintf( __( 'Section must be one of: %s.', 'acrossai-abilities-manager' ), implode( ', ', self::SECTIONS ) )
			);
		}

		$existing = Global_Styles_Writer::decode( (string) $post->post_content );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		foreach ( self::SECTION_PATHS[ $section ] as $path ) {
			$value = self::path_get( $section_data, $path );
			if ( null !== $value ) {
				self::path_set( $existing, $path, $value );
			}
		}

		return self::update( $post, $existing, false );
	}

	/**
	 * Removes a section from the record while keeping the rest intact.
	 *
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function delete_section( \WP_Post $post, string $section ) {
		$section = self::normalize_section( $section );
		if ( ! in_array( $section, self::SECTIONS, true ) ) {
			return new \WP_Error(
				'invalid_section',
				/* translators: %s: list of valid sections */
				sprintf( __( 'Section must be one of: %s.', 'acrossai-abilities-manager' ), implode( ', ', self::SECTIONS ) )
			);
		}

		$existing = Global_Styles_Writer::decode( (string) $post->post_content );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		foreach ( self::SECTION_PATHS[ $section ] as $path ) {
			self::path_delete( $existing, $path );
		}

		// After section removal, allow an empty styles/settings tree: the flags are stamped here so
		// deleting the last section leaves `{ version, isGlobalStylesUserThemeJSON }` — exactly what
		// core seeds a fresh record with — rather than tripping the empty-content guard.
		return self::update( $post, Global_Styles_Writer::with_user_flags( $existing ), false );
	}

	// -------------------------------------------------------------------------
	// Read helpers
	// -------------------------------------------------------------------------

	/**
	 * Decode content.
	 *
	 * @param \WP_Post $post
	 * @return array
	 */
	public static function decode_content( \WP_Post $post ): array {
		$decoded = Global_Styles_Writer::decode( (string) $post->post_content );

		// Lossy by design, and kept only for callers that genuinely have nothing to do with a broken
		// record (to_row's metadata, get_customized_sections). Anything that writes, or that reports
		// what WordPress is serving, MUST use Global_Styles_Writer::decode() and handle the error —
		// collapsing "corrupt" into "empty" here is what let one bad write erase earlier sections.
		return is_wp_error( $decoded ) ? array() : $decoded;
	}

	/**
	 * Whether the record stores content that is not valid JSON.
	 *
	 * @since  0.0.41
	 * @param  \WP_Post $post Record to inspect.
	 * @return bool
	 */
	public static function is_corrupt( \WP_Post $post ): bool {
		return is_wp_error( Global_Styles_Writer::decode( (string) $post->post_content ) );
	}

	/**
	 * Whether WordPress will actually apply this record.
	 *
	 * Both conditions are core's, from `WP_Theme_JSON_Resolver::get_user_data()`: the content decodes
	 * to an array, and it carries `isGlobalStylesUserThemeJSON`. A record failing either is stored,
	 * readable, and completely inert — which is exactly the state this subsystem used to report as
	 * `origin: "db"` and `effective: true`.
	 *
	 * @since  0.0.41
	 * @param  \WP_Post $post Record to inspect.
	 * @return bool
	 */
	public static function is_applied_by_wordpress( \WP_Post $post ): bool {
		$decoded = Global_Styles_Writer::decode( (string) $post->post_content );
		if ( is_wp_error( $decoded ) ) {
			return false;
		}

		// The flag rule is about the one record per theme that core reads as user data. Block style
		// variations share this post type but are read by a different path and must NOT carry the
		// flag, so judging them by it would report every variation on the site as broken.
		if ( ! self::is_main_record( $post ) ) {
			return true;
		}

		return Global_Styles_Writer::has_user_flags( $decoded );
	}

	/**
	 * Whether this row is the per-theme user record rather than a block style variation.
	 *
	 * WordPress names the record it creates `wp-global-styles-{stylesheet}`; variations stored in the
	 * same post type deliberately do not use that prefix.
	 *
	 * @since  0.0.41
	 * @param  \WP_Post $post Row to classify.
	 * @return bool
	 */
	public static function is_main_record( \WP_Post $post ): bool {
		return 0 === strpos( (string) $post->post_name, 'wp-global-styles-' );
	}

	/**
	 * Warnings that must accompany any report of this record.
	 *
	 * @since  0.0.41
	 * @param  \WP_Post $post Record to inspect.
	 * @return array<int, string>
	 */
	public static function record_warnings( \WP_Post $post ): array {
		$decoded = Global_Styles_Writer::decode( (string) $post->post_content );

		if ( is_wp_error( $decoded ) ) {
			return array( __( 'The DB record does not contain valid JSON; WordPress is ignoring it and serving theme.json defaults instead. Re-save the whole record (merge=false) or run update-global-style with repair=true.', 'acrossai-abilities-manager' ) );
		}

		if ( self::is_main_record( $post ) && ! Global_Styles_Writer::has_user_flags( $decoded ) ) {
			return array( __( 'DB record is missing isGlobalStylesUserThemeJSON; WordPress is ignoring it. Any write through update-global-style adds it, or run update-global-style with repair=true.', 'acrossai-abilities-manager' ) );
		}

		return array();
	}

	/**
	 * To row.
	 *
	 * @param \WP_Post $post
	 * @param bool $include_content
	 * @return array
	 */
	public static function to_row( \WP_Post $post, bool $include_content = false ): array {
		$theme    = self::get_post_theme( $post );
		$applied  = self::is_applied_by_wordpress( $post );
		$warnings = self::record_warnings( $post );

		$row = array(
			'source'               => 'db',
			'post_id'              => (int) $post->ID,
			'title'                => (string) $post->post_title,
			'theme'                => $theme,
			'is_active_theme'      => $theme === (string) get_stylesheet(),
			'customized_sections'  => self::get_customized_sections( $post ),
			'modified'             => (string) $post->post_modified_gmt,
			// Reported on every row, because "stored" and "in use" are different facts and the old
			// row only ever implied the second.
			'applied_by_wordpress' => $applied,
		);

		if ( ! empty( $warnings ) ) {
			$row['warnings'] = $warnings;
		}

		if ( $include_content ) {
			$row['data'] = self::decode_content( $post );
		}
		return $row;
	}

	/**
	 * Get post theme.
	 *
	 * @param \WP_Post $post
	 * @return string
	 */
	public static function get_post_theme( \WP_Post $post ): string {
		$terms = wp_get_object_terms( (int) $post->ID, self::THEME_TAX, array( 'fields' => 'names' ) );
		return ! is_wp_error( $terms ) && ! empty( $terms ) ? (string) $terms[0] : '';
	}

	/**
	 * Lists section names that actually have content in this record.
	 *
	 * @return string[]
	 */
	public static function get_customized_sections( \WP_Post $post ): array {
		$data = self::decode_content( $post );
		if ( empty( $data ) ) {
			return array();
		}

		$customized = array();
		foreach ( self::SECTION_PATHS as $section => $paths ) {
			foreach ( $paths as $path ) {
				$value = self::path_get( $data, $path );
				if ( null === $value ) {
					continue;
				}
				if ( is_array( $value ) && empty( $value ) ) {
					continue;
				}
				$customized[] = $section;
				break;
			}
		}
		return $customized;
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Basic structure validation. Rejects empty or unknown top-level keys but does not enforce the
	 * full JSON Schema (callers may pass partial patches during update).
	 *
	 * @param  array<string, mixed> $data   Payload to check.
	 * @param  string               $origin self::ORIGIN_FILE for a theme.json file (the default, so
	 *                                      existing callers keep file semantics) or
	 *                                      self::ORIGIN_USER for the wp_global_styles record.
	 * @return true|\WP_Error
	 */
	public static function validate_data( array $data, string $origin = self::ORIGIN_FILE ) {
		if ( empty( $data ) ) {
			return new \WP_Error( 'empty_content', __( 'Global Styles content cannot be empty.', 'acrossai-abilities-manager' ) );
		}

		$is_user = self::ORIGIN_USER === $origin;
		$allowed = $is_user ? self::USER_KEYS : self::FILE_KEYS;

		foreach ( array_keys( $data ) as $key ) {
			if ( in_array( $key, $allowed, true ) ) {
				continue;
			}

			// Name the file-only keys explicitly: a caller sending templateParts to the DB source has
			// not made a typo, they have the wrong mental model, and "unknown key" would not say so.
			if ( $is_user && in_array( $key, array( 'customTemplates', 'templateParts', 'patterns', 'blockTypes' ), true ) ) {
				return new \WP_Error(
					'invalid_structure',
					sprintf(
						/* translators: 1: rejected key name, 2: comma-separated list of allowed keys. */
						__( '"%1$s" belongs in a theme.json file, not in the database Global Styles record — WordPress ignores it from that source. Write it with blocks/update-theme-json instead. Allowed here: %2$s.', 'acrossai-abilities-manager' ),
						$key,
						implode( ', ', $allowed )
					)
				);
			}

			return new \WP_Error(
				'invalid_structure',
				sprintf(
					/* translators: 1: invalid key name, 2: comma-separated list of allowed keys. */
					__( 'Unknown top-level key "%1$s". Allowed: %2$s.', 'acrossai-abilities-manager' ),
					$key,
					implode( ', ', $allowed )
				)
			);
		}

		// settings/styles should be objects when present.
		foreach ( array( 'settings', 'styles' ) as $key ) {
			if ( isset( $data[ $key ] ) && ! is_array( $data[ $key ] ) ) {
				return new \WP_Error(
					'invalid_structure',
					/* translators: %s: key name */
					sprintf( __( '"%s" must be a JSON object.', 'acrossai-abilities-manager' ), $key )
				);
			}
		}

		return true;
	}

	/**
	 * Scenario 20: every block name under styles.blocks (and settings.blocks)
	 * must be registered with WP_Block_Type_Registry.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_block_styles( array $data ) {
		$blocks = array();
		foreach ( array( array( 'settings', 'blocks' ), array( 'styles', 'blocks' ) ) as $path ) {
			$value = self::path_get( $data, $path );
			if ( is_array( $value ) ) {
				foreach ( array_keys( $value ) as $name ) {
					$blocks[ (string) $name ] = true;
				}
			}
		}
		if ( empty( $blocks ) ) {
			return true;
		}

		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return true; // Cannot validate; trust caller.
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		$invalid  = array();
		foreach ( array_keys( $blocks ) as $name ) {
			if ( ! $registry->is_registered( $name ) ) {
				$invalid[] = $name;
			}
		}

		if ( ! empty( $invalid ) ) {
			return new \WP_Error(
				'unregistered_blocks',
				/* translators: %s: comma-separated list of block names */
				sprintf( __( 'Block style references unregistered blocks: %s.', 'acrossai-abilities-manager' ), implode( ', ', $invalid ) ),
				array( 'invalid_blocks' => $invalid )
			);
		}

		return true;
	}

	/**
	 * Parses a JSON string into an array, returning WP_Error on parse failure.
	 *
	 * @return array|\WP_Error
	 */
	public static function parse_json( string $json ) {
		$json = trim( $json );
		if ( '' === $json ) {
			return new \WP_Error( 'empty_content', __( 'JSON content cannot be empty.', 'acrossai-abilities-manager' ) );
		}
		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'invalid_json',
				/* translators: %s: JSON parse error */
				sprintf( __( 'Invalid JSON: %s', 'acrossai-abilities-manager' ), json_last_error_msg() )
			);
		}
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'invalid_json', __( 'JSON must decode to an object.', 'acrossai-abilities-manager' ) );
		}
		return $decoded;
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function encode_json( array $data ) {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return new \WP_Error( 'json_encode_failed', __( 'Could not encode Global Styles data as JSON.', 'acrossai-abilities-manager' ) );
		}
		return (string) $json;
	}

	// -------------------------------------------------------------------------
	// Path / merge helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the value at $path inside $data, or null when absent.
	 *
	 * @param array<int, string> $path
	 */
	public static function path_get( array $data, array $path ) {
		foreach ( $path as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return null;
			}
			$data = $data[ $key ];
		}
		return $data;
	}

	/**
	 * Sets the value at $path inside $data, creating intermediate arrays as needed.
	 *
	 * @param array<int, string> $path
	 */
	public static function path_set( array &$data, array $path, $value ): void {
		if ( empty( $path ) ) {
			return;
		}
		$cursor = &$data;
		foreach ( $path as $key ) {
			if ( ! isset( $cursor[ $key ] ) || ! is_array( $cursor[ $key ] ) ) {
				$cursor[ $key ] = array();
			}
			$cursor = &$cursor[ $key ];
		}
		$cursor = $value;
	}

	/**
	 * Removes the value at $path inside $data, leaving siblings intact.
	 *
	 * @param array<int, string> $path
	 */
	public static function path_delete( array &$data, array $path ): void {
		if ( empty( $path ) ) {
			return;
		}
		$leaf   = array_pop( $path );
		$cursor = &$data;
		foreach ( $path as $key ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
				return;
			}
			$cursor = &$cursor[ $key ];
		}
		if ( is_array( $cursor ) ) {
			unset( $cursor[ $leaf ] );
		}
	}

	/**
	 * Recursively merges $b into $a. Scalar/non-array values in $b replace $a's.
	 */
	public static function deep_merge( array $a, array $b ): array {
		foreach ( $b as $key => $value ) {
			if ( is_array( $value ) && isset( $a[ $key ] ) && is_array( $a[ $key ] ) ) {
				$a[ $key ] = self::deep_merge( $a[ $key ], $value );
			} else {
				$a[ $key ] = $value;
			}
		}
		return $a;
	}
}
