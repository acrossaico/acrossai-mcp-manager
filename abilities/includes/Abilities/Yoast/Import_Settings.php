<?php
/**
 * Feature 106 — Import SEO Settings.
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
 * seo/import-settings — Import SEO Settings.
 */
final class Import_Settings extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/import-settings';
	}

	protected function ability_label(): string {
		return __( 'Import SEO Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Apply rows produced by seo/export-settings. Writes are grouped by area so each passes through Yoast\'s own per-group validation once, and a rejected area is reported without blocking the others. Overwrites whatever the payload names, so export first if you may need to roll back.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-settings';
	}

	protected function is_writer(): bool {
		return true;
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function confirmation_message(): string {
		return __(
			'Importing overwrites every setting named in the payload. Export first with seo/export-settings if you may need to roll back. Pass confirm: true to proceed.',
			'acrossai-abilities-manager'
		);
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/export-settings',
		);
	}

	protected function input_properties(): array {
		return array(
			'settings' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'object' ),
				'description' => __( 'Rows of {area, key, value}, as produced by seo/export-settings.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'settings',
		);
	}

	protected function output_properties(): array {
		return array(
			'updated' => array( 'type' => 'array' ),

			'failed' => array( 'type' => 'array' ),
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
		$rows = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		if ( array() === $rows ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one setting row.', 'acrossai-abilities-manager' ) );
		}

		// Grouped by area so each area is written once, through its own validation, rather than one
		// write per row.
		$byarea = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['key'] ) ) {
				continue;
			}

			$key  = (string) $row['key'];
			$area = isset( $row['area'] ) ? (string) $row['area'] : Settings_Repository::area_for( $key );

			if ( '' === $area ) {
				continue;
			}

			$byarea[ $area ][ $key ] = Slash_Input::slash( $row['value'] ?? '', $input );
		}

		$updated = array();
		$failed  = array();

		foreach ( $byarea as $area => $patch ) {
			$written = Settings_Repository::write( (string) $area, $patch );

			if ( is_wp_error( $written ) ) {
				$failed[] = array(
					'area'   => (string) $area,
					'reason' => $written->get_error_message(),
				);
				continue;
			}

			$updated = array_merge( $updated, $written );
		}

		return array(
			'updated' => $updated,
			'failed'  => $failed,
			'message' => sprintf(
				/* translators: 1: number updated, 2: number of areas that failed */
				__( '%1$d setting(s) updated; %2$d area(s) rejected.', 'acrossai-abilities-manager' ),
				count( $updated ),
				count( $failed )
			),
		);
	}
}
