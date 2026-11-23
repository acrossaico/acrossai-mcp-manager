<?php
/**
 * Feature 069 — read the Rank Math role capability matrix.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Role_Capability_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #16 — rank-math/get-role-capabilities.
 *
 * The plugin already ships users/get-role-capabilities, which returns one role's
 * FULL WordPress capability map. What that cannot tell you is which sixteen
 * capabilities Rank Math defines, or how they are distributed across roles. This
 * returns exactly that, including the suffix each ability in this suite composes onto
 * the permission floor — so a denied ability can be traced to a missing grant.
 *
 * Read-only, idempotent.
 */
class Get_Role_Capabilities extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-role-capabilities';
	}

	protected function ability_label(): string {
		return __( 'Get Rank Math Role Capabilities', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return the Rank Math capability matrix: the sixteen capabilities it defines with their labels, and which roles currently hold each one. Use this to diagnose an insufficient_capability or permission failure from another Rank Math ability. Grant or revoke individual capabilities with the plugin\'s users/add-role-capability and users/remove-role-capability — rank_math_* are ordinary WordPress capabilities.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-admin';
	}

	protected function rank_math_cap(): string {
		return 'role_manager';
	}

	protected function input_properties(): array {
		return array(
			'role' => array(
				'type'        => 'string',
				'description' => __( 'Restrict to one role slug, e.g. editor. Omit for every role.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'capabilities' => array( 'type' => 'array' ),
			'roles'        => array( 'type' => 'object' ),
			'role_count'   => array( 'type' => 'integer' ),
			'cap_count'    => array( 'type' => 'integer' ),
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
		$result = Role_Capability_Repository::matrix(
			isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : ''
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number of capabilities, 2: number of roles */
			__( 'Returned %1$d Rank Math capabilities across %2$d roles.', 'acrossai-abilities-manager' ),
			$result['cap_count'],
			$result['role_count']
		);

		return $result;
	}
}
