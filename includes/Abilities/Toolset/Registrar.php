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
