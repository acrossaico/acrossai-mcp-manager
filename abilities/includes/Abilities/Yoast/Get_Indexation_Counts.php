<?php
/**
 * Feature 106 — Get Indexation Counts.
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
 * seo/get-indexation-counts — Get Indexation Counts.
 */
final class Get_Indexation_Counts extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-indexation-counts';
	}

	protected function ability_label(): string {
		return __( 'Get Indexation Counts', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'How many indexables exist per object type — posts, terms, users, archives and system pages. Read this alongside the real content counts to see what has not been indexed yet.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-indexing';
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
			'counts' => array( 'type' => 'array' ),

			'total' => array( 'type' => 'integer' ),
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
		$rows  = Indexable_Repository::counts();
		$total = 0;

		foreach ( $rows as $row ) {
			$total += (int) $row['count'];
		}

		return array(
			'counts'  => $rows,
			'total'   => $total,
			'message' => 0 === $total
				? __( 'No indexables. Check seo/get-indexing-status for why.', 'acrossai-abilities-manager' )
				: sprintf( /* translators: %d: total indexables */ __( '%d indexables.', 'acrossai-abilities-manager' ), $total ),
		);
	}
}
