<?php
/**
 * Feature 069 — edit an existing Rank Math redirection.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Redirections_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #11 — rank-math/update-redirection.
 *
 * The gap this fills: nothing else can EDIT a redirection. Emulating an edit by
 * delete-then-recreate loses the rule's id, its hit counter and its creation date.
 *
 * An update that would create a loop is REFUSED outright — unlike a create, which
 * Rank Math saves in a deactivated state. The two outcomes get distinct error codes
 * so a caller can tell "nothing was saved" from "saved but disabled".
 */
class Update_Redirection extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'update-redirection';
	}

	protected function ability_label(): string {
		return __( 'Update Rank Math Redirection', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Edit an existing redirection in place, preserving its id, hit count and creation date — which delete-and-recreate would lose. Supply only the fields to change. A change that would make the source resolve to the destination is refused and nothing is saved.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-redirections';
	}

	protected function rank_math_cap(): string {
		return 'redirections';
	}

	protected function required_module(): string {
		return Redirections_Repository::MODULE;
	}

	protected function input_properties(): array {
		return array(
			'id'          => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Redirection id. Find it with rank-math/list-redirections or -find-redirection.', 'acrossai-abilities-manager' ),
			),
			'sources'     => array(
				'type'        => 'array',
				'items'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'pattern'    => array( 'type' => 'string' ),
						'comparison' => array(
							'type' => 'string',
							'enum' => array( 'exact', 'contains', 'start', 'end', 'regex' ),
						),
						'ignore'     => array( 'type' => 'string' ),
					),
					'required'             => array( 'pattern', 'comparison' ),
					'additionalProperties' => false,
				),
				'description' => __( 'Replacement source rules. Omit to leave the sources unchanged.', 'acrossai-abilities-manager' ),
			),
			'url_to'      => array(
				'type'        => 'string',
				'description' => __( 'New destination. Omit to leave unchanged.', 'acrossai-abilities-manager' ),
			),
			'header_code' => array(
				'type'        => 'string',
				'enum'        => array( '301', '302', '307', '410', '451' ),
				'description' => __( 'New HTTP status. Omit to leave unchanged.', 'acrossai-abilities-manager' ),
			),
			'status'      => array(
				'type'        => 'string',
				'enum'        => array( 'active', 'inactive' ),
				'description' => __( 'New status. Omit to leave unchanged. For bulk transitions use rank-math/change-redirection-status.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'id'               => array( 'type' => 'integer' ),
			'redirection'      => array( 'type' => array( 'object', 'null' ) ),
			'auto_deactivated' => array( 'type' => 'boolean' ),
			'infinite_loop'    => array( 'type' => 'boolean' ),
		);
	}

	protected function required_input(): array {
		return array( 'id' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		if ( $id < 1 ) {
			return new WP_Error( 'invalid_input', __( 'id is required and must be a positive integer.', 'acrossai-abilities-manager' ) );
		}

		$data = array( 'id' => $id );
		foreach ( array( 'sources', 'url_to', 'header_code', 'status' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$data[ $key ] = 'sources' === $key && is_array( $input[ $key ] )
					? array_values( $input[ $key ] )
					: $input[ $key ];
			}
		}

		if ( array( 'id' => $id ) === $data ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one field to change.', 'acrossai-abilities-manager' ) );
		}

		$result = Redirections_Repository::save( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: %d: redirection id */
			__( 'Updated redirection %d.', 'acrossai-abilities-manager' ),
			$result['id']
		);

		return $result;
	}
}
