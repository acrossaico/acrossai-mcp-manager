<?php
/**
 * Feature 104 — Reset The Crawl Position.
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
 * litespeed/reset-crawler — Reset The Crawl Position.
 */
final class Reset_Crawler extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'reset-crawler';
	}

	protected function ability_label(): string {
		return __( 'Reset The Crawl Position', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Reset the crawl position so the next run starts from the beginning of the sitemap instead of resuming. Use this after changing the sitemap or the crawler configuration. It discards progress, not data.',
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
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function run( array $input ) {
		Crawler_Repository::reset();

		return array(
			'status'  => Crawler_Repository::summary(),
			'message' => __( 'Crawl position reset. The next run starts from the beginning.', 'acrossai-abilities-manager' ),
		);
	}
}
