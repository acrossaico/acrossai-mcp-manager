<?php
/**
 * Rank Math's toolset declaration.
 *
 * The mixed case, and the one that made issue #184 visible: this plugin declares 59 `rank-math/*`
 * abilities and Rank Math itself declares 29 more (as of 1.0.280; it was 27 when this was written,
 * and the number grows with ordinary Rank Math releases). Ours carry `meta.acrossai.tab_group` and
 * always grouped correctly; theirs carry nothing and grouped nowhere, so the Rank Math tab showed
 * 59 of 88 and an assistant reaching for `toolset-rank-math` could not see the rest at all.
 *
 * Declaring the `rank-math` prefix here closes that: both halves land in one group, and neither half
 * needs to know the other exists. The tagger never overwrites a declared group, so ours are untouched.
 *
 * Not an {@see AcrossAI_Integration_Ability_Base}: Rank Math does not gate its abilities behind a
 * setting, so there is no opt-in switch to own — only the grouping. That separation is why the two
 * contracts are distinct.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups Rank Math's own abilities alongside this plugin's.
 */
final class Rank_Math implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key.
	 *
	 * Matches `Base_Rank_Math_Ability::TAB_GROUP`, which is what this plugin's 61 abilities already
	 * declare, and `Toolset\Rank_Math::group()`. All three must agree or the halves split apart.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'rank-math';

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * Display name.
	 *
	 * Currently unused: `Toolset\Rank_Math` is a hand-written dispatcher and supplies the live label
	 * and description, so the bootstrap generates nothing for this group. Both are declared anyway so
	 * that retiring that class is a deletion rather than a rewrite — see the note in
	 * `AcrossAI_Core_Abilities_Bootstrap::register_toolsets()`.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'Rank Math', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Rank Math SEO: audit and fix on-page and site-wide SEO, manage redirections and schema, read analytics, and edit Rank Math settings. Covers both the abilities this plugin provides and the ones Rank Math registers itself. Settings and redirection changes affect the live site and require administrator rights. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Ability names in the Rank Math namespace.
	 *
	 * Claims the prefix for both halves. Ours are skipped by the tagger because they already declare a
	 * group, so in practice this only ever catches Rank Math's own.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array( 'rank-math' );
	}

	/**
	 * Whether Rank Math is present.
	 *
	 * Same check `Category_Registrar` uses, so the category and the toolset appear together.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return class_exists( '\RankMath\Helper' );
	}
}
