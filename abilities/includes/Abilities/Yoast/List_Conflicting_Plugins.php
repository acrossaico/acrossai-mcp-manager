<?php
/**
 * Feature 106 — List Conflicting Plugins.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Tools_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/list-conflicting-plugins — List Conflicting Plugins.
 */
final class List_Conflicting_Plugins extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/list-conflicting-plugins';
	}

	protected function ability_label(): string {
		return __( 'List Conflicting Plugins', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Active plugins Yoast considers to conflict with it — other SEO plugins, competing Open Graph or XML sitemap output, and cloaking plugins. Two plugins emitting meta tags produce duplicates that search engines resolve unpredictably, and it is rarely obvious from the front end which one won.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-tools';
	}

	protected function input_properties(): array {
		return array(
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'conflicts' => array( 'type' => 'array' ),

			'count' => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$rows = Tools_Repository::conflicting_plugins();

		return array(
			'conflicts' => $rows,
			'count'     => count( $rows ),
			'message'   => array() === $rows
				? __( 'No conflicting plugins are active.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of conflicts */
					__( '%d conflicting plugin(s) active. Two plugins emitting the same meta tags produce duplicates.', 'acrossai-abilities-manager' ),
					count( $rows )
				),
		);
	}
}
