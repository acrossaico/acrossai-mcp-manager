<?php
/**
 * The toolset of last resort.
 *
 * Every registered ability should belong to a toolset, so that the tab counts sum to the total and no
 * ability is reachable only by scrolling "All". Curated groups cannot guarantee that on their own: a
 * plugin this codebase has never heard of can register an ability at any time, and before this existed
 * such an ability silently belonged nowhere and was invisible to every MCP dispatcher (issue #184).
 *
 * It claims no prefixes. Nothing is *assigned* here — abilities arrive because
 * {@see AcrossAI_Ability_Group_Tagger} found no better home, which is why its description promises the
 * caller nothing about the contents.
 *
 * On a site where every active plugin is mapped this group is empty, and `Base_Toolset_Ability`
 * declines to register a dispatcher for an empty group — so it costs nothing until it is needed.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Holds abilities no other toolset claims.
 */
final class AcrossAI_Catch_All_Integration implements AcrossAI_Toolset_Integration {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function group(): string {
		return AcrossAI_Toolset_Integrations::CATCH_ALL_GROUP;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'Other', 'acrossai-abilities-manager' );
	}

	/**
	 * What to tell an assistant about a group whose contents are unknown here.
	 *
	 * The honest answer is that this varies per site, so the description says so and points at
	 * `discover` instead of listing subject matter it cannot know. Guessing would be worse than
	 * vagueness: an assistant that skips this tool because the description sounded irrelevant would
	 * miss abilities that are only reachable through it.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Abilities from plugins that have no toolset of their own on this site. The contents are not fixed — they depend on which plugins are installed here — so treat this as the place to look when no other toolset matches, and call action=discover first to see what it actually holds. It may hold nothing at all on a site where every ability has a home elsewhere, and it fills and empties as plugins and themes are activated — so call discover again rather than trusting an earlier answer. Anything here is a normal ability with its own permissions. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Claims nothing: membership is by fallback, never by prefix.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array();
	}

	/**
	 * Always available — it depends on no host plugin.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return true;
	}
}
