<?php
/**
 * Feature 130 — list Redirection redirects.
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
 * redirects/list-redirects — paginated, filterable list of redirects.
 *
 * Read-only, idempotent.
 */
class List_Redirects extends Base_Redirection_Ability {

	protected function slug(): string {
		return 'list-redirects';
	}

	protected function ability_label(): string {
		return __( 'List Redirects', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return a paginated list of Redirection redirects with their source URL, target, HTTP status code, match and action type, group, hit count, last-access and enabled state. Filter by status, group, or a substring of the source or target. Check here before creating a redirect so you do not duplicate one.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'redirects-manage';
	}

	protected function input_properties(): array {
		return array(
			'status'    => array(
				'type'        => 'string',
				'enum'        => array( 'all', 'enabled', 'disabled' ),
				'default'     => 'all',
				'description' => __( 'Filter by enabled state.', 'acrossai-abilities-manager' ),
			),
			'group_id'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Only redirects in this group. Get group ids from redirects/list-groups.', 'acrossai-abilities-manager' ),
			),
			'source'    => array(
				'type'        => 'string',
				'description' => __( 'Match a substring of the source URL.', 'acrossai-abilities-manager' ),
			),
			'target'    => array(
				'type'        => 'string',
				'description' => __( 'Match a substring of the target URL.', 'acrossai-abilities-manager' ),
			),
			'per_page'  => array(
				'type'        => 'integer',
				'default'     => 25,
				'minimum'     => 5,
				'maximum'     => 200,
				'description' => __( 'Page size (5–200).', 'acrossai-abilities-manager' ),
			),
			'page'      => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( '1-based page number.', 'acrossai-abilities-manager' ),
			),
			'orderby'   => array(
				'type'        => 'string',
				'enum'        => array( 'source', 'last_count', 'last_access', 'position' ),
				'default'     => 'last_access',
				'description' => __( 'Sort column. last_count is the hit count.', 'acrossai-abilities-manager' ),
			),
			'direction' => array(
				'type'        => 'string',
				'enum'        => array( 'asc', 'desc' ),
				'default'     => 'desc',
				'description' => __( 'Sort direction.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'redirects' => array( 'type' => 'array' ),
			'count'     => array( 'type' => 'integer' ),
			'total'     => array( 'type' => 'integer' ),
			'page'      => array( 'type' => 'integer' ),
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
		$filter_by = array();

		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'all';
		if ( 'enabled' === $status || 'disabled' === $status ) {
			$filter_by['status'] = $status;
		}
		if ( isset( $input['group_id'] ) && (int) $input['group_id'] > 0 ) {
			$filter_by['group'] = (int) $input['group_id'];
		}
		if ( isset( $input['source'] ) && '' !== trim( (string) $input['source'] ) ) {
			$filter_by['url'] = sanitize_text_field( (string) $input['source'] );
		}
		if ( isset( $input['target'] ) && '' !== trim( (string) $input['target'] ) ) {
			$filter_by['target'] = sanitize_text_field( (string) $input['target'] );
		}

		$page   = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$result = Redirects_Repository::list_redirects(
			array(
				'per_page'  => isset( $input['per_page'] ) ? (int) $input['per_page'] : 25,
				'page'      => $page,
				'orderby'   => isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'last_access',
				'direction' => isset( $input['direction'] ) ? sanitize_key( (string) $input['direction'] ) : 'desc',
				'filterBy'  => $filter_by,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		$total = isset( $result['total'] ) ? (int) $result['total'] : count( $items );

		return array(
			'redirects' => $items,
			'count'     => count( $items ),
			'total'     => $total,
			'page'      => $page,
			'message'   => sprintf(
				/* translators: 1: number on this page, 2: total */
				__( 'Returned %1$d of %2$d redirects.', 'acrossai-abilities-manager' ),
				count( $items ),
				$total
			),
		);
	}
}
