<?php
/**
 * BerlinDB Schema for the ActivityLog module (Feature 096).
 *
 * 32 columns. One table holds MCP JSON-RPC requests and ability runs as a
 * parent/child tree, discriminated by `event_type` and related by `parent_id`.
 *
 * Column sourcing was VERIFIED against live traffic on 2026-10-16 — see
 * specs/096-mcp-activity-log/research.md §Phase 1. Four notes below record
 * corrections that came out of that run (D-10 to D-13); do not "simplify"
 * them back.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\ActivityLog
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Schema defining the 32 columns of the acrossai_mcp_activity_log table.
 *
 * Deliberately NO fulltext index in 1.0.0: search targets `component_name` and
 * `error_message`, both short and both indexed, so LIKE over an indexed varchar
 * is adequate and the MySQL version sniff is avoided entirely.
 */
class Schema extends \BerlinDB\Database\Kern\Schema {

	/**
	 * Array of column definitions.
	 *
	 * @var array
	 */
	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'sortable' => true,
		),
		// The request event that caused this one. 0 for request events and for ability runs with no MCP origin.
		array(
			'name'     => 'parent_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
			'sortable' => true,
		),
		// request | ability | removal. The discriminator.
		array(
			'name'     => 'event_type',
			'type'     => 'varchar',
			'length'   => '16',
			'default'  => 'request',
			'sortable' => true,
		),
		// RESERVED: mcp now; webmcp / auth later. NOT named `source` — the adapter sends a `source` tag whose value on a toolset call is `ability`, and a tag-to-column map by name would silently write it here (research.md D-11).
		array(
			'name'    => 'event_source',
			'type'    => 'varchar',
			'length'  => '32',
			'default' => 'mcp',
		),
		// Denormalised onto ability events so the per-server view filters without walking parent_id.
		array(
			'name'     => 'server_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
			'sortable' => true,
		),
		// The server_id TAG verbatim — confirmed to be our slug on live traffic. Survives server deletion.
		array(
			'name'    => 'server_slug',
			'type'    => 'varchar',
			'length'  => '191',
			'default' => '',
		),
		// ai-connector | oauth | app-password | browser | unknown.
		array(
			'name'     => 'connection_type',
			'type'     => 'varchar',
			'length'   => '32',
			'default'  => 'unknown',
			'sortable' => true,
		),
		// mcp | rest | cli | admin | unknown. SEPARATE from connection_type: one is how the credential arrived, the other which surface called.
		array(
			'name'     => 'invoked_via',
			'type'     => 'varchar',
			'length'   => '32',
			'default'  => '',
			'sortable' => true,
		),
		// From clientInfo.name. Denormalised — names an application, has no row to join to.
		array(
			'name'    => 'client_name',
			'type'    => 'varchar',
			'length'  => '191',
			'default' => '',
		),
		array(
			'name'    => 'client_version',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),
		// Raw slug. Resolved to a display name in bulk at read time, never denormalised.
		array(
			'name'    => 'connector_slug',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),
		// Observed value is the short label `HTTP`, not a class FQN.
		array(
			'name'    => 'transport',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),
		// tools/call, tools/list, initialize, ...
		array(
			'name'     => 'method',
			'type'     => 'varchar',
			'length'   => '64',
			'default'  => '',
			'sortable' => true,
		),
		// From initialize. First thing wanted when a client breaks after an upgrade.
		array(
			'name'    => 'protocol_version',
			'type'    => 'varchar',
			'length'  => '16',
			'default' => '',
		),
		// tool | prompt | resource | ability.
		array(
			'name'    => 'component_type',
			'type'    => 'varchar',
			'length'  => '32',
			'default' => '',
		),
		// THE CANONICAL, JOINABLE NAME. From the adapter's `ability_name` tag (toolset/server-guide), falling back to tool_name / prompt_name / resource_uri. NOT `tool_name`, which McpNameSanitizer rewrites to toolset-server-guide and which matches no ability slug anywhere else (D-10, B31).
		array(
			'name'     => 'component_name',
			'type'     => 'varchar',
			'length'   => '191',
			'default'  => '',
			'sortable' => true,
		),
		// The name the client actually sent, when it differs from component_name — the sanitized string in the client's own tools/list (D-10).
		array(
			'name'    => 'invoked_name',
			'type'    => 'varchar',
			'length'  => '191',
			'default' => '',
		),
		array(
			'name'    => 'component_category',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),
		// success | error | denied | invalid_input.
		array(
			'name'     => 'status',
			'type'     => 'varchar',
			'length'   => '32',
			'default'  => '',
			'sortable' => true,
		),
		// WP_Error code on ability events. Often EMPTY on request events: there is no error_code tag, and a validation failure arrives as status=error with failure_reason prose only (D-12).
		array(
			'name'    => 'error_code',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),
		array(
			'name'    => 'error_message',
			'type'    => 'varchar',
			'length'  => '500',
			'default' => '',
		),
		// Request: the 3rd record_event() argument. Confirmed non-null on live traffic.
		array(
			'name'       => 'duration_ms',
			'type'       => 'int',
			'length'     => '10',
			'unsigned'   => true,
			'allow_null' => true,
			'sortable'   => true,
		),
		// PERSONAL DATA. From get_current_user_id() — there is no user_id tag, nor site_id or timestamp (D-13).
		array(
			'name'     => 'user_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
			'sortable' => true,
		),
		// PERSONAL DATA. IPv4/IPv6, full.
		array(
			'name'    => 'ip_address',
			'type'    => 'varchar',
			'length'  => '45',
			'default' => '',
		),
		// Distinguishes the npm bridge from a CLI client — both authenticate identically.
		array(
			'name'    => 'user_agent',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => '',
		),
		// Mcp-Session-Id. On initialize the tag is ABSENT and the id arrives as new_session_id — coalesce (confirmed on live traffic).
		array(
			'name'    => 'session_id',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),
		array(
			'name'    => 'request_id',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),
		// Sanitised then truncated. Never a raw body.
		array(
			'name'       => 'input_preview',
			'type'       => 'text',
			'allow_null' => true,
		),
		// Ability result. Stays empty on request events — the seam never passes the payload.
		array(
			'name'       => 'output_preview',
			'type'       => 'text',
			'allow_null' => true,
		),
		// strlen() before preview truncation.
		array(
			'name'       => 'input_bytes',
			'type'       => 'int',
			'length'     => '10',
			'unsigned'   => true,
			'allow_null' => true,
		),
		// strlen() of the response, from ResponseSizeProbe. The only answer to 'which tool is eating the context'.
		array(
			'name'       => 'output_bytes',
			'type'       => 'int',
			'length'     => '10',
			'unsigned'   => true,
			'allow_null' => true,
			'sortable'   => true,
		),
		// UTC, via current_time('mysql', 1) — matches CliAuthLog.
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
	 * `type_created` and `server_type_created` are what make one table cheaper
	 * than two: the two views the UI used to need two tables for are now two
	 * index-covered queries on one.
	 *
	 * EVERY composite key here leads with `event_type`, and that is not
	 * cosmetic. One table holds two populations, so a key that omits the
	 * discriminator matches rows of BOTH kinds and every single-kind query pays
	 * for the other kind's rows. Measured 2026-10-21 on a copy of this exact
	 * schema (9,377 rows / 150,032 rows), filtering server + status:
	 *
	 *     (status, created_at)               742 rows examined, 1.155 ms
	 *     (event_type, status, created_at)   239 rows examined, 0.413 ms
	 *
	 * Same answer, 2.8x less work, because `ActivityLogController` defaults
	 * `event_type` to `request` (see its collection params) — so the predicate
	 * is present on EVERY list query and these keys had no caller that wanted
	 * them un-prefixed. The four keys below were (status|component_name|
	 * connection_type|user_id, created_at) in 1.0.0; 1.1.0 re-keyed and renamed
	 * them, which is what `Table::upgrade_to_1_1_0()` performs.
	 *
	 * Renamed rather than re-keyed in place on purpose: the name is the only
	 * thing `index_exists()` can see, so a new name makes the migration
	 * idempotent and self-describing. dbDelta does not re-key an index that
	 * already exists under its own name, so without the rename an upgraded
	 * install would keep the old key forever while reporting the new version —
	 * B34 in index form.
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
			'name'    => 'parent',
			'type'    => 'key',
			'columns' => array( 'parent_id' ),
		),
		array(
			'name'    => 'type_created',
			'type'    => 'key',
			'columns' => array( 'event_type', 'created_at' ),
		),
		array(
			'name'    => 'server_type_created',
			'type'    => 'key',
			'columns' => array( 'server_id', 'event_type', 'created_at' ),
		),
		array(
			'name'    => 'type_status_created',
			'type'    => 'key',
			'columns' => array( 'event_type', 'status', 'created_at' ),
		),
		// No (100) prefix on component_name, deliberately. BerlinDB's
		// Kern\Index exposes no $length property — setting one is a dynamic
		// property, deprecated from PHP 8.2 — so the prefix cannot be expressed
		// here. It is also unnecessary: varchar(191) at utf8mb4 is 764 bytes,
		// plus 65 for event_type varchar(16) and 5 for created_at — 834 total,
		// well inside InnoDB's 3072-byte limit even after the 1.1.0 re-key.
		// The (100) in data-model.md was a precaution for the old 767-byte cap.
		array(
			'name'    => 'type_component_created',
			'type'    => 'key',
			'columns' => array( 'event_type', 'component_name', 'created_at' ),
		),
		array(
			'name'    => 'type_connection_created',
			'type'    => 'key',
			'columns' => array( 'event_type', 'connection_type', 'created_at' ),
		),
		array(
			'name'    => 'type_user_created',
			'type'    => 'key',
			'columns' => array( 'event_type', 'user_id', 'created_at' ),
		),
		// RESOLVED 2026-10-26. This file was never the source of the
		// "Creation of dynamic property Kern\Index::$length" deprecation, and
		// the earlier note here was wrong to conclude the origin was inside
		// BerlinDB. A full stack trace named it: MCPServerMeta\Schema carried
		// `'length' => array( 'meta_key' => 191 )` on an index definition.
		// Kern\Index declares no $length, so set_vars() assigned it as a
		// dynamic property and then ignored it — the live index was always on
		// the FULL column (SUB_PART NULL). Removed there; zero functional
		// change. Reading a trace beat reasoning about which file "looked"
		// responsible.
		array(
			'name'    => 'created_at',
			'type'    => 'key',
			'columns' => array( 'created_at' ),
		),
	);
}
