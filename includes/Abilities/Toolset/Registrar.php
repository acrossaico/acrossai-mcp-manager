<?php
/**
 * Registers this plugin's Toolset dispatchers.
 *
 * A Toolset is a TOOL — one entry in a server's `tools/list` that routes
 * `action=discover|info|execute` to a whole group of abilities. Tools are a
 * server concern, so the dispatchers live here; the abilities they route to
 * stay in the AcrossAI Abilities Manager add-on, which is where they belong.
 *
 * **Per-plugin Toolsets are deliberately absent.** `toolset/elementor`,
 * `toolset/mailerpress` and the rest stay with whichever plugin owns that
 * integration, and remain reachable through `toolset/integrations` regardless.
 * Only the twelve WordPress groups, the guide and the integrations directory
 * are registered here.
 *
 * **Both plugins may carry these at once, on purpose.** Moving code between two
 * independently-updated plugins cannot be a remove-then-add: a site that updates
 * the add-on first would lose every Toolset until it updated this one. So this
 * ships FIRST and stands down while the add-on still has them —
 * `Base_Toolset_Ability::register()` bails when `wp_has_ability()` already knows
 * the slug, firing `acrossai_toolset_slug_collision` for the record. The add-on
 * loads earlier alphabetically, so during the overlap its copies win and this
 * release is a deliberate no-op.
 *
 * A11 pure-service exception (stateless, static-only — no singleton, no ctor).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities\Toolset
 * @since      0.3.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities\Toolset;

defined( 'ABSPATH' ) || exit;

/**
 * Instantiates every Toolset this plugin owns.
 *
 * @since 0.3.6
 */
final class Registrar {

	/**
	 * The twelve WordPress groups, in the order the add-on declared them.
	 *
	 * @var string[]
	 */
	private const CORE = array(
		Content::class,
		Blocks::class,
		Appearance::class,
		Configuration::class,
		Users::class,
		Updates::class,
		Cron::class,
		Cache::class,
		Backups::class,
		Database::class,
		Files::class,
		Diagnostics::class,
	);

	/**
	 * Every Toolset slug an AcrossAI server offers, in menu order.
	 *
	 * The `acrossai` server type is built from this, which is why it lives here
	 * rather than in `ServerTypes`: the Toolset layer owns this vocabulary, and
	 * a server type is a consumer of it.
	 *
	 * DECLARED, not derived. The slugs are read at seed time — before any
	 * ability exists — so this cannot ask the registry what is present; that is
	 * the entire point. A server created today carries all fifteen dormant, and
	 * they light up when the AcrossAI Abilities Manager add-on arrives, with no
	 * type switch and no Reset.
	 *
	 * Three of these are NOT in {@see self::CORE}, and each for its own reason:
	 * `integrations` spans every non-default group rather than owning one;
	 * `server-guide` is {@see Guide}, which is not a dispatcher; and `other` is
	 * the add-on's catch-all, registered by its integration registry and never
	 * by this plugin. All three are still tools an AcrossAI server offers.
	 *
	 * Kept in step with `CORE` by `RegistrarTest`, which asserts every
	 * dispatcher's own `slug()` appears here — so adding a class and forgetting
	 * this list fails rather than silently shipping a server missing a tool.
	 *
	 * @var string[]
	 */
	private const TOOL_SLUGS = array(
		'toolset/content',
		'toolset/blocks',
		'toolset/appearance',
		'toolset/configuration',
		'toolset/users',
		'toolset/updates',
		'toolset/cron',
		'toolset/cache',
		'toolset/backups',
		'toolset/database',
		'toolset/files',
		'toolset/diagnostics',
		'toolset/other',
		'toolset/integrations',
		Guide::SLUG,
	);

	/**
	 * The Toolset slugs an AcrossAI server offers.
	 *
	 * @since  0.3.6
	 * @return string[]
	 */
	public static function tool_slugs(): array {
		return self::TOOL_SLUGS;
	}

	/**
	 * Slug, label and description for every Toolset this server type offers.
	 *
	 * Answers the admin's question — "what IS this tool?" — for Toolsets whose
	 * abilities are not registered on this site. That is the ordinary state of
	 * an AcrossAI server before the add-on arrives: its fifteen tools are
	 * declared in advance, so without this the Tools tab printed a bare slug
	 * twice with no description and a working server read as a broken one.
	 *
	 * The plugin owns these classes, so it already knows. Same move
	 * `ToolPolicy::PROTOCOL_TOOL_METADATA` makes for the three vendor tools the
	 * registry cannot see at REST time.
	 *
	 * Instances are built WITHOUT the constructor on purpose: constructing one
	 * attaches five hooks, so reading a label the ordinary way would register a
	 * second copy of every filter on every admin page load.
	 *
	 * @since  0.3.6
	 * @return array<int, array{name: string, label: string, description: string}>
	 */
	public static function tool_metadata(): array {
		$metadata = array();

		foreach ( array_merge( self::CORE, array( Integrations::class ) ) as $toolset ) {
			$instance = ( new \ReflectionClass( $toolset ) )->newInstanceWithoutConstructor();

			if ( $instance instanceof Base_Toolset_Ability ) {
				$metadata[] = $instance->tool_metadata();
			}
		}

		$metadata[] = array(
			'name'        => Guide::SLUG,
			'label'       => __( 'Toolset Guide', 'acrossai-mcp-manager' ),
			'description' => __( 'Read this first. Explains how every other tool on this server is called — the three actions, which parameters belong to which, and their real limits — then lists what this site holds, with an ability count per toolset.', 'acrossai-mcp-manager' ),
		);

		// The add-on's catch-all. No class of ours registers it, so its copy
		// lives here rather than being derived — see `Guide::CATCH_ALL_GROUP`.
		$metadata[] = array(
			'name'        => 'toolset/other',
			'label'       => __( 'Other', 'acrossai-mcp-manager' ),
			'description' => __( 'Abilities that matched no other group. Membership is by fallthrough rather than assignment, so what it holds depends entirely on which plugins are installed. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-mcp-manager' ),
		);

		return $metadata;
	}

	/**
	 * Construct every dispatcher.
	 *
	 * Each constructor only ATTACHES hooks — `wp_abilities_api_init` at 20 plus
	 * the four filters a Toolset contributes to. Nothing registers an ability
	 * here, so this is safe to call before the abilities registry exists, and
	 * each dispatcher still declines to register when its group has no members.
	 *
	 * @since 0.3.6
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::CORE as $toolset ) {
			new $toolset();
		}

		// Spans every non-default group, so it must exist even when none of the
		// twelve above register — it is how a client reaches plugin capability
		// that never got a tool of its own.
		new Integrations();

		// Registered last: it reports on the others, so they should have
		// declared themselves before it describes them.
		new Guide();
	}
}
