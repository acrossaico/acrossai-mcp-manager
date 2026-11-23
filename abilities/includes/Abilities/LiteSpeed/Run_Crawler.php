<?php
/**
 * Feature 104 — Run The Crawler Now.
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
 * litespeed/run-crawler — Run The Crawler Now.
 */
final class Run_Crawler extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'run-crawler';
	}

	protected function ability_label(): string {
		return __( 'Run The Crawler Now', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Start a crawl immediately rather than waiting for the schedule. The crawl runs asynchronously and can take a long time on a large site, so this reports that the run was dispatched, never that it finished — poll litespeed/get-crawler-status for progress. A crawl warms the cache by visiting pages, which costs real server load while it runs.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-crawler';
	}

	protected function suggested_abilities(): array {
		return array(
			'litespeed/get-crawler-status',
		);
	}

	protected function input_properties(): array {
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'dispatched' => array( 'type' => 'boolean' ),

			'status'     => array( 'type' => 'object' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	/**
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function run( array $input ) {
		Crawler_Repository::run();

		return array(
			'dispatched' => true,
			'status'     => Crawler_Repository::summary(),
			'message'    => __( 'Crawl dispatched. Poll litespeed/get-crawler-status for progress.', 'acrossai-abilities-manager' ),
		);
	}
}
