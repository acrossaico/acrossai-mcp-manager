<?php
/**
 * BerlinDB Schema for the MCPServerMeta module.
 *
 * Standard WordPress meta-table shape (mirrors wp_postmeta / wp_usermeta),
 * but keyed by `server_id` (the plugin's own MCP server PK) instead of
 * `post_id`. Backs any per-server key-value setting — F037 uses it for
 * `_embeds_enabled` (master toggle) + `_embeds_clients` (JSON blob:
 * { npm: 0|1, mcp-client: [slugs], connectors: [slugs] }).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerMeta
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta;

defined( 'ABSPATH' ) || exit;

class Schema extends \BerlinDB\Database\Kern\Schema {

	/** @var array */
	public $columns = array(
		array(
			'name'     => 'meta_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'sortable' => true,
		),
		array(
			'name'       => 'server_id',
			'type'       => 'bigint',
			'length'     => '20',
			'unsigned'   => true,
			'default'    => 0,
			'allow_null' => false,
		),
		array(
			'name'       => 'meta_key',
			'type'       => 'varchar',
			'length'     => '255',
			'allow_null' => true,
		),
		array(
			'name'       => 'meta_value',
			'type'       => 'longtext',
			'allow_null' => true,
		),
	);

	/**
	 * Indexes.
	 *
	 * `server_id_meta_key` (key, not unique) — mirrors wp_postmeta's
	 * `post_id` index shape; accelerates per-server key lookups.
	 *
	 * NO index-level `length` key, deliberately. One used to sit on `meta_key`
	 * (`'length' => array( 'meta_key' => 191 )`) intending a 191-character
	 * prefix for the old 767-byte index limit. It never did anything:
	 * BerlinDB's `Kern\Index` declares no `$length` property, so `set_vars()`
	 * assigned it as a DYNAMIC property — deprecated from PHP 8.2 — and then
	 * ignored it. Verified on the live table: `SUB_PART` is NULL, i.e. the
	 * index covers the FULL column, which is what it has always done.
	 *
	 * It is also unnecessary. `varchar(255)` at utf8mb4 is 1020 bytes, well
	 * inside InnoDB's 3072-byte limit under the DYNAMIC row format that MySQL
	 * 5.7+ and 8.0 default to. The 191 figure belongs to the 767-byte era.
	 *
	 * Removing it is therefore zero functional change and silences a
	 * deprecation notice that fired on EVERY request — `MCPServerMeta\Table`
	 * is constructed in `Main::bootstrap_database_tables()`, which runs on
	 * every page load, admin and front end alike. If a prefix is ever genuinely
	 * needed, it has to be expressed somewhere BerlinDB reads; this key is not
	 * that place.
	 *
	 * @var array
	 */
	public $indexes = array(
		array(
			'name'    => 'primary',
			'type'    => 'primary',
			'columns' => array( 'meta_id' ),
		),
		array(
			'name'    => 'server_id',
			'type'    => 'key',
			'columns' => array( 'server_id' ),
		),
		array(
			'name'    => 'meta_key',
			'type'    => 'key',
			'columns' => array( 'meta_key' ),
		),
	);
}
