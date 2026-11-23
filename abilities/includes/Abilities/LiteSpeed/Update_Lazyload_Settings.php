<?php
/**
 * Feature 104 — Update Lazy Load Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-lazyload-settings — Update Lazy Load Settings.
 */
final class Update_Lazyload_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-lazyload-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Lazy Load Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Turn image and iframe lazy loading on or off, set the placeholder image, and control automatic image sizing. Lazy loading images above the fold makes a page feel slower, so pair this with litespeed/update-media-exclusions.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-media';
	}

	protected function suggested_abilities(): array {
		return array(
			'litespeed/update-media-exclusions',
			'litespeed/get-media-status',
		);
	}

	protected function area_written(): string {
		return 'media-lazyload';
	}
}
