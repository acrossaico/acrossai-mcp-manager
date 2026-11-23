<?php
/**
 * Static formatting helpers for the Abilities module REST responses.
 *
 * Converts AcrossAI_Abilities_Row objects to consistent REST response shapes
 * and builds nested registry meta structures for wp_register_ability().
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Row;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Static REST response and runtime meta formatters.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Formatter {

	/**
	 * Format a single ability row as a REST response array.
	 *
	 * Timestamps are returned in ISO 8601 UTC format for consistent client handling.
	 * The `editable` flag signals whether the calling client may modify identity
	 * and execution fields (true for source=db, false otherwise).
	 *
	 * @since  0.1.0
	 * @param  AcrossAI_Abilities_Row $row DB row to format.
	 * @return array
	 */
	public static function format_for_response( AcrossAI_Abilities_Row $row ): array {
		return array(
			'id'              => $row->id,
			'ability_slug'    => $row->ability_slug,
			'label'           => $row->label,
			'description'     => $row->description,
			'category'        => $row->category,
			'status'          => $row->status,
			'provider'        => $row->provider,
			'source'          => $row->source,
			'tab_group'       => '', // Feature 102: DB-created abilities belong to no toolset.
			'editable'        => 'db' === $row->source,
			// Always false: a DB-created ability is never a dispatcher. Emitted
			// anyway so both formatter shapes carry the same keys — a field
			// present on only half the rows is one the screen has to guess at.
			'protected'       => false,
			'site_allowed'    => $row->site_allowed,
			'callback_type'   => $row->callback_type,
			'callback_config' => $row->callback_config,
			'input_schema'    => $row->input_schema,
			'output_schema'   => $row->output_schema,
			'show_in_rest'    => $row->show_in_rest,
			'show_in_mcp'     => $row->show_in_mcp,
			'mcp_type'        => $row->mcp_type,
			'readonly'        => $row->readonly,
			'destructive'     => $row->destructive,
			'idempotent'      => $row->idempotent,
			'created_at'      => self::to_iso8601( $row->created_at ),
			'updated_at'      => self::to_iso8601( $row->updated_at ),
			'created_by'      => $row->created_by,
			'updated_by'      => $row->updated_by,
		);
	}

	/**
	 * Format a collection of ability rows for a paginated list response.
	 *
	 * @since  0.1.0
	 * @param  AcrossAI_Abilities_Row[] $rows  Array of rows.
	 * @return array[]
	 */
	public static function format_collection( array $rows ): array {
		return array_map( array( self::class, 'format_for_response' ), $rows );
	}

	/**
	 * Format a single ability row for an exposure collection response.
	 *
	 * Returns a machine-consumable shape with only the fields needed by MCP clients.
	 * Audit fields (created_at, created_by, etc.) and internal execution config are
	 * excluded to minimize metadata exposure.
	 *
	 * @since  0.1.0
	 * @param  AcrossAI_Abilities_Row $row DB row to format.
	 * @return array
	 */
	public static function format_for_exposure( AcrossAI_Abilities_Row $row ): array {
		return array(
			'ability_slug'  => $row->ability_slug,
			'label'         => $row->label,
			'description'   => $row->description,
			'category'      => $row->category,
			'mcp_type'      => $row->mcp_type,
			'input_schema'  => $row->input_schema,
			'output_schema' => $row->output_schema,
			'readonly'      => $row->readonly,
			'destructive'   => $row->destructive,
			'idempotent'    => $row->idempotent,
		);
	}

	/**
	 * Format an exposure collection.
	 *
	 * @since  0.1.0
	 * @param  AcrossAI_Abilities_Row[] $rows  Array of rows.
	 * @return array[]
	 */
	public static function format_exposure_collection( array $rows ): array {
		return array_map( array( self::class, 'format_for_exposure' ), $rows );
	}

	/**
	 * Format a merged registry+override entry (from AcrossAI_Ability_Registry_Query)
	 * into the same flat REST shape that format_for_response() produces for DB rows.
	 *
	 * This allows the custom abilities endpoint to return registry-sourced abilities
	 * (plugin/theme/core) in the same format consumed by AbilitiesList.jsx.
	 *
	 * @since  0.1.0
	 * @param  array $merged Merged ability array from AcrossAI_Ability_Merger::merge().
	 * @return array
	 */
	public static function format_merged_ability( array $merged ): array {
		return array(
			'id'              => $merged['id'] ?? null,
			'ability_slug'    => $merged['slug'] ?? '',
			'label'           => $merged['label'] ?? null,
			'description'     => $merged['description'] ?? null,
			'category'        => $merged['category'] ?? null,
			'status'          => 'publish',
			'provider'        => $merged['provider'] ?? null,
			'source'          => $merged['source'] ?? 'plugin',
			'tab_group'       => $merged['tab_group'] ?? '',
			'editable'        => false,
			// True for a dispatcher — a `toolset/*` router, an mcp-adapter
			// router, or a transport ability a sibling declared. It is listed
			// so the inventory is complete, but the write endpoints refuse it
			// with `rest_protected_ability`, so the screen must not offer an
			// override control for it.
			'protected'       => (bool) ( $merged['protected'] ?? false ),
			'site_allowed'    => $merged['site_allowed'] ?? null,
			'callback_type'   => $merged['callback_type'] ?? null,
			'callback_config' => $merged['callback_config'] ?? null,
			'input_schema'    => $merged['input_schema'] ?? null,
			'output_schema'   => $merged['output_schema'] ?? null,
			'show_in_rest'    => $merged['show_in_rest'] ?? null,
			'show_in_mcp'     => $merged['show_in_mcp'] ?? null,
			'mcp_type'        => $merged['mcp_type'] ?? null,
			'readonly'        => $merged['readonly'] ?? null,
			'destructive'     => $merged['destructive'] ?? null,
			'idempotent'      => $merged['idempotent'] ?? null,
			'created_at'      => self::to_iso8601( $merged['created_at'] ?? null ),
			'updated_at'      => self::to_iso8601( $merged['updated_at'] ?? null ),
			'created_by'      => $merged['created_by'] ?? null,
			'updated_by'      => $merged['updated_by'] ?? null,
			'has_override'    => $merged['has_override'] ?? false,
			'_override'       => $merged['_override'] ?? null,
			'_registry'       => $merged['_registry'] ?? null,
		);
	}

	/**
	 * Format a collection of merged registry+override entries.
	 *
	 * @since  0.1.0
	 * @param  array[] $merged_items Array of merged ability arrays.
	 * @return array[]
	 */
	public static function format_merged_collection( array $merged_items ): array {
		return array_map( array( self::class, 'format_merged_ability' ), $merged_items );
	}

	/**
	 * Build the nested registry meta array for wp_register_ability().
	 *
	 * Registry consumers expect annotation fields inside a nested `meta` key,
	 * not at the top level of the args array (BUG-FLAT-ARGS-PATH prevention).
	 *
	 * @since  0.1.0
	 * @param  AcrossAI_Abilities_Row $row DB row.
	 * @return array Registry args suitable for wp_register_ability().
	 */
	public static function build_registry_args( AcrossAI_Abilities_Row $row ): array {
		$meta = array(
			'source'        => $row->source,
			'provider'      => $row->provider,
			'show_in_rest'  => $row->show_in_rest,
			'show_in_mcp'   => $row->show_in_mcp,
			'mcp_type'      => $row->mcp_type,
			'readonly'      => $row->readonly,
			'destructive'   => $row->destructive,
			'idempotent'    => $row->idempotent,
			'callback_type' => $row->callback_type,
		);

		return array(
			'label'       => (string) $row->label,
			'description' => (string) $row->description,
			'category'    => (string) $row->category,
			'meta'        => array_filter(
				$meta,
				static function ( $value ) {
					return null !== $value;
				}
			),
		);
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Convert a MySQL datetime string to ISO 8601 UTC format.
	 *
	 * Returns null for null/empty input.
	 *
	 * @since  0.1.0
	 * @param  string|null $datetime MySQL datetime string (assumed UTC).
	 * @return string|null
	 */
	private static function to_iso8601( ?string $datetime ): ?string {
		if ( null === $datetime || '' === $datetime ) {
			return null;
		}
		$ts = strtotime( $datetime );
		if ( false === $ts ) {
			return $datetime;
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}
}
