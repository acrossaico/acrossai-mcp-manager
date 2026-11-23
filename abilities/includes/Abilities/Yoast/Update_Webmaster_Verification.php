<?php
/**
 * Feature 106 — Update Webmaster Verification Codes.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-webmaster-verification — Update Webmaster Verification Codes.
 */
final class Update_Webmaster_Verification extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-webmaster-verification';
	}

	protected function ability_label(): string {
		return __( 'Update Webmaster Verification Codes', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The verification codes for Google Search Console, Bing, Baidu and Yandex. Yoast renders each as a meta tag in the head; supply the code only, not the whole tag.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'webmaster';
	}
}
