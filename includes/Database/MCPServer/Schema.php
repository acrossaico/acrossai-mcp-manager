<?php
/**
 * BerlinDB Schema for the MCPServer module.
 *
 * 13 columns per Feature 011 plan §Concrete column decisions MCPServer.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Schema class defining all 13 columns of the acrossai_mcp_servers table.
 *
 * @since 0.1.0
 */
class Schema extends \BerlinDB\Database\Kern\Schema {

	/**
	 * Array of column definitions.
	 *
	 * @var array
	 */
	public $columns = array(

		// Primary key — 'primary' flag omitted; PRIMARY KEY DDL comes from $indexes.
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'sortable' => true,
		),

		// Server display name.
		array(
			'name'   => 'server_name',
			'type'   => 'varchar',
			'length' => '255',
		),

		// Server slug (route path segment). Indexed for lookup.
		array(
			'name'       => 'server_slug',
			'type'       => 'varchar',
			'length'     => '255',
			'default'    => '',
			'sortable'   => true,
			'searchable' => true,
		),

		// Human-readable description.
		array(
			'name'    => 'description',
			'type'    => 'varchar',
			'length'  => '500',
			'default' => '',
		),

		// Enabled toggle (0/1).
		array(
			'name'    => 'is_enabled',
			'type'    => 'tinyint',
			'length'  => '1',
			'default' => 0,
		),

		// Origin: 'plugin' (self-managed) or third-party plugin slug.
		array(
			'name'    => 'registered_from',
			'type'    => 'varchar',
			'length'  => '50',
			'default' => 'plugin',
		),

		// REST route namespace segment.
		array(
			'name'    => 'server_route_namespace',
			'type'    => 'varchar',
			'length'  => '100',
			'default' => 'mcp',
		),

		// REST route path segment.
		array(
			'name'    => 'server_route',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => '',
		),

		// Server version string.
		array(
			'name'    => 'server_version',
			'type'    => 'varchar',
			'length'  => '50',
			'default' => 'v1.0.0',
		),

		// F025 protocol-tool enablement flags — one boolean column per MCP protocol
		// tool. Default 1 means "enabled" and, on the ALTER for existing installs,
		// backfills every pre-F025 row with all three protocol tools enabled.
		// See ToolPolicy::COLUMN_MAP for the canonical column→slug mapping.
		array(
			'name'    => 'tool_discover_abilities',
			'type'    => 'tinyint',
			'length'  => '1',
			'default' => 1,
		),
		array(
			'name'    => 'tool_get_ability_info',
			'type'    => 'tinyint',
			'length'  => '1',
			'default' => 1,
		),
		array(
			'name'    => 'tool_execute_ability',
			'type'    => 'tinyint',
			'length'  => '1',
			'default' => 1,
		),

		// F030 — per-server operator opt-in that bypasses each exposed ability's
		// permission_callback for in-flight MCP requests to this specific server.
		// Default 0 (OFF) preserves prior behaviour on upgrade. Runtime override
		// gated by CurrentServerHolder + ExposureResolver (see
		// includes/Abilities/PermissionOverrideProcessor.php).
		array(
			'name'    => 'override_abilities_permission',
			'type'    => 'tinyint',
			'length'  => '1',
			'default' => 0,
		),

		// F082 — per-server default ability exposure policy. Tri-state:
		// 'per-ability' → each ability uses its own meta.mcp.public (matches
		// pre-F082 behaviour; new installs land here)
		// 'expose'      → server exposes every ability by default (future
		// registrations inherit ON automatically)
		// 'hide'        → server hides every ability by default (future
		// registrations inherit OFF automatically)
		// Per-ability rows in acrossai_mcp_server_abilities are OVERRIDES that
		// win over this default via ExposureResolver::resolve_effective().
		// Default 'per-ability' preserves prior behaviour on every existing row.
		array(
			'name'    => 'abilities_default_policy',
			'type'    => 'varchar',
			'length'  => '16',
			'default' => 'per-ability',
		),

		// Feature 090 — the server's TYPE. A type is a starting point plus a
		// label: it decides what Switch and Reset WRITE into the tool columns
		// and curated rows. It is never consulted at registration time, so what
		// a server serves stays exactly what the Tools tab says.
		//
		// Default 'mcp-adapter' is load-bearing, not cosmetic: the 1.1.6
		// migration relies on it to backfill every pre-existing row inside the
		// ALTER itself, which is the correct legacy value for all of them except
		// the F088 AcrossAI row (corrected by a targeted UPDATE in the same
		// migration). A default of 'acrossai' would wrongly stamp every pre-090
		// server as an AcrossAI server.
		//
		// NOTE both create paths MUST write this explicitly from
		// ServerTypes::default_slug() — a row that falls through to this column
		// default gets the legacy type, not the registry default.
		array(
			'name'    => 'server_type',
			'type'    => 'varchar',
			'length'  => '32',
			'default' => 'mcp-adapter',
		),

		// Feature 090 — coarse tool policy, the Tools-tab sibling of
		// `abilities_default_policy` above and deliberately the same shape:
		// 'per-tool' → compose from the tool_* columns + curated rows (today's
		// behaviour, and what a server type's preset fills in)
		// 'expose'   → every tool-level ability in this server's POOL, INCLUDING
		// ones registered later (a STANDING rule, not a snapshot — reactivating
		// a companion plugin must not require re-adding its toolsets by hand).
		// The pool is ServerTypes::pool() — every tool-level ability on the
		// site, NOT the type's own list. A type is a template for Reset, never
		// a filter over what may be added.
		// 'hide'     → expose no tools
		//
		// 'expose'/'hide' sit ABOVE the type preset and win over it, exactly as
		// abilities_default_policy wins over per-ability override rows.
		array(
			'name'    => 'tools_default_policy',
			'type'    => 'varchar',
			'length'  => '16',
			'default' => 'per-tool',
		),

		// F037 — the `embeds_enabled` column was briefly added by
		// upgrade_to_1_1_3 during initial development but retracted
		// per user redesign 2026-07-27: all F037 state now lives in
		// the meta table `wp_acrossai_mcp_servers_meta` under meta_key
		// `_embeds_enabled` (WP-canonical meta pattern). Column is
		// DROPped by upgrade_to_1_1_4 on the next admin_init.

		// Audit timestamp — no explicit default; BerlinDB uses '0000-00-00 00:00:00'
		// for datetime columns. 'created' flag handles auto-timestamping at the
		// application layer (CURRENT_TIMESTAMP quoted by BerlinDB is invalid DDL).
		array(
			'name'       => 'created_at',
			'type'       => 'datetime',
			'created'    => true,
			'date_query' => true,
			'sortable'   => true,
		),
	);

	/**
	 * Array of index definitions.
	 *
	 * BerlinDB v3 requires the PRIMARY KEY to be declared as an explicit Index
	 * entry — the 'primary' column flag is query-layer only, not DDL.
	 *
	 * @var array
	 */
	public $indexes = array(
		array(
			'name'    => 'primary',
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
		array(
			'name'    => 'server_slug',
			'type'    => 'key',
			'columns' => array( 'server_slug' ),
		),
	);
}
