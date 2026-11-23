<?php
/**
 * Feature 069 — repair the llms.txt rewrite route.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Routes_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #60 — rank-math/refresh-llms-route.
 *
 * The plugin already ships a generic cache/flush-rewrite-rules. What this adds
 * is the diagnosis: it checks whether Rank Math's llms.txt rule is actually absent,
 * flushes only if so, and reports whether the flush fixed it. A flush that does not
 * restore the rule points at the module being off rather than at stale rules, which
 * is a different fix.
 *
 * Idempotent: when the rule is already present it performs no work.
 */
class Refresh_Llms_Route extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'refresh-llms-route';
	}

	protected function ability_label(): string {
		return __( 'Refresh Rank Math llms.txt Route', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Check whether Rank Math\'s llms.txt rewrite rule is present in the persisted rules and flush them only if it is missing. Reports the state before and after, so a flush that does not restore the rule tells you the module is off rather than the rules being stale. Does nothing when the rule is already present.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-sitemap';
	}

	protected function rank_math_cap(): string {
		return 'general';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'rule_present_before' => array( 'type' => 'boolean' ),
			'flushed'             => array( 'type' => 'boolean' ),
			'rule_present_after'  => array( 'type' => 'boolean' ),
			'module_active'       => array( 'type' => 'boolean' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$status = Routes_Repository::llms_status( 1 );
		$active = (bool) $status['module_active'];

		$result                  = Routes_Repository::refresh_llms_route();
		$result['module_active'] = $active;

		if ( ! $result['flushed'] ) {
			$result['message'] = __( 'The llms.txt rewrite rule is already present. No flush was needed.', 'acrossai-abilities-manager' );
		} elseif ( $result['rule_present_after'] ) {
			$result['message'] = __( 'The llms.txt rewrite rule was missing and has been restored.', 'acrossai-abilities-manager' );
		} elseif ( ! $active ) {
			$result['message'] = __( 'Rewrite rules were flushed but the llms.txt rule is still absent because the module is inactive. Enable it with rank-math/set-module-status, then run this again.', 'acrossai-abilities-manager' );
		} else {
			$result['message'] = __( 'Rewrite rules were flushed but the llms.txt rule is still absent even though the module is active. Rules may regenerate on the next front-end request; re-check with rank-math/get-llms-status.', 'acrossai-abilities-manager' );
		}

		return $result;
	}
}
