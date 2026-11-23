<?php
/**
 * Feature 104 — Get Crawler Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-crawler-settings — Get Crawler Settings.
 */
final class Get_Crawler_Settings extends Base_Settings_Read_Ability {

	protected function slug(): string {
		return 'get-crawler-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Crawler Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read the crawler configuration: whether it is enabled, the crawl interval, the server load limit, the sitemap it works from, and the roles and cookies it simulates. Separate from litespeed/get-crawler-status, which reports what a run is currently doing.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-crawler';
	}

	protected function areas_read(): array {
		return array(
			'crawler',
		);
	}
}
