<?php
/**
 * Feature 069 — list Rank Math modules with their state.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Module_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #63 — rank-math/list-modules.
 *
 * The discovery read for rank-math/set-module-status: many abilities in
 * this suite fail with rank_math_module_inactive, and this is how a client finds
 * out which module to enable.
 *
 * Rank Math gates its own module screen on manage_options, so there is no granular
 * capability to compose onto the floor.
 *
 * Read-only, idempotent.
 */
class List_Modules extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'list-modules';
	}

	protected function ability_label(): string {
		return __( 'List Rank Math Modules', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'List every Rank Math module with its slug, label, whether it is active, whether it is unavailable on this install (a PRO-only feature or an unmet dependency such as WooCommerce), and whether changing its state refreshes rewrite rules. Use this to resolve a rank_math_module_inactive error from another ability.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-admin';
	}

	protected function rank_math_cap(): string {
		return '';
	}

	protected function input_properties(): array {
		return array(
			'only_active' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Return only the active modules.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'modules'      => array( 'type' => 'array' ),
			'count'        => array( 'type' => 'integer' ),
			'active_count' => array( 'type' => 'integer' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$modules = Module_Repository::listing();

		if ( array() === $modules ) {
			return new WP_Error( 'not_found', __( 'Rank Math did not report any modules. Its module manager may not have initialised yet.', 'acrossai-abilities-manager' ) );
		}

		$active_count = count( array_filter( $modules, static fn( array $m ): bool => (bool) $m['active'] ) );

		if ( ! empty( $input['only_active'] ) ) {
			$modules = array_values( array_filter( $modules, static fn( array $m ): bool => (bool) $m['active'] ) );
		}

		return array(
			'modules'      => $modules,
			'count'        => count( $modules ),
			'active_count' => $active_count,
			'message'      => sprintf(
				/* translators: 1: number of modules returned, 2: number active */
				__( 'Returned %1$d Rank Math modules, %2$d of them active.', 'acrossai-abilities-manager' ),
				count( $modules ),
				$active_count
			),
		);
	}
}
