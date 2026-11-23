<?php
/**
 * Feature 106 — Update Post Type SEO Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Settings_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-post-type-seo — Update Post Type SEO Settings.
 */
final class Update_Post_Type_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/update-post-type-seo';
	}

	protected function ability_label(): string {
		return __( 'Update Post Type SEO Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change the Yoast settings for one post type — title and description templates, indexing, metabox visibility, schema defaults. Each key is routed to the settings area that owns it, so a mixed patch still passes through Yoast\'s per-group validation. Read seo/get-post-type-seo first for the exact key names.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-content-types';
	}

	protected function is_writer(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array(
			'name' => array(
				'type'        => 'string',
				'description' => __( 'The post type name.', 'acrossai-abilities-manager' ),
			),

			'settings' => array(
				'type'        => 'object',
				'description' => __( 'Setting key => value. Keys are the full option names, as returned by the matching get ability.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'name',
			'settings',
		);
	}

	protected function output_properties(): array {
		return array(
			'name' => array( 'type' => 'string' ),

			'updated' => array( 'type' => 'array' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$name  = (string) $input['name'];
		$patch = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		if ( ! post_type_exists( $name ) ) {
			return new WP_Error(
				'unknown_post_type',
				sprintf( /* translators: %s: name */ __( 'No post type named "%s".', 'acrossai-abilities-manager' ), $name )
			);
		}

		if ( array() === $patch ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one setting to change.', 'acrossai-abilities-manager' ) );
		}

		// Grouped by the area that owns each key, because a content type's settings are spread across
		// several areas and each must go through its own validation.
		$byarea = array();

		foreach ( $patch as $key => $value ) {
			$key  = (string) $key;
			$area = Settings_Repository::area_for( $key );

			if ( '' === $area ) {
				return new WP_Error(
					'setting_not_writable',
					sprintf( /* translators: %s: key */ __( '"%s" is not a writable Yoast setting.', 'acrossai-abilities-manager' ), $key )
				);
			}

			$byarea[ $area ][ $key ] = Slash_Input::slash( $value, $input );
		}

		$updated = array();

		foreach ( $byarea as $area => $keys ) {
			$written = Settings_Repository::write( (string) $area, $keys );

			if ( is_wp_error( $written ) ) {
				return $written;
			}

			$updated = array_merge( $updated, $written );
		}

		return array(
			'name'    => $name,
			'updated' => $updated,
			'message' => sprintf(
				/* translators: 1: comma-separated keys, 2: name */
				__( 'Updated %1$s for "%2$s".', 'acrossai-abilities-manager' ),
				implode( ', ', $updated ),
				$name
			),
		);
	}
}
