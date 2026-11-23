<?php
/**
 * Feature 069 — read a Rank Math status panel.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Status_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #19 — rank-math/get-status.
 *
 * Consolidates five reads behind a panel enum, mirroring Rank Math's own
 * dispatch hash at includes/modules/status/class-rest.php:141-147. Five separate
 * abilities would differ only in which class::get_json_data() they call.
 *
 * panel=version_control is READ-ONLY here — rollback and beta opt-in are out of
 * scope for Feature 069 by product decision.
 *
 * Read-only, idempotent.
 */
class Get_Status extends Base_Rank_Math_Ability {

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'get-status';
	}

	/**
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Rank Math Status', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Return one Rank Math diagnostic panel: system status, the live maintenance-tool catalogue, import/export state, version-control state, or Google Search Console connection state. Use panel=tools to discover which maintenance tools are currently runnable before calling rank-math/run-maintenance-tool.', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function sub_group(): string {
		return 'rank-math-status';
	}

	/**
	 * Rank Math gates all of these on manage_options via
	 * Rest_Helper::can_manage_options(), so there is no granular capability to
	 * compose onto the floor.
	 *
	 * @return string
	 */
	protected function rank_math_cap(): string {
		return '';
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function input_properties(): array {
		return array(
			'panel'         => array(
				'type'        => 'string',
				'enum'        => Status_Repository::PANELS,
				'default'     => 'status',
				'description' => __( 'Which panel to read. "tools" returns the live maintenance-tool catalogue; "google" returns Search Console / GA4 connection state.', 'acrossai-abilities-manager' ),
			),
			'include_sites' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Only for panel=google. When true, also list the Search Console properties. This makes a live Google API request, so it is off by default.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function output_properties(): array {
		return array(
			'panel' => array( 'type' => 'string' ),
			'data'  => array( 'type' => 'object' ),
		);
	}

	/**
	 * @return string[]
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$panel = isset( $input['panel'] ) ? sanitize_key( (string) $input['panel'] ) : 'status';
		if ( ! in_array( $panel, Status_Repository::PANELS, true ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: submitted panel name, 2: comma-separated list of valid panels */
					__( 'Unknown panel "%1$s". Valid panels: %2$s.', 'acrossai-abilities-manager' ),
					$panel,
					implode( ', ', Status_Repository::PANELS )
				)
			);
		}

		$data = Status_Repository::panel( $panel, ! empty( $input['include_sites'] ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'panel'   => $panel,
			'data'    => $data,
			/* translators: %s: panel name */
			'message' => sprintf( __( 'Returned the Rank Math "%s" panel.', 'acrossai-abilities-manager' ), $panel ),
		);
	}
}
