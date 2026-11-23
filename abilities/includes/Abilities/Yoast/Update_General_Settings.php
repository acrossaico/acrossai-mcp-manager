<?php
/**
 * Feature 106 — Update General SEO Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-general-settings — Update General SEO Settings.
 */
final class Update_General_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-general-settings';
	}

	protected function ability_label(): string {
		return __( 'Update General SEO Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Turn Yoast features on or off site-wide: the SEO and readability analysis, inclusive-language analysis, XML sitemaps, the admin bar menu, usage tracking, and the advanced meta box for non-admins. These are the master switches; turning the analysis off hides the scores everywhere rather than resetting them.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'general';
	}
}
