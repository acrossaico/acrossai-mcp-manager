<?php
/**
 * Feature 130 — list the Redirection 404 error log.
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
 * redirects/list-404-errors — the URLs visitors hit that returned nothing.
 *
 * Read-only, idempotent. Grouped by URL with a hit count by default, which is
 * the triage view: the raw material for deciding which redirects a site needs.
 * Pass group_by: none for individual log rows with IP and user agent.
 */
class List_404_Errors extends Base_Redirection_Ability {

	protected function slug(): string {
		return 'list-404-errors';
	}

	protected function ability_label(): string {
		return __( 'List 404 Errors', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return the 404 error log — URLs that visitors requested and that returned nothing. Grouped by URL with a hit count by default, so you can see which missing URLs matter most and turn them into redirects with redirects/create-redirect. Pass group_by: none for individual log rows. Read-only.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'redirects-logs';
	}

	protected function input_properties(): array {
		return array(
			'group_by' => array(
				'type'        => 'string',
				'enum'        => array( 'url', 'none' ),
				'default'     => 'url',
				'description' => __( 'Group identical URLs with a count (url), or return individual log rows (none).', 'acrossai-abilities-manager' ),
			),
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
			'errors'   => array( 'type' => 'array' ),
			'count'    => array( 'type' => 'integer' ),
			'total'    => array( 'type' => 'integer' ),
			'page'     => array( 'type' => 'integer' ),
			'group_by' => array( 'type' => 'string' ),
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
		$group_by = isset( $input['group_by'] ) ? sanitize_key( (string) $input['group_by'] ) : 'url';
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$result = Redirects_Repository::list_404(
			array(
				'group_by' => $group_by,
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
			'errors'   => $items,
			'count'    => count( $items ),
			'total'    => $total,
			'page'     => $page,
			'group_by' => $group_by,
			'message'  => sprintf(
				/* translators: 1: number on this page, 2: total */
				__( 'Returned %1$d of %2$d logged 404s.', 'acrossai-abilities-manager' ),
				count( $items ),
				$total
			),
		);
	}
}
