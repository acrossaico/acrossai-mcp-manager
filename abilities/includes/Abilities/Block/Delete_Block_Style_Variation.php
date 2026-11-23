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
 * Deletes a Block Style Variation from one storage location.
 *  - Scenario 17: no "section" + confirm=true deletes the whole record/file.
 *  - Scenario 18: with "section", removes only that section.
 *  - Scenario 3: refuses to delete parent-theme files.
 *  - Scenario 24: warns when the variation is currently active; requires
 *    confirm_active to proceed regardless.
 *  - Scenarios 15, 16: file ops route through Variation_File → File_Mods_Guard.
 */
class Delete_Block_Style_Variation extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/delete-block-style-variation',
			'args' => array(
				'label'               => __( 'Delete Block Style Variation', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deletes a Block Style Variation. Without "section", deletes the whole record/file (requires confirm=true). With "section", removes just that slice. Refuses to delete parent-theme files; active variations require confirm_active.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'           => array( 'type' => 'string' ),
						'theme'          => array(
							'type'    => 'string',
							'default' => '',
						),
						'source'         => array(
							'type'    => 'string',
							'enum'    => array( '', 'db', 'theme', 'child_theme', 'plugin' ),
							'default' => '',
						),
						'theme_type'     => array(
							'type'    => 'string',
							'enum'    => array( '', 'child', 'parent', 'theme' ),
							'default' => '',
						),
						'plugin_slug'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'section'        => array(
							'type'    => 'string',
							'enum'    => array( '', 'colors', 'typography', 'spacing', 'layout', 'blockStyles' ),
							'default' => '',
						),
						'confirm'        => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Required when deleting the entire variation.', 'acrossai-abilities-manager' ),
						),
						'confirm_active' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Required when the variation is currently active on the site.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'deleted'    => array( 'type' => 'object' ),
						'mode'       => array( 'type' => 'string' ),
						'warnings'   => array( 'type' => 'array' ),
						'locations'  => array( 'type' => 'array' ),
						'candidates' => array( 'type' => 'array' ),
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
						'readonly'    => false,
						'destructive' => true,
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
		$slug           = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$theme          = sanitize_key( $input['theme'] ?? '' );
		$source         = sanitize_text_field( $input['source'] ?? '' );
		$theme_type     = sanitize_text_field( $input['theme_type'] ?? '' );
		$plugin_slug    = sanitize_key( $input['plugin_slug'] ?? '' );
		$section_raw    = (string) ( $input['section'] ?? '' );
		$confirm        = ! empty( $input['confirm'] );
		$confirm_active = ! empty( $input['confirm_active'] );

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
				'message'   => sprintf( __( 'No Block Style Variation with slug "%s" was found.', 'acrossai-abilities-manager' ), $slug ),
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

		if ( 'theme' === $selected_src || 'plugin' === $selected_src ) {
			$blocked = File_Mods_Guard::blocked_response();
			if ( null !== $blocked ) {
				return $blocked;
			}
		}

		// Scenario 3 — never delete parent-theme files.
		if ( 'theme' === $selected_src && 'parent' === ( $selected['theme_type'] ?? '' ) ) {
			return array(
				'success'   => false,
				'message'   => __( 'Refusing to delete a parent-theme variation file.', 'acrossai-abilities-manager' ),
				'locations' => $locations,
			);
		}

		// Scenario 24 — active variation guard for full deletes.
		if ( '' === $section && 'db' === $selected_src && ! empty( $selected['is_active_variation'] ) && ! $confirm_active ) {
			return array(
				'success'   => false,
				'message'   => __( 'This variation is currently active on the site. Re-run with confirm_active=true to delete it; the site will revert to default styles.', 'acrossai-abilities-manager' ),
				'locations' => $locations,
			);
		}

		if ( '' !== $section ) {
			return $this->delete_section( $selected, $section );
		}

		// Scenario 17 — whole delete requires confirm.
		if ( ! $confirm ) {
			return array(
				'success'   => false,
				'message'   => __( 'Re-run with confirm=true to delete the entire variation. All customisations in this variation will be lost.', 'acrossai-abilities-manager' ),
				'locations' => $locations,
			);
		}

		return $this->delete_whole( $selected, $locations );
	}

	/**
	 * Delete section.
	 *
	 * @param array $loc
	 * @param string $section
	 * @return array
	 */
	private function delete_section( array $loc, string $section ): array {
		$src = (string) ( $loc['source'] ?? '' );

		if ( 'db' === $src ) {
			$post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
			if ( ! $post ) {
				return array(
					'success' => false,
					'message' => __( 'wp_global_styles variation post not found.', 'acrossai-abilities-manager' ),
				);
			}
			$result = Variation_Db::delete_section( $post, $section );
			if ( is_wp_error( $result ) ) {
				return $this->error_response( $result );
			}
			$updated = get_post( (int) $result );
			return array(
				'success'  => true,
				/* translators: %s: section name */
				'message'  => sprintf( __( 'Removed "%s" from the DB variation.', 'acrossai-abilities-manager' ), $section ),
				'deleted'  => $updated ? Variation_Db::to_row( $updated, true ) : array(),
				'mode'     => 'section',
				'warnings' => array(),
			);
		}

		$path = (string) ( $loc['path'] ?? '' );
		$data = Variation_File::read_json( $path );
		if ( is_wp_error( $data ) ) {
			return $this->error_response( $data );
		}
		foreach ( Variation_Db::SECTION_PATHS[ $section ] as $p ) {
			Global_Styles_Db::path_delete( $data, $p );
		}
		$bytes = Variation_File::write_json( $path, $data );
		if ( is_wp_error( $bytes ) ) {
			return $this->error_response( $bytes );
		}

		return array(
			'success'  => true,
			/* translators: 1: section, 2: path */
			'message'  => sprintf( __( 'Removed "%1$s" from %2$s.', 'acrossai-abilities-manager' ), $section, $path ),
			'deleted'  => array(
				'source'     => $src,
				'theme'      => (string) ( $loc['theme'] ?? '' ),
				'theme_type' => (string) ( $loc['theme_type'] ?? '' ),
				'plugin'     => (string) ( $loc['plugin'] ?? '' ),
				'slug'       => (string) ( $loc['slug'] ?? '' ),
				'path'       => $path,
				'bytes'      => (int) $bytes,
			),
			'mode'     => 'section',
			'warnings' => array(),
		);
	}

	/**
	 * Delete whole.
	 *
	 * @param array $loc
	 * @param array $locations
	 * @return array
	 */
	private function delete_whole( array $loc, array $locations ): array {
		$src      = (string) ( $loc['source'] ?? '' );
		$warnings = array();

		switch ( $src ) {
			case 'db':
				$post = get_post( (int) ( $loc['post_id'] ?? 0 ) );
				if ( ! $post || ! Variation_Db::delete( $post ) ) {
					return array(
						'success' => false,
						'message' => __( 'Failed to delete DB variation.', 'acrossai-abilities-manager' ),
					);
				}
				break;

			case 'theme':
			case 'plugin':
				if ( 'plugin' === $src && false === ( $loc['plugin_active'] ?? true ) ) {
					/* translators: %s: plugin slug */
					$warnings[] = sprintf( __( 'Plugin "%s" is inactive — deleted the file directly anyway.', 'acrossai-abilities-manager' ), $loc['plugin'] ?? '' );
				}
				$result = Variation_File::delete_file( (string) ( $loc['path'] ?? '' ) );
				if ( is_wp_error( $result ) ) {
					return $this->error_response( $result );
				}
				break;

			default:
				return array(
					'success' => false,
					'message' => __( 'Unknown source.', 'acrossai-abilities-manager' ),
				);
		}

		$remaining = array_values(
			array_filter(
				$locations,
				static function ( $other ) use ( $loc ): bool {
					return ! self::is_same_location( $other, $loc );
				}
			)
		);
		if ( ! empty( $remaining ) ) {
			$warnings[] = __( 'Other copies still exist; WordPress will fall back to the next-highest-priority location.', 'acrossai-abilities-manager' );
		} else {
			$warnings[] = __( 'No other copies remain — this variation is fully removed.', 'acrossai-abilities-manager' );
		}

		return array(
			'success'   => true,
			/* translators: %s: slug */
			'message'   => sprintf( __( 'Deleted variation "%s".', 'acrossai-abilities-manager' ), (string) ( $loc['slug'] ?? '' ) ),
			'deleted'   => $loc,
			'mode'      => 'whole',
			'warnings'  => $warnings,
			'locations' => $remaining,
		);
	}

	/**
	 * Is same location.
	 *
	 * @param array $a
	 * @param array $b
	 * @return bool
	 */
	private static function is_same_location( array $a, array $b ): bool {
		if ( ( $a['source'] ?? '' ) !== ( $b['source'] ?? '' ) ) {
			return false;
		}
		if ( 'db' === ( $a['source'] ?? '' ) ) {
			return (int) ( $a['post_id'] ?? 0 ) === (int) ( $b['post_id'] ?? 0 );
		}
		return ( $a['path'] ?? '' ) === ( $b['path'] ?? '' );
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
