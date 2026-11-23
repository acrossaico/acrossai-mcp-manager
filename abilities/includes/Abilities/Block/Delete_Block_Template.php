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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Template\Template_Db;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Template\Template_Detector;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Template\Template_File;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes a block template from one storage location.
 *
 *  - Auto-detects the source; returns "multiple_locations" with candidates when
 *    the slug exists in more than one place.
 *  - Scenario 3: refuses to delete parent-theme files. Caller can migrate to
 *    child first and delete the override.
 *  - Scenario 14: warns when a plugin source is inactive but proceeds anyway.
 *  - Scenario 15: surfaces file-not-writable as a clear error with the path.
 */
class Delete_Block_Template extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/delete-block-template',
			'args' => array(
				'label'               => __( 'Delete Block Template', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deletes a block template by slug. Auto-resolves the source when there\'s only one copy; pass source / theme_type / plugin_slug to disambiguate. Refuses to delete parent-theme files.', 'acrossai-abilities-manager' ),
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
							'description' => __( 'Template slug to delete.', 'acrossai-abilities-manager' ),
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
						'theme'       => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Theme hint for DB row lookup.', 'acrossai-abilities-manager' ),
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
						'sub_group'       => 'templates',
						'sub_group_label' => __( 'Templates', 'acrossai-abilities-manager' ),
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
		$slug        = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$source      = sanitize_text_field( $input['source'] ?? '' );
		$theme_type  = sanitize_text_field( $input['theme_type'] ?? '' );
		$plugin_slug = sanitize_key( $input['plugin_slug'] ?? '' );
		$theme_hint  = sanitize_key( $input['theme'] ?? '' );

		if ( '' === $slug ) {
			return array(
				'success' => false,
				'message' => __( 'Slug is required.', 'acrossai-abilities-manager' ),
			);
		}

		$locations = Template_Detector::locate( $slug, $theme_hint );
		if ( empty( $locations ) ) {
			return array(
				'success'   => false,
				/* translators: %s: slug */
				'message'   => sprintf( __( 'No template with slug "%s" was found.', 'acrossai-abilities-manager' ), $slug ),
				'locations' => array(),
			);
		}

		$selected = Template_Detector::select( $locations, $source, $theme_type, $plugin_slug );
		if ( is_wp_error( $selected ) ) {
			$data = $selected->get_error_data();
			return array(
				'success'    => false,
				'message'    => $selected->get_error_message(),
				'locations'  => $locations,
				'candidates' => is_array( $data ) ? ( $data['locations'] ?? array() ) : array(),
			);
		}

		// Scenario 3 — refuse to delete parent theme files.
		if ( 'theme' === ( $selected['source'] ?? '' ) && 'parent' === ( $selected['theme_type'] ?? '' ) ) {
			return array(
				'success'   => false,
				'message'   => __( 'Refusing to delete a parent-theme file. Parent files are the upstream fallback — delete the child-theme override or DB record instead.', 'acrossai-abilities-manager' ),
				'locations' => $locations,
			);
		}

		$warnings = array();

		switch ( $selected['source'] ?? '' ) {
			case 'db':
				$post = get_post( (int) ( $selected['post_id'] ?? 0 ) );
				if ( ! $post || ! Template_Db::delete( $post ) ) {
					return array(
						'success' => false,
						'message' => __( 'Failed to delete database template.', 'acrossai-abilities-manager' ),
					);
				}
				break;

			case 'theme':
			case 'plugin':
				$path = (string) ( $selected['path'] ?? '' );
				if ( 'plugin' === ( $selected['source'] ?? '' ) && false === ( $selected['plugin_active'] ?? true ) ) {
					/* translators: %s: plugin slug */
					$warnings[] = sprintf( __( 'Plugin "%s" is inactive — deleted the file directly anyway.', 'acrossai-abilities-manager' ), $selected['plugin'] ?? '' );
				}
				$result = Template_File::delete_file( $path );
				if ( is_wp_error( $result ) ) {
					return array(
						'success' => false,
						'message' => $result->get_error_message(),
					);
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
				static function ( $loc ) use ( $selected ): bool {
					return ! self::is_same_location( $loc, $selected );
				}
			)
		);
		if ( ! empty( $remaining ) ) {
			$warnings[] = __( 'Other copies of this slug still exist; WordPress will fall back to the next-highest-priority location (DB → child → parent → plugin).', 'acrossai-abilities-manager' );
		}

		return array(
			'success'   => true,
			/* translators: %s: slug */
			'message'   => sprintf( __( 'Deleted template "%s".', 'acrossai-abilities-manager' ), $slug ),
			'deleted'   => $selected,
			'warnings'  => $warnings,
			'locations' => $remaining,
		);
	}

	/**
	 * Best-effort equality between two location descriptors.
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
}
