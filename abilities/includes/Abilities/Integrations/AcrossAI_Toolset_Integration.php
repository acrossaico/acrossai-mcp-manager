<?php
/**
 * What one integration declares about its toolset.
 *
 * A toolset is derived, not registered: `AcrossAI_Ability_Group` builds the group map from whatever
 * `meta.acrossai.tab_group` values it finds on registered abilities. That works while this plugin
 * declares every ability, and stops working the moment the host plugin declares its own — those
 * abilities carry no `meta.acrossai`, so they belong to no group, appear in no tab, and are reachable
 * through no Toolset dispatcher (issue #184).
 *
 * This interface is the single declaration that closes the gap. From these five answers the plugin
 * derives: which abilities get tagged into the group, the admin tab and its count, the toolset filter,
 * the Toolset column, and the MCP dispatcher tool. Adding an integration means writing one of these —
 * no tagger edit, no `Toolset/` file, no bootstrap line.
 *
 * **It says nothing about who supplies the abilities**, which is what lets one mechanism cover all
 * three shapes an integration takes:
 *
 * - *Host plugin supplies everything* (ACF today). Nothing of ours registers; prefix tagging assigns
 *   the group.
 * - *Both supply some* (Rank Math today: 61 ours, 26 theirs). Ours already declare `tab_group` and are
 *   left alone; theirs are tagged. Both land in one group.
 * - *We supply everything* (Elementor today). Ours already declare `tab_group`; this declaration still
 *   owns the label, the description and the dispatcher.
 *
 * Tagging only ever fills a gap — an ability that already declares a group is never re-tagged — which
 * is what makes the mixed case work without either side knowing about the other.
 *
 * Deliberately separate from {@see AcrossAI_Integration_Ability_Base}. That class owns the *opt-in
 * switch*, which only applies when the host plugin gates its own abilities behind a setting. Grouping
 * and opt-in are different concerns: Rank Math needs grouping and no opt-in, ACF needs both. An
 * integration that needs both implements this interface *and* extends that base.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * One integration's toolset declaration.
 */
interface AcrossAI_Toolset_Integration {

	/**
	 * The toolset key.
	 *
	 * Becomes the `meta.acrossai.tab_group` value, the `?tab=` argument, the dispatcher's group and —
	 * via `toolset/{group}` — the MCP tool name. Must survive `AcrossAI_Key_Sanitizer::key()`
	 * unchanged: lowercase, digits, `-` and `_` only.
	 *
	 * Per `DEC-ABILITY-GROUP-IDENTIFIER-LOAD-BEARING` this value is load-bearing, not cosmetic.
	 * Changing it on a shipped integration moves its abilities between MCP tools.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function group(): string;

	/**
	 * Display name for the toolset.
	 *
	 * Used by the MCP dispatcher. The admin tab derives its own label from the group key via
	 * `AcrossAI_Tab_Group_Label`, so the two agree only if this matches that derivation — which is
	 * the normal case, and a deliberate divergence is allowed where the derived form reads badly.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string;

	/**
	 * What this toolset covers, written for an MCP client.
	 *
	 * This is the text an assistant reads when choosing between tools, so it earns its place by
	 * naming what is actually inside rather than restating the plugin's marketing. Follow the
	 * existing dispatchers: what the group contains, any notable permission requirement, then the
	 * three actions.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string;

	/**
	 * Ability-name prefixes this integration owns.
	 *
	 * The segment before the first `/` in an ability name — `array( 'acf' )` claims `acf/*`. An
	 * ability matching one of these and carrying no group of its own is tagged into `group()`.
	 *
	 * Return an empty array for an integration whose abilities this plugin declares in full: there is
	 * nothing to tag, and claiming a prefix you do not own would capture another plugin's abilities.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public function ability_prefixes(): array;

	/**
	 * Whether the host plugin is present on this site.
	 *
	 * Checked before the integration contributes anything. Note the dispatcher does not rely on this:
	 * `Base_Toolset_Ability` declines to register when its group is empty, so an inactive
	 * integration's tab and MCP tool disappear on their own.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool;
}
