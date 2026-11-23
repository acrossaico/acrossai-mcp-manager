<?php
/**
 * Feature 104 — Update Optimisation Exclusions.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Settings_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-optimization-exclusions — Update Optimisation Exclusions.
 */
final class Update_Optimization_Exclusions extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'update-optimization-exclusions';
	}

	protected function ability_label(): string {
		return __( 'Update Optimisation Exclusions', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Add to, remove from, or replace one optimisation exclusion list. Types: css (skip CSS combine), js (skip JS combine), js-defer (skip deferring), js-delay (include in delay), uri (skip optimisation entirely), role, guest-js. This is the correct fix when combining or deferring breaks one script — hold that script back rather than turning the feature off for the whole site.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-optimize';
	}

	protected function input_properties(): array {
		return array(
			'type'   => array(
				'type'        => 'string',
				'enum'        => array( 'css', 'js', 'js-defer', 'js-delay', 'uri', 'role', 'guest-js' ),
				'description' => __( 'Which exclusion list.', 'acrossai-abilities-manager' ),
			),

			'values' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Values to apply.', 'acrossai-abilities-manager' ),
			),

			'mode'   => array(
				'type'        => 'string',
				'enum'        => array( 'replace', 'add', 'remove' ),
				'default'     => 'replace',
				'description' => __( 'How to apply the values.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'type',
			'values',
		);
	}

	protected function output_properties(): array {
		return array(
			'type'    => array( 'type' => 'string' ),

			'key'     => array( 'type' => 'string' ),

			'values'  => array( 'type' => 'array' ),

			'changed' => array( 'type' => 'boolean' ),
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
		$type   = isset( $input['type'] ) ? (string) $input['type'] : '';
		$values = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : array();
		$mode   = isset( $input['mode'] ) ? (string) $input['mode'] : 'replace';
		$map    = array(
			'css'      => 'optm-css_exc',
			'js'       => 'optm-js_exc',
			'js-defer' => 'optm-js_defer_exc',
			'js-delay' => 'optm-js_delay_inc',
			'uri'      => 'optm-exc',
			'role'     => 'optm-exc_roles',
			'guest-js' => 'optm-gm_js_exc',
		);

		if ( ! isset( $map[ $type ] ) ) {
			return new WP_Error(
				'unknown_exclusion_type',
				sprintf(
					/* translators: 1: requested type, 2: comma-separated known types */
					__( '"%1$s" is not an optimisation exclusion type. Known types: %2$s.', 'acrossai-abilities-manager' ),
					$type,
					implode( ', ', array_keys( $map ) )
				)
			);
		}

		$key     = $map[ $type ];
		$current = (array) Settings_Repository::cast( Settings_Repository::value( $key ), 'array' );
		$merged  = Settings_Repository::merge_list( $current, $values, $mode );

		// The area is resolved from the key, never assumed: these maps span areas.
		$area = Settings_Repository::area_for( $key );

		$updated = Settings_Repository::write( $area, array( $key => $merged ) );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'type'    => $type,
			'key'     => $key,
			'values'  => $merged,
			'changed' => array() !== $updated,
			'message' => array() === $updated
				? __( 'No change — that list already had those values.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: 1: exclusion type, 2: number of entries */
					__( 'Updated the "%1$s" list; it now has %2$d entr(y/ies).', 'acrossai-abilities-manager' ),
					$type,
					count( $merged )
				),
		);
	}
}
