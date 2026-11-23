<?php
/**
 * Feature 106 — Update Breadcrumb Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-breadcrumb-settings — Update Breadcrumb Settings.
 */
final class Update_Breadcrumb_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-breadcrumb-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Breadcrumb Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Breadcrumb output: whether it is enabled, the separator, the home and prefix labels, whether the blog page is shown, how taxonomies map to post types, and the archive and 404 formats. Only takes effect where the theme calls Yoast\'s breadcrumb function or uses the block.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'breadcrumbs';
	}
}
