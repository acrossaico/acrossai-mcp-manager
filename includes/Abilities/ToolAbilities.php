<?php
/**
 * ToolAbilities — the tool-level ability list behind the two admin pickers.
 *
 * Feature 087. Not every registered ability is an operator-facing choice. A
 * handful are *tool-level*: they are the entries an MCP server advertises in
 * `tools/list`, and every other ability reaches a client through one of them.
 * Two families exist today —
 *
 *   - the three mcp-adapter protocol tools (`ToolPolicy::PROTOCOL_TOOLS`),
 *     whose plugin-owned callbacks enumerate and run the ability catalogue;
 *   - the sibling `acrossai-abilities-manager` plugin's `toolset/*`
 *     dispatchers, each of which takes `action=discover|info|execute` and
 *     routes to the abilities in one `meta.acrossai.tab_group`.
 *
 * One list, two opposite jobs, which is why it lives in one place:
 *
 *   - **Abilities tab** (`?tab=abilities`) hides these. They are plumbing; a
 *     per-ability Exposed toggle on them either no-ops or breaks the protocol.
 *   - **Tools tab** (`?tab=tools`) shows ONLY these in its left "All abilities"
 *     pool. Curating individual abilities there was never how they reach a
 *     client — abilities travel through the tool-level entries.
 *
 * Companion plugins declare their own via the filter; nothing here hardcodes
 * another plugin's vocabulary. Consumed by `src/js/abilities.js` and
 * `src/js/tools.js`, which receive it through `wp_localize_script()` in
 * `admin/Main.php`. PHP is the single source of truth; the JS literals are a
 * boot-time fallback only.
 *
 * SCOPE — this list drives presentation. It changes neither exposure
 * (F017/F082), curation (F020/F025), nor call-time enforcement, and it is
 * deliberately NOT `MCP\ToolExposureGate::EXCLUDED_SLUGS`, which means "always
 * callable". An ability's presence or absence here does not gate a single call.
 *
 * Stateless per A11 pure-service exemption — no singleton, no constructor state.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helper resolving the tool-level ability slugs.
 *
 * @since 0.1.0
 */
final class ToolAbilities {

	/**
	 * Resolve the tool-level ability list.
	 *
	 * Deliberately not memoized — a companion plugin may register its callback
	 * later than the first call, and this runs at most twice per admin request.
	 *
	 * @since 0.1.0
	 * @return string[] Normalized, de-duplicated, list-indexed ability slugs.
	 */
	public static function get_slugs(): array {
		/**
		 * Filter the abilities treated as tool-level entries by the admin pickers.
		 *
		 * The Abilities tab hides every slug in this list; the Tools tab's left
		 * "All abilities" pool shows nothing else. Callbacks may add or remove
		 * freely — removing one of the defaults puts it back on the Abilities
		 * tab and takes it out of the Tools pool.
		 *
		 * Seeded with `ToolPolicy::PROTOCOL_TOOLS`, the three tools the vendored
		 * mcp-adapter registers. A plugin whose abilities should be individually
		 * addable as tools appends them here.
		 *
		 * This is presentation only — it changes neither exposure, curation, nor
		 * call-time enforcement. An ability already curated on a server keeps
		 * rendering (and stays removable) in the Tools tab's "Added as tools"
		 * pane whether or not it appears in this list.
		 *
		 * @since 0.1.0 (Feature 087)
		 *
		 * @param string[] $slugs Ability slugs treated as tool-level entries.
		 */
		$slugs = apply_filters(
			'acrossai_mcp_manager_tool_abilities',
			array_merge( ToolPolicy::PROTOCOL_TOOLS, array( ServerGuide::SLUG ) )
		);

		// Same normalization the server-registration filters get in
		// Controller::register_database_servers() — a callback returning null or
		// a scalar degrades to an empty list rather than fatalling downstream.
		$slugs = array_map( 'strval', (array) $slugs );
		$slugs = array_filter(
			$slugs,
			static function ( string $slug ): bool {
				return '' !== $slug;
			}
		);

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Keep this plugin's transport abilities out of the sibling's Toolsets.
	 *
	 * A Toolset dispatches to the abilities in one group. Ours belong to no
	 * group: they ARE the transport — the three protocol tools, the setup
	 * diagnostic, the server guide. The sibling's group tagger does not know
	 * that, finds no `meta.acrossai.tab_group`, and files them in its catch-all,
	 * so `toolset/other` ends up listing the very things that describe how to
	 * call a Toolset.
	 *
	 * Observed on a live server: `toolset/other` returned the server guide and
	 * the setup diagnostic as members. The guide is worse than odd there — it
	 * describes the MCP Adapter server type's surface, and was being advertised
	 * inside an AcrossAI-type server.
	 *
	 * The sibling publishes `acrossai_abilities_manager_protected_slugs` for
	 * exactly this, and its `AcrossAI_Ability_Group::resolve()` drops protected
	 * slugs before grouping — so they leave the counts as well as the listings,
	 * which `member_visible` alone would not have achieved.
	 *
	 * Contributes the whole tool-level list rather than naming slugs, so a
	 * future transport ability is excluded the moment it is declared.
	 *
	 * Filtering a hook the sibling may never fire costs nothing; the same
	 * unconditional-registration reasoning as `ToolsetExposureBridge`.
	 *
	 * @since  0.1.0
	 * @param  mixed $slugs Slugs collected so far.
	 * @return mixed
	 */
	public static function protect_from_toolsets( $slugs ) {
		if ( ! is_array( $slugs ) ) {
			return $slugs;
		}

		// `SetupRequired` is named separately because it is deliberately NOT in
		// `get_slugs()`: it is not a tool an operator curates, it is substituted
		// into the served list by `ToolPolicy` when a server type's requirement
		// is unmet. Transport all the same, and just as wrong inside a Toolset.
		return array_values(
			array_unique(
				array_merge( $slugs, self::get_slugs(), array( SetupRequired::SLUG ) )
			)
		);
	}
}
