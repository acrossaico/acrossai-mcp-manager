<?php
/**
 * ToolPolicy — canonical composer + splitter for per-server MCP tool state.
 *
 * Feature 025 hybrid storage model:
 *   - The three MCP protocol tools live as `tinyint(1) NOT NULL DEFAULT 1`
 *     columns on `wp_acrossai_mcp_servers` (see Schema::$columns).
 *   - Curated abilities live as presence rows in `wp_acrossai_mcp_server_tools`
 *     (Feature 020, unchanged).
 *
 * This class owns the canonical union+split logic so both the server-registration
 * paths (Controller::register_database_servers + Controller::filter_default_server_config)
 * AND the REST layer (ToolsController::get_tools + ToolsController::post_tools) route
 * through a single implementation. Mirrors F017's ExposureResolver pattern
 * (DEC-ABILITY-OVERRIDE-RESOLUTION) — no consumer may re-derive the union inline.
 *
 * Stateless per A11 pure-service exemption — no singleton, no constructor state.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Abilities\SetupRequired;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helper for composing the effective tool list and splitting a POST payload.
 *
 * @since 0.1.0
 */
final class ToolPolicy {

	/**
	 * `tools_default_policy` values — deliberately the SAME vocabulary as the
	 * sibling `abilities_default_policy` column (`expose` | `hide` |
	 * `per-ability`). An operator reading either column should not have to learn
	 * two words for one idea; only the per-item value differs, because the item
	 * differs.
	 *
	 * @var string
	 */
	public const POLICY_PER_TOOL = 'per-tool';

	/** @var string */
	public const POLICY_EXPOSE = 'expose';

	/** @var string */
	public const POLICY_HIDE = 'hide';

	/**
	 * Every accepted `tools_default_policy` value.
	 *
	 * @var string[]
	 */
	public const POLICIES = array( self::POLICY_PER_TOOL, self::POLICY_EXPOSE, self::POLICY_HIDE );

	/**
	 * The three MCP protocol tools registered by the vendored mcp-adapter package.
	 * Single canonical PHP source — the JS mirror in `src/js/tools.js` is kept in
	 * step by hand at build time.
	 */
	public const PROTOCOL_TOOLS = array(
		'mcp-adapter/discover-abilities',
		'mcp-adapter/get-ability-info',
		'mcp-adapter/execute-ability',
	);

	/**
	 * Column-name to protocol-slug map. Used by `split_payload()` to route a
	 * unified `tools` array into the two storage layers, and by `compose_for_row()`
	 * to iterate rows' column values back into slugs.
	 */
	public const COLUMN_MAP = array(
		'tool_discover_abilities' => 'mcp-adapter/discover-abilities',
		'tool_get_ability_info'   => 'mcp-adapter/get-ability-info',
		'tool_execute_ability'    => 'mcp-adapter/execute-ability',
	);

	/**
	 * Canonical metadata for the three protocol tools — used by
	 * `ToolsController::get_tools()` when `include_abilities=1` to guarantee
	 * they appear in the response's ability catalog regardless of whether
	 * `wp_get_abilities()` returned them.
	 *
	 * The vendor mcp-adapter registers these via `wp_register_ability` on
	 * `wp_abilities_api_init`, but its listener attaches inside
	 * `Controller::initialize_adapter()` (rest_api_init) — which fires AFTER
	 * `wp_abilities_api_init` on any REST request whose Abilities-API
	 * bootstrap already ran on `init`. That leaves `wp_get_abilities()`
	 * blind to the three protocol slugs at REST-handler time. Since
	 * `ToolPolicy` is the authoritative canonical source for these slugs,
	 * we ship their metadata inline as a runtime-timing-safe fallback.
	 *
	 * (SEC-025-v2-2 correction, 2026-07-14 runtime evidence.)
	 */
	public const PROTOCOL_TOOL_METADATA = array(
		array(
			'name'        => 'mcp-adapter/discover-abilities',
			'label'       => 'Discover Abilities',
			'description' => 'Lists all publicly available WordPress abilities registered on this site. AI clients use this to discover what actions the server can perform.',
			'type'        => 'tool',
			'category'    => 'mcp-adapter',
		),
		array(
			'name'        => 'mcp-adapter/get-ability-info',
			'label'       => 'Get Ability Info',
			'description' => 'Returns detailed information about a specific ability, including its input/output schema and description. Used by AI clients before executing an ability.',
			'type'        => 'tool',
			'category'    => 'mcp-adapter',
		),
		array(
			'name'        => 'mcp-adapter/execute-ability',
			'label'       => 'Execute Ability',
			'description' => 'Executes a WordPress ability with the provided input parameters and returns the result. This is the primary tool used by AI clients to interact with WordPress.',
			'type'        => 'tool',
			'category'    => 'mcp-adapter',
		),
	);

	/**
	 * Compose the effective tool list for a server row.
	 *
	 * Union of (enabled protocol columns → slugs) and curated slugs from
	 * `MCPServerToolQuery::get_added_slugs()`. Deduped and `array_values()`-
	 * normalized. Order stability: protocol slugs first (in COLUMN_MAP key
	 * order, only for columns with value 1), curated after (row-insertion
	 * order returned by `get_added_slugs`).
	 *
	 * B18: uses `! empty()` to check column values — never strict-compare to 1
	 * because `$wpdb` can return TINYINTs as strings; the Row constructor
	 * casts these to int at load time, but defense-in-depth here matches
	 * F020's `ToolExposureGate` style.
	 *
	 * @since 0.1.0
	 * @param Row $row The server row.
	 * @return string[] The composed tool list.
	 */
	public static function compose_for_row( Row $row ): array {
		$tools = array();

		foreach ( self::COLUMN_MAP as $column => $slug ) {
			if ( ! empty( $row->{$column} ) ) {
				$tools[] = $slug;
			}
		}

		$curated = MCPServerToolQuery::instance()->get_added_slugs( (int) $row->id );
		foreach ( $curated as $slug ) {
			$tools[] = (string) $slug;
		}

		return array_values( array_unique( array_map( 'strval', $tools ) ) );
	}

	/**
	 * Compose the effective tool list for a server row at MCP server-registration
	 * time.
	 *
	 * Returns:
	 *   1. Enabled protocol columns (F025) — three tool_* boolean columns.
	 *   2. Curated ability slugs (F020) — presence rows in wp_acrossai_mcp_server_tools.
	 *
	 * **NOT included** (F026 scope revert, 2026-07-15): abilities where
	 * `meta.mcp.public = true` (or `is_exposed = 1` per-server override) are
	 * NO LONGER advertised directly in `tools/list`. AI clients access them
	 * through the three built-in meta tools (`mcp-adapter/discover-abilities`,
	 * `.../get-ability-info`, `.../execute-ability`) whose callbacks now honor
	 * per-server visibility via commit 070ffe2's `wp_register_ability_args`
	 * swap. This keeps `tools/list` scoped to the operator's Tools-tab picks
	 * and prevents ability slugs from leaking into the tool advertisement
	 * surface.
	 *
	 * **F090 ended the straight-passthrough relationship with
	 * `compose_for_row()`.** The two now answer genuinely different questions:
	 *
	 *   - `compose_for_row()`             — what the operator CONFIGURED
	 *   - `compose_effective_tools_for_row()` — what this server ACTUALLY SERVES
	 *
	 * Both F090 precedence layers live HERE, and nowhere else. Architecture
	 * review rated splitting them across `ToolPolicy` and `MCP\Controller`
	 * High: REST reads `compose_for_row()` while MCP registration reads this
	 * method, so a rule implemented in only one place would let the Tools tab
	 * show the operator a list the server is not serving.
	 *
	 * Precedence, highest first:
	 *
	 *   1. Type requirement UNMET -> exactly the diagnostic slug. A server
	 *      cannot advertise tools whose abilities are not registered, and the
	 *      client must be told why rather than handed an empty list.
	 *   2. `tools_default_policy` `expose` / `hide` -> a STANDING rule that wins
	 *      over individual curation, exactly as `abilities_default_policy` wins
	 *      over per-ability override rows.
	 *   3. `per-tool` -> the configured set (columns + curated rows).
	 *
	 * The server TYPE's own tool list is deliberately NOT in this chain. A type
	 * is a template that WRITES into layer 3 when Reset or a switch runs; it is
	 * never a runtime filter.
	 *
	 * Writes nothing. An unmet requirement must leave curated rows untouched so
	 * they return intact when the sibling plugin is reactivated.
	 *
	 * @since 0.1.0 (Feature 026; precedence chain added by Feature 090)
	 * @param Row $row The server row.
	 * @return string[] What this server serves right now, deduped.
	 */
	public static function compose_effective_tools_for_row( Row $row ): array {
		$server_type = (string) $row->server_type;

		// Layer 1 — requirement unmet. Highest precedence: nothing else can
		// make unregistered abilities servable.
		if ( ! ServerTypes::is_available( $server_type ) ) {
			return array( SetupRequired::SLUG );
		}

		// Layer 2 — the coarse standing rule.
		$policy = (string) $row->tools_default_policy;

		if ( self::POLICY_HIDE === $policy ) {
			return array();
		}

		if ( self::POLICY_EXPOSE === $policy ) {
			// The site's POOL, not the type's declared list. Resolved live on
			// every request, which is what makes this a STANDING rule rather
			// than a snapshot: a tool-level ability registered tomorrow by a
			// plugin installed tomorrow lands in the pool and is exposed with no
			// admin action. That is the whole reason this is a column and not a
			// one-time write.
			//
			// Scoping it to the type's own list would silently exclude anything
			// the type does not already name — including third-party tools no
			// type claims.
			return ServerTypes::pool();
		}

		// Layer 3 — the configured set, narrowed to abilities that still exist,
		// minus the diagnostic.
		//
		// `SetupRequired` IS a registered ability, so without the subtraction a
		// slug written straight into the curated rows would survive the narrowing
		// and a HEALTHY server would advertise a "the add-on is missing" tool
		// while nothing is missing. T042 specifies it is admitted only to a server
		// whose own requirement is unmet — layer 1 above — and this is what makes
		// that true rather than merely intended.
		//
		// Not reachable through the UI: the picker renders `ServerTypes::pool()`,
		// which excludes it. Reachable by POSTing the slug to the tools route as
		// an administrator, which is why the rule belongs here in the composer
		// rather than in the picker.
		//
		// Curated rows are presence rows and they OUTLIVE the plugin that
		// registered the ability: deactivate Advanced Custom Fields and the
		// `toolset/acf` row remains, so the tab kept listing a tool the site can
		// no longer serve. Filtering here — not deleting the row — means the
		// operator's pick returns intact the moment the plugin is reactivated,
		// which is the same guarantee a deactivate/reactivate cycle already has.
		return array_values(
			array_diff(
				ServerTypes::registered_only( self::compose_for_row( $row ) ),
				array( SetupRequired::SLUG )
			)
		);
	}

	/**
	 * Split a REST POST body's `tools` array into the two storage layers.
	 *
	 * Returns:
	 *   [
	 *     'columns' => [
	 *         'tool_discover_abilities' => 0|1,
	 *         'tool_get_ability_info'   => 0|1,
	 *         'tool_execute_ability'    => 0|1,
	 *     ],
	 *     'curated' => string[]  // slugs with protocol slugs stripped
	 *   ]
	 *
	 * Missing protocol slugs in `$tools` become `0` (the "removed" state).
	 * Non-protocol slugs are handed unchanged to the curated array — the
	 * caller (ToolsController::post_tools) uses this half as
	 * MCPServerToolQuery::replace_set()'s desired-set argument.
	 *
	 * @since 0.1.0
	 * @param array<int, mixed> $tools The submitted tools array (any mix of types; normalized here).
	 * @return array{columns: array<string, int>, curated: string[]}
	 */
	public static function split_payload( array $tools ): array {
		$normalized = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $tools ),
					'strlen'
				)
			)
		);

		$columns = array();
		foreach ( self::COLUMN_MAP as $column => $slug ) {
			$columns[ $column ] = in_array( $slug, $normalized, true ) ? 1 : 0;
		}

		$curated = array_values( array_diff( $normalized, self::PROTOCOL_TOOLS ) );

		return array(
			'columns' => $columns,
			'curated' => $curated,
		);
	}
}
