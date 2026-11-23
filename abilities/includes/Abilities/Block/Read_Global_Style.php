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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_Db;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_Detector;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_File;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Global Styles. Implements Scenarios 24 and 25:
 *   - With no "section", returns the full record at the selected location.
 *   - With "section", returns just that slice. If the section isn't set in the
 *     DB record, falls back to the theme.json default for that section.
 *   - When no DB record exists, falls back through child → parent theme.json.
 *
 * Always reports all known locations so the caller can see overrides.
 */
class Read_Global_Style extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/read-global-style',
			'args' => array(
				'label'               => __( 'Read Global Style', 'acrossai-abilities-manager' ),
				'description'         => __( 'Reads a Global Styles record (or one section of it) for a theme. Defaults to the database; falls back to theme.json defaults when no DB record exists. Pass "section" to return only colors / typography / spacing / layout / blockStyles / elements / customCss. origin reports "db" only when WordPress is actually applying the record; a stored-but-ignored record reads "db:ignored" and says why in warnings.', 'acrossai-abilities-manager' ),
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
							'enum'    => array( '', 'db', 'theme', 'child_theme', 'plugin' ),
							'default' => '',
						),
						'theme_type'  => array(
							'type'    => 'string',
							'enum'    => array( '', 'child', 'parent', 'theme' ),
							'default' => '',
						),
						'plugin_slug' => array(
							'type'    => 'string',
							'default' => '',
						),
						'section'     => array(
							'type'        => 'string',
							'enum'        => array( '', 'colors', 'typography', 'spacing', 'layout', 'blockStyles', 'elements', 'customCss' ),
							'default'     => '',
							'description' => __( 'Return only this section instead of the full record.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'record'     => array( 'type' => 'object' ),
						'section'    => array( 'type' => 'string' ),
						'data'       => array( 'type' => 'object' ),
						'origin'     => array( 'type' => 'string' ),
						'locations'  => array( 'type' => 'array' ),
						'candidates' => array( 'type' => 'array' ),
						'warnings'   => array( 'type' => 'array' ),
						'message'    => array( 'type' => 'string' ),
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
						'readonly'    => true,
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
		$theme       = sanitize_key( $input['theme'] ?? '' );
		$source      = sanitize_text_field( $input['source'] ?? '' );
		$theme_type  = sanitize_text_field( $input['theme_type'] ?? '' );
		$plugin_slug = sanitize_key( $input['plugin_slug'] ?? '' );
		$section_raw = (string) ( $input['section'] ?? '' );

		$section = '';
		if ( '' !== $section_raw ) {
			$normalized = Global_Styles_Db::normalize_section( $section_raw );
			if ( ! Global_Styles_Db::valid_section( $normalized ) ) {
				return array(
					'success' => false,
					/* translators: %s: list of valid sections */
					'message' => sprintf( __( 'Invalid section. Allowed: %s.', 'acrossai-abilities-manager' ), implode( ', ', Global_Styles_Db::valid_sections() ) ),
				);
			}
			$section = $normalized;
		}

		$locations = Global_Styles_Detector::locate( $theme );

		// Scenario 24 — no record anywhere, fall back to merged theme.json defaults.
		if ( empty( $locations ) ) {
			$fallback = $this->read_theme_json_defaults();
			if ( '' !== $section ) {
				$fallback['data'] = $this->extract_section( $fallback['data'], $section );
			}
			return array(
				'success'   => true,
				'origin'    => $fallback['origin'],
				'section'   => $section,
				'data'      => $fallback['data'],
				'locations' => array(),
				'warnings'  => array( __( 'No Global Styles record exists for this theme. Returning theme.json defaults.', 'acrossai-abilities-manager' ) ),
				'message'   => __( 'No DB record — returning theme.json defaults.', 'acrossai-abilities-manager' ),
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

		$materialised = $this->materialise( $selected );
		if ( is_wp_error( $materialised ) ) {
			return array(
				'success'   => false,
				'message'   => $materialised->get_error_message(),
				'locations' => $locations,
			);
		}

		// Scenario 25 — return just the section, falling back to theme.json when missing.
		if ( '' !== $section ) {
			$slice  = $this->extract_section( $materialised['data'], $section );
			$origin = (string) $materialised['origin'];
			if ( empty( $slice ) ) {
				$fallback = $this->read_theme_json_defaults();
				$slice    = $this->extract_section( $fallback['data'], $section );
				$origin   = $fallback['origin'];
			}
			$materialised['data']   = $slice;
			$materialised['origin'] = $origin;
		}

		$warnings = array_merge(
			(array) ( $materialised['warnings'] ?? array() ),
			$this->collect_warnings( $locations, $selected )
		);

		return array(
			'success'   => true,
			'record'    => $this->summarise( $selected ),
			'section'   => $section,
			'data'      => $materialised['data'],
			'origin'    => $materialised['origin'],
			'locations' => $locations,
			'warnings'  => $warnings,
		);
	}

	/**
	 * Summarise.
	 *
	 * @param array $loc
	 * @return array
	 */
	private function summarise( array $loc ): array {
		$summary = array(
			'source'        => (string) ( $loc['source'] ?? '' ),
			'theme'         => (string) ( $loc['theme'] ?? '' ),
			'theme_type'    => (string) ( $loc['theme_type'] ?? '' ),
			'plugin'        => (string) ( $loc['plugin'] ?? '' ),
			'plugin_active' => (bool) ( $loc['plugin_active'] ?? false ),
			'post_id'       => (int) ( $loc['post_id'] ?? 0 ),
			'path'          => (string) ( $loc['path'] ?? '' ),
		);

		if ( array_key_exists( 'applied_by_wordpress', $loc ) ) {
			$summary['applied_by_wordpress'] = (bool) $loc['applied_by_wordpress'];
		}

		return $summary;
	}

	/**
	 * @return array{data: array, origin: string, warnings: array<int, string>}|\WP_Error
	 */
	private function materialise( array $loc ) {
		$src = (string) ( $loc['source'] ?? '' );
		if ( 'db' === $src ) {
			$post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
			if ( ! $post ) {
				return new \WP_Error( 'db_post_missing', __( 'wp_global_styles post not found.', 'acrossai-abilities-manager' ) );
			}

			// `origin: "db"` is a claim about what WordPress is serving, so it is only made when the
			// record actually qualifies — it decodes and carries isGlobalStylesUserThemeJSON. A
			// record failing either is reported as "db:ignored", with the reason in warnings, rather
			// than passed off as the live styles while the site renders theme.json defaults.
			return array(
				'data'     => Global_Styles_Db::decode_content( $post ),
				'origin'   => Global_Styles_Db::is_applied_by_wordpress( $post ) ? 'db' : 'db:ignored',
				'warnings' => Global_Styles_Db::record_warnings( $post ),
			);
		}

		$data = Global_Styles_File::read_json( (string) ( $loc['path'] ?? '' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return array(
			'data'     => $data,
			'origin'   => 'plugin' === $src ? 'plugin:theme.json' : 'theme:theme.json',
			'warnings' => array(),
		);
	}

	/**
	 * Extract section.
	 *
	 * @param array $data
	 * @param string $section
	 * @return array
	 */
	private function extract_section( array $data, string $section ): array {
		$out = array();
		foreach ( ( Global_Styles_Db::SECTION_PATHS[ $section ] ?? array() ) as $path ) {
			$value = Global_Styles_Db::path_get( $data, $path );
			if ( null !== $value ) {
				Global_Styles_Db::path_set( $out, $path, $value );
			}
		}
		return $out;
	}

	/**
	 * Reads the child theme.json merged over the parent theme.json. Returns
	 * the merged array and an origin string for the response.
	 *
	 * @return array{data: array, origin: string}
	 */
	private function read_theme_json_defaults(): array {
		$parent_dir  = Global_Styles_File::get_parent_theme_dir();
		$parent_json = Global_Styles_File::theme_json_path( $parent_dir );
		$parent      = is_file( $parent_json ) ? Global_Styles_File::read_json( $parent_json ) : array();
		if ( is_wp_error( $parent ) ) {
			$parent = array();
		}

		$child_dir = Global_Styles_File::get_child_theme_dir();
		if ( null === $child_dir ) {
			return array(
				'data'   => $parent,
				'origin' => 'theme.json:parent',
			);
		}

		$child_json = Global_Styles_File::theme_json_path( $child_dir );
		$child      = is_file( $child_json ) ? Global_Styles_File::read_json( $child_json ) : array();
		if ( is_wp_error( $child ) ) {
			$child = array();
		}

		return array(
			'data'   => Global_Styles_Db::deep_merge( is_array( $parent ) ? $parent : array(), is_array( $child ) ? $child : array() ),
			'origin' => 'theme.json:merged',
		);
	}

	/**
	 * Collect warnings.
	 *
	 * @param array $locations
	 * @param array $selected
	 * @return array
	 */
	private function collect_warnings( array $locations, array $selected ): array {
		$warnings  = array();
		$effective = Global_Styles_Detector::effective( $locations );

		if ( count( $locations ) > 1 ) {
			$warnings[] = __( 'Multiple Global Styles locations exist. WordPress always serves the highest-priority copy: DB → child theme → parent theme → plugin.', 'acrossai-abilities-manager' );
		}

		if ( $effective && false === ( $effective['applied_by_wordpress'] ?? true ) ) {
			$warnings[] = __( 'The DB record outranks the theme.json copies, but WordPress is ignoring it, so the theme.json values are what the site is rendering.', 'acrossai-abilities-manager' );
		}

		if ( $effective && ( $selected['source'] ?? '' ) !== ( $effective['source'] ?? '' ) ) {
			$warnings[] = __( 'You are reading a copy that is not the one WordPress is currently serving.', 'acrossai-abilities-manager' );
		}

		foreach ( $locations as $loc ) {
			if ( ( $loc['source'] ?? '' ) === 'plugin' && false === ( $loc['plugin_active'] ?? true ) ) {
				/* translators: %s: plugin slug */
				$warnings[] = sprintf( __( 'Plugin "%s" is inactive — its theme.json will not register until the plugin is activated.', 'acrossai-abilities-manager' ), $loc['plugin'] ?? '' );
			}
		}

		return $warnings;
	}
}
