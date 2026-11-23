<?php
/**
 * Feature 106 — Update Social Sharing Defaults.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-social-defaults — Update Social Sharing Defaults.
 */
final class Update_Social_Defaults extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-social-defaults';
	}

	protected function ability_label(): string {
		return __( 'Update Social Sharing Defaults', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Default Open Graph and Twitter output per content type: the fallback image, title and description templates used when a post has none of its own, and the Twitter card type. Distinct from seo/update-social-profiles, which is the list of accounts the site links to.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'social-defaults';
	}
}
