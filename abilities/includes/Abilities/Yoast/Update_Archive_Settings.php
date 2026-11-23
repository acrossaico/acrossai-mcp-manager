<?php
/**
 * Feature 106 — Update Archive And Indexing Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-archive-settings — Update Archive And Indexing Settings.
 */
final class Update_Archive_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-archive-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Archive And Indexing Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Which archives exist and which are told not to be indexed: per post type, per taxonomy, the author, date and format archives, and whether each shows in search. Disabling an archive redirects it away entirely; noindexing leaves it reachable but out of search results — a distinction worth getting right, because they are not interchangeable.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'archives';
	}
}
