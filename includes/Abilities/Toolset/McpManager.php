<?php
/**
 * The MCP Manager Toolset.
 *
 * The one Toolset whose abilities this plugin ships itself. Every other
 * dispatcher here fronts a group the AcrossAI Abilities Manager add-on
 * registers; this one fronts the activity log, which is this plugin's own
 * table and admin screen, so the abilities live beside it in
 * `Includes\Abilities\ActivityLog`.
 *
 * That difference is only about where the members come from. A caller sees the
 * same three actions as every other Toolset, and `AbilityGroup` resolves
 * membership the same way — by each ability declaring
 * `meta.acrossai.tab_group`. Nothing here is a special case at the dispatch
 * layer.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes/Abilities/Toolset
 * @since      0.4.8
 */

namespace AcrossAI_MCP_Manager\Includes\Abilities\Toolset;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatcher for the MCP Manager group.
 */
final class McpManager extends Base_Toolset_Ability {

	/**
	 * Group identifier its member abilities declare.
	 *
	 * Public so the abilities can name it rather than repeating the string —
	 * a typo there puts an ability in a group nothing dispatches, which fails
	 * silently by simply never appearing.
	 *
	 * @var string
	 */
	public const GROUP = 'mcp-manager';

	/**
	 * This dispatcher's ability slug.
	 *
	 * A constant as well as {@see self::slug()} because {@see Guide} has to
	 * name it from outside the instance — see its NOT_A_SUBJECT list.
	 *
	 * @var string
	 */
	public const SLUG = 'toolset/mcp-manager';

	/**
	 * @return string
	 */
	protected function group(): string {
		return self::GROUP;
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return self::SLUG;
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'MCP Manager', 'acrossai-mcp-manager' );
	}

	/**
	 * Contribute nothing to the server-type registry.
	 *
	 * The base class declares its dispatcher into the `acrossai` server type
	 * and, crucially, sets that type's `requires` to null — "the requirement is
	 * this plugin, and if this code is running it is met". That reasoning holds
	 * for every OTHER dispatcher, because each only reaches this point when the
	 * AcrossAI Abilities Manager add-on has registered its group's abilities.
	 *
	 * It does not hold here. This Toolset's members ship with this plugin, so
	 * it registers on every site — including sites with no add-on at all. The
	 * inherited behaviour therefore announced that the AcrossAI type needed
	 * nothing, on precisely the sites where its toolsets cannot exist, and
	 * `FleetTypeRequirementTest` caught it.
	 *
	 * Declaring nothing has a second effect worth stating, because it is also
	 * desirable: no server type claims `toolset/mcp-manager`, so it stays in
	 * the unclaimed pool that {@see Registrar} offers on EVERY type. An
	 * operator can add it to any server; Reset writes it on none. That is the
	 * right default for a tool that reads this site's own traffic.
	 *
	 * @since  0.4.8
	 * @param  mixed $types Server types, untouched.
	 * @return mixed
	 */
	public function declare_server_type_tool( $types ) {
		return $types;
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'This site\'s own MCP activity log — what reached this site, what it ran, and whether it worked. Read one server\'s traffic or every server at once, filter by event type, status, connection type, client, connector, fleet peer, component or a date range, and page through large results with a cursor. Also returns the distinct filter values this site actually holds, a period summary, and one request with the ability runs that happened inside it. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-mcp-manager' );
	}
}
