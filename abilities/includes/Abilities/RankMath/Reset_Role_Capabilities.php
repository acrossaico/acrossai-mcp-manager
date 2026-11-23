<?php
/**
 * Feature 069 — restore Rank Math's default role capabilities.
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
 * Ability #17 — rank-math/reset-role-capabilities.
 *
 * Distinct from the plugin's users/reset-role, which restores a role to its
 * WordPress defaults and would therefore STRIP Rank Math capabilities rather than
 * restore them.
 *
 * Destructive: every deliberate per-role customisation is discarded, across all
 * roles at once, with no undo. The before/after matrices are returned so the
 * previous distribution can be reconstructed by hand if needed.
 *
 * No bulk WRITER ships in this suite — Helper::set_capabilities() strips
 * capabilities from roles omitted from the payload, so grants go through the
 * plugin's existing per-capability abilities instead.
 */
class Reset_Role_Capabilities extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'reset-role-capabilities';
	}

	protected function ability_label(): string {
		return __( 'Reset Rank Math Role Capabilities', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Restore Rank Math\'s default capability distribution for every role at once, discarding all customisation. There is no undo, so the response includes the before and after matrices to allow manual reconstruction. Read the current state first with rank-math/get-role-capabilities. This is not the same as the plugin\'s users/reset-role, which restores WordPress defaults and would remove Rank Math capabilities entirely.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-admin';
	}

	protected function rank_math_cap(): string {
		return 'role_manager';
	}

	protected function required_module(): string {
		return Role_Capability_Repository::MODULE;
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'roles_reset' => array( 'type' => 'integer' ),
			'before'      => array( 'type' => 'object' ),
			'after'       => array( 'type' => 'object' ),
		);
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Rank_Math_Ability::ability().
	 */
	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => true, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Role_Capability_Repository::reset();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: %d: number of roles reset */
			_n( 'Reset Rank Math capabilities for %d role.', 'Reset Rank Math capabilities for %d roles.', $result['roles_reset'], 'acrossai-abilities-manager' ),
			$result['roles_reset']
		);

		return $result;
	}
}
