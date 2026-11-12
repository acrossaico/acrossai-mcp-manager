<?php
/**
 * Derives how an MCP client reached us, for the activity log (Feature 096).
 *
 * "Connection type" is not stored by anything upstream — the adapter does not
 * know and WordPress does not record it. It is assembled from signals that are
 * only available while the request is in flight, which is why this runs inside
 * the observability handler rather than at read time.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Observability
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Observability;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Fleet\FleetContext;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless resolver. A11-style: no singleton ceremony, no instance state
 * beyond one per-request memo, never Loader-wired.
 */
final class ConnectionContext {

	/** OAuth bearer whose client row carries a connector profile. */
	public const TYPE_AI_CONNECTOR = 'ai-connector';

	/** OAuth bearer with no connector profile — a bare DCR client. */
	public const TYPE_OAUTH = 'oauth';

	/** Application password: the npm bridge and most CLI clients. */
	public const TYPE_APP_PASSWORD = 'app-password';

	/** Cookie + REST nonce: the admin UI and Quick Connect preview. */
	public const TYPE_BROWSER = 'browser';

	/**
	 * A signature-verified call from another WordPress site managing this one.
	 *
	 * Needed because the fleet route authenticates by RSA signature and then
	 * sets a current user, so without this the request satisfies
	 * `is_user_logged_in()` and reports itself as `browser` — a signed
	 * server-to-server call recorded in an audit log as somebody clicking
	 * around the admin.
	 */
	public const TYPE_FLEET = 'fleet';

	/** None of the above matched. */
	public const TYPE_UNKNOWN = 'unknown';

	/**
	 * Per-request slug → numeric server id memo.
	 *
	 * @var array<string, int>
	 */
	private static $server_id_cache = array();

	/**
	 * Resolve the connection type for the request in flight.
	 *
	 * Order matters, most specific first. Fleet leads: the route sets a current
	 * user after verifying a signature, so every later test would otherwise
	 * report `browser`. OAuth comes next because it is the only signal that
	 * distinguishes a connector-backed client from a bare one. Application
	 * password is checked before cookie because WordPress sets a current user
	 * for both, so "is there a logged-in user" cannot tell them apart on its
	 * own.
	 *
	 * @return string One of the TYPE_* constants.
	 */
	public static function resolve_type(): string {
		// First, and before the OAuth check: a fleet request carries no bearer
		// and no application password, so every test below it would fall
		// through to `browser` on the strength of the user the route set. This
		// is also the most specific signal available — it is only ever true
		// after a signature has verified.
		if ( FleetContext::is_fleet() ) {
			return self::TYPE_FLEET;
		}

		$auth = AuthContextHolder::instance();

		if ( $auth->is_oauth() ) {
			return '' !== $auth->get_connector_slug()
				? self::TYPE_AI_CONNECTOR
				: self::TYPE_OAUTH;
		}

		// WordPress sets this global only after a successful application-password
		// authentication, so its presence is conclusive.
		if ( ! empty( $GLOBALS['wp_rest_application_password_uuid'] ) ) {
			return self::TYPE_APP_PASSWORD;
		}

		if ( is_user_logged_in() ) {
			return self::TYPE_BROWSER;
		}

		return self::TYPE_UNKNOWN;
	}

	/**
	 * Connector slug for this request, or '' when not connector-backed.
	 */
	public static function connector_slug(): string {
		return AuthContextHolder::instance()->get_connector_slug();
	}

	/**
	 * OAuth client id for this request, or '' when not an OAuth caller.
	 *
	 * The clients registry keys an OAuth caller on this rather than on its
	 * name or its agent: the id is already unique per server, and it is the
	 * only value that survives both a renamed client and a client used from
	 * two machines. Also the join key a Revoke needs — a registry that could
	 * show an OAuth client without being able to revoke it would be a list of
	 * problems with no remedy.
	 *
	 * @since 0.4.8
	 * @return string
	 */
	public static function client_id(): string {
		return AuthContextHolder::instance()->get_client_id();
	}

	/**
	 * The application-password UUID that authenticated this request, or ''.
	 *
	 * {@see self::resolve_type()} already reads this global to decide the
	 * connection type and then discards it. Keeping it is what makes Revoke
	 * real for an application-password client, which on most installs is the
	 * majority of them.
	 *
	 * @since 0.4.8
	 * @return string
	 */
	public static function credential_id(): string {
		if ( empty( $GLOBALS['wp_rest_application_password_uuid'] ) ) {
			return '';
		}

		$uuid = $GLOBALS['wp_rest_application_password_uuid'];

		return is_scalar( $uuid ) ? substr( (string) $uuid, 0, 36 ) : '';
	}

	/**
	 * A human label for the credential in use, or ''.
	 *
	 * The NAME an operator gave the application password — the thing shown on
	 * their own profile screen, so a row can be matched to a credential in
	 * hand. Deliberately not a fragment of the UUID: the UUID is never shown
	 * to the person who created the password, so a piece of it would identify
	 * nothing. Non-secret either way — the password itself is hashed and
	 * unreadable even here.
	 *
	 * @since 0.4.8
	 * @param  int $user_id Authenticated user.
	 * @return string
	 */
	public static function credential_hint( int $user_id ): string {
		$uuid = self::credential_id();

		if ( '' === $uuid || $user_id <= 0 || ! class_exists( '\WP_Application_Passwords' ) ) {
			return '';
		}

		// Returns null for an unknown uuid, which `empty()` covers alongside an
		// unnamed password — a password with no name has no hint to give.
		$password = \WP_Application_Passwords::get_user_application_password( $user_id, $uuid );

		if ( empty( $password['name'] ) ) {
			return '';
		}

		return mb_substr( (string) $password['name'], 0, 32 );
	}

	/**
	 * Key of the host-list row that authorised this fleet call, or ''.
	 *
	 * The registry keys a fleet caller on this, not on {@see self::fleet_peer()}.
	 * The peer string is whatever the other site called itself at the time, and
	 * it has been observed changing between two calls thirty-three seconds
	 * apart — its site name, then its hostname. The host key does not move.
	 *
	 * @since 0.4.8
	 * @return string
	 */
	public static function fleet_host_key(): string {
		return FleetContext::host_key();
	}

	/**
	 * Hostname of the fleet host driving this request, or '' when none is.
	 *
	 * Reads through to {@see FleetContext} rather than duplicating its state,
	 * so there is one place a fleet identity is established and one place it is
	 * read from. Empty on every non-fleet request, which is almost all of them.
	 *
	 * @since 0.3.8
	 * @return string
	 */
	public static function fleet_peer(): string {
		return FleetContext::peer();
	}

	/**
	 * Client application name and version, from the MCP session's stored
	 * `clientInfo`.
	 *
	 * `initialize` is the only request that carries `clientInfo`, and the
	 * adapter stashes the whole params blob on the session. Every later request
	 * in that session can therefore recover the client's identity by reading it
	 * back — which is why this is worth doing at all.
	 *
	 * Returns empty strings rather than nulls so the caller can assign directly
	 * to two non-null columns.
	 *
	 * @param string $session_id MCP session id, or '' when none.
	 * @param int    $user_id    Session owner.
	 * @return array{name: string, version: string}
	 */
	public static function client_info( string $session_id, int $user_id ): array {
		$empty = array(
			'name'    => '',
			'version' => '',
		);

		if ( '' === $session_id || $user_id <= 0 ) {
			return $empty;
		}

		// class_exists only: the adapter is a composer dependency, so PHPStan
		// resolves the class and a method_exists() guard beside it is provably
		// dead code. The class check still earns its place — the adapter is
		// loaded by a plugin that an operator can deactivate.
		$manager = '\\WP\\MCP\\Transport\\Infrastructure\\SessionManager';

		if ( ! class_exists( $manager ) ) {
			return $empty;
		}

		$session = $manager::get_session( $user_id, $session_id );

		// get_session() returns WP_Error on bad input and false when unknown.
		if ( ! is_array( $session ) ) {
			return $empty;
		}

		$params = isset( $session['client_params'] ) && is_array( $session['client_params'] )
			? $session['client_params']
			: array();

		$info = isset( $params['clientInfo'] ) && is_array( $params['clientInfo'] )
			? $params['clientInfo']
			: array();

		return array(
			'name'    => isset( $info['name'] ) && is_scalar( $info['name'] ) ? (string) $info['name'] : '',
			'version' => isset( $info['version'] ) && is_scalar( $info['version'] ) ? (string) $info['version'] : '',
		);
	}

	/**
	 * Resolve a server slug to its numeric row id.
	 *
	 * The adapter's `server_id` tag is our SLUG — confirmed against live traffic
	 * on 2026-10-16, see research.md §Phase 1. Returns 0 when the slug no longer
	 * resolves, which is the correct value for a deleted server: the row keeps
	 * `server_slug` so history stays readable either way.
	 *
	 * Memoised per request because a chatty client issues many calls against the
	 * same server and this would otherwise be one SELECT per event.
	 *
	 * @param string $slug Server slug from the `server_id` tag.
	 * @return int Numeric id, or 0.
	 */
	public static function server_id_for_slug( string $slug ): int {
		if ( '' === $slug ) {
			return 0;
		}

		if ( isset( self::$server_id_cache[ $slug ] ) ) {
			return self::$server_id_cache[ $slug ];
		}

		$id = 0;

		if ( class_exists( MCPServerQuery::class ) ) {
			$rows = MCPServerQuery::instance()->query(
				array(
					'server_slug' => $slug,
					'number'      => 1,
				)
			);

			if ( is_array( $rows ) && isset( $rows[0] ) && isset( $rows[0]->id ) ) {
				$id = (int) $rows[0]->id;
			}
		}

		self::$server_id_cache[ $slug ] = $id;

		return $id;
	}

	/**
	 * The client's source address.
	 *
	 * Personal data (FR-039). Recorded in full by the decision in Clarification
	 * Q1, on the condition that the subject-data path ships alongside it — which
	 * is why PersonalData.php is in this same release and not deferred.
	 */
	public static function ip_address(): string {
		$candidates = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' );

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			// X-Forwarded-For may carry a chain; the client is the first entry.
			$raw   = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
			$first = trim( (string) strtok( $raw, ',' ) );
			$valid = filter_var( $first, FILTER_VALIDATE_IP );

			if ( false !== $valid ) {
				return (string) $valid;
			}
		}

		return '';
	}

	/**
	 * The client's user agent, truncated to the column width.
	 *
	 * Distinguishes the npm bridge from a CLI client: both authenticate by
	 * application password, so `connection_type` alone cannot separate them.
	 */
	public static function user_agent(): string {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}

		$agent = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) );

		return mb_substr( $agent, 0, 255 );
	}

	/** Drop the per-request memo. Called from the holder's lifecycle. */
	public static function flush_cache(): void {
		self::$server_id_cache = array();
	}
}
