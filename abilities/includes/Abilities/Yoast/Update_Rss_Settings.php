<?php
/**
 * Feature 106 — Update RSS Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-rss-settings — Update RSS Settings.
 */
final class Update_Rss_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-rss-settings';
	}

	protected function ability_label(): string {
		return __( 'Update RSS Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The text Yoast prepends and appends to every item in the site feeds, used to link scraped copies back to the original. Supports the %%POSTLINK%% and %%BLOGLINK%% variables.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'rss';
	}
}
