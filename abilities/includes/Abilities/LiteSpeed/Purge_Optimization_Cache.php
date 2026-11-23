<?php
/**
 * Feature 104 — Purge Generated CSS And JS.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Purge_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/purge-optimization-cache — Purge Generated CSS And JS.
 */
final class Purge_Optimization_Cache extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'purge-optimization-cache';
	}

	protected function ability_label(): string {
		return __( 'Purge Generated CSS And JS', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Purge only the generated CSS and JS artefacts, leaving the page cache alone. The targeted call after a theme or plugin update changes a stylesheet: a full purge would also discard every cached page for no reason.',
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
			'target' => array( 'type' => 'string' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$purged = Purge_Repository::purge( 'css-js' );

		if ( is_wp_error( $purged ) ) {
			return $purged;
		}

		return array(
			'target'  => 'css-js',
			'message' => __( 'Generated CSS and JS purged. They rebuild on the next visit to each page.', 'acrossai-abilities-manager' ),
		);
	}
}
