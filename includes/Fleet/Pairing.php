<?php
/**
 * Joining a fleet: mint a credential, hand it to the hub, remember the hub.
 *
 * This is the one place in the plugin that creates a live credential and sends
 * it somewhere else, so the ordering below is deliberate and the rollback is
 * not optional.
 *
 * ## The contract with acrossai-pro
 *
 * POST `{hub}/wp-json/acrossai-pro/v1/fleet/register`, unauthenticated —
 * this site holds nothing the hub would recognise yet, so the pairing secret
 * IS the authentication.
 *
 *     {
 *       "secret":    "<from the pairing code>",
 *       "site_url":  "https://child.example.com",
 *       "site_name": "Child Example",
 *       "user":      "admin",
 *       "password":  "<application password, spaces stripped>"
 *     }
 *
 * On success the hub answers `200` with its own identity, which is what the
 * managed site then displays as the host:
 *
 *     { "hub_url": "https://hub.example.com", "hub_name": "Agency" }
 *
 * Any other status is a refusal, and the hub's `message` is shown verbatim
 * when it sends one — it knows why better than this side can guess.
 *
 * ## Why the credential is minted before the call
 *
 * The hub needs the password in the registration body, so there is no ordering
 * where it exists only after the hub has accepted. The consequence is that a
 * failed pairing leaves a live password behind unless this class removes it,
 * which is exactly what {@see self::pair()} does on every failure path. A
 * credential nobody is using and nobody can see is the worst thing this
 * feature could leave on a site.
 *
 * ## What stays behind on success
 *
 * The password's UUID, never the password. See {@see Hosts}.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Fleet
 * @since      0.4.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Fleet;

use WP_Application_Passwords;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Pairing {

	/**
	 * Registration path on the hub.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	private const REGISTER_PATH = '/wp-json/acrossai-pro/v1/fleet/register';

	/**
	 * Request timeout. Generous: the hub validates a code, writes a row and
	 * answers, and a managed site on shared hosting talking to another one
	 * can be slow without being broken. A pairing that fails on a timeout
	 * leaves the operator unsure whether it half-worked.
	 *
	 * @since 0.4.6
	 * @var   int
	 */
	private const TIMEOUT = 20;

	/**
	 * Largest response this site will read from a hub.
	 *
	 * @since 0.4.6
	 * @var   int
	 */
	private const MAX_RESPONSE_BYTES = 64000;

	/**
	 * Join the fleet described by a pairing code.
	 *
	 * @since 0.4.6
	 *
	 * @param int    $server_id     The Fleet server's primary key.
	 * @param string $code          Pairing code as pasted.
	 * @param int    $user_id       User the credential is minted on.
	 * @param bool   $allow_private Operator has accepted a private hub address.
	 * @return array<string, mixed>|WP_Error The stored host record, or why not.
	 */
	public static function pair( int $server_id, string $code, int $user_id, bool $allow_private = false ) {
		if ( $server_id <= 0 || $user_id <= 0 ) {
			return new WP_Error(
				'acrossai_mcp_fleet_bad_request',
				__( 'Could not work out which site or user to pair. Reload the page and try again.', 'acrossai-mcp-manager' )
			);
		}

		$parsed = PairingCode::parse( $code );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$url = UrlGuard::validate( $parsed['url'], $allow_private );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		// Core refuses to issue an Application Password on a site that is not
		// served over HTTPS, and says so only by returning false. Check first
		// so the operator gets the actual reason instead of a mint failure.
		if ( ! wp_is_application_passwords_available() ) {
			return new WP_Error(
				'acrossai_mcp_fleet_no_app_passwords',
				__( 'This site must use HTTPS before it can join a fleet. Application Passwords are unavailable until it does.', 'acrossai-mcp-manager' )
			);
		}

		$host_name = (string) wp_parse_url( $url, PHP_URL_HOST );

		$created = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				/* translators: %s: hostname of the managing site. */
				'name'   => sprintf( __( 'AcrossAI Fleet — %s', 'acrossai-mcp-manager' ), $host_name ),
				'app_id' => wp_generate_uuid4(),
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		list( $password, $item ) = $created;
		$uuid                    = (string) $item['uuid'];

		$response = self::register_with_hub( $url, $parsed['secret'], $user_id, $password, $allow_private, $host_name );

		// Every failure from here on revokes. The hub either never received the
		// credential or refused it, so leaving it live would put an unused,
		// unexplained password on an administrator's account.
		if ( is_wp_error( $response ) ) {
			self::revoke( $user_id, $uuid );
			return $response;
		}

		$host = array(
			'key'          => sanitize_key( str_replace( '.', '-', $host_name ) ) . '-' . substr( md5( $url ), 0, 6 ),
			'host'         => isset( $response['hub_name'] ) && '' !== (string) $response['hub_name']
				? (string) $response['hub_name']
				: $host_name,
			'url'          => $url,
			'uuid'         => $uuid,
			'added_at'     => time(),
			'last_call_at' => 0,
		);

		if ( ! Hosts::add( $server_id, $host ) ) {
			self::revoke( $user_id, $uuid );
			return new WP_Error(
				'acrossai_mcp_fleet_store_failed',
				__( 'The managing site accepted the pairing but this site could not record it, so the credential was withdrawn. Try again.', 'acrossai-mcp-manager' )
			);
		}

		return $host;
	}

	/**
	 * Forget a host and revoke the credential it was using.
	 *
	 * Revoke first. A record removed before its password is revoked leaves a
	 * live credential with nothing on screen pointing at it — the one outcome
	 * worse than a failed removal, because nobody can find it to undo.
	 *
	 * @since 0.4.6
	 *
	 * @param int    $server_id The Fleet server's primary key.
	 * @param string $key       Host key.
	 * @param int    $user_id   User the credential was minted on.
	 * @return true|WP_Error
	 */
	public static function unpair( int $server_id, string $key, int $user_id ) {
		$host = Hosts::find( $server_id, $key );
		if ( null === $host ) {
			return new WP_Error(
				'acrossai_mcp_fleet_unknown_host',
				__( 'That host is already gone.', 'acrossai-mcp-manager' )
			);
		}

		if ( '' !== (string) $host['uuid'] ) {
			self::revoke( $user_id, (string) $host['uuid'] );
		}

		Hosts::remove( $server_id, $key );

		return true;
	}

	/**
	 * POST the credential to the hub and read its answer.
	 *
	 * @since 0.4.6
	 *
	 * @param string $url           Validated hub base URL.
	 * @param string $secret        Secret from the pairing code.
	 * @param int    $user_id       User the credential belongs to.
	 * @param string $password      The freshly minted Application Password.
	 * @param bool   $allow_private Whether a private hub address was accepted.
	 * @param string $host_name     Hub hostname, for the scoped allowance.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function register_with_hub(
		string $url,
		string $secret,
		int $user_id,
		string $password,
		bool $allow_private,
		string $host_name
	) {
		$user = get_userdata( $user_id );
		$args = array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0, // A redirect would carry the credential somewhere unvalidated.
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'user-agent'  => 'AcrossAI Fleet (' . home_url() . ')',
			'body'        => (string) wp_json_encode(
				array(
					'secret'    => $secret,
					'site_url'  => home_url(),
					'site_name' => (string) get_bloginfo( 'name' ),
					'user'      => $user instanceof \WP_User ? $user->user_login : '',
					// Core prints these in groups of four for legibility and
					// strips the spaces when verifying. Send the compact form
					// so the hub stores exactly what it will transmit.
					'password'  => str_replace( ' ', '', $password ),
				)
			),
		);

		$endpoint = $url . self::REGISTER_PATH;
		$call     = static function () use ( $endpoint, $args ) {
			return wp_remote_post( $endpoint, $args );
		};

		$response = $allow_private
			? UrlGuard::with_private_host_allowed( $host_name, $call )
			: $call();

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'acrossai_mcp_fleet_unreachable',
				sprintf(
					/* translators: 1: hostname, 2: underlying transport error. */
					__( 'Could not reach %1$s: %2$s', 'acrossai-mcp-manager' ),
					$host_name,
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = substr( (string) wp_remote_retrieve_body( $response ), 0, self::MAX_RESPONSE_BYTES );
		$parsed = json_decode( $body, true );
		$parsed = is_array( $parsed ) ? $parsed : array();

		if ( 200 !== $status ) {
			$detail = isset( $parsed['message'] ) && is_string( $parsed['message'] ) ? $parsed['message'] : '';

			return new WP_Error(
				'acrossai_mcp_fleet_refused',
				'' !== $detail
					? $detail
					: sprintf(
						/* translators: 1: hostname, 2: HTTP status code. */
						__( '%1$s refused the pairing (HTTP %2$d). The code may have expired or already been used.', 'acrossai-mcp-manager' ),
						$host_name,
						$status
					)
			);
		}

		return $parsed;
	}

	/**
	 * Withdraw an Application Password, ignoring a failure to find it.
	 *
	 * Called from rollback paths where the caller is already reporting a
	 * different problem; a second error here would replace a useful message
	 * with a confusing one.
	 *
	 * @since 0.4.6
	 *
	 * @param int    $user_id User the password belongs to.
	 * @param string $uuid    Application Password UUID.
	 */
	private static function revoke( int $user_id, string $uuid ): void {
		if ( '' === $uuid ) {
			return;
		}

		WP_Application_Passwords::delete_application_password( $user_id, $uuid );
	}
}
