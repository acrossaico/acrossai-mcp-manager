<?php
/**
 * Feature 106 — Update Archive SEO Settings.
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
 * seo/update-archive-seo — Update Archive SEO Settings.
 */
final class Update_Archive_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/update-archive-seo';
	}

	protected function ability_label(): string {
		return __( 'Update Archive SEO Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The special archives that belong to no single content type: the author archive, the date archive and the site-wide search and 404 pages. Each can be given a title and description template, disabled outright, or left reachable but noindexed — and disabling is not the same as noindexing, since a disabled archive redirects away entirely.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-content-types';
	}

	protected function is_writer(): bool {
		return true;
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-seo-settings',
		);
	}

	protected function input_properties(): array {
		return array(
			'archive' => array(
				'type'        => 'string',
				'enum'        => array( 'author', 'date', 'search', '404' ),
				'description' => __( 'Which archive.', 'acrossai-abilities-manager' ),
			),

			'settings' => array(
				'type'        => 'object',
				'description' => __( 'Setting key => value for that archive.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'archive',
			'settings',
		);
	}

	protected function output_properties(): array {
		return array(
			'archive' => array( 'type' => 'string' ),

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
		$archive = (string) $input['archive'];
		$patch   = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		if ( array() === $patch ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one setting to change.', 'acrossai-abilities-manager' ) );
		}

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
			'archive' => $archive,
			'updated' => $updated,
			'message' => sprintf(
				/* translators: 1: comma-separated keys, 2: archive */
				__( 'Updated %1$s on the %2$s archive.', 'acrossai-abilities-manager' ),
				implode( ', ', $updated ),
				$archive
			),
		);
	}
}
