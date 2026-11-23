<?php
/**
 * Feature 106 — Update Knowledge Graph Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-knowledge-graph — Update Knowledge Graph Settings.
 */
final class Update_Knowledge_Graph extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-knowledge-graph';
	}

	protected function ability_label(): string {
		return __( 'Update Knowledge Graph Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Who the site represents — a person or an organisation — and the details search engines attach to that entity: name, alternate name, logo, and for a person the linked user. This drives Yoast\'s schema output and the site\'s identity in search results.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'knowledge-graph';
	}
}
