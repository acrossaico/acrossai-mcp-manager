<?php
/**
 * F032 — BerlinDB Schema for the ConnectorApprovedUsers module.
 *
 * Six columns. `UNIQUE(server_id, connector_slug, user_id)` is the presence
 * constraint: at most one approved-row per (server, connector, user) triple.
 * Mirrors the F017 MCPServerAbility composite-key shape.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\ConnectorApprovedUsers
 * @since      0.1.6 (F032)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers;

use AcrossAI_MCP_Manager\Includes\Database\Support\DatetimeColumn;

defined( 'ABSPATH' ) || exit;

class Schema extends \BerlinDB\Database\Kern\Schema {

	/**
	 * Column definitions.
	 *
	 * @var array
	 */
	public $columns = array(

		// Primary key.
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'sortable' => true,
		),

		// FK reference to wp_acrossai_mcp_servers.id (no physical FK).
		array(
			'name'       => 'server_id',
			'type'       => 'bigint',
			'length'     => '20',
			'unsigned'   => true,
			'searchable' => true,
		),

		// Connector profile slug (e.g. 'claude', 'chatgpt'). Matches oauth_clients.connector_slug width.
		array(
			'name'       => 'connector_slug',
			'type'       => 'varchar',
			'length'     => '64',
			'default'    => '',
			'searchable' => true,
		),

		// FK reference to wp_users.ID (no physical FK).
		array(
			'name'       => 'user_id',
			'type'       => 'bigint',
			'length'     => '20',
			'unsigned'   => true,
			'searchable' => true,
		),

		// user_id of the admin who approved this user. 0 if the row was
		// created by a non-admin surface (should not happen — audit signal).
		array(
			'name'     => 'approved_by',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),

		// Set by BerlinDB `created` timestamping on INSERT.
		array(
			'name'       => 'approved_at',
			'type'       => 'datetime',
			'created'    => true,
			'date_query' => true,
			'sortable'   => true,
			'validate'   => array( DatetimeColumn::class, 'validate_utc' ),
		),
	);

	/**
	 * Index definitions.
	 *
	 * BerlinDB v3 requires the PRIMARY KEY to be declared as an explicit Index
	 * entry — the `primary` column flag is query-layer only, not DDL.
	 *
	 * @var array
	 */
	public $indexes = array(
		array(
			'name'    => 'primary',
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
		// Presence constraint — at most one row per (server, connector, user).
		array(
			'name'    => 'server_connector_user',
			'type'    => 'unique',
			'columns' => array( 'server_id', 'connector_slug', 'user_id' ),
		),
		// Panel-listing lookup: enumerate all approved users for a (server, connector).
		array(
			'name'    => 'server_connector',
			'type'    => 'key',
			'columns' => array( 'server_id', 'connector_slug' ),
		),
		// UserLifecycle cascade lookup: find every approval belonging to a user.
		array(
			'name'    => 'user_id',
			'type'    => 'key',
			'columns' => array( 'user_id' ),
		),
	);
}
