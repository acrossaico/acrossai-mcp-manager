<?php
/**
 * Feature 104 — Get Media Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-media-settings — Get Media Settings.
 */
final class Get_Media_Settings extends Base_Settings_Read_Ability {

	protected function slug(): string {
		return 'get-media-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Media Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read every lazy-loading and media setting: image and iframe lazy load, the placeholder shown while an image loads, the exclusion lists, and the viewport-image toggles.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-media';
	}

	protected function areas_read(): array {
		return array(
			'media-lazyload',
			'media-placeholder',
			'media-exclusions',
			'media-viewport',
		);
	}
}
