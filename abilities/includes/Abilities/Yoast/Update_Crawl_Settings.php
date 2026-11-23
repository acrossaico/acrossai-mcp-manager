<?php
/**
 * Feature 106 — Update Crawl Optimisation Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-crawl-settings — Update Crawl Optimisation Settings.
 */
final class Update_Crawl_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-crawl-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Crawl Optimisation Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Control what WordPress emits into the page head and what it lets crawlers reach: the RSD, WLW, shortlink and REST head links, feeds, emoji scripts, oEmbed, the generator tag, and which query parameters are denied. Removing feeds and archives shrinks what search engines crawl; it also breaks anything that consumes them, so change one at a time.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'crawl';
	}
}
