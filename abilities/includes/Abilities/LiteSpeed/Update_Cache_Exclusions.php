<?php
/**
 * Feature 104 — Update Cache Exclusions.
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
 * litespeed/update-cache-exclusions — Update Cache Exclusions.
 */
final class Update_Cache_Exclusions extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'update-cache-exclusions';
	}

	protected function ability_label(): string {
		return __( 'Update Cache Exclusions', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Add to, remove from, or replace one cache exclusion list. Types: uri, category, tag, cookie, user-agent, role, force-uri (force caching), force-public-uri, private-uri, drop-query-string. The mode parameter — replace, add or remove — means a caller can add one line without first reading the whole list and writing it back, which is how entries get lost.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-cache';
	}

	protected function input_properties(): array {
		return array(
			'type'   => array(
				'type'        => 'string',
				'enum'        => array( 'uri', 'category', 'tag', 'cookie', 'user-agent', 'role', 'force-uri', 'force-public-uri', 'private-uri', 'drop-query-string' ),
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
			'uri'               => 'cache-exc',
			'category'          => 'cache-exc_cat',
			'tag'               => 'cache-exc_tag',
			'cookie'            => 'cache-exc_cookies',
			'user-agent'        => 'cache-exc_useragents',
			'role'              => 'cache-exc_roles',
			'force-uri'         => 'cache-force_uri',
			'force-public-uri'  => 'cache-force_pub_uri',
			'private-uri'       => 'cache-priv_uri',
			'drop-query-string' => 'cache-drop_qs',
		);

		if ( ! isset( $map[ $type ] ) ) {
			return new WP_Error(
				'unknown_exclusion_type',
				sprintf(
					/* translators: 1: requested type, 2: comma-separated known types */
					__( '"%1$s" is not a cache exclusion type. Known types: %2$s.', 'acrossai-abilities-manager' ),
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
