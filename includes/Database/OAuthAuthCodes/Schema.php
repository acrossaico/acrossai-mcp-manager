<?php
/**
 * BerlinDB Schema for the OAuthAuthCodes module (Feature 021).
 *
 * 11 columns. FR-040 SHA-256 invariant: code_hash char(64). PKCE S256
 * invariant preserved from F011: code_challenge char(43). Atomic single-use
 * via `used tinyint` + `expires_at` clause (B10 pattern).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthAuthCodes
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes;

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
			'name'   => 'code_hash',
			'type'   => 'char',
			'length' => '64',
		),
		array(
			'name'    => 'client_id',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),
		// F032 — first-class server binding. NOT NULL final state per FR-003 / Q4.
		// Resolved from RFC 8707 `resource` param at authorize time (FR-010).
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
			'name'    => 'redirect_uri',
			'type'    => 'varchar',
			'length'  => '500',
			'default' => '',
		),
		// F011 PKCE S256 invariant — DO NOT narrow.
		array(
			'name'    => 'code_challenge',
			'type'    => 'char',
			'length'  => '43',
			'default' => '',
		),
		array(
			'name'    => 'code_challenge_method',
			'type'    => 'varchar',
			'length'  => '16',
			'default' => 'S256',
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
			'name'    => 'used',
			'type'    => 'tinyint',
			'length'  => '1',
			'default' => 0,
		),
		array(
			'name'     => 'expires_at',
			'type'     => 'datetime',
			'validate' => array( DatetimeColumn::class, 'validate_utc' ),
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
			'name'    => 'code_hash',
			'type'    => 'unique',
			'columns' => array( 'code_hash' ),
		),
		array(
			'name'    => 'expires_at',
			'type'    => 'key',
			'columns' => array( 'expires_at' ),
		),
	);
}
