<?php
/**
 * Feature 069 — write the Rank Math titles & meta settings blob.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #3 — rank-math/update-title-settings.
 *
 * This is the global template layer, which nothing else covers: Rank Math core's
 * own abilities read a single post's resolved meta, and per-post writes set one
 * post's override. Neither can express "set the SEO title template for all
 * products", which is what this ability is for.
 *
 * Also owns Local SEO, since Rank Math files those fields under the titles blob.
 * The repeatable groups there (opening_hours, phone_numbers, additional_info) are
 * where an untyped raw option write is most dangerous.
 */
class Update_Title_Settings extends Base_Settings_Write_Ability {

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'update-title-settings';
	}

	/**
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Rank Math Title & Meta Settings', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Write Rank Math title and meta settings — the GLOBAL template layer that applies to every object of a type, not a single post\'s override. Covers per-post-type and per-taxonomy title/description templates and robots directives, the homepage, author and date archives, global defaults, social profiles, and Local SEO including opening hours. Use scope=post-type or taxonomy with an object naming it. Read the matching panel with rank-math/get-settings-panel first to discover the field ids and allowed values.', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function rank_math_cap(): string {
		return 'titles';
	}

	/**
	 * @return string
	 */
	protected function option_type(): string {
		return 'titles';
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
		return array( 'global', 'homepage', 'author', 'misc', 'social', 'local-seo', 'post-type', 'taxonomy' );
	}

	/**
	 * @return string
	 */
	protected function scope_description(): string {
		return __( 'Which group of title/meta settings to write. "post-type" and "taxonomy" additionally require an object naming the post type or taxonomy. "misc" holds the date archive, search and 404 templates.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param string $scope Scope value.
	 * @return string
	 */
	protected function panel_for( string $scope ): string {
		return 'titles-' . $scope;
	}
}
