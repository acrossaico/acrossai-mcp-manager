<?php
/**
 * Feature 130 — delete Redirection redirects.
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
 * redirects/delete-redirects — permanently remove one or more redirects.
 *
 * Destructive and bulk: Redirection has no trash for redirects, so a delete is
 * final. Confirm-gated, and each id is deleted through its own model call so the
 * response reports exactly which were removed and which were already gone. To
 * merely turn a redirect off, use redirects/update-redirect with enabled:false.
 */
class Delete_Redirects extends Base_Redirection_Ability {

	protected function slug(): string {
		return 'delete-redirects';
	}

	protected function ability_label(): string {
		return __( 'Delete Redirects', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Permanently delete one or more redirects by id. This cannot be undone — Redirection does not trash redirects. Requires confirm: true. To disable a redirect without deleting it, use redirects/update-redirect with enabled: false instead.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'redirects-manage';
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array(
			'ids' => array(
				'type'        => 'array',
				'minItems'    => 1,
				'items'       => array( 'type' => 'integer', 'minimum' => 1 ),
				'description' => __( 'Redirect ids to delete. Get them from redirects/list-redirects.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'deleted'       => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'missing'       => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'deleted_count' => array( 'type' => 'integer' ),
		);
	}

	protected function required_input(): array {
		return array( 'ids' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => true, 'idempotent' => false );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		if ( ! isset( $input['ids'] ) || ! is_array( $input['ids'] ) || array() === $input['ids'] ) {
			return new WP_Error( 'invalid_input', __( 'ids must contain at least one redirect id.', 'acrossai-abilities-manager' ) );
		}

		$ids = array();
		foreach ( $input['ids'] as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( array() === $ids ) {
			return new WP_Error( 'invalid_input', __( 'No valid redirect ids were given.', 'acrossai-abilities-manager' ) );
		}

		$result = Redirects_Repository::delete( $ids );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$deleted = $result['deleted'];
		$missing = $result['missing'];

		return array(
			'deleted'       => $deleted,
			'missing'       => $missing,
			'deleted_count' => count( $deleted ),
			'message'       => sprintf(
				/* translators: 1: deleted count, 2: missing count */
				__( 'Deleted %1$d redirect(s); %2$d id(s) did not exist.', 'acrossai-abilities-manager' ),
				count( $deleted ),
				count( $missing )
			),
		);
	}
}
