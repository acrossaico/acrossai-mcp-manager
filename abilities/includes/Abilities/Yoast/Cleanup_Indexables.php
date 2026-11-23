<?php
/**
 * Feature 106 — Report Orphaned Indexables.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Indexable_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/cleanup-indexables — Report Orphaned Indexables.
 */
final class Cleanup_Indexables extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/cleanup-indexables';
	}

	protected function ability_label(): string {
		return __( 'Report Orphaned Indexables', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Compare the indexable counts against the live content counts, so indexables left behind by deleted posts or terms are visible. Read-only: Yoast runs its own scheduled cleanup and there is no entry point to force one from a request, so this reports the discrepancy rather than pretending to fix it.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-indexing';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-indexing-status',
		);
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
			'comparison' => array( 'type' => 'array' ),
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
		$indexed = array();

		foreach ( Indexable_Repository::counts() as $row ) {
			$indexed[ (string) $row['object_type'] ] = (int) $row['count'];
		}

		$live = array(
			'post' => (int) array_sum( (array) wp_count_posts( 'post' ) ) + (int) array_sum( (array) wp_count_posts( 'page' ) ),
			'user' => (int) count_users()['total_users'],
		);

		$rows = array();

		foreach ( array_unique( array_merge( array_keys( $indexed ), array_keys( $live ) ) ) as $type ) {
			$rows[] = array(
				'object_type' => (string) $type,
				'indexed'     => $indexed[ $type ] ?? 0,
				'live'        => $live[ $type ] ?? null,
			);
		}

		return array(
			'comparison' => $rows,
			'message'    => __( 'Indexed counts against live counts. A large gap suggests indexation has not finished.', 'acrossai-abilities-manager' ),
		);
	}
}
