<?php
/**
 * One-shot backfill of `mcp-adapter/server-guide` onto MCP Adapter servers.
 *
 * The guide is part of the MCP Adapter type's tool set, but it has no `tool_*`
 * column — it can only exist as a curated row. Three kinds of server therefore
 * ended up without it:
 *
 *   - the SEEDED `Default MCP Server`, because `DefaultServerSeeder` writes no
 *     tool state at all (the three `tool_*` columns default to 1 and no curated
 *     row is ever written) — true even on a fresh install;
 *   - every server that predates the guide;
 *   - servers created from the form or the wizard, which
 *     `ToolPolicy::apply_type_defaults()` already fixed.
 *
 * This closes the first two. It runs on `admin_init` at priority 6 —
 * deliberately AFTER `Settings::maybe_seed_default_server` (4) and
 * `Settings::handle_actions` (5). That ordering is what lets one mechanism cover
 * both cases: on a fresh install the seeded row already exists by the time this
 * runs, and on an upgraded site the pre-existing rows do, while a create or
 * delete in the same request is never raced. At priority 3, alongside the schema
 * reconciler, it would run before seeding, miss the new row, and still record
 * itself as done.
 *
 * WHY NOT A BerlinDB `$upgrades` MIGRATION
 *
 * No schema changes, and the obvious implementation is unsafe four ways:
 *
 *   1. `ServerTypes::tools_for()` runs `registered_only()`, whose protocol-tool
 *      exemption covers the three `mcp-adapter/*` protocol slugs but NOT this
 *      guide. `ServerGuide::register()` is hooked on `wp_abilities_api_init`
 *
 *      @20, which WordPress fires LAZILY, so at `admin_init` that ordering is
 *      implicit. A registry that is populated but does not yet hold the guide
 *      filters it straight out and the backfill writes nothing.
 *   2. `tools_for()` also applies the `acrossai_mcp_server_types` filter, which
 *      would run third-party code inside a schema migration.
 *   3. `MCPServerToolQuery::replace_set()` REPLACES the whole set — it deletes
 *      every slug not in the desired list — and issues raw START TRANSACTION /
 *      COMMIT, which commits mid-migration and collapses WP_UnitTestCase's
 *      outer rollback (B53, which this feature has already hit twice).
 *   4. `replace_set()` throws, while upgrade callbacks return bool; an uncaught
 *      throwable during `maybe_upgrade()` on admin_init is a white screen.
 *
 * So this writes `ServerGuide::SLUG` DIRECTLY via `add_item()`: no registry, no
 * filter, no transaction, and nothing existing is touched. The slug is this
 * plugin's own constant for this plugin's own server type — it is not the
 * sibling's vocabulary. `ToolPolicyResetTest` locks the MCP Adapter type's
 * curated remainder to exactly this one slug, so a second one cannot be added
 * there without failing a test that names this file.
 *
 * ONE-SHOT, NOT RE-ASSERTED
 *
 * `Table::upgrade_to_1_1_6()` already makes the argument for its own gated
 * UPDATE: an unconditional re-run "would revert an operator who had switched
 * this server". The same holds here — re-asserting every request would re-add a
 * row somebody deliberately removed from the Tools tab. Running once is safe
 * today precisely because the guide is new enough that nobody can yet have
 * removed it on purpose.
 *
 * A11 pure-service exception (stateless, static-only — no singleton, no ctor).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 * @since      0.3.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Abilities\ServerGuide;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;
use AcrossAI_MCP_Manager\Includes\MCP\ToolExposureGate;

defined( 'ABSPATH' ) || exit;

/**
 * One-shot writer of the server-guide curated row.
 *
 * @since 0.3.6
 */
final class ServerGuideBackfill {

	/**
	 * Option flag marking the backfill as done.
	 *
	 * Deleting it re-runs the backfill, which is the documented way to recover
	 * the row on a server where it is wanted back.
	 */
	public const DONE_OPTION = 'acrossai_mcp_server_guide_backfilled';

	/**
	 * Add the guide to every MCP Adapter server that lacks it, once.
	 *
	 * The done-flag is written LAST. A fatal midway therefore leaves the flag
	 * unset and the next admin request retries, rather than recording a run
	 * that only half happened.
	 *
	 * @since 0.3.6
	 * @return void
	 */
	public static function maybe_backfill(): void {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}

		foreach ( self::servers_missing_the_guide() as $server_id ) {
			// `add_item()`, never `replace_set()`: this must ADD one row and
			// leave every other curated pick alone. The `server_ability` UNIQUE
			// index is the backstop if the SELECT above ever races.
			MCPServerToolQuery::instance()->add_item(
				array(
					'server_id'    => $server_id,
					'ability_slug' => ServerGuide::SLUG,
				)
			);

			// The gate caches added slugs per request, and it is the caller's
			// job to invalidate — `replace_set()` does not do it either.
			ToolExposureGate::flush_cache( $server_id );
		}

		update_option( self::DONE_OPTION, 1, false );
	}

	/**
	 * IDs of MCP Adapter servers with no server-guide row.
	 *
	 * Scoped to the legacy type on purpose. An `acrossai` server carries the
	 * sibling's `toolset/server-guide` instead, and giving it this one would
	 * put a tool in its list that its own type never declared.
	 *
	 * @since 0.3.6
	 * @return int[]
	 */
	private static function servers_missing_the_guide(): array {
		global $wpdb;

		$servers = $wpdb->prefix . 'acrossai_mcp_servers';
		$tools   = $wpdb->prefix . 'acrossai_mcp_server_tools';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot upgrade path; no cache layer spans two plugin tables.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT s.id FROM %i AS s
				 WHERE s.server_type = %s
				   AND NOT EXISTS (
				       SELECT 1 FROM %i AS t
				       WHERE t.server_id = s.id AND t.ability_slug = %s
				   )',
				$servers,
				ServerTypes::LEGACY,
				$tools,
				ServerGuide::SLUG
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
