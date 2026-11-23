<?php
/**
 * Yoast SEO's toolset declaration.
 *
 * **The "both supply some" shape**, and the first suite in this plugin to both claim a host prefix
 * and add abilities of its own. Yoast registers five abilities under `yoast-seo/*`; this plugin adds
 * 62 more under `seo/*` and `taxonomies/*`. `ability_prefixes()` claims `yoast-seo` so Yoast's five
 * are tagged into the same group rather than falling into the Other catch-all, which is exactly the
 * gap issue #184 described — Yoast was simply never declared.
 *
 * Our own abilities must therefore never use a `yoast-seo/` slug: the prefix belongs to Yoast, and a
 * collision would shadow one of theirs. Test_Yoast_Suite_Contract asserts that.
 *
 * **A caveat about when those five actually appear.** Yoast gates its own abilities behind
 * `Should_Index_Indexables_Conditional`, which resolves to `is_production_mode()`, so on a staging or
 * local site it registers none of them and this group contains only ours. That is Yoast's decision,
 * not something this declaration can or should override — indexables store permalinks and building
 * them on staging bakes in the wrong ones. Our 62 are deliberately not gated the same way.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Yoast_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Groups the Yoast SEO abilities — ours and Yoast's own.
 */
final class Yoast_Seo implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key. Must equal Base_Yoast_Ability::TAB_GROUP.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'yoast-seo';

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
	 * Declared rather than derived: the label rule would render `yoast-seo` as "Yoast Seo", losing
	 * the capitalisation of an acronym in a product name.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'Yoast SEO', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Yoast SEO: read and change the site-wide settings — title and meta templates, archives and indexing, breadcrumbs, the knowledge graph, social defaults and profiles, schema, RSS and llms.txt; edit the SEO of taxonomy terms and set primary terms; inspect the SEO of pages that are not posts, such as the front page, post type archives, author archives and search; manage XML sitemaps and their caches; check indexation; and report on content problems including orphaned pages, duplicated focus keyphrases and low scores. Where Yoast SEO itself is running, its own per-post abilities appear in this same toolset — use those to edit an individual post, and these for everything else. Every ability requires administrator rights; discouraging search engines site-wide, importing settings and resetting indexation must be confirmed. Narrow action=discover with sub_group: yoast-settings, yoast-content-types, yoast-terms, yoast-indexables, yoast-sitemap, yoast-indexing, yoast-content and yoast-tools. action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Claims Yoast's own prefix so its five abilities join this toolset.
	 *
	 * Unlike Contact Form 7 and LiteSpeed, which register nothing and therefore claim nothing, Yoast
	 * genuinely publishes `yoast-seo/*` abilities. Tagging only ever fills a gap — an ability that
	 * already declares a group is never re-tagged — so this adopts Yoast's without touching ours.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array( 'yoast-seo' );
	}

	/**
	 * Whether Yoast SEO is present.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return Yoast_Guard::is_available();
	}
}
