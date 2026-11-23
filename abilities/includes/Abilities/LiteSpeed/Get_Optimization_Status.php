<?php
/**
 * Feature 104 — Get Optimisation Status.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-optimization-status — Get Optimisation Status.
 */
final class Get_Optimization_Status extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'get-optimization-status';
	}

	protected function ability_label(): string {
		return __( 'Get Optimisation Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'What the optimisation pipeline has actually produced, as opposed to what is configured: whether minify, combine and defer are on for CSS and JS, and whether generated assets exist on disk. Use this to tell configured from working — settings can be on while nothing has been generated yet.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-optimize';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'status'    => array( 'type' => 'array' ),

			'generated' => array( 'type' => 'boolean' ),
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
		$rows = Settings_Repository::describe_area( 'optimize-css' );
		$rows = array_merge( $rows, Settings_Repository::describe_area( 'optimize-js' ) );
		$rows = array_merge( $rows, Settings_Repository::describe_area( 'optimize-html' ) );

		$dir       = WP_CONTENT_DIR . '/litespeed/css';
		$generated = is_dir( $dir ) && array() !== array_diff( (array) scandir( $dir ), array( '.', '..' ) );

		return array(
			'status'    => $rows,
			'generated' => $generated,
			'message'   => $generated
				? __( 'Optimisation is configured and generated assets exist.', 'acrossai-abilities-manager' )
				: __( 'No generated assets on disk yet — they are built on the first visit to each page.', 'acrossai-abilities-manager' ),
		);
	}
}
