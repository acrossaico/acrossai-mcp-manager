<?php
/**
 * Merges registry ability data with stored override data.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Static utility for merging registry ability data with stored overrides.
 *
 * @since 0.1.0
 */
class AcrossAI_Ability_Merger {

	/**
	 * Fields that can be overridden (nullable columns in the override table).
	 *
	 * @var string[]
	 */
	private static $overridable_fields = array(
		'label',
		'description',
		'category',
		'callback_type',
		'callback_config',
		'site_allowed',
		'readonly',
		'destructive',
		'idempotent',
		'show_in_rest',
		'show_in_mcp',
		'mcp_type',
	);

	/**
	 * Merge registry ability data with a stored override row into an Effective Ability shape.
	 *
	 * For each overridable field, a non-null override value wins over the registry default.
	 * The returned array includes `has_override`, `updated_at`, `updated_by`, and `_registry` keys.
	 *
	 * @since  0.1.0
	 * @param  array       $registry Raw ability array from wp_get_ability().
	 * @param  object|null $override BerlinDB Row object or null if no override exists.
	 * @return array
	 */
	public static function merge( array $registry, $override ): array {
		$result = $registry;

		$has_override = null !== $override;

		foreach ( self::$overridable_fields as $field ) {
			if ( $has_override && null !== $override->{$field} ) {
				$result[ $field ] = $override->{$field};
			} else {
				// Fall back to registry value; use null if registry does not define it.
				$result[ $field ] = isset( $registry[ $field ] ) ? $registry[ $field ] : null;
			}
		}

		$result['id']           = $has_override ? $override->id : null;
		$has_override           = $has_override && self::has_any_non_null_field( $override );
		$result['has_override'] = $has_override;
		$result['updated_at']   = $has_override ? $override->updated_at : null;
		$result['updated_by']   = $has_override ? $override->updated_by : null;
		$result['created_at']   = $has_override ? $override->created_at : null;
		$result['created_by']   = $has_override ? $override->created_by : null;
		$result['_registry']    = $registry;

		// Raw per-field override values (null = not set in DB / no row).
		// The JS edit panel uses this — NOT the merged values above — to seed
		// radio controls so fields without an explicit DB value show "Inherit".
		$override_raw = array();
		foreach ( self::$overridable_fields as $field ) {
			$override_raw[ $field ] = $has_override ? $override->{$field} : null;
		}
		$result['_override'] = $override_raw;

		return $result;
	}

	/**
	 * Check whether any overridable field on an override row holds a non-null, non-empty value.
	 *
	 * Used to compute has_override based on actual field content rather than row existence.
	 *
	 * @since  0.1.0
	 * @param  object $override BerlinDB Row object — guaranteed non-null.
	 * @return bool
	 */
	private static function has_any_non_null_field( object $override ): bool {
		foreach ( self::$overridable_fields as $field ) {
			// @phpstan-ignore-next-line (dynamic property access on BerlinDB row)
			if ( null !== $override->{$field} ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether every field in a payload matches the corresponding registry default.
	 *
	 * Returns true only when NO field in the payload differs from the registry value,
	 * meaning a DB write would be pointless (FR-024).
	 *
	 * @since  0.1.0
	 * @param  array $payload  Submitted field values (keyed by field name).
	 * @param  array $registry Raw ability array from wp_get_ability().
	 * @return bool
	 */
	public static function is_all_default( array $payload, array $registry ): bool {
		foreach ( self::$overridable_fields as $field ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				continue;
			}
			$registry_value = isset( $registry[ $field ] ) ? $registry[ $field ] : null;
			// Strict comparison is REQUIRED here. PHP loose equality treats false == null
			// as true, which would conflate "explicit No override" (false) with "no override
			// set/Inherit" (null). That would silently discard a valid admin governance decision.
			if ( $payload[ $field ] !== $registry_value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalize a WP_Ability object or raw array into a flat array shape.
	 *
	 * Uses wp_get_abilities() and wp_get_ability() which return WP_Ability objects (WP 6.9+).
	 * All internal utilities (Merger, SourceDetector, RegistryQuery) expect a flat
	 * array. Call this once at the boundary before passing registry data anywhere.
	 *
	 * @since  0.1.0
	 * @param  \WP_Ability|array $ability WP_Ability object or already-normalized array.
	 * @return array Flat ability array with slug, label, description, category,
	 *               provider, source, callback_type, input_schema, output_schema,
	 *               and all annotation/overridable fields read from WP_Ability meta.
	 */
	public static function normalize_registry( $ability ): array {
		if ( is_array( $ability ) ) {
			return $ability;
		}

		if ( ! ( $ability instanceof \WP_Ability ) ) {
			return array();
		}

		$name            = $ability->get_name();
		$slash_pos       = strpos( $name, '/' );
		$provider        = false !== $slash_pos ? substr( $name, 0, $slash_pos ) : '';
		$annotations_raw = $ability->get_meta_item( 'annotations', array() );
		$annotations     = is_array( $annotations_raw ) ? $annotations_raw : array();

		// Reads a field from the annotations array first (external plugin convention),
		// then falls back to a top-level meta item (internal build_registry_args convention).
		$ann_or_meta = static function ( string $key ) use ( $ability, $annotations ) {
			return array_key_exists( $key, $annotations )
				? $annotations[ $key ]
				: $ability->get_meta_item( $key, null );
		};

		$mcp_meta_raw = $ability->get_meta_item( 'mcp', null );
		$mcp_meta     = is_array( $mcp_meta_raw ) ? $mcp_meta_raw : null;

		// Feature 102: meta.acrossai.tab_group drives the Toolset column and the toolset
		// filter on the abilities screen. Read-only here — the MCP tool catalogue is derived
		// from the same value (DEC-ABILITY-GROUP-IDENTIFIER-LOAD-BEARING), so re-tagging an
		// ability moves it between MCP tools. Nothing in this plugin writes it.
		$acrossai_meta_raw = $ability->get_meta_item( 'acrossai', null );
		$acrossai_meta     = is_array( $acrossai_meta_raw ) ? $acrossai_meta_raw : null;
		$tab_group         = ( null !== $acrossai_meta && isset( $acrossai_meta['tab_group'] ) )
			? (string) $acrossai_meta['tab_group']
			: '';

		$input_schema_raw  = $ability->get_input_schema();
		$output_schema_raw = $ability->get_output_schema();

		return array(
			'slug'            => $name,
			'label'           => $ability->get_label(),
			'description'     => $ability->get_description(),
			'category'        => $ability->get_category(),
			'provider'        => $provider,
			'source'          => $ability->get_meta_item( 'source', null ),
			'tab_group'       => $tab_group,
			'show_in_rest'    => $ability->get_meta_item( 'show_in_rest', false ),
			'callback_type'   => $ann_or_meta( 'callback_type' ),
			'callback_config' => null, // execution config — not stored in WP_Ability.
			'input_schema'    => ( is_array( $input_schema_raw ) && empty( $input_schema_raw ) ) ? null : $input_schema_raw,
			'output_schema'   => ( is_array( $output_schema_raw ) && empty( $output_schema_raw ) ) ? null : $output_schema_raw,
			'readonly'        => $ann_or_meta( 'readonly' ),
			'destructive'     => $ann_or_meta( 'destructive' ),
			'idempotent'      => $ann_or_meta( 'idempotent' ),
			'show_in_mcp'     => ( null !== $mcp_meta && array_key_exists( 'public', $mcp_meta ) ) ? $mcp_meta['public'] : $ann_or_meta( 'show_in_mcp' ),
			'mcp_type'        => ( null !== $mcp_meta && array_key_exists( 'type', $mcp_meta ) ) ? $mcp_meta['type'] : $ann_or_meta( 'mcp_type' ),
			'site_allowed'    => $ann_or_meta( 'site_allowed' ),
		);
	}
}
