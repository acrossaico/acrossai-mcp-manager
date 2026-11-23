<?php
/**
 * Feature 130 — list Redirection groups.
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
 * redirects/list-groups — the groups a redirect can belong to.
 *
 * Read-only, idempotent. A redirect must be placed in a group; this is where
 * the caller gets the group id to pass to redirects/create-redirect.
 */
class List_Groups extends Base_Redirection_Ability {

	protected function slug(): string {
		return 'list-groups';
	}

	protected function ability_label(): string {
		return __( 'List Redirect Groups', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return the redirect groups, each with its id, name, module (WordPress, Apache or Nginx), redirect count and enabled state. Use a group id when creating or moving a redirect.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'redirects-settings';
	}

	protected function input_properties(): array {
		return array(
			'per_page'  => array(
				'type'        => 'integer',
				'default'     => 50,
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
			'direction' => array(
				'type'        => 'string',
				'enum'        => array( 'asc', 'desc' ),
				'default'     => 'asc',
				'description' => __( 'Sort direction by name.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'groups' => array( 'type' => 'array' ),
			'count'  => array( 'type' => 'integer' ),
			'total'  => array( 'type' => 'integer' ),
			'page'   => array( 'type' => 'integer' ),
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
		$result = Redirects_Repository::list_groups(
			array(
				'per_page'  => isset( $input['per_page'] ) ? (int) $input['per_page'] : 50,
				'page'      => $page,
				'orderby'   => 'name',
				'direction' => isset( $input['direction'] ) ? sanitize_key( (string) $input['direction'] ) : 'asc',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		$total = isset( $result['total'] ) ? (int) $result['total'] : count( $items );

		return array(
			'groups'  => $items,
			'count'   => count( $items ),
			'total'   => $total,
			'page'    => $page,
			'message' => sprintf(
				/* translators: %d: number of groups */
				__( 'Returned %d redirect groups.', 'acrossai-abilities-manager' ),
				count( $items )
			),
		);
	}
}
