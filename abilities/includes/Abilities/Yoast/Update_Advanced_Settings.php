<?php
/**
 * Feature 106 — Update Advanced SEO Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-advanced-settings — Update Advanced SEO Settings.
 */
final class Update_Advanced_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-advanced-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Advanced SEO Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Permalink and fallback-metadata settings: whether the category base is stripped from category URLs, the category and tag base slugs, the site-wide default SEO title and meta description used when a post sets none, and whether Yoast force-rewrites titles for themes that do not support title-tag. Changing a base slug changes live URLs — flush permalinks and add redirects for the old paths.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'advanced';
	}
}
