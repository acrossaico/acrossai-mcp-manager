<?php
/**
 * Database schema definition for the unified abilities table.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities/Database
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database;

use BerlinDB\Database\Kern\Schema;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Schema class defining all 23 columns of the acrossai_abilities table.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Schema extends Schema {

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

		// Ability identifier.
		array(
			'name'       => 'ability_slug',
			'type'       => 'varchar',
			'length'     => '255',
			'searchable' => true,
			'sortable'   => true,
		),

		// Display name (FR-021: nullable — override rows carry no label).
		array(
			'name'       => 'label',
			'type'       => 'varchar',
			'length'     => '255',
			'allow_null' => true,
			'default'    => null,
			'searchable' => true,
			'sortable'   => true,
		),

		// Full description.
		array(
			'name'       => 'description',
			'type'       => 'longtext',
			'allow_null' => true,
			'default'    => null,
			'searchable' => true,
		),

		// Organizational category.
		array(
			'name'       => 'category',
			'type'       => 'varchar',
			'length'     => '100',
			'allow_null' => true,
			'default'    => null,
			'sortable'   => true,
		),

		// Lifecycle status. 20-char max; nullable for override rows that carry no lifecycle.
		array(
			'name'       => 'status',
			'type'       => 'varchar',
			'length'     => '20',
			'allow_null' => true,
			'default'    => null,
			'sortable'   => true,
		),

		// Provider string.
		array(
			'name'       => 'provider',
			'type'       => 'varchar',
			'length'     => '100',
			'allow_null' => true,
			'default'    => null,
			'sortable'   => true,
		),

		// Source origin of the record. Defaults to db.
		array(
			'name'     => 'source',
			'type'     => 'varchar',
			'length'   => '50',
			'default'  => 'db',
			'sortable' => true,
		),

		// Tri-state: site-wide allow override.
		array(
			'name'       => 'site_allowed',
			'type'       => 'tinyint',
			'length'     => '1',
			'allow_null' => true,
			'default'    => null,
		),

		// Callback type (enum guard in save_override); nullable for override rows.
		array(
			'name'       => 'callback_type',
			'type'       => 'varchar',
			'length'     => '50',
			'allow_null' => true,
			'default'    => null,
		),

		// Callback configuration JSON.
		array(
			'name'       => 'callback_config',
			'type'       => 'longtext',
			'allow_null' => true,
			'default'    => null,
		),

		// Input JSON Schema.
		array(
			'name'       => 'input_schema',
			'type'       => 'longtext',
			'allow_null' => true,
			'default'    => null,
		),

		// Output JSON Schema.
		array(
			'name'       => 'output_schema',
			'type'       => 'longtext',
			'allow_null' => true,
			'default'    => null,
		),

		// REST and MCP visibility flags (tri-state).
		array(
			'name'       => 'show_in_rest',
			'type'       => 'tinyint',
			'length'     => '1',
			'allow_null' => true,
			'default'    => null,
		),
		array(
			'name'       => 'show_in_mcp',
			'type'       => 'tinyint',
			'length'     => '1',
			'allow_null' => true,
			'default'    => null,
		),
		// MCP type.
		array(
			'name'       => 'mcp_type',
			'type'       => 'varchar',
			'length'     => '100',
			'allow_null' => true,
			'default'    => null,
		),

		// Remaining tri-state boolean columns.
		array(
			'name'       => 'readonly',
			'type'       => 'tinyint',
			'length'     => '1',
			'allow_null' => true,
			'default'    => null,
		),
		array(
			'name'       => 'destructive',
			'type'       => 'tinyint',
			'length'     => '1',
			'allow_null' => true,
			'default'    => null,
		),
		array(
			'name'       => 'idempotent',
			'type'       => 'tinyint',
			'length'     => '1',
			'allow_null' => true,
			'default'    => null,
		),

		// Audit timestamps — no explicit default; BerlinDB uses '0000-00-00 00:00:00'
		// for datetime columns. 'created'/'modified' flags handle auto-timestamping
		// at the application layer (CURRENT_TIMESTAMP quoted by BerlinDB is invalid DDL).
		array(
			'name'       => 'created_at',
			'type'       => 'datetime',
			'created'    => true,
			'date_query' => true,
			'sortable'   => true,
		),
		array(
			'name'       => 'updated_at',
			'type'       => 'datetime',
			'modified'   => true,
			'date_query' => true,
			'sortable'   => true,
		),

		// Audit user IDs.
		array(
			'name'       => 'created_by',
			'type'       => 'bigint',
			'length'     => '20',
			'unsigned'   => true,
			'allow_null' => true,
			'default'    => null,
		),
		array(
			'name'       => 'updated_by',
			'type'       => 'bigint',
			'length'     => '20',
			'unsigned'   => true,
			'allow_null' => true,
			'default'    => null,
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
	);
}
