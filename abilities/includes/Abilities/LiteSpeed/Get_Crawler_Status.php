<?php
/**
 * Feature 104 — Get Crawler Status.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Crawler_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-crawler-status — Get Crawler Status.
 */
final class Get_Crawler_Status extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'get-crawler-status';
	}

	protected function ability_label(): string {
		return __( 'Get Crawler Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'What the crawler is doing right now: whether a run is in progress, which crawler variant, its position in the sitemap, how many URLs it has crawled, when it last started, and why it last stopped. Separate from litespeed/get-crawler-settings, which reports the configuration.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-crawler';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'status' => array( 'type' => 'object' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function run( array $input ) {
		$summary = Crawler_Repository::summary();

		return array(
			'status'  => $summary,
			'message' => $summary['is_running']
				? sprintf(
					/* translators: 1: crawler index, 2: position, 3: list size */
					__( 'Crawler %1$d is running at position %2$d of %3$d.', 'acrossai-abilities-manager' ),
					$summary['current_crawler'],
					$summary['position'],
					$summary['list_size']
				)
				: __( 'The crawler is not running.', 'acrossai-abilities-manager' ),
		);
	}
}
