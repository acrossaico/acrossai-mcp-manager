<?php
/**
 * BerlinDB Query for the MCPServerAbility module.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerAbility
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility;

use BerlinDB\Database\Kern\Query as BerlinDB_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * BerlinDB Query subclass for the MCP server abilities table.
 *
 * Adds one bespoke helper `upsert()` on top of the inherited public API.
 *
 * @since 0.1.0
 */
class Query extends BerlinDB_Query {

	/**
	 * Table name (without WordPress table prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'acrossai_mcp_server_abilities';

	/**
	 * SQL alias used in JOIN expressions.
	 *
	 * @var string
	 */
	protected $table_alias = 'mcpsa';

	/**
	 * Schema class for this query.
	 *
	 * @var string
	 */
	protected $table_schema = Schema::class;

	/**
	 * Singular item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name = 'mcp_server_ability';

	/**
	 * Plural item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name_plural = 'mcp_server_abilities';

	/**
	 * Row class for query results.
	 *
	 * @var string
	 */
	protected $item_shape = Row::class;

	/**
	 * Singleton instance.
	 *
	 * @var Query|null
	 */
	protected static $instance = null;

	/**
	 * Private constructor — enforces singleton pattern (A2/S6).
	 *
	 * @since 0.1.0
	 */
	private function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Visibility override (public → private) enforces the singleton pattern per A2/S6; PHPCS misses the visibility semantics.
		parent::__construct();
	}

	/**
	 * Get the singleton instance.
	 *
	 * @since  0.1.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Insert-or-update a per-(server, ability) exposure row.
	 *
	 * If a row already exists for the given `(server_id, ability_slug)` pair,
	 * update `is_exposed`. Otherwise insert a new row.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $server_id    The MCP server id.
	 * @param string $ability_slug The ability name (\WP_Ability::get_name()).
	 * @param bool   $is_exposed   Whether the ability is exposed on this server.
	 * @return bool True on success, false on failure.
	 */
	public function upsert( int $server_id, string $ability_slug, bool $is_exposed ): bool {
		$existing = $this->query(
			array(
				'server_id'    => $server_id,
				'ability_slug' => $ability_slug,
				'number'       => 1,
			)
		);

		if ( ! empty( $existing ) ) {
			return (bool) $this->update_item(
				$existing[0]->id,
				array( 'is_exposed' => (int) $is_exposed )
			);
		}

		return (bool) $this->add_item(
			array(
				'server_id'    => $server_id,
				'ability_slug' => $ability_slug,
				'is_exposed'   => (int) $is_exposed,
			)
		);
	}

	/**
	 * F082 SEC-002 — Bulk-delete every override row for a given server.
	 *
	 * Called by the `mcp_server_deleted` cascade cleanup listener wired in
	 * `Main.php::define_public_hooks()`. Mirrors F020's
	 * `MCPServerTool\Query::delete_items_for_server()` shape verbatim.
	 *
	 * @since 0.1.0 (F082)
	 *
	 * @param int $server_id The MCP server id whose override rows to delete.
	 * @return int Number of rows deleted.
	 */
	public function delete_items_for_server( int $server_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_server_abilities';
		$count = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bulk cascade cleanup; BerlinDB per-row delete would be N round-trips (matches F020 SEC-020-011 rationale).
			$table,
			array( 'server_id' => $server_id ),
			array( '%d' )
		);
		// Flush the per-item BerlinDB cache group so stale reads don't survive.
		wp_cache_flush_group( 'acrossai_mcp_server_ability' );
		return (int) ( false === $count ? 0 : $count );
	}

	/**
	 * F082 SEC-002 — static callback for `mcp_server_deleted` cascade cleanup.
	 *
	 * Fired by BerlinDB's `MCPServer\Query::delete_item()` after a successful
	 * server-row delete. Payload: `int $server_id, bool $result`. Both the
	 * single-row delete path and the bulk-delete path route through
	 * `delete_item()`, so this single hook covers both. No-ops when `$result`
	 * is false — a failed server delete MUST NOT trigger cascade cleanup.
	 *
	 * @since 0.1.0 (F082)
	 *
	 * @param int  $server_id The deleted server's id.
	 * @param bool $result    Whether the DB delete succeeded.
	 * @return void
	 */
	public static function on_mcp_server_deleted( int $server_id, bool $result ): void {
		if ( ! $result || $server_id <= 0 ) {
			return;
		}
		self::instance()->delete_items_for_server( $server_id );
	}
}
