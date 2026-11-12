<?php
/**
 * BerlinDB Schema for the Clients registry.
 *
 * One row per client per server. The row that makes this table worth having
 * is the one the activity log can no longer produce: a client that connected
 * months ago, after retention has removed every request it ever made.
 *
 * ## Why `identity_hash` and not just `identity`
 *
 * The unique index has to key on something fixed-width. An identity can be an
 * OAuth client id, a fleet host key, a client name or a user-agent product
 * token, and `varchar(191)` inside a three-column UNIQUE would push past the
 * InnoDB utf8mb4 key-length ceiling once `connection_type` joined it. So the
 * hash carries the constraint and the raw value stays beside it for a human
 * to read.
 *
 * ## Why `client_id` exists
 *
 * Revoking an OAuth grant needs a `client_id` or a `token_family_id`, and the
 * activity log has neither — so a registry built only from traffic could show
 * an OAuth client and never revoke it. This column is what makes Revoke real.
 *
 * ## `last_seen_at` is nullable on purpose
 *
 * NULL means "registered, never used", which is a state worth seeing: it is
 * the difference between a client that is set up wrong and one that is not
 * set up yet. A zero datetime would read as 1970 and sort wrong.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\Clients
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\Clients;

use AcrossAI_MCP_Manager\Includes\Database\Support\DatetimeColumn;

defined( 'ABSPATH' ) || exit;

/**
 * Columns and indexes of the acrossai_mcp_clients table.
 *
 * @since 0.4.9
 */
class Schema extends \BerlinDB\Database\Kern\Schema {

	/**
	 * Array of column definitions.
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

		// Foreign reference to wp_acrossai_mcp_servers.id (no physical FK).
		array(
			'name'       => 'server_id',
			'type'       => 'bigint',
			'length'     => '20',
			'unsigned'   => true,
			'default'    => 0,
			'searchable' => true,
			'sortable'   => true,
		),

		// sha1( server_id|connection_type|identity ). Fixed width so it can
		// carry the UNIQUE constraint that `identity` is too wide for.
		array(
			'name'    => 'identity_hash',
			'type'    => 'char',
			'length'  => '40',
			'default' => '',
		),

		// The identity in readable form — an OAuth client id, a fleet host
		// key, a client name or a user-agent product token.
		array(
			'name'       => 'identity',
			'type'       => 'varchar',
			'length'     => '191',
			'default'    => '',
			'searchable' => true,
		),

		// A ConnectionContext TYPE_* value: how the caller authenticated.
		array(
			'name'       => 'connection_type',
			'type'       => 'varchar',
			'length'     => '32',
			'default'    => '',
			'searchable' => true,
			'sortable'   => true,
		),

		// A ClientsConstants ROUTE_* value: how the client was set up. Stored
		// rather than derived at read time because the agent that revealed it
		// may be gone by the time anyone looks.
		array(
			'name'       => 'route',
			'type'       => 'varchar',
			'length'     => '32',
			'default'    => '',
			'searchable' => true,
			'sortable'   => true,
		),

		// Display name as the client reported it on MCP `initialize`.
		array(
			'name'       => 'client_name',
			'type'       => 'varchar',
			'length'     => '191',
			'default'    => '',
			'searchable' => true,
			'sortable'   => true,
		),

		array(
			'name'    => 'client_version',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),

		// Connector profile slug, for OAuth callers only.
		array(
			'name'       => 'connector_slug',
			'type'       => 'varchar',
			'length'     => '64',
			'default'    => '',
			'searchable' => true,
		),

		// OAuth client id. The join key Revoke needs; empty for every other
		// route.
		array(
			'name'       => 'client_id',
			'type'       => 'varchar',
			'length'     => '64',
			'default'    => '',
			'searchable' => true,
		),

		// WP application-password UUID, so an app-password client can be
		// revoked. Available on the request and otherwise discarded.
		array(
			'name'    => 'credential_id',
			'type'    => 'varchar',
			'length'  => '36',
			'default' => '',
		),

		// A short non-secret fragment, so an operator can match a row to the
		// credential in their hand without it being shown one.
		array(
			'name'    => 'credential_hint',
			'type'    => 'varchar',
			'length'  => '32',
			'default' => '',
		),

		array(
			'name'       => 'user_id',
			'type'       => 'bigint',
			'length'     => '20',
			'unsigned'   => true,
			'default'    => 0,
			'searchable' => true,
			'sortable'   => true,
		),

		array(
			'name'    => 'last_ip',
			'type'    => 'varchar',
			'length'  => '45',
			'default' => '',
		),

		array(
			'name'    => 'last_user_agent',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => '',
		),

		array(
			'name'     => 'request_count',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
			'sortable' => true,
		),

		array(
			'name'       => 'first_seen_at',
			'type'       => 'datetime',
			'date_query' => true,
			'sortable'   => true,
			'validate'   => array( DatetimeColumn::class, 'validate_utc' ),
		),

		// NULL until the client actually calls. See the class docblock.
		array(
			'name'       => 'last_seen_at',
			'type'       => 'datetime',
			'allow_null' => true,
			'default'    => null,
			'date_query' => true,
			'sortable'   => true,
			'validate'   => array( DatetimeColumn::class, 'validate_utc' ),
		),

		// Soft revoke: the row keeps its history instead of disappearing,
		// which is the whole point of a durable registry.
		array(
			'name'       => 'revoked_at',
			'type'       => 'datetime',
			'allow_null' => true,
			'default'    => null,
			'date_query' => true,
			'sortable'   => true,
			'validate'   => array( DatetimeColumn::class, 'validate_utc' ),
		),
	);

	/**
	 * Array of index definitions.
	 *
	 * BerlinDB v3 requires the PRIMARY KEY as an explicit entry — the
	 * `primary` column flag is query-layer only, not DDL.
	 *
	 * `server_identity` UNIQUE is the correctness invariant: at most one row
	 * per (server, identity). It is also what the upsert collides on, so
	 * removing it would not merely permit duplicates, it would stop the
	 * recorder working at all.
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
			'name'    => 'server_identity',
			'type'    => 'unique',
			'columns' => array( 'server_id', 'identity_hash' ),
		),
		array(
			'name'    => 'server_last_seen',
			'type'    => 'key',
			'columns' => array( 'server_id', 'last_seen_at' ),
		),
		array(
			'name'    => 'connector_slug',
			'type'    => 'key',
			'columns' => array( 'connector_slug' ),
		),
		array(
			'name'    => 'last_seen_at',
			'type'    => 'key',
			'columns' => array( 'last_seen_at' ),
		),
	);
}
