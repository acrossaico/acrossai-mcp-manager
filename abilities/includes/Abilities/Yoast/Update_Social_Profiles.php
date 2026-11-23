<?php
/**
 * Feature 106 — Update Social Profile URLs.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-social-profiles — Update Social Profile URLs.
 */
final class Update_Social_Profiles extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-social-profiles';
	}

	protected function ability_label(): string {
		return __( 'Update Social Profile URLs', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The social accounts this site belongs to — Facebook, X/Twitter, Instagram, LinkedIn, Pinterest, YouTube, Wikipedia, Mastodon and others. Yoast publishes these as sameAs links in its schema so search engines can connect the site to its profiles.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'social-profiles';
	}
}
