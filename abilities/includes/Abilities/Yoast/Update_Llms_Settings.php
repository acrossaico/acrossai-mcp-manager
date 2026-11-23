<?php
/**
 * Feature 106 — Update llms.txt Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-llms-settings — Update llms.txt Settings.
 */
final class Update_Llms_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-llms-settings';
	}

	protected function ability_label(): string {
		return __( 'Update llms.txt Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Yoast\'s llms.txt output — the file that tells AI crawlers which pages describe the site. Selects the About, Contact, Terms, Privacy and Shop pages, plus any other pages to include.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'llms';
	}
}
