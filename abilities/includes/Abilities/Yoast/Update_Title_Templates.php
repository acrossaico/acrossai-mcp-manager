<?php
/**
 * Feature 106 — Update Title And Meta Templates.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-title-templates — Update Title And Meta Templates.
 */
final class Update_Title_Templates extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-title-templates';
	}

	protected function ability_label(): string {
		return __( 'Update Title And Meta Templates', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The SEO title and meta description TEMPLATES for every post type, taxonomy, archive and special page — the patterns like %%title%% %%sep%% %%sitename%% that Yoast expands per page. These set the default for content that has no per-post override; they do not change any individual post, which yoast-seo/update-post-seo-data does.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'title-templates';
	}
}
