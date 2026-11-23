<?php
/**
 * Feature 130 — create a Redirection redirect.
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
 * redirects/create-redirect — the headline ability.
 *
 * Not idempotent: calling twice with the same input creates two redirects. This
 * is what consumes content/delete-post's suggested_redirect and what turns a
 * logged 404 from redirects/list-404-errors into a working redirect.
 *
 * The action_type and action_code must agree — a url action needs a 3xx code
 * and a target; an error action needs a 4xx/5xx code and no target — and the
 * combination is validated here so a broken redirect is never written.
 */
class Create_Redirect extends Base_Redirection_Ability {

	protected function slug(): string {
		return 'create-redirect';
	}

	protected function ability_label(): string {
		return __( 'Create Redirect', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Create a redirect from a source URL. The common case is a permanent redirect: action_type "url" with action_code 301 and a target. Also supports 302/307/308 temporary redirects, an "error" action returning 404/410 with no target, "pass" to serve the target without changing the URL, "random" to a random post, and "nothing" to only log. Defaults to a plain URL match; set regex true for a pattern source. Calling twice creates two redirects — check redirects/list-redirects first.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'redirects-manage';
	}

	protected function input_properties(): array {
		return array(
			'source'      => array(
				'type'        => 'string',
				'description' => __( 'The source URL or path to match, e.g. /old-page. Required.', 'acrossai-abilities-manager' ),
			),
			'target'      => array(
				'type'        => 'string',
				'description' => __( 'Where to send the request. Required for url and pass actions; omit for error, random and nothing.', 'acrossai-abilities-manager' ),
			),
			'action_type' => array(
				'type'        => 'string',
				'enum'        => Redirects_Repository::ACTION_TYPES,
				'default'     => 'url',
				'description' => __( 'What to do: url (redirect), error (HTTP error), pass (serve target, keep URL), random (random post), nothing (log only).', 'acrossai-abilities-manager' ),
			),
			'action_code' => array(
				'type'        => 'integer',
				'description' => __( 'HTTP status. Defaults to 301 for a url action and 410 for an error action. url accepts 301/302/303/307/308; error accepts 4xx/5xx such as 404 or 410.', 'acrossai-abilities-manager' ),
			),
			'match_type'  => array(
				'type'        => 'string',
				'enum'        => Redirects_Repository::MATCH_TYPES,
				'default'     => 'url',
				'description' => __( 'How to match the request. Leave as url for the common case.', 'acrossai-abilities-manager' ),
			),
			'group_id'    => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Group to place the redirect in. Defaults to Redirection\'s last-used group. Get ids from redirects/list-groups.', 'acrossai-abilities-manager' ),
			),
			'title'       => array(
				'type'        => 'string',
				'description' => __( 'Optional human label for the redirect.', 'acrossai-abilities-manager' ),
			),
			'regex'       => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Treat the source as a regular expression.', 'acrossai-abilities-manager' ),
			),
			'enabled'     => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Whether the redirect is active immediately.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'id'       => array( 'type' => array( 'integer', 'null' ) ),
			'redirect' => array( 'type' => array( 'object', 'null' ) ),
		);
	}

	protected function required_input(): array {
		return array( 'source' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$source = isset( $input['source'] ) ? trim( (string) $input['source'] ) : '';
		if ( '' === $source ) {
			return new WP_Error( 'invalid_input', __( 'source is required.', 'acrossai-abilities-manager' ) );
		}

		$action_type = isset( $input['action_type'] ) ? sanitize_key( (string) $input['action_type'] ) : 'url';
		if ( ! in_array( $action_type, Redirects_Repository::ACTION_TYPES, true ) ) {
			return new WP_Error( 'invalid_input', __( 'action_type is not a recognised Redirection action.', 'acrossai-abilities-manager' ) );
		}

		$match_type = isset( $input['match_type'] ) ? sanitize_key( (string) $input['match_type'] ) : 'url';
		if ( ! in_array( $match_type, Redirects_Repository::MATCH_TYPES, true ) ) {
			return new WP_Error( 'invalid_input', __( 'match_type is not a recognised Redirection match type.', 'acrossai-abilities-manager' ) );
		}

		$target        = isset( $input['target'] ) ? trim( (string) $input['target'] ) : '';
		$needs_target  = in_array( $action_type, array( 'url', 'pass' ), true );
		$rejects_target = in_array( $action_type, array( 'error', 'random', 'nothing' ), true );

		if ( $needs_target && '' === $target ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: action type */
					__( 'target is required for a "%s" action.', 'acrossai-abilities-manager' ),
					$action_type
				)
			);
		}
		if ( $rejects_target && '' !== $target ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: action type */
					__( 'A "%s" action takes no target; remove it.', 'acrossai-abilities-manager' ),
					$action_type
				)
			);
		}

		// Resolve and validate the HTTP status against the action.
		$action_code = isset( $input['action_code'] ) ? (int) $input['action_code'] : 0;
		if ( 0 === $action_code ) {
			$action_code = 'error' === $action_type ? 410 : 301;
		}
		if ( 'url' === $action_type && ! in_array( $action_code, Redirects_Repository::REDIRECT_CODES, true ) ) {
			return new WP_Error( 'invalid_input', __( 'A url action needs a redirect status code such as 301, 302, 307 or 308.', 'acrossai-abilities-manager' ) );
		}
		if ( 'error' === $action_type && ! in_array( $action_code, Redirects_Repository::ERROR_CODES, true ) ) {
			return new WP_Error( 'invalid_input', __( 'An error action needs an HTTP error code such as 404 or 410.', 'acrossai-abilities-manager' ) );
		}

		$details = array(
			'url'         => $source,
			'match_type'  => $match_type,
			'action_type' => $action_type,
			'action_code' => $action_code,
			'group_id'    => isset( $input['group_id'] ) ? (int) $input['group_id'] : 0,
			'regex'       => ! empty( $input['regex'] ) ? 1 : 0,
			'enabled'     => ! isset( $input['enabled'] ) || (bool) $input['enabled'],
		);

		if ( '' !== $target ) {
			$details['action_data'] = array( 'url' => $target );
		}
		if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
			$details['title'] = sanitize_text_field( (string) $input['title'] );
		}

		$redirect = Redirects_Repository::create( $details );
		if ( is_wp_error( $redirect ) ) {
			return $redirect;
		}

		$id = isset( $redirect['id'] ) ? (int) $redirect['id'] : null;

		return array(
			'id'       => $id,
			'redirect' => $redirect,
			'message'  => null === $id
				? __( 'Created the redirect.', 'acrossai-abilities-manager' )
				: sprintf( /* translators: %d: redirect id */ __( 'Created redirect %d.', 'acrossai-abilities-manager' ), $id ),
		);
	}
}
