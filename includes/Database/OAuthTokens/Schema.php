<?php
/**
 * BerlinDB Schema for the OAuthTokens module (Feature 021).
 *
 * 11 columns (SEC-021-001 adds `token_family_id` for family revocation
 * per RFC 9700 §2.2.2). FR-040: token_hash char(64) SHA-256 invariant.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthTokens
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthTokens;

use AcrossAI_MCP_Manager\Includes\Database\Support\DatetimeColumn;

defined( 'ABSPATH' ) || exit;

class Schema extends \BerlinDB\Database\Kern\Schema {

	/** @var array */
	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'sortable' => true,
		),
		// FR-040 SHA-256 invariant — DO NOT narrow.
		array(
			'name'   => 'token_hash',
			'type'   => 'char',
			'length' => '64',
		),
		array(
			'name'    => 'token_type',
			'type'    => 'varchar',
			'length'  => '16',
			'default' => 'access',
		),
		array(
			'name'    => 'client_id',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),
		// F032 — first-class server binding. NOT NULL final state per FR-002 / Q4.
		// Populated at token issuance from auth_code.server_id + at refresh from prior_token.server_id.
		array(
			'name'       => 'server_id',
			'type'       => 'bigint',
			'length'     => '20',
			'unsigned'   => true,
			'allow_null' => false,
		),
		array(
			'name'     => 'user_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'    => 'scope',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => 'mcp',
		),
		array(
			'name'    => 'resource',
			'type'    => 'varchar',
			'length'  => '500',
			'default' => '',
		),
		array(
			'name'     => 'expires_at',
			'type'     => 'datetime',
			'validate' => array( DatetimeColumn::class, 'validate_utc' ),
		),
		array(
			'name'    => 'revoked',
			'type'    => 'tinyint',
			'length'  => '1',
			'default' => 0,
		),
		// SEC-021-001 — RFC 9700 §2.2.2 family revocation invariant. DO NOT narrow (UUIDv4 = 36 chars).
		array(
			'name'    => 'token_family_id',
			'type'    => 'char',
			'length'  => '36',
			'default' => '',
		),
		array(
			'name'       => 'created_at',
			'type'       => 'datetime',
			'created'    => true,
			'date_query' => true,
			'sortable'   => true,
			'validate'   => array( DatetimeColumn::class, 'validate_utc' ),
		),
	);

	/** @var array */
	public $indexes = array(
		array(
			'name'    => 'primary',
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
		array(
			'name'    => 'token_hash',
			'type'    => 'unique',
			'columns' => array( 'token_hash' ),
		),
		array(
			'name'    => 'client_id',
			'type'    => 'key',
			'columns' => array( 'client_id' ),
		),
		array(
			'name'    => 'user_id',
			'type'    => 'key',
			'columns' => array( 'user_id' ),
		),
		array(
			'name'    => 'expires_at',
			'type'    => 'key',
			'columns' => array( 'expires_at' ),
		),
		array(
			'name'    => 'token_type',
			'type'    => 'key',
			'columns' => array( 'token_type' ),
		),
		// SEC-021-001 — enables family-scoped bulk revoke path.
		array(
			'name'    => 'token_family_id',
			'type'    => 'key',
			'columns' => array( 'token_family_id' ),
		),
		// F032 — composite key accelerates the primary lookup pattern
		// `WHERE server_id = ? AND client_id = ?` used by REST endpoints.
		array(
			'name'    => 'server_id_client_id',
			'type'    => 'key',
			'columns' => array( 'server_id', 'client_id' ),
		),
	);
}
