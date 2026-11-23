<?php
/**
 * Feature 130 — update a Redirection redirect.
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
 * redirects/update-redirect — change an existing redirect.
 *
 * Only the fields supplied are changed. Redirection's own update re-sanitizes
 * the whole record, so this ability reads the current redirect first and merges
 * the caller's changes onto it — a partial call can never blank the fields it
 * did not mention. Covers enable/disable via the enabled flag.
 *
 * Reversible, so not confirm-gated. The destructive remove is a separate
 * ability, redirects/delete-redirects.
 */
class Update_Redirect extends Base_Redirection_Ability {

	protected function slug(): string {
		return 'update-redirect';
	}

	protected function ability_label(): string {
		return __( 'Update Redirect', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Change an existing redirect by id: its source or target, the HTTP status code, the action type, the group it belongs to, its title, or whether it is enabled. Only the fields you pass are changed. To enable or disable a redirect, pass enabled true or false.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'redirects-manage';
	}

	protected function input_properties(): array {
		return array(
			'id'          => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The redirect id to update. Get it from redirects/list-redirects.', 'acrossai-abilities-manager' ),
			),
			'source'      => array(
				'type'        => 'string',
				'description' => __( 'New source URL or path.', 'acrossai-abilities-manager' ),
			),
			'target'      => array(
				'type'        => 'string',
				'description' => __( 'New target URL (for url and pass actions).', 'acrossai-abilities-manager' ),
			),
			'action_type' => array(
				'type'        => 'string',
				'enum'        => Redirects_Repository::ACTION_TYPES,
				'description' => __( 'Change the action.', 'acrossai-abilities-manager' ),
			),
			'action_code' => array(
				'type'        => 'integer',
				'description' => __( 'Change the HTTP status code.', 'acrossai-abilities-manager' ),
			),
			'group_id'    => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Move the redirect to another group.', 'acrossai-abilities-manager' ),
			),
			'title'       => array(
				'type'        => 'string',
				'description' => __( 'Change the human label.', 'acrossai-abilities-manager' ),
			),
			'regex'       => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the source is a regular expression.', 'acrossai-abilities-manager' ),
			),
			'enabled'     => array(
				'type'        => 'boolean',
				'description' => __( 'Enable (true) or disable (false) the redirect.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'id'       => array( 'type' => 'integer' ),
			'redirect' => array( 'type' => array( 'object', 'null' ) ),
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
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id < 1 ) {
			return new WP_Error( 'invalid_input', __( 'A positive id is required.', 'acrossai-abilities-manager' ) );
		}

		$current = Redirects_Repository::get( $id );
		if ( null === $current ) {
			return new WP_Error( 'redirect_not_found', sprintf( /* translators: %d: redirect id */ __( 'No redirect with id %d.', 'acrossai-abilities-manager' ), $id ) );
		}

		// Start from the current record so unmentioned fields survive.
		$current_target = isset( $current['action_data']['url'] ) ? (string) $current['action_data']['url'] : '';
		$action_type    = isset( $input['action_type'] ) ? sanitize_key( (string) $input['action_type'] ) : (string) $current['action_type'];
		if ( ! in_array( $action_type, Redirects_Repository::ACTION_TYPES, true ) ) {
			return new WP_Error( 'invalid_input', __( 'action_type is not a recognised Redirection action.', 'acrossai-abilities-manager' ) );
		}

		$target = array_key_exists( 'target', $input ) ? trim( (string) $input['target'] ) : $current_target;

		$needs_target   = in_array( $action_type, array( 'url', 'pass' ), true );
		$rejects_target = in_array( $action_type, array( 'error', 'random', 'nothing' ), true );
		if ( $needs_target && '' === $target ) {
			return new WP_Error( 'invalid_input', sprintf( /* translators: %s: action type */ __( 'target is required for a "%s" action.', 'acrossai-abilities-manager' ), $action_type ) );
		}
		if ( $rejects_target ) {
			$target = '';
		}

		$action_code = isset( $input['action_code'] ) ? (int) $input['action_code'] : (int) $current['action_code'];
		if ( 'url' === $action_type && ! in_array( $action_code, Redirects_Repository::REDIRECT_CODES, true ) ) {
			$action_code = 301;
		}
		if ( 'error' === $action_type && ! in_array( $action_code, Redirects_Repository::ERROR_CODES, true ) ) {
			$action_code = 410;
		}

		$details = array(
			'url'         => array_key_exists( 'source', $input ) ? trim( (string) $input['source'] ) : (string) $current['url'],
			'match_type'  => (string) $current['match_type'],
			'action_type' => $action_type,
			'action_code' => $action_code,
			'group_id'    => isset( $input['group_id'] ) ? (int) $input['group_id'] : (int) $current['group_id'],
			'regex'       => array_key_exists( 'regex', $input ) ? ( ! empty( $input['regex'] ) ? 1 : 0 ) : ( ! empty( $current['regex'] ) ? 1 : 0 ),
			'title'       => array_key_exists( 'title', $input ) ? sanitize_text_field( (string) $input['title'] ) : (string) $current['title'],
		);
		if ( '' !== $target ) {
			$details['action_data'] = array( 'url' => $target );
		}

		$updated = Redirects_Repository::update( $id, $details );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		// Apply enabled state explicitly so it is correct regardless of how the
		// plugin's own update path treats it. Default to the current state.
		$enabled = array_key_exists( 'enabled', $input ) ? (bool) $input['enabled'] : (bool) $current['enabled'];
		$final   = Redirects_Repository::set_enabled( $id, $enabled );
		if ( is_wp_error( $final ) ) {
			return $final;
		}

		return array(
			'id'       => $id,
			'redirect' => $final,
			'message'  => sprintf( /* translators: %d: redirect id */ __( 'Updated redirect %d.', 'acrossai-abilities-manager' ), $id ),
		);
	}
}
