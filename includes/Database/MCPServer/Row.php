<?php
/**
 * BerlinDB Row for a single MCPServer record.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single row from the MCPServer module's table.
 *
 * @property array $properties
 */
class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */    public $id                            = 0;
	/** @var string */ public $server_name                   = '';
	/** @var string */ public $server_slug                   = '';
	/** @var string */ public $description                   = '';
	/** @var int */    public $is_enabled                    = 0;
	/** @var string */ public $registered_from               = 'plugin';
	/** @var string */ public $server_route_namespace        = 'mcp';
	/** @var string */ public $server_route                  = '';
	/** @var string */ public $server_version                = 'v1.0.0';
	/** @var int */    public $tool_discover_abilities       = 1;
	/** @var int */    public $tool_get_ability_info         = 1;
	/** @var int */    public $tool_execute_ability          = 1;
	/** @var int */    public $override_abilities_permission = 0;
	/** @var string */ public $abilities_default_policy      = 'per-ability';
	/** @var string */ public $created_at                    = '';

	/**
	 * Constructor — casts primitive types.
	 *
	 * B18: `$wpdb` returns TINYINT columns as strings; explicit `(int)` casts
	 * are mandatory here so downstream consumers can strict-compare or use
	 * `! empty()` safely on the tinyint enablement flags (F025 tool_* + F030
	 * override_abilities_permission).
	 *
	 * @param object|array $item Raw DB row.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );
		$this->id                            = (int) $this->id;
		$this->tool_discover_abilities       = (int) $this->tool_discover_abilities;
		$this->tool_get_ability_info         = (int) $this->tool_get_ability_info;
		$this->tool_execute_ability          = (int) $this->tool_execute_ability;
		$this->override_abilities_permission = (int) $this->override_abilities_permission;
	}

	/**
	 * Return this row as an associative array (external consumers depend on this).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                            => $this->id,
			'server_name'                   => $this->server_name,
			'server_slug'                   => $this->server_slug,
			'description'                   => $this->description,
			'is_enabled'                    => $this->is_enabled,
			'registered_from'               => $this->registered_from,
			'server_route_namespace'        => $this->server_route_namespace,
			'server_route'                  => $this->server_route,
			'server_version'                => $this->server_version,
			'tool_discover_abilities'       => $this->tool_discover_abilities,
			'tool_get_ability_info'         => $this->tool_get_ability_info,
			'tool_execute_ability'          => $this->tool_execute_ability,
			'override_abilities_permission' => $this->override_abilities_permission,
			'abilities_default_policy'      => $this->abilities_default_policy,
			'created_at'                    => $this->created_at,
		);
	}
}
