<?php
/**
 * Feature 130 — list the Redirection hit log.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Redirection
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Redirection;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Redirection\Redirects_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * redirects/list-hit-log — recent redirects that fired.
 *
 * Read-only, idempotent. Each row records a request that matched a redirect:
 * the source URL, where it was sent, when, and the referring agent. Useful for
 * confirming a redirect you just created is actually being hit.
 */
class List_Hit_Log extends Base_Redirection_Ability {

	protected function slug(): string {
		return 'list-hit-log';
	}

	protected function ability_label(): string {
		return __( 'List Redirect Hit Log', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return the redirect hit log — requests that matched a redirect and were sent on. Each row has the source URL, the target it was sent to, the time, and the user agent. Use it to confirm a redirect is firing. Read-only. Logging must be enabled in Redirection\'s settings for rows to appear.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'redirects-logs';
	}

	protected function input_properties(): array {
		return array(
			'per_page' => array(
				'type'        => 'integer',
				'default'     => 25,
				'minimum'     => 5,
				'maximum'     => 200,
				'description' => __( 'Page size (5–200).', 'acrossai-abilities-manager' ),
			),
			'page'     => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( '1-based page number.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'hits'  => array( 'type' => 'array' ),
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
		$page   = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$result = Redirects_Repository::list_hits(
			array(
				'per_page' => isset( $input['per_page'] ) ? (int) $input['per_page'] : 25,
				'page'     => $page,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		$total = isset( $result['total'] ) ? (int) $result['total'] : count( $items );

		return array(
			'hits'    => $items,
			'count'   => count( $items ),
			'total'   => $total,
			'page'    => $page,
			'message' => sprintf(
				/* translators: 1: number on this page, 2: total */
				__( 'Returned %1$d of %2$d logged redirect hits.', 'acrossai-abilities-manager' ),
				count( $items ),
				$total
			),
		);
	}
}
