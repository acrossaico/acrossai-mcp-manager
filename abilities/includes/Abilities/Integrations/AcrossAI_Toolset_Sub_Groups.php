<?php
/**
 * Lets an integration place another plugin's abilities into a sub-group.
 *
 * {@see AcrossAI_Toolset_Integration} answers which toolset a foreign ability belongs to, and
 * `AcrossAI_Ability_Group_Tagger` writes that answer onto the ability. Neither can answer the
 * division *within* the toolset: `Base_Toolset_Ability::sub_group_of()` is a pure read with no
 * fallback, so an ability registered by another plugin reports `sub_group: ""` and is unreachable
 * through `action=discover&sub_group=…` — the same shape of gap #184 was for the toolset, one
 * level down. This interface closes it.
 *
 * **Declared as rules, not as names.** A map of ability names would be correct on the day it was
 * written and stale the moment the host plugin shipped one more ability — which is exactly how
 * #184 (Rank Math) and #255 (Elementor) happened. So the primary mechanism matches on what an
 * ability *is*: whether it is a resource or a tool, and what its name contains. A new ability from
 * the host plugin is placed by whichever rule fits, and by {@see self::default_sub_group()} when
 * none does, so it is never left unplaced.
 *
 * Three signals are available on a foreign ability at registration time — its name, its
 * `meta.mcp.type`, and its annotations. Its category is not one of them: the tagger resolves from
 * the ability name alone.
 *
 * **Optional on purpose.** `AcrossAI_Toolset_Integration` has five required methods and is
 * implemented more than twenty times, five of those in `acrossai-pro`. A sixth required method
 * would break every one of them. An integration opts in by implementing this as well, and the ones
 * that never do keep working untouched.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.42
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Declares how an integration's foreign abilities divide into sub-groups.
 */
interface AcrossAI_Toolset_Sub_Groups {

	/**
	 * Ordered placement rules. The first one that matches wins.
	 *
	 * Each entry is an array with:
	 *
	 * - `sub_group` (string, required) — the key to assign.
	 * - `resource`  (bool, optional)   — require `meta.mcp.type` to be `resource` when true, or to
	 *                                    be anything else when false. Omit to ignore the type.
	 * - `keywords`  (string[], optional) — matches when any one appears anywhere in the ability
	 *                                    name after the namespace slash.
	 * - `patterns`  (string[], optional) — matches when any one matches the name after the slash;
	 *                                    a trailing `*` is a wildcard, otherwise it is exact.
	 *
	 * A rule with no condition at all matches everything, which makes it a second default — put it
	 * last or not at all. Order is the whole contract here: a rule that could match two sub-groups
	 * belongs above the one it should beat.
	 *
	 * @since  0.0.42
	 * @return array<int, array<string, mixed>>
	 */
	public function sub_group_rules(): array;

	/**
	 * Exact ability names that bypass the rules.
	 *
	 * Keyed by the full ability name, including the namespace. Beats every rule, so this is how a
	 * single ability the rules get wrong is corrected without contorting them. Expected to stay
	 * short — a long list here means the rules are wrong and should be fixed instead.
	 *
	 * @since  0.0.42
	 * @return array<string, string> Ability name => sub_group key.
	 */
	public function sub_group_overrides(): array;

	/**
	 * Where an ability goes when no rule matched.
	 *
	 * Return a non-empty key. This is what guarantees an ability the host plugin adds later is
	 * still placed, which is the point of the whole interface — returning `''` opts back out of
	 * that guarantee and leaves such abilities unreachable by sub-group.
	 *
	 * @since  0.0.42
	 * @return string
	 */
	public function default_sub_group(): string;

	/**
	 * Display label for each sub-group key this integration can assign.
	 *
	 * Without an entry here the label is derived as `ucwords( str_replace( '-', ' ', $key ) )` by
	 * {@see \AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition}, which turns
	 * `elementor-elements` into "Elementor Elements" rather than "Elements & Widgets".
	 *
	 * An integration that already keeps this map on its `Base_<Plugin>_Ability` should return that
	 * map rather than restate it, so the two halves of the group cannot drift apart.
	 *
	 * @since  0.0.42
	 * @return array<string, string> sub_group key => label.
	 */
	public function sub_group_labels(): array;
}
