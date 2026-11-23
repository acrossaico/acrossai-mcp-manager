<?php
/**
 * Feature 069 — write the Rank Math general settings blob.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #2 — rank-math/update-general-settings.
 *
 * Links, breadcrumbs, verification codes, image SEO, 404-monitor config and
 * redirection behaviour all live in the SAME option blob, need the SAME
 * capability, and go through the SAME save_settings('general', …) call, so one
 * writer with a section enum replaces six near-identical classes.
 *
 * robots.txt and Instant Indexing are deliberately absent from the enum: both
 * have their own ability because robots.txt has read-only state that gates the
 * write, and Instant Indexing writes a different option entirely.
 */
class Update_General_Settings extends Base_Settings_Write_Ability {

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'update-general-settings';
	}

	/**
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Rank Math General Settings', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Write Rank Math general settings: link behaviour, breadcrumbs, search-engine verification codes, image SEO alt/title automation, 404-monitor configuration, or redirection behaviour. Values are validated against the section\'s field specification and written through Rank Math\'s own sanitizer with explicit field types, so multi-line values are preserved. Read the matching panel with rank-math/get-settings-panel first to discover the field ids. For robots.txt use rank-math/update-robots-txt; for Instant Indexing use rank-math/update-instant-indexing-settings.', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function rank_math_cap(): string {
		return 'general';
	}

	/**
	 * @return string
	 */
	protected function option_type(): string {
		return 'general';
	}

	/**
	 * @return string
	 */
	protected function scope_key(): string {
		return 'section';
	}

	/**
	 * @return string[]
	 */
	protected function scope_enum(): array {
		return array( 'links', 'breadcrumbs', 'webmaster', 'image-seo', '404-monitor', 'redirections', 'others' );
	}

	/**
	 * @return string
	 */
	protected function scope_description(): string {
		return __( 'Which group of general settings to write. "webmaster" holds the search-engine verification codes; "others" holds headless support, the frontend SEO score widget and RSS injection.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param string $scope Scope value.
	 * @return string
	 */
	protected function panel_for( string $scope ): string {
		return 'general-' . $scope;
	}
}
