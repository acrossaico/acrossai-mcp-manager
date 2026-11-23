<?php
/**
 * Feature 104 — List Crawlers.
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
 * litespeed/list-crawlers — List Crawlers.
 */
final class List_Crawlers extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'list-crawlers';
	}

	protected function ability_label(): string {
		return __( 'List Crawlers', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every crawler variant the configuration generates — one per combination of role, cookie and mobile state — each with its index, label and whether it is enabled. The index is what litespeed/set-crawler-state takes.',
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
			'crawlers' => array( 'type' => 'array' ),

			'count'    => array( 'type' => 'integer' ),
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
		$rows = Crawler_Repository::crawlers();

		return array(
			'crawlers' => $rows,
			'count'    => count( $rows ),
			'message'  => sprintf(
				/* translators: %d: number of crawlers */
				_n( '%d crawler.', '%d crawlers.', count( $rows ), 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
