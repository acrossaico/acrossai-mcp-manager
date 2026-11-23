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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_Db;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a Block Style Variation. Implements Scenarios 28 + 29:
 *   - With no "section", returns the full record at the selected location.
 *   - With "section", returns just that slice. Falls back to file defaults
 *     when missing from the DB record.
 */
class Read_Block_Style_Variation extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/read-block-style-variation',
			'args' => array(
				'label'               => __( 'Read Block Style Variation', 'acrossai-abilities-manager' ),
				'description'         => __( 'Reads a Block Style Variation by slug. Defaults to the database; falls back to /styles file defaults. Pass "section" to return only colors / typography / spacing / layout / blockStyles.', 'acrossai-abilities-manager' ),
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
							'description' => __( 'Variation slug (e.g. "dark", "light").', 'acrossai-abilities-manager' ),
						),
						'theme'       => array(
							'type'    => 'string',
							'default' => '',
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
							'type'    => 'string',
							'enum'    => array( '', 'colors', 'typography', 'spacing', 'layout', 'blockStyles' ),
							'default' => '',
						),
					),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'variation'  => array( 'type' => 'object' ),
						'data'       => array( 'type' => 'object' ),
						'origin'     => array( 'type' => 'string' ),
						'section'    => array( 'type' => 'string' ),
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
						'sub_group'       => 'block-style-variations',
						'sub_group_label' => __( 'Block Style Variations', 'acrossai-abilities-manager' ),
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
		$slug        = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$theme       = sanitize_key( $input['theme'] ?? '' );
		$source      = sanitize_text_field( $input['source'] ?? '' );
		$theme_type  = sanitize_text_field( $input['theme_type'] ?? '' );
		$plugin_slug = sanitize_key( $input['plugin_slug'] ?? '' );
		$section_raw = (string) ( $input['section'] ?? '' );

		if ( '' === $slug ) {
			return array(
				'success' => false,
				'message' => __( 'Slug is required.', 'acrossai-abilities-manager' ),
			);
		}

		$section = '';
		if ( '' !== $section_raw ) {
			$normalized = Variation_Db::normalize_section( $section_raw );
			if ( ! Variation_Db::valid_section( $normalized ) ) {
				return array(
					'success' => false,
					/* translators: %s: list of valid sections */
					'message' => sprintf( __( 'Invalid section. Allowed: %s.', 'acrossai-abilities-manager' ), implode( ', ', Variation_Db::valid_sections() ) ),
				);
			}
			$section = $normalized;
		}

		$locations = Variation_Detector::locate( $slug, $theme );
		if ( empty( $locations ) ) {
			return array(
				'success'   => false,
				/* translators: %s: slug */
				'message'   => sprintf( __( 'No Block Style Variation with slug "%s" was found. Use block-style-variations-create to add one.', 'acrossai-abilities-manager' ), $slug ),
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

		$materialised = $this->materialise( $selected );
		if ( is_wp_error( $materialised ) ) {
			return array(
				'success'   => false,
				'message'   => $materialised->get_error_message(),
				'locations' => $locations,
			);
		}

		if ( '' !== $section ) {
			$slice  = $this->extract_section( $materialised['data'], $section );
			$origin = $materialised['origin'];
			if ( empty( $slice ) ) {
				// Fall back to file defaults from a non-selected location if any.
				$fallback = $this->lookup_file_fallback( $locations, $section );
				if ( null !== $fallback ) {
					$slice  = $fallback['data'];
					$origin = $fallback['origin'];
				}
			}
			$materialised['data']   = $slice;
			$materialised['origin'] = $origin;
		}

		$warnings = $this->collect_warnings( $locations, $selected );

		return array(
			'success'   => true,
			'variation' => $this->summarise( $selected ),
			'data'      => $materialised['data'],
			'origin'    => $materialised['origin'],
			'section'   => $section,
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
		return array(
			'source'              => (string) ( $loc['source'] ?? '' ),
			'theme'               => (string) ( $loc['theme'] ?? '' ),
			'theme_type'          => (string) ( $loc['theme_type'] ?? '' ),
			'plugin'              => (string) ( $loc['plugin'] ?? '' ),
			'plugin_active'       => (bool) ( $loc['plugin_active'] ?? false ),
			'slug'                => (string) ( $loc['slug'] ?? '' ),
			'post_id'             => (int) ( $loc['post_id'] ?? 0 ),
			'path'                => (string) ( $loc['path'] ?? '' ),
			'is_active_variation' => (bool) ( $loc['is_active_variation'] ?? false ),
		);
	}

	/**
	 * @return array{data: array, origin: string}|\WP_Error
	 */
	private function materialise( array $loc ) {
		$src = (string) ( $loc['source'] ?? '' );
		if ( 'db' === $src ) {
			$post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
			if ( ! $post ) {
				return new \WP_Error( 'db_post_missing', __( 'wp_global_styles variation post not found.', 'acrossai-abilities-manager' ) );
			}
			return array(
				'data'   => Variation_Db::decode_content( $post ),
				'origin' => 'db',
			);
		}
		$data = Variation_File::read_json( (string) ( $loc['path'] ?? '' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return array(
			'data'   => $data,
			'origin' => 'plugin' === $src ? 'plugin:styles' : 'theme:styles',
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
		foreach ( ( Variation_Db::SECTION_PATHS[ $section ] ?? array() ) as $path ) {
			$value = Global_Styles_Db::path_get( $data, $path );
			if ( null !== $value ) {
				Global_Styles_Db::path_set( $out, $path, $value );
			}
		}
		return $out;
	}

	/**
	 * Looks at other (non-DB) locations for a section default.
	 *
	 * @return array{data: array, origin: string}|null
	 */
	private function lookup_file_fallback( array $locations, string $section ): ?array {
		foreach ( $locations as $loc ) {
			if ( ( $loc['source'] ?? '' ) === 'db' ) {
				continue;
			}
			$data = Variation_File::read_json( (string) ( $loc['path'] ?? '' ) );
			if ( is_wp_error( $data ) ) {
				continue;
			}
			$slice = $this->extract_section( $data, $section );
			if ( ! empty( $slice ) ) {
				return array(
					'data'   => $slice,
					'origin' => 'plugin' === ( $loc['source'] ?? '' ) ? 'plugin:styles' : 'theme:styles',
				);
			}
		}
		return null;
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
		$effective = Variation_Detector::effective( $locations );

		if ( count( $locations ) > 1 ) {
			$warnings[] = __( 'Multiple locations exist. WordPress always serves the highest-priority copy: DB → child theme → parent theme → plugin.', 'acrossai-abilities-manager' );
		}
		if ( $effective && ( $selected['source'] ?? '' ) !== ( $effective['source'] ?? '' ) ) {
			$warnings[] = __( 'You are reading a copy that is not the one WordPress is currently serving.', 'acrossai-abilities-manager' );
		}
		foreach ( $locations as $loc ) {
			if ( ( $loc['source'] ?? '' ) === 'plugin' && false === ( $loc['plugin_active'] ?? true ) ) {
				/* translators: %s: plugin slug */
				$warnings[] = sprintf( __( 'Plugin "%s" is inactive — its variation will not register until the plugin is activated.', 'acrossai-abilities-manager' ), $loc['plugin'] ?? '' );
			}
		}
		return $warnings;
	}
}
