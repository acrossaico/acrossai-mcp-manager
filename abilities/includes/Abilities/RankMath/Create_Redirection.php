<?php
/**
 * Feature 069 — create a Rank Math redirection.
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
 * Ability #53 — rank-math/create-redirection.
 *
 * Not idempotent: calling twice with the same input creates two rules.
 *
 * A source whose pattern resolves to the destination is saved but forced inactive by
 * Rank Math, and the response reports that as infinite_loop_new rather than a bare
 * success — otherwise the caller believes it created a working redirect.
 */
class Create_Redirection extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'create-redirection';
	}

	protected function ability_label(): string {
		return __( 'Create Rank Math Redirection', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Create a redirection with one or more source rules. Each source needs a pattern and a comparison of exact, contains, start, end or regex. If the source would resolve to the destination, Rank Math saves the rule but forces it inactive to avoid a loop, and the response says so. Calling twice creates two rules — check first with rank-math/find-redirection.', 'acrossai-abilities-manager' );
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
			'sources'     => array(
				'type'        => 'array',
				'minItems'    => 1,
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
				'description' => __( 'Source rules to match. Patterns are site-relative paths.', 'acrossai-abilities-manager' ),
			),
			'url_to'      => array(
				'type'        => 'string',
				'description' => __( 'Destination URL or path. Not required when header_code is 410 or 451.', 'acrossai-abilities-manager' ),
			),
			'header_code' => array(
				'type'        => 'string',
				'enum'        => array( '301', '302', '307', '410', '451' ),
				'default'     => '301',
				'description' => __( 'HTTP status. 301 permanent, 302/307 temporary, 410 gone, 451 unavailable for legal reasons.', 'acrossai-abilities-manager' ),
			),
			'status'      => array(
				'type'        => 'string',
				'enum'        => array( 'active', 'inactive' ),
				'default'     => 'active',
				'description' => __( 'Whether the rule takes effect immediately.', 'acrossai-abilities-manager' ),
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
		return array( 'sources' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		if ( ! isset( $input['sources'] ) || ! is_array( $input['sources'] ) || array() === $input['sources'] ) {
			return new WP_Error( 'invalid_input', __( 'sources must contain at least one source rule.', 'acrossai-abilities-manager' ) );
		}

		$header_code = isset( $input['header_code'] ) ? (string) $input['header_code'] : '301';
		$url_to      = isset( $input['url_to'] ) ? (string) $input['url_to'] : '';

		// 410 Gone and 451 have no destination by definition; every other code does.
		if ( '' === trim( $url_to ) && ! in_array( $header_code, array( '410', '451' ), true ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: HTTP status code */
					__( 'url_to is required for a %s redirection.', 'acrossai-abilities-manager' ),
					$header_code
				)
			);
		}

		$result = Redirections_Repository::save(
			array(
				'sources'     => array_values( $input['sources'] ),
				'url_to'      => $url_to,
				'header_code' => $header_code,
				'status'      => isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'active',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = $result['auto_deactivated']
			? sprintf(
				/* translators: %d: redirection id */
				__( 'Created redirection %d, but Rank Math forced it inactive because the source resolves to the destination. Fix the source, then activate it with rank-math/change-redirection-status.', 'acrossai-abilities-manager' ),
				$result['id']
			)
			: sprintf(
				/* translators: %d: redirection id */
				__( 'Created redirection %d.', 'acrossai-abilities-manager' ),
				$result['id']
			);

		return $result;
	}
}
