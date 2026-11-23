<?php
/**
 * Feature 069 — read the Rank Math 404 monitor log.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Log_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #55 — rank-math/list-404-logs.
 *
 * The natural companion to the redirection abilities: a frequently-hit 404 is a
 * redirection waiting to be created. Sort by times_accessed to find those first.
 *
 * Read-only, idempotent.
 */
class List_404_Logs extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'list-404-logs';
	}

	protected function ability_label(): string {
		return __( 'List Rank Math 404 Logs', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return logged 404 requests with the URI, hit count, timestamp and — in advanced monitor mode — referer, user agent and IP. Sort by times_accessed to surface the URLs worth redirecting, then create rules with rank-math/create-redirection.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-redirections';
	}

	protected function rank_math_cap(): string {
		return '404-monitor';
	}

	protected function required_module(): string {
		return Log_Repository::MODULE;
	}

	protected function input_properties(): array {
		return array(
			'limit'   => array(
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 200,
				'description' => __( 'Page size.', 'acrossai-abilities-manager' ),
			),
			'page'    => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( '1-based page number.', 'acrossai-abilities-manager' ),
			),
			'search'  => array(
				'type'        => 'string',
				'description' => __( 'Match against the logged URI.', 'acrossai-abilities-manager' ),
			),
			'orderby' => array(
				'type'        => 'string',
				'enum'        => array( 'id', 'uri', 'accessed', 'times_accessed' ),
				'default'     => 'accessed',
				'description' => __( 'Sort column. Use times_accessed to find the most-requested missing URLs.', 'acrossai-abilities-manager' ),
			),
			'order'   => array(
				'type'        => 'string',
				'enum'        => array( 'ASC', 'DESC' ),
				'default'     => 'DESC',
				'description' => __( 'Sort direction.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'logs'  => array( 'type' => 'array' ),
			'count' => array( 'type' => 'integer' ),
			'total' => array( 'type' => 'integer' ),
			'page'  => array( 'type' => 'integer' ),
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
		$result = Log_Repository::listing(
			isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 50,
			isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
			isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
			isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'accessed',
			isset( $input['order'] ) && 'ASC' === strtoupper( (string) $input['order'] ) ? 'ASC' : 'DESC'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number returned, 2: total logged */
			__( 'Returned %1$d of %2$d logged 404s.', 'acrossai-abilities-manager' ),
			$result['count'],
			$result['total']
		);

		return $result;
	}
}
