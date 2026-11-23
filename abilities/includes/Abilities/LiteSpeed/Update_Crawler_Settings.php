<?php
/**
 * Feature 104 — Update Crawler Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/update-crawler-settings — Update Crawler Settings.
 */
final class Update_Crawler_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'update-crawler-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Crawler Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change the crawler configuration: enable it, set the crawl interval and server load limit, choose the sitemap, and set the roles and cookies it simulates. A load limit set too high will slow the site for real visitors while a crawl runs.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-crawler';
	}

	protected function area_written(): string {
		return 'crawler';
	}
}
