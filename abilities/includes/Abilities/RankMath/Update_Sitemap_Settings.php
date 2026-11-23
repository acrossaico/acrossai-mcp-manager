<?php
/**
 * Feature 069 — write the Rank Math sitemap settings blob.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #4 — rank-math/update-sitemap-settings.
 *
 * Complements rank-math/get-sitemap-health, which reports live state:
 * this ability changes configuration. Sitemap exclusions (exclude_posts,
 * exclude_terms) are only reachable here — nothing else can exclude a post from
 * the sitemap.
 *
 * A write does not rebuild the sitemap; pair with
 * rank-math/invalidate-sitemap-cache when the change must take effect
 * immediately.
 */
class Update_Sitemap_Settings extends Base_Settings_Write_Ability {

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'update-sitemap-settings';
	}

	/**
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Rank Math Sitemap Settings', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Write Rank Math sitemap settings: items per page, image inclusion, and the post and term exclusion lists (scope=general), or whether a given post type or taxonomy appears in the XML and HTML sitemaps (scope=post-type or taxonomy with an object). This changes configuration only — follow with rank-math/invalidate-sitemap-cache for the change to appear in the served sitemap immediately.', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function rank_math_cap(): string {
		return 'sitemap';
	}

	/**
	 * @return string
	 */
	protected function option_type(): string {
		return 'sitemap';
	}

	/**
	 * @return string
	 */
	protected function scope_key(): string {
		return 'scope';
	}

	/**
	 * @return string[]
	 */
	protected function scope_enum(): array {
		return array( 'general', 'post-type', 'taxonomy' );
	}

	/**
	 * @return string
	 */
	protected function scope_description(): string {
		return __( 'Which group of sitemap settings to write. "general" holds items_per_page and the exclude_posts / exclude_terms lists. "post-type" and "taxonomy" additionally require an object naming it.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param string $scope Scope value.
	 * @return string
	 */
	protected function panel_for( string $scope ): string {
		return 'sitemap-' . $scope;
	}

	/**
	 * @return string
	 */
	protected function required_module(): string {
		return 'sitemap';
	}
}
