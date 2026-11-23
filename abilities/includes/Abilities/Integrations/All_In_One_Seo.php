<?php
/**
 * All in One SEO's toolset declaration.
 *
 * The "host plugin supplies everything" shape, for now. AIOSEO 5.0.3 ships thirteen abilities of its
 * own and this plugin ships none, so every member of this group is theirs and prefix tagging is the
 * whole mechanism.
 *
 * Without this declaration all thirteen land in the catch-all — verified on AIOSEO 5.0.3, every one
 * reporting `tab_group => other`. That is issue #184 exactly: a host plugin ships Abilities API
 * support, its abilities carry no `meta.acrossai`, nothing claims the name prefix, and they end up in
 * a group with no Toolset of their own. An assistant asking for the SEO toolset could not see them.
 *
 * **Five prefixes, not one.** AIOSEO namespaces per area rather than per plugin —
 * `aioseo-posts/`, `aioseo-settings/`, `aioseo-robots/`, `aioseo-audit/` and
 * `aioseo-notifications/` — so the claim has to enumerate them. `aioseo` itself is claimed too: it
 * is the namespace a suite of ours would use (AIOSEO has never used the bare prefix), and claiming it
 * now means those abilities group correctly the day they land. Claiming a prefix nobody registers
 * costs nothing — the tagger only ever matches names that exist.
 *
 * Deliberately NOT under `seo/`. That namespace is wholly occupied by this plugin's Yoast suite, and
 * the two SEO plugins can be active together, so a shared namespace would mean one side silently
 * losing abilities to the other by load order — the failure
 * {@see \AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Ability_Collision_Recorder} exists to
 * report.
 *
 * No hand-written dispatcher: the generic `Integration_Toolset` is generated from this declaration.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.44
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups All in One SEO's own abilities.
 */
final class All_In_One_Seo implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key.
	 *
	 * `all-in-one-seo`, matching the plugin's name rather than its `aioseo` code prefix, because this
	 * value becomes the admin tab label and the `toolset/{group}` MCP tool name. Any ability of ours
	 * added later must declare this same string as its `tab_group`.
	 *
	 * @since 0.0.44
	 * @var   string
	 */
	public const TAB_GROUP = 'all-in-one-seo';

	/**
	 * @since  0.0.44
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'All in One SEO', 'acrossai-abilities-manager' );
	}

	/**
	 * What this toolset covers.
	 *
	 * Describes what AIOSEO's own thirteen actually do, including the two limits an assistant will
	 * otherwise discover by failing: the settings read is opaque and has no write counterpart, and the
	 * audit is the cached scan rather than a fresh run.
	 *
	 * @since  0.0.44
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'All in One SEO: read and write one post\'s SEO fields (title, meta description, focus and additional keyphrases, robots directives, canonical URL, pillar flag and social meta) with its TruSEO score and optional full analysis breakdown; list posts missing a title, description or focus keyphrase; list posts ranked by TruSEO score; read the cached SEO Site Analysis for the homepage and a site-wide health snapshot; list active AIOSEO notifications; and manage the custom robots.txt rules — list, add, update, delete — alongside the robots.txt output actually being served. Two limits worth knowing before planning: the settings read returns AIOSEO\'s whole option tree as opaque data and there is no write counterpart, so search appearance, sitemaps, social and schema settings are read-only here; and the audit abilities return the last stored scan rather than running a new one. Post writes need edit rights for the post; the rest need AIOSEO\'s own settings capabilities. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Ability-name prefixes this integration owns.
	 *
	 * AIOSEO's five area namespaces, plus the bare `aioseo` prefix reserved for abilities of ours.
	 * Each is a whole first segment: `aioseo-posts` claims `aioseo-posts/*` and does not match
	 * `aioseo-postsomething/*`.
	 *
	 * @since  0.0.44
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array(
			'aioseo',
			'aioseo-posts',
			'aioseo-settings',
			'aioseo-robots',
			'aioseo-audit',
			'aioseo-notifications',
		);
	}

	/**
	 * Whether All in One SEO is present.
	 *
	 * Two symbols: the version constant the plugin defines at load, and the `aioseo()` container
	 * every read in the plugin routes through. The constant alone is set by both the free and Pro
	 * editions early enough to be true while the container is still unbuilt.
	 *
	 * @since  0.0.44
	 * @return bool
	 */
	public function is_active(): bool {
		return defined( 'AIOSEO_VERSION' ) && function_exists( 'aioseo' );
	}
}
