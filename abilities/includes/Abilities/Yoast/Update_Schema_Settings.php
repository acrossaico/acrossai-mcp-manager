<?php
/**
 * Feature 106 — Update Schema Defaults.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-schema-settings — Update Schema Defaults.
 */
final class Update_Schema_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-schema-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Schema Defaults', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The default schema page type and article type per post type — what Yoast declares each kind of content to BE in structured data. A post left as the wrong article type is a common and invisible cause of rich results not appearing.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'schema';
	}
}
